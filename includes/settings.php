<?php
/**
 * Site-wide settings: Settings -> RSVP Reminders.
 */

if (!defined('ABSPATH')) {
    exit;
}

function wpbono_rsvp_reminders_defaults() {
    return array(
        'enabled'              => 'yes',
        'lead_days'            => 2,
        'second_lead_days'     => 0, // 0 disables the second reminder
        'require_updates_optin' => 'yes',
        'default_updates_yes'  => 'yes',
        'attach_invite'        => 'yes',
        'subject'              => __('Reminder: {event-name} is on {event-date}', 'wpbono-rsvp-reminders'),
        'intro'                => __('A quick reminder that you are on the list for this one. See you there.', 'wpbono-rsvp-reminders'),
        'dry_run'              => 'no',
    );
}

function wpbono_rsvp_reminders_settings() {
    $saved = get_option(WPBONO_RSVP_REMINDERS_OPTION, array());
    return wp_parse_args(is_array($saved) ? $saved : array(), wpbono_rsvp_reminders_defaults());
}

function wpbono_rsvp_reminders_setting($key) {
    $settings = wpbono_rsvp_reminders_settings();
    return isset($settings[$key]) ? $settings[$key] : null;
}

function wpbono_rsvp_reminders_register_settings() {
    register_setting(
        'wpbono_rsvp_reminders',
        WPBONO_RSVP_REMINDERS_OPTION,
        array(
            'type'              => 'array',
            'sanitize_callback' => 'wpbono_rsvp_reminders_sanitize',
            'default'           => wpbono_rsvp_reminders_defaults(),
        )
    );
}
add_action('admin_init', 'wpbono_rsvp_reminders_register_settings');

function wpbono_rsvp_reminders_sanitize($input) {
    $defaults = wpbono_rsvp_reminders_defaults();
    $out = array();

    foreach (array('enabled', 'require_updates_optin', 'default_updates_yes', 'attach_invite', 'dry_run') as $flag) {
        $out[$flag] = (isset($input[$flag]) && $input[$flag] === 'yes') ? 'yes' : 'no';
    }

    // A lead time of 0 would mean "send at the moment the event starts", which
    // is never what anyone wants, so the primary one is clamped to at least 1.
    $out['lead_days'] = isset($input['lead_days']) ? max(1, min(60, (int) $input['lead_days'])) : $defaults['lead_days'];
    $out['second_lead_days'] = isset($input['second_lead_days']) ? max(0, min(60, (int) $input['second_lead_days'])) : 0;

    // The second reminder has to land after the first, or the two collapse.
    if ($out['second_lead_days'] >= $out['lead_days']) {
        $out['second_lead_days'] = 0;
    }

    $out['subject'] = isset($input['subject']) ? sanitize_text_field($input['subject']) : $defaults['subject'];
    $out['intro'] = isset($input['intro']) ? wp_kses_post($input['intro']) : $defaults['intro'];

    return $out;
}

function wpbono_rsvp_reminders_menu() {
    add_options_page(
        __('RSVP Reminders', 'wpbono-rsvp-reminders'),
        __('RSVP Reminders', 'wpbono-rsvp-reminders'),
        'manage_options',
        'wpbono-rsvp-reminders',
        'wpbono_rsvp_reminders_settings_page'
    );
}
add_action('admin_menu', 'wpbono_rsvp_reminders_menu');

function wpbono_rsvp_reminders_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $s = wpbono_rsvp_reminders_settings();
    $next = wp_next_scheduled(WPBONO_RSVP_REMINDERS_CRON);
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('RSVP Reminders', 'wpbono-rsvp-reminders'); ?></h1>

        <p>
            <?php
            if ($next) {
                printf(
                    /* translators: %s: human readable time until the next run. */
                    esc_html__('Next check in %s. Reminders are sent for events whose lead time has come due.', 'wpbono-rsvp-reminders'),
                    esc_html(human_time_diff(time(), $next))
                );
            } else {
                esc_html_e('The schedule is not armed. Reload this page to re-arm it.', 'wpbono-rsvp-reminders');
            }
            ?>
        </p>

        <form method="post" action="options.php">
            <?php settings_fields('wpbono_rsvp_reminders'); ?>
            <?php $name = WPBONO_RSVP_REMINDERS_OPTION; ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Send reminders', 'wpbono-rsvp-reminders'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr($name); ?>[enabled]" value="yes" <?php checked($s['enabled'], 'yes'); ?> />
                            <?php esc_html_e('Enabled', 'wpbono-rsvp-reminders'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wpbono-lead"><?php esc_html_e('Default lead time', 'wpbono-rsvp-reminders'); ?></label></th>
                    <td>
                        <input id="wpbono-lead" type="number" min="1" max="60" name="<?php echo esc_attr($name); ?>[lead_days]" value="<?php echo esc_attr($s['lead_days']); ?>" class="small-text" />
                        <?php esc_html_e('days before the event.', 'wpbono-rsvp-reminders'); ?>
                        <p class="description"><?php esc_html_e('Any event can override this on its own edit screen.', 'wpbono-rsvp-reminders'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wpbono-lead2"><?php esc_html_e('Second reminder', 'wpbono-rsvp-reminders'); ?></label></th>
                    <td>
                        <input id="wpbono-lead2" type="number" min="0" max="60" name="<?php echo esc_attr($name); ?>[second_lead_days]" value="<?php echo esc_attr($s['second_lead_days']); ?>" class="small-text" />
                        <?php esc_html_e('days before the event. 0 sends only one reminder.', 'wpbono-rsvp-reminders'); ?>
                        <p class="description"><?php esc_html_e('Must be smaller than the default lead time, otherwise it is ignored.', 'wpbono-rsvp-reminders'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Who gets one', 'wpbono-rsvp-reminders'); ?></th>
                    <td>
                        <p><?php esc_html_e('Always limited to attendees whose RSVP is Yes.', 'wpbono-rsvp-reminders'); ?></p>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr($name); ?>[require_updates_optin]" value="yes" <?php checked($s['require_updates_optin'], 'yes'); ?> />
                            <?php esc_html_e('Also require "Receive updates about event" to be Yes', 'wpbono-rsvp-reminders'); ?>
                        </label>
                        <br />
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr($name); ?>[default_updates_yes]" value="yes" <?php checked($s['default_updates_yes'], 'yes'); ?> />
                            <?php esc_html_e('Default "Receive updates about event" to Yes on the RSVP form', 'wpbono-rsvp-reminders'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('EventON ships that field defaulting to No, so almost nobody opts in. This flips the default for new RSVPs; existing ones keep whatever they chose.', 'wpbono-rsvp-reminders'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Calendar invite', 'wpbono-rsvp-reminders'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr($name); ?>[attach_invite]" value="yes" <?php checked($s['attach_invite'], 'yes'); ?> />
                            <?php esc_html_e('Attach the calendar invitation to the reminder', 'wpbono-rsvp-reminders'); ?>
                        </label>
                        <p class="description">
                            <?php
                            if (function_exists('wpbono_fse_theme_event_ics')) {
                                esc_html_e('Refreshes their calendar entry if the event has moved. Uses the invitation builder in the WPBono FSE theme.', 'wpbono-rsvp-reminders');
                            } else {
                                esc_html_e('Unavailable: this needs the WPBono FSE theme, which builds the invitation.', 'wpbono-rsvp-reminders');
                            }
                            ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wpbono-subject"><?php esc_html_e('Subject', 'wpbono-rsvp-reminders'); ?></label></th>
                    <td>
                        <input id="wpbono-subject" type="text" class="large-text" name="<?php echo esc_attr($name); ?>[subject]" value="<?php echo esc_attr($s['subject']); ?>" />
                        <p class="description"><?php esc_html_e('Placeholders: {event-name}, {event-date}, {first-name}', 'wpbono-rsvp-reminders'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wpbono-intro"><?php esc_html_e('Message', 'wpbono-rsvp-reminders'); ?></label></th>
                    <td>
                        <textarea id="wpbono-intro" class="large-text" rows="4" name="<?php echo esc_attr($name); ?>[intro]"><?php echo esc_textarea($s['intro']); ?></textarea>
                        <p class="description"><?php esc_html_e('Shown above the event details. Same placeholders as the subject.', 'wpbono-rsvp-reminders'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Dry run', 'wpbono-rsvp-reminders'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr($name); ?>[dry_run]" value="yes" <?php checked($s['dry_run'], 'yes'); ?> />
                            <?php esc_html_e('Log what would be sent, send nothing', 'wpbono-rsvp-reminders'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Attendees are still marked as reminded, so a dry run tells you the targeting is right without mailing anyone. Clear the log below before a real run.', 'wpbono-rsvp-reminders'); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <h2><?php esc_html_e('Recent activity', 'wpbono-rsvp-reminders'); ?></h2>
        <?php
        $log = get_option('wpbono_rsvp_reminders_log', array());
        if (empty($log)) {
            echo '<p>' . esc_html__('Nothing sent yet.', 'wpbono-rsvp-reminders') . '</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr>';
            echo '<th>' . esc_html__('When', 'wpbono-rsvp-reminders') . '</th>';
            echo '<th>' . esc_html__('Event', 'wpbono-rsvp-reminders') . '</th>';
            echo '<th>' . esc_html__('Recipient', 'wpbono-rsvp-reminders') . '</th>';
            echo '<th>' . esc_html__('Lead', 'wpbono-rsvp-reminders') . '</th>';
            echo '<th>' . esc_html__('Result', 'wpbono-rsvp-reminders') . '</th>';
            echo '</tr></thead><tbody>';
            foreach (array_reverse($log) as $row) {
                echo '<tr>';
                echo '<td>' . esc_html(wp_date('Y-m-d H:i', (int) $row['time'])) . '</td>';
                echo '<td>' . esc_html($row['event']) . '</td>';
                echo '<td>' . esc_html($row['to']) . '</td>';
                echo '<td>' . esc_html($row['lead']) . 'd</td>';
                echo '<td>' . esc_html($row['result']) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        ?>
        <form method="post">
            <?php wp_nonce_field('wpbono_rsvp_reminders_clear_log'); ?>
            <input type="hidden" name="wpbono_rsvp_reminders_action" value="clear_log" />
            <?php submit_button(__('Clear log', 'wpbono-rsvp-reminders'), 'secondary', 'submit', false); ?>
        </form>
    </div>
    <?php
}

function wpbono_rsvp_reminders_handle_actions() {
    if (!isset($_POST['wpbono_rsvp_reminders_action']) || !current_user_can('manage_options')) {
        return;
    }
    if ($_POST['wpbono_rsvp_reminders_action'] === 'clear_log') {
        check_admin_referer('wpbono_rsvp_reminders_clear_log');
        delete_option('wpbono_rsvp_reminders_log');
    }
}
add_action('admin_init', 'wpbono_rsvp_reminders_handle_actions');

/**
 * Flip EventON's "Receive updates about event" default to Yes on the RSVP form.
 *
 * EventON builds the field with value 'no' for a new RSVP, so opting in takes a
 * deliberate click and hardly anyone makes it, which leaves the reminder
 * audience near empty. Only the default for a *new* RSVP changes here: an
 * existing RSVP being edited still shows whatever that person chose.
 */
function wpbono_rsvp_reminders_default_updates_yes($fields, $rsvp_event, $existing_rsvp) {
    if (wpbono_rsvp_reminders_setting('default_updates_yes') !== 'yes') {
        return $fields;
    }
    if (!empty($existing_rsvp)) {
        return $fields;
    }
    if (isset($fields['updates'])) {
        $fields['updates']['value'] = 'yes';
    }
    return $fields;
}
add_filter('evors_form_fields_array', 'wpbono_rsvp_reminders_default_updates_yes', 10, 3);
