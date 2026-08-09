<?php
/**
 * Composing and sending one reminder.
 */

if (!defined('ABSPATH')) {
    exit;
}

function wpbono_rsvp_reminders_send($rsvp_id, $event_id, $lead_days) {
    $rsvp = new EVO_RSVP_CPT($rsvp_id);

    // The event is re-derived from the RSVP, since EVORS_Event also needs that
    // RSVP's repeat_interval. The caller selected this RSVP *by* e_id, so the
    // two always agree; this guard makes that invariant explicit rather than
    // leaving $event_id decorative, where a future caller could mail someone
    // about an event other than the one they RSVP-ed to.
    if ((int) $rsvp->event_id() !== (int) $event_id) {
        return false;
    }

    $rsvp_event = new EVORS_Event($rsvp->event_id(), $rsvp->repeat_interval());
    if (empty($rsvp_event->event)) {
        return false;
    }

    $event = $rsvp_event->event;
    $event->get_event_post();

    $to = $rsvp->email();
    if (!is_email($to)) {
        return false;
    }

    $tokens = array(
        '{event-name}' => $event->get_title(),
        '{event-date}' => $event->get_formatted_smart_time($rsvp->repeat_interval()),
        '{first-name}' => $rsvp->first_name() ? $rsvp->first_name() : '',
    );

    $subject = strtr((string) wpbono_rsvp_reminders_setting('subject'), $tokens);
    $intro = strtr((string) wpbono_rsvp_reminders_setting('intro'), $tokens);

    $message = wpbono_rsvp_reminders_body($event, $rsvp, $intro);

    // Match whatever EventON RSVP sends its own mail as, so reminders don't
    // arrive from a different address than the confirmation.
    $from = function_exists('EVORS') && !empty(EVORS()->email)
        ? EVORS()->email->get_from_email('confirmation')
        : get_bloginfo('name') . ' <' . get_bloginfo('admin_email') . '>';

    // EventON assembles this from two raw option fields and runs only
    // htmlspecialchars_decode over them (class-emailing.php:290-332), so a
    // newline in either one injects headers here or fails the send outright.
    // The theme's evors_beforesend_email_data repair does not cover this path,
    // because the address is read straight off EVORS() rather than filtered.
    $from = str_replace(array("\r", "\n", "\0"), '', $from);

    $headers = array(
        'From: ' . $from,
        'Content-Type: text/html; charset=UTF-8',
    );

    if (wpbono_rsvp_reminders_setting('dry_run') === 'yes') {
        wpbono_rsvp_reminders_log($event->get_title(), $to, $lead_days, 'dry run');
        return true;
    }

    if (wpbono_rsvp_reminders_setting('attach_invite') === 'yes' && function_exists('wpbono_fse_theme_event_ics')) {
        wpbono_rsvp_reminders_attach_invite($rsvp, $event, $from, $to);
    }

    $sent = wp_mail($to, $subject, $message, $headers);
    wpbono_rsvp_reminders_log($event->get_title(), $to, $lead_days, $sent ? 'sent' : 'failed');

    return $sent;
}

/**
 * Attach the same calendar invitation the confirmation carried.
 *
 * Same UID, higher SEQUENCE, so a client revises the existing entry rather than
 * adding a second one; if the event moved, this is what corrects their
 * calendar. Handed to PHPMailer as a string because the file is per-attendee
 * and must not be written under uploads/.
 */
function wpbono_rsvp_reminders_attach_invite($rsvp, $event, $from, $to) {
    $organizer = '';
    if (preg_match('/<([^>]+)>/', $from, $m)) {
        $organizer = trim($m[1]);
    } elseif (is_email(trim($from))) {
        $organizer = trim($from);
    }

    $ics = wpbono_fse_theme_event_ics($event, $rsvp, $organizer);
    if ($ics === '') {
        return;
    }

    $target = strtolower(trim($to));
    $attach = function ($phpmailer) use ($ics, $target, &$attach) {
        remove_action('phpmailer_init', $attach);

        $recipients = array();
        foreach ($phpmailer->getToAddresses() as $address) {
            $recipients[] = strtolower(trim($address[0]));
        }
        if (!in_array($target, $recipients, true)) {
            return;
        }

        $phpmailer->addStringAttachment(
            $ics,
            'invite.ics',
            'base64',
            'text/calendar; charset=UTF-8; method=REQUEST'
        );
    };
    add_action('phpmailer_init', $attach);
}

/**
 * Reminder body, wrapped in EventON's own email header/footer so it matches the
 * confirmation the attendee already has.
 */
function wpbono_rsvp_reminders_body($event, $rsvp, $intro) {
    $accent = '#d71920';
    $ff = 'font-family:Helvetica,Arial,sans-serif;';

    $location = '';
    if ($data = $event->get_location_data()) {
        $location = implode(' - ', array_filter(array(
            !empty($data['name']) ? $data['name'] : '',
            !empty($data['location_address']) ? $data['location_address'] : '',
        )));
    }

    $logo = '';
    if (function_exists('get_theme_file_path') && file_exists(get_theme_file_path('assets/img/email-logo.png'))) {
        $logo = get_theme_file_uri('assets/img/email-logo.png');
    }

    ob_start();

    if (function_exists('EVO')) {
        echo EVO()->get_email_part('header');
    }
    ?>
    <table width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%; max-width:600px; margin:0 auto; <?php echo esc_attr($ff); ?>">
        <?php if ($logo !== '') : ?>
        <tr>
            <td style="padding:30px 20px 10px; text-align:center; border:none;">
                <img src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>" width="210" style="width:210px; max-width:70%; height:auto; border:0; display:inline-block;" />
            </td>
        </tr>
        <?php endif; ?>
        <tr>
            <td style="padding:10px 20px 30px; border:none; text-align:left; overflow-wrap:anywhere; word-break:break-word;">
                <p style="font-size:16px; color:#6f6f6f; margin:0 0 16px; line-height:130%;"><?php echo wp_kses_post($intro); ?></p>

                <p style="font-size:26px; color:#303030; font-weight:bold; text-transform:uppercase; margin:0 0 6px; line-height:115%;">
                    <?php echo esc_html($event->get_title()); ?>
                </p>

                <p style="font-size:16px; color:#303030; margin:4px 0; line-height:130%;">
                    <span style="color:#8f8f8f;"><?php esc_html_e('Event Time', 'wpbono-rsvp-reminders'); ?>:</span>
                    <?php echo esc_html($event->get_formatted_smart_time($rsvp->repeat_interval())); ?>
                </p>

                <?php if ($location !== '') : ?>
                <p style="font-size:16px; color:#303030; margin:4px 0; line-height:130%;">
                    <span style="color:#8f8f8f;"><?php esc_html_e('Location', 'wpbono-rsvp-reminders'); ?>:</span>
                    <?php echo esc_html(html_entity_decode($location)); ?>
                    <a href="<?php echo esc_url('https://maps.google.com/?q=' . rawurlencode(wp_strip_all_tags($location))); ?>" style="color:<?php echo esc_attr($accent); ?>;" target="_blank"><?php esc_html_e('Map', 'wpbono-rsvp-reminders'); ?></a>
                </p>
                <?php endif; ?>

                <p style="margin:24px 0 8px;">
                    <a href="<?php echo esc_url(get_permalink($event->ID)); ?>" style="display:inline-block; font-size:14px; font-weight:bold; background-color:<?php echo esc_attr($accent); ?>; color:#ffffff; padding:12px 20px; text-decoration:none; border-radius:24px;" target="_blank">
                        <?php esc_html_e('View Event', 'wpbono-rsvp-reminders'); ?>
                    </a>
                </p>
            </td>
        </tr>
        <tr>
            <td style="padding:20px; border-top:1px solid #e2e2e2; color:#8f8f8f; <?php echo esc_attr($ff); ?> font-size:14px; text-align:center; background-color:#f7f7f7; border-radius:0 0 15px 15px;">
                <p style="margin:0; line-height:120%;">
                    <?php esc_html_e('You are getting this because you RSVP-ed yes and asked for updates about this event.', 'wpbono-rsvp-reminders'); ?>
                </p>
            </td>
        </tr>
    </table>
    <?php
    if (function_exists('EVO')) {
        echo EVO()->get_email_part('footer');
    }

    return ob_get_clean();
}

/**
 * Rolling activity log, capped so the option never grows without bound.
 */
function wpbono_rsvp_reminders_log($event_title, $to, $lead, $result) {
    $log = get_option('wpbono_rsvp_reminders_log', array());
    if (!is_array($log)) {
        $log = array();
    }

    $log[] = array(
        'time'   => time(),
        'event'  => (string) $event_title,
        'to'     => (string) $to,
        'lead'   => (int) $lead,
        'result' => (string) $result,
    );

    if (count($log) > 200) {
        $log = array_slice($log, -200);
    }

    update_option('wpbono_rsvp_reminders_log', $log, false);
}
