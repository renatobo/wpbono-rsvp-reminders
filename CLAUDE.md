# WPBono RSVP Reminders — Agent Notes

Operational memory for contributors touching this plugin. `AGENTS.md` is a
symlink to this file so other agent toolchains find the same notes.

## What this is

Scheduled reminder emails for EventON RSVP attendees on DROC
(Ducati Riders of Orange County, `drocdesmo.com`). EventON sells a "Reminders"
add-on; it is not licensed here, so this fills that gap and stays configurable
without touching plugin files.

It exists as a **separate plugin, not theme code**, by explicit decision
(2026-08-09): reminders are behaviour the site owner configures and can switch
off, and a cron that mails members should not vanish because a theme changed.
The WPBono FSE theme owns the *transactional* RSVP emails (confirmation and
update notice) via its `eventon/templates/email/rsvp/` overrides. This plugin
owns the *scheduled* ones. That line is the architecture.

- Plugin slug / directory: **`wpbono-rsvp-reminders`**. This is the upgrade key
  WordPress uses to recognise an installed copy. Never rename it: a rename
  installs a second plugin beside the first and orphans its settings.
- Hard dependency on the **EventON RSVP add-on** (`EVO_RSVP_CPT`,
  `EVORS_Event`, `EVORS()`). Every path is guarded and an admin notice fires if
  it is missing.
- Soft dependency on the **WPBono FSE theme** for `wpbono_fse_theme_event_ics()`
  (the calendar invitation builder). Guarded with `function_exists`; without the
  theme, reminders still send, just without an attached invite, and the settings
  screen says so.

## Layout

```
wpbono-rsvp-reminders.php   plugin header, cron (un)scheduling, dependency notice
includes/settings.php       Settings -> RSVP Reminders, and the "Receive updates" default flip
includes/event-meta.php     per-event lead override + off switch on the event edit screen
includes/scheduler.php      the hourly sweep: who is due, who has had one
includes/mailer.php         composing and sending one reminder
```

## Who gets a reminder

Two conditions, both required (the second is a setting, on by default):

1. RSVP status is **Yes** (`rsvp` meta == `y`). Maybe and No never get one.
2. **"Receive updates about event"** is Yes (`updates` meta == `yes`).

EventON ships that second field defaulting to **No**, so in practice almost
nobody opted in and the reminder audience was near empty. The plugin flips the
default to Yes for *new* RSVPs through EventON's own
`evors_form_fields_array` filter (see `class-form.php:489`, where the field is
built with `'value' => ($RR && $RR->get_updates() ? 'yes' : 'no')`). An existing
RSVP being edited keeps whatever that person chose — do not "fix" that, it would
silently re-consent people who declined.

## Scheduling model

- Cron hook `wpbono_rsvp_reminders_tick`, **hourly**, not daily. WP-Cron only
  runs when the site gets traffic, so a daily schedule drifts and can skip a day
  on a quiet night.
- Each tick asks **"what is due now that hasn't gone yet"**, never "what falls
  in this hour". A window-based check would silently drop reminders whenever
  cron ran late. A late or repeated tick is therefore harmless.
- That holds for *sequential* ticks only. Two sweeps running **at once** would
  both read the same empty marker and both send, so the sweep takes a 15 minute
  transient lock (`wpbono_rsvp_reminders_running`). It expires on its own: a
  fatal mid-sweep costs one hour, not every reminder from then on.
- The schedule **self-heals** on `admin_init`: a database restore or another
  plugin's over-broad `wp_clear_scheduled_hook` can drop the entry without the
  plugin being deactivated, and reminders would stop with no symptom.
- The sent marker (`_wpbono_reminder_sent` on the RSVP post) is
  `array( repeat index => array( lead times in days ) )`. It is written
  **before** the send is attempted. A mailer failure costs one reminder rather
  than looping the same person every hour for days. Trade accepted deliberately.
  Before occurrence support the marker was a flat array of lead times; that
  format is still read, as occurrence 0, so an upgrade does not re-mail everyone
  already reminded. Do not drop that fallback.
- Sent markers are **cleared when an event's schedule changes**, because a
  rescheduled event needs re-announcing to people who were told the old date.
  Both `evcal_srow` and `repeat_intervals` count, compared as one signature
  stored in `_wpbono_reminder_last_start`.
- A **first run close to an event** sends only the shortest lead that is already
  past, not every one of them an hour apart.
- A tick sends at most **25 reminders** (`wpbono_rsvp_reminders_max_per_tick`).
  Sending is inline, one SMTP handshake each, so a popular event would otherwise
  outlast `max_execution_time` — and because the marker is written *before* the
  send, everyone past the cutoff would be marked reminded and never mailed. The
  remainder is still due an hour later, which is exactly what the due-check
  model is for. Do not remove this cap without moving sending off the tick.
- Both sweep queries are bounded (200 events, 500 attendees) and **log when they
  hit the cap**, because a silent truncation here reads as "some people just
  didn't get one".

## Query shape

`fields => 'ids'` queries return from `WP_Query` *before* it primes any caches
(`class-wp-query.php:3326`), so passing `update_post_meta_cache` alongside them
does nothing at all. Where the sweep then reads meta off those IDs it calls
`wpbono_rsvp_reminders_prime_meta()` explicitly; without it every read is its
own query. Don't "tidy" that back into a query arg.

The edit screen's reminded-count is a `found_posts` count, not a fetch-and-tally
loop. It must **not** set `no_found_rows`, which is precisely what suppresses
`found_posts`.

## Repeating events

EventON keeps only the **first** occurrence's start in `evcal_srow`; the series
lives in `repeat_intervals`, a map of repeat index to `array(start, end)` in raw
unix. An RSVP records which occurrence it is for in its own `repeat_interval`
meta, absent meaning 0.

So the sweep cannot filter events on `evcal_srow` alone: a weekly ride that
began last year would never be selected again even though occurrence 40 is next
Saturday. Repeating events are pulled in on the `evcal_repeat` flag and narrowed
per occurrence in PHP, and eligible attendees are matched on `repeat_interval`
(occurrence 0 also matching empty and missing, as EventON does in
`class-event_rsvp.php:510`).

`wpbono_rsvp_reminders_is_repeating()` mirrors `EVO_Event::is_repeating_event()`:
the flag alone is not enough, a single-entry interval map is a one-off event
that once had repeats.

Raw unix values are used throughout, matching `evcal_srow` and what EventON
stores, rather than the timezone-adjusted `get_repeats_adjusted()` variants.

## Lead times

- Site-wide default in Settings, plus an optional second (shorter) reminder.
- Any event overrides it on its own edit screen (`_wpbono_reminder_lead_days`),
  or opts out entirely (`_wpbono_reminder_disabled`).
- The second reminder is ignored unless it is **shorter** than the primary,
  otherwise the two collapse onto the same moment.
- If someone already had a reminder at a *shorter* lead, a longer one is
  suppressed: nobody wants "in 7 days" arriving after "tomorrow".

## Email

- Wrapped in `EVO()->get_email_part('header'/'footer')` so it matches the
  confirmation the attendee already has. Note those parts are **not** theme
  overridable: EventON builds their path as
  `TEMPLATEPATH . '/' . 'eventon' . 'templates/email/'`, missing a slash, so it
  looks for a directory called `eventontemplates`. Branding has to live inside
  the table, not in the wrapper.
- Logo is chosen in Settings (`logo_id`, an attachment ID), falling back to the
  theme's bundled `assets/img/email-logo.png`, then to no logo at all. Do **not**
  switch to the Site Logo: it is a white SVG
  (`DROC_tagline_white_horizontal-white.svg`), and no mail client renders SVG,
  nor would white show on a white card. That is why the picker is restricted to
  JPEG and PNG **twice**: the media modal filters the library, and
  `wpbono_rsvp_reminders_sanitize()` re-checks the mime type, because the posted
  value is only an ID and the modal filter is UI, not enforcement.
- That sanitize callback runs from `sanitize_option`, so it fires on **every**
  update of the settings option, cron and frontend included. Anything in it that
  touches `wp-admin/includes/` has to be guarded with `function_exists` —
  `add_settings_error()` already is. This bit once.
- The invite is attached via `phpmailer_init` + `addStringAttachment`, never
  `wp_mail`'s attachments array. That array takes file paths only, and the .ics
  names the attendee, so writing it under `uploads/` would expose member email
  addresses at a guessable URL.
- Same UID and a climbing SEQUENCE as the confirmation's invite, so a reminder
  **revises** the attendee's calendar entry rather than duplicating it. This is
  what corrects their calendar when an event moves.

### The invitation builder lives in the theme, and is shared

`wpbono_fse_theme_event_ics($event, $rsvp, $organizer)` is defined in the
**WPBono FSE theme's `functions.php`**, not here. Three emails call it:

| sender | owner |
| --- | --- |
| RSVP confirmation | theme, via `wpbono_fse_theme_attach_rsvp_ics` |
| RSVP update notice | theme, same filter |
| Scheduled reminder | this plugin, `includes/mailer.php`, behind `function_exists` |

This plugin only sends reminders, but it does **not** build its own invite, and
should not start. All three deliberately emit the same
`UID:evo-rsvp-<rsvpID>@host` and share the `SEQUENCE` counter in
`_wpbono_ics_sequence` on the RSVP post. That is precisely what makes a reminder
*revise* the attendee's existing calendar entry rather than adding a duplicate,
and what corrects their calendar when an event moves. Forking the builder here
would drift: a different UID puts a second event on someone's calendar, a stale
SEQUENCE makes the revision silently ignored.

The consequence runs the other way too: **a change to that theme function
changes the email this plugin sends**, with nothing here to flag it. If the
theme is swapped or deactivated, reminders still go out, just with no invite
attached, and the settings screen says as much.

If the coupling ever needs breaking, moving the builder into this plugin only
inverts the problem (confirmations would then depend on a reminders plugin being
active). A third shared plugin is the clean answer; duplication is not.

## Known trade, already accepted

Gmail shows Yes/Maybe/No on `METHOD:REQUEST` invitations despite
`ATTENDEE;RSVP=FALSE`. Those clicks send an iMIP reply that EventON cannot
parse, so an answer changed in Gmail never reaches the RSVP list. The site stays
the source of truth for the roster. Decided 2026-08-09; do not "fix" it by
adding a reply parser without discussing it first.
