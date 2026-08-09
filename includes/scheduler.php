<?php
/**
 * The hourly sweep: decide who is due a reminder, and mark who has had one.
 */

if (!defined('ABSPATH')) {
    exit;
}

const WPBONO_RSVP_REMINDERS_META_SENT = '_wpbono_reminder_sent';
const WPBONO_RSVP_REMINDERS_META_SIG = '_wpbono_reminder_last_start';
const WPBONO_RSVP_REMINDERS_LOCK = 'wpbono_rsvp_reminders_running';

// Both queries are bounded, and both would truncate silently. Hitting either
// cap is logged rather than left to be discovered as "some people never got a
// reminder".
const WPBONO_RSVP_REMINDERS_EVENT_LIMIT = 200;
const WPBONO_RSVP_REMINDERS_RSVP_LIMIT = 500;

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
 *
 * A *sequentially* late or repeated tick is harmless for those reasons. Two
 * ticks running at once are not: both would read the same empty marker and both
 * would send. WP-Cron spawns on traffic and does overlap, so the sweep takes a
 * lock. The lock expires on its own, so a fatal mid-sweep costs one hour rather
 * than wedging reminders permanently.
 */
function wpbono_rsvp_reminders_run() {
    if (!wpbono_rsvp_reminders_has_eventon()) {
        return;
    }
    if (wpbono_rsvp_reminders_setting('enabled') !== 'yes') {
        return;
    }
    if (get_transient(WPBONO_RSVP_REMINDERS_LOCK)) {
        return;
    }
    set_transient(WPBONO_RSVP_REMINDERS_LOCK, time(), 15 * MINUTE_IN_SECONDS);

    try {
        $now = time();
        $leads = wpbono_rsvp_reminders_lead_set();
        $lookahead = $now + (max($leads) * DAY_IN_SECONDS);

        // Sending is inline and one SMTP handshake each, so a popular event can
        // outlast max_execution_time. That would be silent data loss rather
        // than a retry: the marker is written before the send, so everyone past
        // the cutoff would be recorded as reminded and never mailed. Capping
        // the tick keeps it well inside the limit, and the rest is simply still
        // due an hour later, which the due-check model already handles.
        $budget = (int) apply_filters('wpbono_rsvp_reminders_max_per_tick', 25);

        $events = wpbono_rsvp_reminders_candidate_events($now, $lookahead);
        if (count($events) >= WPBONO_RSVP_REMINDERS_EVENT_LIMIT) {
            wpbono_rsvp_reminders_log('', '', 0, 'candidate events hit the ' . WPBONO_RSVP_REMINDERS_EVENT_LIMIT . ' row cap');
        }

        foreach ($events as $event_id) {
            if ($budget <= 0) {
                break;
            }
            wpbono_rsvp_reminders_process_event($event_id, $now, $lookahead, $budget);
        }

        if ($budget <= 0) {
            wpbono_rsvp_reminders_log('', '', 0, 'tick budget spent, resuming next hour');
        }
    } finally {
        wpbono_rsvp_reminders_log_flush();
        delete_transient(WPBONO_RSVP_REMINDERS_LOCK);
    }
}
add_action(WPBONO_RSVP_REMINDERS_CRON, 'wpbono_rsvp_reminders_run');

/**
 * Events that could have something due between now and the furthest lead time.
 *
 * A one-off event qualifies when its own start falls in the window. A repeating
 * one cannot be filtered that way: evcal_srow holds only the *first*
 * occurrence, so a weekly ride that began last year would never be selected
 * again even though occurrence 40 is next Saturday. Those are pulled in on the
 * repeat flag instead and narrowed down per occurrence in PHP.
 */
function wpbono_rsvp_reminders_candidate_events($now, $lookahead) {
    $events = get_posts(array(
        'post_type'      => 'ajde_events',
        'post_status'    => 'publish',
        'posts_per_page' => WPBONO_RSVP_REMINDERS_EVENT_LIMIT,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_query'     => array(
            'relation' => 'AND',
            array(
                'key'     => 'evcal_srow',
                'value'   => $lookahead,
                'compare' => '<=',
                'type'    => 'NUMERIC',
            ),
            array(
                'relation' => 'OR',
                array(
                    'key'     => 'evcal_srow',
                    'value'   => $now,
                    'compare' => '>=',
                    'type'    => 'NUMERIC',
                ),
                array(
                    'key'   => 'evcal_repeat',
                    'value' => 'yes',
                ),
            ),
        ),
    ));

    // Every candidate then has several meta values read off it. A fields=>ids
    // query returns before WP_Query primes any caches (class-wp-query.php:3326),
    // so without this each of those reads is its own query.
    wpbono_rsvp_reminders_prime_meta($events);

    return $events;
}

/**
 * Prime the post meta cache for a set of IDs.
 *
 * update_post_meta_cache is not the way to do this after a fields=>ids query:
 * WP_Query returns before it is ever consulted, so passing it is inert and
 * reads as though priming had been considered and declined.
 */
function wpbono_rsvp_reminders_prime_meta($ids) {
    if (!empty($ids)) {
        update_meta_cache('post', $ids);
    }
}

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

/**
 * The occurrences of one event that start inside the window, as ri => start.
 *
 * A one-off event is occurrence 0 at evcal_srow. A repeating one is described
 * by repeat_intervals, a map of repeat index to array(start, end). Raw unix
 * values are used throughout, matching evcal_srow and what EventON stores,
 * rather than the timezone-adjusted variants.
 */
function wpbono_rsvp_reminders_occurrences($event_id, $now, $lookahead) {
    $occurrences = array();

    if (wpbono_rsvp_reminders_is_repeating($event_id)) {
        $repeats = maybe_unserialize(get_post_meta($event_id, 'repeat_intervals', true));
        foreach ((array) $repeats as $ri => $pair) {
            $start = is_array($pair) && isset($pair[0]) ? (int) $pair[0] : 0;
            if ($start > $now && $start <= $lookahead) {
                $occurrences[(int) $ri] = $start;
            }
        }
        return $occurrences;
    }

    $start = (int) get_post_meta($event_id, 'evcal_srow', true);
    if ($start > $now && $start <= $lookahead) {
        $occurrences[0] = $start;
    }
    return $occurrences;
}

/**
 * Mirrors EVO_Event::is_repeating_event(): the flag alone is not enough, a
 * single-entry interval map is a one-off event that once had repeats.
 */
function wpbono_rsvp_reminders_is_repeating($event_id) {
    if (get_post_meta($event_id, 'evcal_repeat', true) !== 'yes') {
        return false;
    }
    $repeats = maybe_unserialize(get_post_meta($event_id, 'repeat_intervals', true));
    return is_array($repeats) && count($repeats) > 1;
}

function wpbono_rsvp_reminders_process_event($event_id, $now, $lookahead, &$budget = null) {
    if (wpbono_rsvp_reminders_event_disabled($event_id)) {
        return;
    }

    $repeating = wpbono_rsvp_reminders_is_repeating($event_id);

    // Which lead times apply to this event: its own (or the site default),
    // plus the site-wide second reminder when one is configured.
    $leads = array(wpbono_rsvp_reminders_lead_days($event_id));
    $second = (int) wpbono_rsvp_reminders_setting('second_lead_days');
    if ($second > 0 && $second < $leads[0]) {
        $leads[] = $second;
    }

    foreach (wpbono_rsvp_reminders_occurrences($event_id, $now, $lookahead) as $ri => $start) {
        if ($budget !== null && $budget <= 0) {
            return;
        }

        $due = array();
        foreach ($leads as $lead) {
            if ($now >= ($start - ($lead * DAY_IN_SECONDS))) {
                $due[] = $lead;
            }
        }
        if (empty($due)) {
            continue;
        }

        // Longest lead first, so an attendee who has already had the short
        // reminder is never sent the long one afterwards.
        rsort($due);

        $rsvps = wpbono_rsvp_reminders_eligible_rsvps($event_id, $repeating ? $ri : null);
        if (count($rsvps) >= WPBONO_RSVP_REMINDERS_RSVP_LIMIT) {
            wpbono_rsvp_reminders_log(get_the_title($event_id), '', 0, 'attendees hit the ' . WPBONO_RSVP_REMINDERS_RSVP_LIMIT . ' row cap');
        }

        foreach ($rsvps as $rsvp_id) {
            if ($budget !== null && $budget <= 0) {
                return;
            }

            $sent = wpbono_rsvp_reminders_sent_leads($rsvp_id, $ri);

            // Nothing has gone yet and several leads are already past: this is
            // a first run close to the event. Only the most recent lead still
            // means anything, and sending the rest would fire the whole ladder
            // an hour apart.
            $candidates = empty($sent) ? array(min($due)) : $due;

            foreach ($candidates as $lead) {
                if (in_array($lead, $sent, true)) {
                    continue;
                }

                // If they already had a reminder at a shorter lead, an older one
                // is moot: nobody wants "in 7 days" after "tomorrow".
                if (!empty($sent) && min($sent) <= $lead) {
                    continue;
                }

                $sent[] = $lead;
                wpbono_rsvp_reminders_mark_sent($rsvp_id, $ri, $sent);

                wpbono_rsvp_reminders_send($rsvp_id, $event_id, $lead);
                if ($budget !== null) {
                    $budget--;
                }
                break; // one email per attendee per occurrence per tick
            }
        }
    }
}

/**
 * Lead times already sent to one attendee for one occurrence.
 *
 * The marker is array( repeat index => array( lead days ) ). Before occurrence
 * support it was a flat array of lead days, which is read here as occurrence 0
 * so an upgrade does not re-send to everyone already reminded.
 */
function wpbono_rsvp_reminders_sent_leads($rsvp_id, $ri = 0) {
    $sent = wpbono_rsvp_reminders_sent_map($rsvp_id);
    return isset($sent[(int) $ri]) ? array_map('intval', $sent[(int) $ri]) : array();
}

function wpbono_rsvp_reminders_sent_map($rsvp_id) {
    $sent = get_post_meta($rsvp_id, WPBONO_RSVP_REMINDERS_META_SENT, true);
    if (!is_array($sent) || empty($sent)) {
        return array();
    }
    // Pre-occurrence format: a flat list of lead times.
    if (!is_array(reset($sent))) {
        return array(0 => array_map('intval', $sent));
    }
    return $sent;
}

function wpbono_rsvp_reminders_mark_sent($rsvp_id, $ri, $leads) {
    $sent = wpbono_rsvp_reminders_sent_map($rsvp_id);
    $sent[(int) $ri] = array_values(array_unique(array_map('intval', $leads)));
    update_post_meta($rsvp_id, WPBONO_RSVP_REMINDERS_META_SENT, $sent);
}

/**
 * Attendees eligible for a reminder on this event, optionally for one
 * occurrence of it.
 *
 * Always limited to a Yes RSVP. The "Receive updates about event" opt-in is an
 * additional filter, on by default, because that is the consent the attendee
 * actually gave for mail beyond their confirmation.
 *
 * $ri null means "every RSVP on this event regardless of occurrence", which is
 * what a one-off event wants: filtering those by repeat_interval would drop an
 * RSVP carrying a stray value.
 */
function wpbono_rsvp_reminders_eligible_rsvps($event_id, $ri = null) {
    $meta_query = array(
        'relation' => 'AND',
        array('key' => 'e_id', 'value' => $event_id),
        array('key' => 'rsvp', 'value' => 'y'),
    );

    if (wpbono_rsvp_reminders_setting('require_updates_optin') === 'yes') {
        $meta_query[] = array('key' => 'updates', 'value' => 'yes');
    }

    if ($ri !== null) {
        $meta_query[] = wpbono_rsvp_reminders_ri_clause($ri);
    }

    return wpbono_rsvp_reminders_rsvp_ids($meta_query);
}

/**
 * Occurrence 0 covers RSVPs saved before the event repeated, which have no
 * repeat_interval meta at all. EventON matches the same three ways.
 */
function wpbono_rsvp_reminders_ri_clause($ri) {
    if ((int) $ri !== 0) {
        return array('key' => 'repeat_interval', 'value' => (int) $ri);
    }
    return array(
        'relation' => 'OR',
        array('key' => 'repeat_interval', 'value' => '0'),
        array('key' => 'repeat_interval', 'value' => ''),
        array('key' => 'repeat_interval', 'compare' => 'NOT EXISTS'),
    );
}

function wpbono_rsvp_reminders_rsvp_ids($meta_query) {
    $ids = get_posts(array(
        'post_type'      => 'evo-rsvp',
        'post_status'    => 'any',
        'posts_per_page' => WPBONO_RSVP_REMINDERS_RSVP_LIMIT,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_query'     => $meta_query,
    ));

    // The sweep reads the sent marker off every one of these.
    wpbono_rsvp_reminders_prime_meta($ids);

    return $ids;
}

/**
 * Clear the sent markers for an event when its schedule moves.
 *
 * A rescheduled event is a different proposition, and people who were told
 * "Saturday" need telling again. Both the one-off start and the repeat interval
 * map count: editing a repeating event's series moves every occupied occurrence
 * at once.
 *
 * Markers are cleared for every RSVP on the event rather than only the eligible
 * ones, so somebody who has since changed their answer or their opt-in does not
 * keep a stale marker that would suppress a later reminder if they change back.
 */
function wpbono_rsvp_reminders_reset_on_reschedule($meta_id, $post_id, $meta_key, $meta_value) {
    if (!in_array($meta_key, array('evcal_srow', 'repeat_intervals'), true)) {
        return;
    }
    if (get_post_type($post_id) !== 'ajde_events') {
        return;
    }

    $signature = wpbono_rsvp_reminders_schedule_signature($post_id);
    $previous = (string) get_post_meta($post_id, WPBONO_RSVP_REMINDERS_META_SIG, true);

    if ($previous !== '' && $previous !== $signature) {
        foreach (wpbono_rsvp_reminders_rsvp_ids(array(array('key' => 'e_id', 'value' => $post_id))) as $rsvp_id) {
            delete_post_meta($rsvp_id, WPBONO_RSVP_REMINDERS_META_SENT);
        }
    }
    update_post_meta($post_id, WPBONO_RSVP_REMINDERS_META_SIG, $signature);
}
add_action('updated_postmeta', 'wpbono_rsvp_reminders_reset_on_reschedule', 10, 4);
add_action('added_post_meta', 'wpbono_rsvp_reminders_reset_on_reschedule', 10, 4);

/**
 * One value covering both the single start and the whole repeat series, so a
 * change to either is a single comparison.
 */
function wpbono_rsvp_reminders_schedule_signature($event_id) {
    $start = (int) get_post_meta($event_id, 'evcal_srow', true);
    $repeats = maybe_unserialize(get_post_meta($event_id, 'repeat_intervals', true));
    return $start . ':' . md5(is_array($repeats) ? wp_json_encode($repeats) : '');
}
