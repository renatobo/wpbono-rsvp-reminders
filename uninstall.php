<?php
/**
 * Remove everything this plugin stored.
 *
 * The activity log holds attendee email addresses, so leaving it behind after a
 * deletion keeps member personal data in wp_options with nothing left in the
 * admin to view or clear it. That is the reason this file exists; the settings
 * and the per-post markers go with it.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('wpbono_rsvp_reminders_settings');
delete_option('wpbono_rsvp_reminders_log');
delete_transient('wpbono_rsvp_reminders_running');

// Reminder state lives on RSVP and event posts: _wpbono_reminder_sent,
// _wpbono_reminder_last_start, _wpbono_reminder_lead_days,
// _wpbono_reminder_disabled. delete_post_meta_by_key does the cache
// invalidation that a direct DELETE would skip.
foreach (array(
    '_wpbono_reminder_sent',
    '_wpbono_reminder_last_start',
    '_wpbono_reminder_lead_days',
    '_wpbono_reminder_disabled',
) as $meta_key) {
    delete_post_meta_by_key($meta_key);
}
