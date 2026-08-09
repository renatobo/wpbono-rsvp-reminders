# WPBono RSVP Reminders

[![WordPress](https://img.shields.io/badge/WordPress-7.0%2B-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777bb4?logo=php&logoColor=white)](https://www.php.net/)
[![Tested up to](https://img.shields.io/badge/Tested%20up%20to-7.0.2-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![Release](https://img.shields.io/github/v/release/renatobo/wpbono-rsvp-reminders?label=release)](https://github.com/renatobo/wpbono-rsvp-reminders/releases)
[![License: GPL v3 or later](https://img.shields.io/badge/License-GPL%20v3%20or%20later-blue.svg)](https://www.gnu.org/licenses/gpl-3.0.html)

Scheduled reminder emails for EventON RSVP attendees, with a site-wide default
lead time and a per-event override. EventON sells a "Reminders" add-on; it is
not licensed on DROC, so this fills that gap and stays configurable without
touching plugin files.

## Contents

- [Quick start](#quick-start)
- [Who gets a reminder](#who-gets-a-reminder)
- [Requirements](#requirements)
- [Installation](#installation)
- [Settings](#settings)
- [How scheduling works](#how-scheduling-works)
- [Repeating events](#repeating-events)
- [The calendar invite](#the-calendar-invite)
- [Relationship to the theme](#relationship-to-the-theme)
- [Uninstalling](#uninstalling)
- [Development](#development)
- [License](#license)

## Quick start

1. Activate the plugin with the EventON RSVP add-on already active.
2. Go to **Settings → RSVP Reminders**.
3. Set the lead time, optionally a shorter second reminder, and pick an email
   logo.
4. Leave **Dry run** ticked for one cycle and check **Recent activity** to
   confirm the targeting before anything is mailed.

## Who gets a reminder

Two conditions, both required:

1. The RSVP status is **Yes**. Maybe and No never get one.
2. **Receive updates about event** is Yes. This is a setting, on by default.

EventON ships that second field defaulting to No, so in practice almost nobody
opted in and the reminder audience was near empty. The plugin flips the default
to Yes for *new* RSVPs through EventON's own `evors_form_fields_array` filter.
An existing RSVP being edited keeps whatever that person chose, which is
deliberate: changing it would silently re-consent people who declined.

## Requirements

| | |
| --- | --- |
| WordPress | 7.0 or later |
| PHP | 8.2 or later |
| Required | EventON, and the EventON **RSVP** add-on |
| Optional | The WPBono FSE theme, for the calendar invite and the default email logo |

Without the theme, reminders still send: just with no invite attached, and the
settings screen says so.

## Installation

Install from a GitHub release, or clone into `wp-content/plugins/`. Updates come
through [Git Updater](https://github.com/afragen/git-updater).

> The plugin directory must stay `wpbono-rsvp-reminders`. That name is the key
> WordPress uses to recognise an installed copy: renaming it installs a second
> plugin beside the first and orphans its settings.

## Settings

**Settings → RSVP Reminders**

| Setting | What it does |
| --- | --- |
| Send reminders | Master switch. |
| Default lead time | Days before the event. Any event can override it. |
| Second reminder | An optional shorter reminder. Ignored unless it is shorter than the primary, otherwise the two land at the same moment. |
| Who gets one | Whether the "Receive updates" opt-in is required, and whether new RSVPs default to Yes. |
| Calendar invite | Attach the `.ics`. Needs the theme. |
| Email logo | Used by all three RSVP emails. PNG or JPEG; SVG is refused and WebP warns. |
| Subject / Message | Supports `{event-name}`, `{event-date}`, `{first-name}`. |
| Dry run | Logs what would be sent and sends nothing. Attendees are still marked as reminded, so it verifies targeting without mailing anyone. |

Each event also gets an **RSVP Reminder** box on its edit screen: a per-event
lead time, an off switch, and a count of attendees already reminded.

## How scheduling works

A cron hook runs **hourly**, not daily. WP-Cron only fires when the site gets
traffic, so a daily schedule drifts and can skip a day on a quiet night.

Each tick asks *"what is due now that hasn't gone yet"*, never *"what falls in
this hour"*. A window check would silently drop reminders whenever cron ran
late; a due check catches up on the next run. The schedule also re-arms itself
on `admin_init`, because a database restore or another plugin's over-broad
`wp_clear_scheduled_hook` can drop the entry with no other symptom.

Two bounds worth knowing:

- The sweep takes a **lock**, so two overlapping ticks cannot both mail the same
  person.
- A tick sends at most **25 reminders** (filter
  `wpbono_rsvp_reminders_max_per_tick`). Sending is inline, one SMTP handshake
  each, so an uncapped blast on a popular event would outlast
  `max_execution_time`. The remainder is still due an hour later.

## Repeating events

Fully supported, and less obvious than it looks. EventON keeps only the *first*
occurrence's start in `evcal_srow`; the series lives in `repeat_intervals`, and
each RSVP records which occurrence it belongs to. Reminders are therefore timed
per occurrence, and the sent marker is keyed by occurrence, so a weekly ride
reminds its attendees every week rather than once ever.

## The calendar invite

The attached `.ics` carries the same UID and a climbing SEQUENCE as the
confirmation's invite, so a reminder **revises** the attendee's existing calendar
entry instead of adding a duplicate. That is what corrects their calendar when
an event moves.

Known trade: Gmail shows Yes/Maybe/No on the invitation despite
`ATTENDEE;RSVP=FALSE`. Those clicks send an iMIP reply EventON cannot parse, so
an answer changed in Gmail never reaches the RSVP list. **The site stays the
source of truth for the roster.**

## Relationship to the theme

The WPBono FSE theme owns the *transactional* RSVP emails (confirmation, update
notice). This plugin owns the *scheduled* ones. That line is the architecture.

The theme also builds the calendar invite and provides the default email logo,
both reached through accessors rather than by literal path. Choosing a logo here
changes all three RSVP emails, not just the reminder.

## Uninstalling

Deleting the plugin removes its settings, its activity log and its post meta.
The log holds attendee email addresses, which is why the cleanup exists rather
than leaving member data behind in `wp_options`.

## Development

Local work happens in the bono Docker stack. There is no WP-CLI in the web
container; for one-offs, write a script that requires `wp-load.php`, `docker cp`
it in, and run it with `php`. See `CLAUDE.md` for the operational notes and
`ui.md` for the settings-screen design.

## License

GPL-3.0-or-later.
