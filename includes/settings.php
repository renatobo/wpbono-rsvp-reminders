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
        'logo_id'              => 0, // 0 falls back to the theme's bundled logo
        'subject'              => __('Reminder: {event-name} is on {event-date}', 'wpbono-rsvp-reminders'),
        'intro'                => __('A quick reminder that you are on the list for this one. See you there.', 'wpbono-rsvp-reminders'),
        'dry_run'              => 'no',
    );
}

/**
 * Settings, memoised for the request.
 *
 * Every wpbono_rsvp_reminders_setting() call used to rebuild this: an option
 * read, a wp_parse_args, and the two __() calls inside defaults(). One reminder
 * does that four times, and evors_form_fields_array runs it on the public (so
 * uncached) AJAX request that renders an RSVP form.
 */
function &wpbono_rsvp_reminders_settings_cache() {
    static $settings = null;
    return $settings;
}

function wpbono_rsvp_reminders_settings() {
    $cache = &wpbono_rsvp_reminders_settings_cache();
    if ($cache === null) {
        $saved = get_option(WPBONO_RSVP_REMINDERS_OPTION, array());
        $cache = wp_parse_args(is_array($saved) ? $saved : array(), wpbono_rsvp_reminders_defaults());
    }
    return $cache;
}

/**
 * A save happens in the same request that may go on to read the settings back,
 * so the memo has to be dropped when the option moves under it.
 */
function wpbono_rsvp_reminders_flush_settings_cache() {
    $cache = &wpbono_rsvp_reminders_settings_cache();
    $cache = null;
}
add_action('update_option_' . WPBONO_RSVP_REMINDERS_OPTION, 'wpbono_rsvp_reminders_flush_settings_cache');
add_action('add_option_' . WPBONO_RSVP_REMINDERS_OPTION, 'wpbono_rsvp_reminders_flush_settings_cache');
add_action('delete_option_' . WPBONO_RSVP_REMINDERS_OPTION, 'wpbono_rsvp_reminders_flush_settings_cache');

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

    $out['logo_id'] = wpbono_rsvp_reminders_sanitize_logo_id(isset($input['logo_id']) ? $input['logo_id'] : 0);

    $out['subject'] = isset($input['subject']) ? sanitize_text_field($input['subject']) : $defaults['subject'];
    $out['intro'] = isset($input['intro']) ? wp_kses_post($input['intro']) : $defaults['intro'];

    return $out;
}

/**
 * Validate the chosen logo attachment.
 *
 * The media modal filters the library, but that is UI only: what actually posts
 * is an attachment ID, so every rule is enforced again here. Each of these comes
 * from a real failure rather than a hypothetical.
 *
 * - SVG is rejected outright. No mail client renders it, and this site's Site
 *   Logo *is* an SVG, which makes it the most likely first pick. Saying so beats
 *   ignoring the choice silently.
 * - WebP is accepted with a warning. Outlook desktop will not render it, which
 *   is why the theme's bundled file is a PNG even though it ships WebP
 *   elsewhere. Whether that matters depends on the audience, so it is the
 *   administrator's call, not a refusal.
 * - The URL has to be absolute http(s). It is resolved from a mail client on
 *   somebody's phone, where a site-relative path or a local file is useless.
 *
 * Note this runs from sanitize_option, so it fires on *any* update of the
 * option, cron and frontend included, where wp-admin/includes/template.php is
 * not loaded. Hence the function_exists guard on add_settings_error.
 */
function wpbono_rsvp_reminders_sanitize_logo_id($raw) {
    $logo_id = absint($raw);
    if ($logo_id <= 0) {
        return 0;
    }

    $notice = function ($code, $message, $type) {
        if (function_exists('add_settings_error')) {
            add_settings_error(WPBONO_RSVP_REMINDERS_OPTION, $code, $message, $type);
        }
    };

    $mime = get_post_mime_type($logo_id);

    if ($mime === 'image/svg+xml') {
        $notice(
            'wpbono_logo_svg',
            __('The email logo cannot be an SVG: no mail client renders it. Use a PNG or JPEG. That image was not saved.', 'wpbono-rsvp-reminders'),
            'error'
        );
        return 0;
    }

    if (!in_array($mime, array('image/jpeg', 'image/png', 'image/webp'), true)) {
        $notice(
            'wpbono_logo_type',
            __('The email logo must be a PNG or JPEG. That image was not saved.', 'wpbono-rsvp-reminders'),
            'error'
        );
        return 0;
    }

    $url = wp_get_attachment_image_url($logo_id, 'medium');
    if (!is_string($url) || !preg_match('#^https?://#i', $url)) {
        $notice(
            'wpbono_logo_url',
            __('That image has no absolute web address, so it cannot load in an email. It was not saved.', 'wpbono-rsvp-reminders'),
            'error'
        );
        return 0;
    }

    if ($mime === 'image/webp') {
        $notice(
            'wpbono_logo_webp',
            __('Saved, but Outlook on Windows will not render a WebP logo. A PNG is safer.', 'wpbono-rsvp-reminders'),
            'warning'
        );
    }

    return $logo_id;
}

function wpbono_rsvp_reminders_menu() {
    $hook = add_options_page(
        __('RSVP Reminders', 'wpbono-rsvp-reminders'),
        __('RSVP Reminders', 'wpbono-rsvp-reminders'),
        'manage_options',
        WPBONO_RSVP_REMINDERS_PAGE,
        'wpbono_rsvp_reminders_settings_page'
    );
    add_action('admin_print_footer_scripts-' . $hook, 'wpbono_rsvp_reminders_media_picker_script');
    add_action('load-' . $hook, 'wpbono_rsvp_reminders_settings_assets');
}
add_action('admin_menu', 'wpbono_rsvp_reminders_menu');

/**
 * The media modal is heavy, so it loads on this one screen rather than site-wide.
 */
function wpbono_rsvp_reminders_settings_assets() {
    wp_enqueue_media();
}

function wpbono_rsvp_reminders_admin_styles($hook_suffix) {
    if ('settings_page_' . WPBONO_RSVP_REMINDERS_PAGE !== $hook_suffix) {
        return;
    }
    wp_enqueue_style(
        'wpbono-rsvp-reminders-admin',
        WPBONO_RSVP_REMINDERS_URL . 'assets/admin.css',
        array(),
        WPBONO_RSVP_REMINDERS_VERSION
    );
}
add_action('admin_enqueue_scripts', 'wpbono_rsvp_reminders_admin_styles');

function wpbono_rsvp_reminders_media_picker_script() {
    ?>
    <script>
    (function () {
        var wrap = document.getElementById('wpbono-logo-field');
        if (!wrap || !window.wp || !wp.media) { return; }

        var input = wrap.querySelector('input[type=hidden]');
        var preview = wrap.querySelector('.wpbono-logo-preview');
        var choose = wrap.querySelector('.wpbono-logo-choose');
        var clear = wrap.querySelector('.wpbono-logo-clear');
        var frame;

        choose.addEventListener('click', function (e) {
            e.preventDefault();
            if (!frame) {
                frame = wp.media({
                    title: choose.dataset.title,
                    button: { text: choose.dataset.button },
                    // Narrower than the server accepts, on purpose: sanitize
                    // takes WebP with a warning, but there is no reason to
                    // offer a format Outlook cannot render as if it were fine.
                    library: { type: ['image/jpeg', 'image/png'] },
                    multiple: false
                });
                frame.on('select', function () {
                    var img = frame.state().get('selection').first().toJSON();
                    input.value = img.id;
                    preview.src = (img.sizes && img.sizes.medium) ? img.sizes.medium.url : img.url;
                    preview.hidden = false;
                    clear.hidden = false;
                });
            }
            frame.open();
        });

        clear.addEventListener('click', function (e) {
            e.preventDefault();
            input.value = '0';
            preview.hidden = true;
            clear.hidden = true;
        });
    }());
    </script>
    <?php
}

function wpbono_rsvp_reminders_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $s = wpbono_rsvp_reminders_settings();
    $next = wp_next_scheduled(WPBONO_RSVP_REMINDERS_CRON);

    $version = WPBONO_RSVP_REMINDERS_VERSION;
    $banner_url = WPBONO_RSVP_REMINDERS_URL . 'assets/wpbono-rsvp-reminders-settings-banner.svg';
    $release_notes_url = WPBONO_RSVP_REMINDERS_REPO . '/releases/tag/v' . rawurlencode($version);
    ?>
    <div class="wrap">
        <div class="wpbono-rsvp-reminders-admin">
            <div class="wpbono-rsvp-reminders-hero">
                <img
                    src="<?php echo esc_url($banner_url); ?>"
                    alt="<?php echo esc_attr__('WPBono RSVP Reminders settings banner', 'wpbono-rsvp-reminders'); ?>"
                    class="wpbono-rsvp-reminders-hero-image"
                />
            </div>

            <div class="wpbono-rsvp-reminders-meta">
                <a href="<?php echo esc_url(WPBONO_RSVP_REMINDERS_REPO); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e('GitHub Repository', 'wpbono-rsvp-reminders'); ?>
                </a>
                <span>
                    <?php
                    /* translators: %s: Plugin version. */
                    echo esc_html(sprintf(__('Version %s', 'wpbono-rsvp-reminders'), $version));
                    ?>
                </span>
                <a href="<?php echo esc_url($release_notes_url); ?>" target="_blank" rel="noopener noreferrer"
                   aria-label="<?php echo esc_attr(sprintf(
                       /* translators: %s: Plugin version. */
                       __('Release notes for version %s', 'wpbono-rsvp-reminders'),
                       $version
                   )); ?>">
                    <?php esc_html_e('Release notes', 'wpbono-rsvp-reminders'); ?>
                </a>
                <a href="https://github.com/renatobo" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e('Renato Bonomini on GitHub', 'wpbono-rsvp-reminders'); ?>
                </a>
                <a href="https://github.com/afragen/git-updater" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e('GitHub updates via Git Updater', 'wpbono-rsvp-reminders'); ?>
                </a>
            </div>

            <div class="wpbono-rsvp-reminders-headline">
                <h1><?php esc_html_e('RSVP Reminders', 'wpbono-rsvp-reminders'); ?></h1>
                <p class="wpbono-rsvp-reminders-intro">
                    <?php esc_html_e('Scheduled reminder emails for EventON RSVP attendees, filling the gap left by the unlicensed EventON Reminders add-on. Attendees who answered Yes get a reminder a set number of days before the event, with a calendar invite that revises their existing entry rather than adding a second one.', 'wpbono-rsvp-reminders'); ?>
                </p>
                <p class="wpbono-rsvp-reminders-intro wpbono-rsvp-reminders-intro-secondary">
                    <?php
                    if ($next) {
                        printf(
                            /* translators: %s: human readable time until the next run. */
                            esc_html__('Next check in %s. Reminders go out for any event whose lead time has come due, so a late or missed check catches up on the following one.', 'wpbono-rsvp-reminders'),
                            esc_html(human_time_diff(time(), $next))
                        );
                    } else {
                        esc_html_e('The schedule is not armed. Reload this page to re-arm it.', 'wpbono-rsvp-reminders');
                    }
                    ?>
                </p>
            </div>

            <?php
            /*
             * No settings_errors() call. add_options_page() puts this screen under
             * options-general.php, so core's admin-header.php loads options-head.php,
             * which already prints them. Calling it again duplicates every notice.
             */
            ?>

            <?php if (!wpbono_rsvp_reminders_has_eventon()) : ?>
                <div class="notice notice-error inline">
                    <p><?php esc_html_e('The EventON RSVP add-on is not active. Nothing will be sent until it is.', 'wpbono-rsvp-reminders'); ?></p>
                </div>
            <?php endif; ?>

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
                    <th scope="row"><?php esc_html_e('Email logo', 'wpbono-rsvp-reminders'); ?></th>
                    <td>
                        <?php
                        $logo_id = (int) $s['logo_id'];
                        $logo_src = $logo_id ? wp_get_attachment_image_url($logo_id, 'medium') : '';
                        ?>
                        <div id="wpbono-logo-field">
                            <input type="hidden" name="<?php echo esc_attr($name); ?>[logo_id]" value="<?php echo esc_attr($logo_id); ?>" />
                            <p>
                                <img class="wpbono-logo-preview wpbono-rsvp-reminders-logo-preview"
                                     src="<?php echo esc_url($logo_src); ?>" alt=""
                                     <?php echo $logo_src ? '' : 'hidden'; ?> />
                                <button type="button" class="button wpbono-logo-choose"
                                        data-title="<?php esc_attr_e('Select email logo', 'wpbono-rsvp-reminders'); ?>"
                                        data-button="<?php esc_attr_e('Use this image', 'wpbono-rsvp-reminders'); ?>">
                                    <?php esc_html_e('Select image', 'wpbono-rsvp-reminders'); ?>
                                </button>
                                <button type="button" class="button-link wpbono-logo-clear" style="margin-left:8px;"
                                        <?php echo $logo_src ? '' : 'hidden'; ?>>
                                    <?php esc_html_e('Use the default', 'wpbono-rsvp-reminders'); ?>
                                </button>
                            </p>
                        </div>
                        <p class="description">
                            <?php
                            if (function_exists('wpbono_fse_theme_email_logo_url')) {
                                esc_html_e('Used by all three RSVP emails: the confirmation, the update notice and the reminder. Leave it unset to use the logo the WPBono FSE theme provides.', 'wpbono-rsvp-reminders');
                            } else {
                                esc_html_e('Shown at the top of the reminder. The WPBono FSE theme is not active, so without one the reminder has no logo.', 'wpbono-rsvp-reminders');
                            }
                            ?>
                            <br />
                            <?php esc_html_e('PNG or JPEG, around 420px wide. SVG is refused because no mail client renders it, and WebP is accepted but will not show in Outlook on Windows.', 'wpbono-rsvp-reminders'); ?>
                            <br />
                            <?php esc_html_e('The email card is white, so a white or transparent-on-light logo will be invisible. Pick a dark version.', 'wpbono-rsvp-reminders'); ?>
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

        <section class="wpbono-rsvp-reminders-card" aria-labelledby="wpbono-rsvp-reminders-activity">
        <h2 id="wpbono-rsvp-reminders-activity"><?php esc_html_e('Recent activity', 'wpbono-rsvp-reminders'); ?></h2>
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
            <?php
            // Named fields, not the defaults: settings_fields() above already
            // emitted id="_wpnonce" and submit_button() id="submit", and a
            // duplicate id is invalid markup that confuses assistive tech.
            wp_nonce_field('wpbono_rsvp_reminders_clear_log', 'wpbono_clear_log_nonce');
            ?>
            <input type="hidden" name="wpbono_rsvp_reminders_action" value="clear_log" />
            <?php submit_button(__('Clear log', 'wpbono-rsvp-reminders'), 'secondary', 'wpbono_clear_log_submit', false); ?>
        </form>
        </section>
        </div><!-- .wpbono-rsvp-reminders-admin -->
    </div>
    <?php
}

function wpbono_rsvp_reminders_handle_actions() {
    if (!isset($_POST['wpbono_rsvp_reminders_action']) || !current_user_can('manage_options')) {
        return;
    }
    if ($_POST['wpbono_rsvp_reminders_action'] === 'clear_log') {
        check_admin_referer('wpbono_rsvp_reminders_clear_log', 'wpbono_clear_log_nonce');
        delete_option('wpbono_rsvp_reminders_log');
    }
}
add_action('admin_init', 'wpbono_rsvp_reminders_handle_actions');

/**
 * Point the theme's RSVP emails at the administrator's chosen logo.
 *
 * The theme owns the default and the accessor; this only supplies a
 * replacement. Hooking it here rather than resolving the logo inside the mailer
 * is what makes one setting drive all three RSVP emails — confirmation, update
 * notice, reminder — instead of the reminder alone.
 *
 * An unusable value returns the theme's default untouched, and the theme
 * re-checks that independently, so a bad setting degrades to the bundled logo
 * rather than to a broken image.
 */
function wpbono_rsvp_reminders_filter_email_logo($url) {
    $custom = wpbono_rsvp_reminders_setting_logo_url();
    return $custom !== '' ? $custom : $url;
}
add_filter('wpbono_fse_theme_email_logo', 'wpbono_rsvp_reminders_filter_email_logo');

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
