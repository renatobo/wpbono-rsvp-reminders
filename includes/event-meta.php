<?php
/**
 * Per-event override, on the EventON event edit screen.
 */

if (!defined('ABSPATH')) {
    exit;
}

const WPBONO_RSVP_REMINDERS_META_LEAD = '_wpbono_reminder_lead_days';
const WPBONO_RSVP_REMINDERS_META_OFF = '_wpbono_reminder_disabled';

function wpbono_rsvp_reminders_add_meta_box() {
    add_meta_box(
        'wpbono-rsvp-reminders',
        __('RSVP Reminder', 'wpbono-rsvp-reminders'),
        'wpbono_rsvp_reminders_meta_box',
        'ajde_events',
        'side',
        'default'
    );
}
add_action('add_meta_boxes', 'wpbono_rsvp_reminders_add_meta_box');

function wpbono_rsvp_reminders_meta_box($post) {
    wp_nonce_field('wpbono_rsvp_reminders_save', 'wpbono_rsvp_reminders_nonce');

    $lead = get_post_meta($post->ID, WPBONO_RSVP_REMINDERS_META_LEAD, true);
    $off = get_post_meta($post->ID, WPBONO_RSVP_REMINDERS_META_OFF, true) === 'yes';
    $default = (int) wpbono_rsvp_reminders_setting('lead_days');
    ?>
    <p>
        <label>
            <input type="checkbox" name="wpbono_reminder_disabled" value="yes" <?php checked($off); ?> />
            <?php esc_html_e('No reminder for this event', 'wpbono-rsvp-reminders'); ?>
        </label>
    </p>
    <p>
        <label for="wpbono-reminder-lead"><?php esc_html_e('Send this many days ahead', 'wpbono-rsvp-reminders'); ?></label><br />
        <input id="wpbono-reminder-lead" type="number" min="1" max="60" class="small-text"
               name="wpbono_reminder_lead_days" value="<?php echo esc_attr($lead); ?>"
               placeholder="<?php echo esc_attr($default); ?>" />
        <span class="description">
            <?php
            printf(
                /* translators: %d: site-wide default lead time in days. */
                esc_html__('Leave empty to use the site default (%d).', 'wpbono-rsvp-reminders'),
                (int) $default
            );
            ?>
        </span>
    </p>
    <?php

    // Sent-state is the thing people actually need to see when a reminder
    // "didn't go out": almost always it did, earlier, and the marker says so.
    $sent = wpbono_rsvp_reminders_event_sent_count($post->ID);
    if ($sent > 0) {
        echo '<p>' . esc_html(sprintf(
            /* translators: %d: number of attendees already reminded. */
            _n('%d attendee already reminded.', '%d attendees already reminded.', $sent, 'wpbono-rsvp-reminders'),
            $sent
        )) . '</p>';
    }
}

function wpbono_rsvp_reminders_save_meta($post_id) {
    if (!isset($_POST['wpbono_rsvp_reminders_nonce'])
        || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wpbono_rsvp_reminders_nonce'])), 'wpbono_rsvp_reminders_save')) {
        return;
    }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    if (!empty($_POST['wpbono_reminder_disabled'])) {
        update_post_meta($post_id, WPBONO_RSVP_REMINDERS_META_OFF, 'yes');
    } else {
        delete_post_meta($post_id, WPBONO_RSVP_REMINDERS_META_OFF);
    }

    $lead = isset($_POST['wpbono_reminder_lead_days']) ? trim((string) wp_unslash($_POST['wpbono_reminder_lead_days'])) : '';
    if ($lead === '') {
        delete_post_meta($post_id, WPBONO_RSVP_REMINDERS_META_LEAD);
    } else {
        update_post_meta($post_id, WPBONO_RSVP_REMINDERS_META_LEAD, max(1, min(60, (int) $lead)));
    }
}
add_action('save_post_ajde_events', 'wpbono_rsvp_reminders_save_meta');

/**
 * Lead time in days for one event: its own override, else the site default.
 */
function wpbono_rsvp_reminders_lead_days($event_id) {
    $lead = get_post_meta($event_id, WPBONO_RSVP_REMINDERS_META_LEAD, true);
    if ($lead !== '' && (int) $lead > 0) {
        return (int) $lead;
    }
    return max(1, (int) wpbono_rsvp_reminders_setting('lead_days'));
}

function wpbono_rsvp_reminders_event_disabled($event_id) {
    return get_post_meta($event_id, WPBONO_RSVP_REMINDERS_META_OFF, true) === 'yes';
}

/**
 * How many attendees on this event have already had a reminder.
 *
 * Counted in the database rather than fetched and tallied. The previous version
 * pulled every RSVP with posts_per_page -1 and then read the marker one row at
 * a time, which is 106 queries on the busiest event to render one sentence on
 * the edit screen.
 *
 * no_found_rows must stay off here: found_posts is precisely what it suppresses.
 */
function wpbono_rsvp_reminders_event_sent_count($event_id) {
    $query = new WP_Query(array(
        'post_type'              => 'evo-rsvp',
        'post_status'            => 'any',
        'posts_per_page'         => 1,
        'fields'                 => 'ids',
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'meta_query'             => array(
            'relation' => 'AND',
            array('key' => 'e_id', 'value' => $event_id),
            array('key' => WPBONO_RSVP_REMINDERS_META_SENT, 'compare' => 'EXISTS'),
        ),
    ));

    return (int) $query->found_posts;
}
