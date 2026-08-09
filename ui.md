# WPBono RSVP Reminders UI Notes

## Settings Header

- Use the banner image at `assets/wpbono-rsvp-reminders-settings-banner.svg` at
  the top of the settings page.
- Render the banner near its authored width instead of stretching it across the
  full admin container.
  - cap the hero near `750px`
  - allow it to shrink on narrow screens without growing beyond the native size
- The banner should keep the current visual direction:
  - WPBono RSVP Reminders wordmark
  - envelope-and-clock mark, matching `assets/icon.svg`
  - short one-line product explanation with a lighter secondary line
  - DROC red (`#E11C24`) into near-black, the same red the reminder emails use
    as their accent
- Below the banner, keep a compact metadata row with these items:
  - `GitHub Repository`
  - current plugin version
  - `Release notes`
  - author GitHub link
  - single-button link: `GitHub updates via Git Updater`
- The `Release notes` link points to the tagged GitHub release for the current
  version: `https://github.com/renatobo/wpbono-rsvp-reminders/releases/tag/vX.Y.Z`

## Settings Intro Copy

- Keep the page title `RSVP Reminders`.
- Keep the intro paragraph explaining that this fills the gap left by the
  unlicensed EventON Reminders add-on, and that the invite revises rather than
  duplicates the attendee's calendar entry.
- The secondary line is live status, not marketing: when the next check is due,
  or that the schedule is not armed. It says reminders go out for anything whose
  lead time has come due, because that is what makes a late check harmless.
- Keep an inline `notice-error` when the EventON RSVP add-on is missing. The
  plugin does nothing at all without it, so that has to be visible on the screen
  rather than only in the admin-wide notice.

## Layout

- Keep the layout WordPress-admin friendly, not app-like.
- Prefer flat cards, subtle borders, and native admin spacing.
- Settings themselves stay in a single native `form-table`. There are few enough
  of them that tabs would be ceremony.
- `Recent activity` is a `.wpbono-rsvp-reminders-card` section with a
  `widefat striped` table, and its own `Clear log` form.

## Notices and Feedback

- Do not call `settings_errors()` in the page callback. `add_options_page()`
  puts this screen under `options-general.php`, so core's `admin-header.php`
  loads `options-head.php`, which already renders them. A second call prints
  every notice twice.
- Settings are hand-rolled in a `form-table` rather than registered through
  `add_settings_section()` / `add_settings_field()`. Do not add
  `do_settings_sections()`: it takes a page slug, not a settings group, so it
  renders nothing here. `register_setting()` plus `settings_fields()` is still
  what saves and nonces the form.
- The two forms on this page must not share element IDs. `settings_fields()`
  emits `id="_wpnonce"` and `submit_button()` emits `id="submit"`, so the
  clear-log form names its own (`wpbono_clear_log_nonce`,
  `wpbono_clear_log_submit`). Duplicate IDs are invalid markup and confuse
  assistive technology.

## Email Logo Field

- Use `wp_enqueue_media()` with a media-frame button, never a bare URL text
  field. Store the attachment ID; resolve the URL at send time.
- Load the media modal on this screen only. It is heavy.
- The modal library filter is deliberately **narrower** than what the server
  accepts: it offers JPEG and PNG, while `wpbono_rsvp_reminders_sanitize_logo_id()`
  also tolerates WebP with a warning. There is no reason to offer a format
  Outlook cannot render as though it were fine.
- The field description must say all three things, because each has caught
  somebody out:
  - SVG is refused, since no mail client renders it and the Site Logo is an SVG
  - WebP will not show in Outlook on Windows
  - a white or transparent-on-light logo is invisible on the white email card

## Maintenance

- The plugin updates row should use the standard WordPress plugin asset
  filenames:
  - `assets/icon.svg`
  - `assets/icon-128x128.png`
  - `assets/icon-256x256.png`
- Keep those icon assets aligned with the banner artwork. Regenerate the PNGs
  from the SVG rather than editing them:
  - `rsvg-convert -w 128 -h 128 assets/icon.svg -o assets/icon-128x128.png`
  - `rsvg-convert -w 256 -h 256 assets/icon.svg -o assets/icon-256x256.png`
- When cutting a release, keep these version references synchronized:
  - `wpbono-rsvp-reminders.php` plugin header `Version`
  - `wpbono-rsvp-reminders.php` constant `WPBONO_RSVP_REMINDERS_VERSION`
- When the header or layout design changes, update this file in the same change.
