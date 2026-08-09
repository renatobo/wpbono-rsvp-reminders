<?php
/**
 * Plugin Name: WPBono RSVP Reminders
 * Plugin URI: https://drocdesmo.com
 * Description: Scheduled reminder emails for EventON RSVP attendees, with a site-wide default lead time and a per-event override.
 * Version: 1.0.1
 * Author: Renato Bonomini
 * Author URI: https://github.com/renatobo
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: wpbono-rsvp-reminders
 * Requires at least: 7.0
 * Requires PHP: 8.2
 * Requires Plugins: eventon-rsvp
 * GitHub Plugin URI: https://github.com/renatobo/wpbono-rsvp-reminders
 * Primary Branch:    main
 * Release Asset:     true
 *
 * The directory name wpbono-rsvp-reminders is the upgrade key WordPress uses to
 * recognise an installed copy. Never rename it: a rename installs a second
 * plugin alongside the first and orphans the original's settings.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WPBONO_RSVP_REMINDERS_VERSION', '1.0.1');
define('WPBONO_RSVP_REMINDERS_DIR', plugin_dir_path(__FILE__));
define('WPBONO_RSVP_REMINDERS_URL', plugin_dir_url(__FILE__));
define('WPBONO_RSVP_REMINDERS_PAGE', 'wpbono-rsvp-reminders');
define('WPBONO_RSVP_REMINDERS_REPO', 'https://github.com/renatobo/wpbono-rsvp-reminders');
define('WPBONO_RSVP_REMINDERS_CRON', 'wpbono_rsvp_reminders_tick');
define('WPBONO_RSVP_REMINDERS_OPTION', 'wpbono_rsvp_reminders_settings');

require_once WPBONO_RSVP_REMINDERS_DIR . 'includes/settings.php';
require_once WPBONO_RSVP_REMINDERS_DIR . 'includes/event-meta.php';
require_once WPBONO_RSVP_REMINDERS_DIR . 'includes/scheduler.php';
require_once WPBONO_RSVP_REMINDERS_DIR . 'includes/mailer.php';

function wpbono_rsvp_reminders_load_textdomain() {
    load_plugin_textdomain(
        'wpbono-rsvp-reminders',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
}
add_action('init', 'wpbono_rsvp_reminders_load_textdomain');

/**
 * Hourly rather than daily on purpose.
 *
 * WP-Cron only runs when the site gets traffic, so a daily schedule can drift
 * by hours or miss a day on a quiet site. The scheduler treats each tick as
 * "send anything now due that hasn't gone yet", so a late or extra tick is
 * harmless and a missed one is caught by the next.
 */
function wpbono_rsvp_reminders_activate() {
    if (!wp_next_scheduled(WPBONO_RSVP_REMINDERS_CRON)) {
        wp_schedule_event(time() + 60, 'hourly', WPBONO_RSVP_REMINDERS_CRON);
    }
}
register_activation_hook(__FILE__, 'wpbono_rsvp_reminders_activate');

function wpbono_rsvp_reminders_deactivate() {
    $timestamp = wp_next_scheduled(WPBONO_RSVP_REMINDERS_CRON);
    if ($timestamp) {
        wp_unschedule_event($timestamp, WPBONO_RSVP_REMINDERS_CRON);
    }
    wp_clear_scheduled_hook(WPBONO_RSVP_REMINDERS_CRON);

    // A sweep interrupted by the deactivation would otherwise hold its lock for
    // the full expiry, blocking the first tick after it is switched back on.
    delete_transient('wpbono_rsvp_reminders_running');
}
register_deactivation_hook(__FILE__, 'wpbono_rsvp_reminders_deactivate');

/**
 * Self-heal the schedule.
 *
 * A cron entry can go missing without the plugin being deactivated: a database
 * restore, a migration, or another plugin calling wp_clear_scheduled_hook too
 * broadly. Re-arming on admin_init means reminders resume without anyone
 * noticing they had stopped.
 */
function wpbono_rsvp_reminders_ensure_schedule() {
    if (!wp_next_scheduled(WPBONO_RSVP_REMINDERS_CRON)) {
        wp_schedule_event(time() + 60, 'hourly', WPBONO_RSVP_REMINDERS_CRON);
    }
}
add_action('admin_init', 'wpbono_rsvp_reminders_ensure_schedule');

/**
 * Settings link on the Plugins screen, matching the other WPBono plugins.
 */
function wpbono_rsvp_reminders_action_links($links) {
    array_unshift(
        $links,
        sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('options-general.php?page=' . WPBONO_RSVP_REMINDERS_PAGE)),
            esc_html__('Settings', 'wpbono-rsvp-reminders')
        )
    );

    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wpbono_rsvp_reminders_action_links');
add_filter('network_admin_plugin_action_links_' . plugin_basename(__FILE__), 'wpbono_rsvp_reminders_action_links');

/**
 * EventON RSVP is a hard dependency: every code path reads its CPT and classes.
 */
function wpbono_rsvp_reminders_has_eventon(): bool {
    return class_exists('EVO_RSVP_CPT') && class_exists('EVORS_Event') && function_exists('EVORS');
}

/**
 * The theme is a soft dependency: it builds the calendar invitation and
 * supplies the default email logo, but reminders still send without it.
 */
function wpbono_rsvp_reminders_has_theme(): bool {
    return function_exists('wpbono_fse_theme_event_ics');
}

/**
 * Dependency notices.
 *
 * "Requires Plugins" in the header covers the EventON RSVP add-on, but only for
 * activation, and only for that add-on: the EventON core directory is named
 * eventON, and core drops any slug that is not lowercase and hyphens, so it can
 * never be declared there. This notice is what catches core being missing, or
 * either being deactivated after this plugin was already running.
 *
 * There is no header for a theme dependency at all, so the theme is reported
 * here too, as a warning rather than an error because it is not fatal.
 */
function wpbono_rsvp_reminders_dependency_notice() {
    if (!current_user_can('activate_plugins')) {
        return;
    }

    if (!wpbono_rsvp_reminders_has_eventon()) {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('WPBono RSVP Reminders needs EventON and its RSVP add-on to be active. No reminders will be sent until they are.', 'wpbono-rsvp-reminders');
        echo '</p></div>';
        return;
    }

    if (!wpbono_rsvp_reminders_has_theme()) {
        echo '<div class="notice notice-warning"><p>';
        echo esc_html__('WPBono RSVP Reminders is sending without the WPBono FSE theme, which builds the calendar invitation and supplies the email logo. Reminders still go out, just with no invite attached.', 'wpbono-rsvp-reminders');
        echo '</p></div>';
    }
}
add_action('admin_notices', 'wpbono_rsvp_reminders_dependency_notice');
