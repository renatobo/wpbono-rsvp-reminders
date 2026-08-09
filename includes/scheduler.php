<?php
/**
 * The hourly sweep: decide who is due a reminder, and mark who has had one.
 */

if (!defined('ABSPATH')) {
    exit;
}

const WPBONO_RSVP_REMINDERS_META_SENT = '_wpbono_reminder_sent';

/**
 * One tick.
 *
 * Deliberately "what is due right now that hasn't gone yet" rather than "what
 * falls in this hour". WP-Cron fires on traffic, so a tick can be late by hours
 * or skipped entirely on a quiet night; a window-based check would silently
 * drop those reminders, while a due-check catches up on the next run.
 *
 * The sent marker is written before the send is attempted for a given lead, so
 * a mailer failure costs one reminder rather than looping the same person on
 * every tick for days.
 */
function wpbono_rsvp_reminders_run() {
    if (!wpbono_rsvp_reminders_has_eventon()) {
        return;
    }
    if (wpbono_rsvp_reminders_setting('enabled') !== 'yes') {
        return;
    }

    $now = time();
    $leads = wpbono_rsvp_reminders_lead_set();
    $lookahead = $now + (max($leads) * DAY_IN_SECONDS);

    // Only events that start between now and the furthest lead time can have
    // anything due, which keeps this to a handful of posts on any real site.
    $events = get_posts(array(
        'post_type'      => 'ajde_events',
        'post_status'    => 'publish',
        'posts_per_page' => 100,
        'fields'         => 'ids',
        'meta_query'     => array(
            array(
                'key'     => 'evcal_srow',
                'value'   => array($now, $lookahead),
                'compare' => 'BETWEEN',
                'type'    => 'NUMERIC',
            ),
        ),
    ));

    foreach ($events as $event_id) {
        wpbono_rsvp_reminders_process_event($event_id, $now);
    }
}
add_action(WPBONO_RSVP_REMINDERS_CRON, 'wpbono_rsvp_reminders_run');

/**
 * The lead times in play site-wide, longest first.
 *
 * Per-event overrides can exceed these, so the lookahead built from this set is
 * a floor rather than a guarantee; an event with a longer override is simply
 * picked up once it enters the window, which is still before its own send time
 * as long as the override is under the site maximum of 60 days.
 */
function wpbono_rsvp_reminders_lead_set() {
    $leads = array((int) wpbono_rsvp_reminders_setting('lead_days'));
    $second = (int) wpbono_rsvp_reminders_setting('second_lead_days');
    if ($second > 0) {
        $leads[] = $second;
    }
    $leads[] = 60; // covers any per-event override, which is clamped to 60
    rsort($leads);
    return $leads;
}

function wpbono_rsvp_reminders_process_event($event_id, $now) {
    if (wpbono_rsvp_reminders_event_disabled($event_id)) {
        return;
    }

    $start = (int) get_post_meta($event_id, 'evcal_srow', true);
    if ($start <= $now) {
        return;
    }

    // Which lead times apply to this event: its own (or the site default),
    // plus the site-wide second reminder when one is configured.
    $leads = array(wpbono_rsvp_reminders_lead_days($event_id));
    $second = (int) wpbono_rsvp_reminders_setting('second_lead_days');
    if ($second > 0 && $second < $leads[0]) {
        $leads[] = $second;
    }

    $due = array();
    foreach ($leads as $lead) {
        if ($now >= ($start - ($lead * DAY_IN_SECONDS))) {
            $due[] = $lead;
        }
    }
    if (empty($due)) {
        return;
    }

    // Longest lead first, so a first-time run close to an event sends the
    // earlier reminder rather than skipping straight to the last-minute one.
    rsort($due);

    foreach (wpbono_rsvp_reminders_eligible_rsvps($event_id) as $rsvp_id) {
        $sent = wpbono_rsvp_reminders_sent_leads($rsvp_id);

        foreach ($due as $lead) {
            if (in_array($lead, $sent, true)) {
                continue;
            }

            // If they already had a reminder at a shorter lead, an older one is
            // moot: nobody wants "in 7 days" after "tomorrow".
            if (!empty($sent) && min($sent) <= $lead) {
                continue;
            }

            $sent[] = $lead;
            update_post_meta($rsvp_id, WPBONO_RSVP_REMINDERS_META_SENT, $sent);

            wpbono_rsvp_reminders_send($rsvp_id, $event_id, $lead);
            break; // one email per attendee per tick
        }
    }
}

function wpbono_rsvp_reminders_sent_leads($rsvp_id) {
    $sent = get_post_meta($rsvp_id, WPBONO_RSVP_REMINDERS_META_SENT, true);
    return is_array($sent) ? array_map('intval', $sent) : array();
}

/**
 * Attendees eligible for a reminder on this event.
 *
 * Always limited to a Yes RSVP. The "Receive updates about event" opt-in is an
 * additional filter, on by default, because that is the consent the attendee
 * actually gave for mail beyond their confirmation.
 */
function wpbono_rsvp_reminders_eligible_rsvps($event_id) {
    $meta_query = array(
        'relation' => 'AND',
        array('key' => 'e_id', 'value' => $event_id),
        array('key' => 'rsvp', 'value' => 'y'),
    );

    if (wpbono_rsvp_reminders_setting('require_updates_optin') === 'yes') {
        $meta_query[] = array('key' => 'updates', 'value' => 'yes');
    }

    return get_posts(array(
        'post_type'      => 'evo-rsvp',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => $meta_query,
    ));
}

/**
 * Clear the sent markers for an event when its start time moves.
 *
 * A rescheduled event is a different proposition, and people who were told
 * "Saturday" need telling again. Only forward moves matter; an event pulled
 * earlier than the lead window simply never re-sends.
 */
function wpbono_rsvp_reminders_reset_on_reschedule($meta_id, $post_id, $meta_key, $meta_value) {
    if ($meta_key !== 'evcal_srow' || get_post_type($post_id) !== 'ajde_events') {
        return;
    }

    $previous = (int) get_post_meta($post_id, '_wpbono_reminder_last_start', true);
    $new = (int) $meta_value;

    if ($previous && $previous !== $new) {
        foreach (wpbono_rsvp_reminders_eligible_rsvps($post_id) as $rsvp_id) {
            delete_post_meta($rsvp_id, WPBONO_RSVP_REMINDERS_META_SENT);
        }
    }
    update_post_meta($post_id, '_wpbono_reminder_last_start', $new);
}
add_action('updated_postmeta', 'wpbono_rsvp_reminders_reset_on_reschedule', 10, 4);
add_action('added_post_meta', 'wpbono_rsvp_reminders_reset_on_reschedule', 10, 4);
