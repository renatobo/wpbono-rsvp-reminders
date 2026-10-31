<?php
/** Scheduler regression harness. WordPress storage/hooks are in-memory doubles. */
error_reporting(E_ALL);
set_error_handler(static function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
define('ABSPATH', __DIR__);
define('DAY_IN_SECONDS', 86400);
define('WPBONO_RSVP_REMINDERS_CRON', 'tick');
$GLOBALS['hooks'] = array();
function add_action($name, $callback, $priority = 10, $args = 1) { $GLOBALS['hooks'][$name][] = $callback; }
function add_filter($name, $callback, $priority = 10, $args = 1) { add_action($name, $callback); }
function get_post_meta($id, $key, $single = true) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function get_post_type($id) { return $GLOBALS['types'][$id] ?? ''; }
function update_meta_cache($type, $ids) {}
function maybe_unserialize($value) { return $value; }
function get_the_title($id) { return "Event $id"; }
function wpbono_rsvp_reminders_setting($name) { return $GLOBALS['settings'][$name] ?? ''; }
function wpbono_rsvp_reminders_event_disabled($id) { return false; }
function wpbono_rsvp_reminders_lead_days($id) { return 7; }
function wpbono_rsvp_reminders_log(...$args) {}
function wpbono_rsvp_reminders_send($rsvp, $event, $lead) {
    // Mail transport is isolated; dry runs record previews instead of emails.
    $GLOBALS[wpbono_rsvp_reminders_setting('dry_run') === 'yes' ? 'previews' : 'emails'][] = array($rsvp, $event, $lead);
}
function mutate_meta($operation, $id, $key, $value = '') {
    $filter = $operation . '_post_metadata';
    foreach ($GLOBALS['hooks'][$filter] ?? array() as $callback) {
        $callback(null, $id, $key, $value, false);
    }
    if ($operation === 'delete') { unset($GLOBALS['meta'][$id][$key]); }
    else { $GLOBALS['meta'][$id][$key] = $value; }
    $action = array('update' => 'updated', 'add' => 'added', 'delete' => 'deleted')[$operation] . '_post_meta';
    foreach ($GLOBALS['hooks'][$action] ?? array() as $callback) { $callback(1, $id, $key, $value); }
}
function update_post_meta($id, $key, $value) { mutate_meta('update', $id, $key, $value); }
function delete_post_meta($id, $key) { mutate_meta('delete', $id, $key); }
function matches_meta($id, $clause) {
    if (isset($clause['key'])) {
        if (($clause['compare'] ?? '') === 'NOT EXISTS') { return !isset($GLOBALS['meta'][$id][$clause['key']]); }
        return (string) get_post_meta($id, $clause['key']) === (string) $clause['value'];
    }
    $results = array();
    foreach ($clause as $key => $part) { if ($key !== 'relation') { $results[] = matches_meta($id, $part); } }
    return ($clause['relation'] ?? 'AND') === 'OR' ? in_array(true, $results, true) : !in_array(false, $results, true);
}
function get_posts($args) {
    $ids = array();
    foreach ($GLOBALS['types'] as $id => $type) {
        if ($type === $args['post_type'] && matches_meta($id, $args['meta_query'])) { $ids[] = $id; }
    }
    sort($ids);
    return array_slice($ids, (($args['paged'] ?? 1) - 1) * $args['posts_per_page'], $args['posts_per_page']);
}
require dirname(__DIR__) . '/includes/scheduler.php';
function same($expected, $actual) {
    if ($expected !== $actual) { throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); }
}
function fixture($repeating = false) {
    $GLOBALS['meta'] = array(1 => array('evcal_srow' => 1100000));
    $GLOBALS['types'] = array(1 => 'ajde_events');
    $GLOBALS['settings'] = array('dry_run' => 'no', 'second_lead_days' => 1, 'require_updates_optin' => 'yes');
    $GLOBALS['emails'] = $GLOBALS['previews'] = $GLOBALS['wpbono_rsvp_reminders_previous_schedule'] = array();
    if ($repeating) {
        $GLOBALS['meta'][1]['evcal_repeat'] = 'yes';
        $GLOBALS['meta'][1]['repeat_intervals'] = array(0 => array(1100000, 1103600), 1 => array(1200000, 1203600));
    }
    attendee(10);
}
function attendee($id, $ri = null, $sent = array()) {
    $GLOBALS['types'][$id] = 'evo-rsvp';
    $GLOBALS['meta'][$id] = array('e_id' => 1, 'rsvp' => 'y', 'updates' => 'yes', WPBONO_RSVP_REMINDERS_META_SENT => $sent);
    if ($ri !== null) { $GLOBALS['meta'][$id]['repeat_interval'] = $ri; }
}
$tests = array(
    'dry run then real run sends exactly once' => function () {
        fixture(); $GLOBALS['settings']['dry_run'] = 'yes';
        wpbono_rsvp_reminders_process_event(1, 1050000, 2000000);
        same(array(), wpbono_rsvp_reminders_sent_map(10)); same(1, count($GLOBALS['previews'])); same(array(), $GLOBALS['emails']);
        $GLOBALS['settings']['dry_run'] = 'no';
        wpbono_rsvp_reminders_process_event(1, 1050000, 2000000);
        wpbono_rsvp_reminders_process_event(1, 1050000, 2000000);
        same(array(array(10, 1, 1)), $GLOBALS['emails']);
    },
    'late first tick sends shortest due lead' => function () {
        fixture(); wpbono_rsvp_reminders_process_event(1, 1050000, 2000000);
        same(array(1), wpbono_rsvp_reminders_sent_leads(10));
    },
    'shorter reminder suppresses obsolete longer reminder' => function () {
        fixture(); attendee(10, null, array(0 => array(1)));
        wpbono_rsvp_reminders_process_event(1, 1050000, 2000000); same(array(), $GLOBALS['emails']);
    },
    'legacy flat markers are retained' => function () {
        fixture(); attendee(10, null, array(7));
        same(array(7), wpbono_rsvp_reminders_sent_leads(10));
        wpbono_rsvp_reminders_process_event(1, 1000000, 2000000); same(array(), $GLOBALS['emails']);
    },
    'budget resumes remaining attendees on next tick' => function () {
        fixture(); attendee(11); $budget = 1;
        wpbono_rsvp_reminders_process_event(1, 1050000, 2000000, $budget); same(0, $budget);
        $budget = 1; wpbono_rsvp_reminders_process_event(1, 1050000, 2000000, $budget);
        same(array(10, 11), array_column($GLOBALS['emails'], 0));
    },
    'No Maybe and opted out attendees excluded' => function () {
        fixture(); $GLOBALS['meta'][10]['rsvp'] = 'n'; attendee(11); $GLOBALS['meta'][11]['rsvp'] = 'm'; attendee(12); $GLOBALS['meta'][12]['updates'] = 'no';
        wpbono_rsvp_reminders_process_event(1, 1050000, 2000000); same(array(), $GLOBALS['emails']);
    },
    'repeat zero matches missing empty and zero indices only' => function () {
        fixture(true); attendee(11, ''); attendee(12, 0); attendee(13, 1);
        same(array(10, 11, 12), wpbono_rsvp_reminders_eligible_rsvps(1, 0));
    },
    'append occurrence preserves all existing markers on first edit after upgrade' => function () {
        fixture(true); attendee(10, 0, array(0 => array(7), 1 => array(1)));
        $repeats = get_post_meta(1, 'repeat_intervals'); $repeats[2] = array(1300000, 1303600);
        update_post_meta(1, 'repeat_intervals', $repeats);
        same(array(0 => array(7), 1 => array(1)), wpbono_rsvp_reminders_sent_map(10));
    },
    'move one occurrence resets only that index' => function () {
        fixture(true); attendee(10, 1, array(0 => array(7), 1 => array(7)));
        $repeats = get_post_meta(1, 'repeat_intervals'); $repeats[1][0] += 3600;
        update_post_meta(1, 'repeat_intervals', $repeats);
        same(array(0 => array(7)), wpbono_rsvp_reminders_sent_map(10));
    },
    'end time edits preserve markers' => function () {
        fixture(true); attendee(10, 0, array(0 => array(7)));
        $repeats = get_post_meta(1, 'repeat_intervals'); $repeats[0][1] += 3600;
        update_post_meta(1, 'repeat_intervals', $repeats); same(array(7), wpbono_rsvp_reminders_sent_leads(10));
    },
    'one-off reschedule resets legacy markers' => function () {
        fixture(); attendee(10, null, array(7)); update_post_meta(1, 'evcal_srow', 1150000);
        same(array(), wpbono_rsvp_reminders_sent_map(10));
    },
    'repeating base timestamp change preserves unchanged repeat map' => function () {
        fixture(true); attendee(10, 0, array(0 => array(7))); update_post_meta(1, 'evcal_srow', 1150000);
        same(array(7), wpbono_rsvp_reminders_sent_leads(10));
    },
    'removed occurrence clears only its markers' => function () {
        fixture(true); attendee(10, 0, array(0 => array(7), 1 => array(7), 2 => array(7)));
        $repeats = get_post_meta(1, 'repeat_intervals'); $repeats[2] = array(1300000, 1303600);
        update_post_meta(1, 'repeat_intervals', $repeats); unset($repeats[1]);
        update_post_meta(1, 'repeat_intervals', $repeats); same(array(0 => array(7), 2 => array(7)), wpbono_rsvp_reminders_sent_map(10));
    },
    'reset reaches attendees beyond sweep cap including opted out' => function () {
        fixture(); for ($i = 10; $i < 515; $i++) { attendee($i, null, array(7)); $GLOBALS['meta'][$i]['updates'] = 'no'; }
        update_post_meta(1, 'evcal_srow', 1150000);
        for ($i = 10; $i < 515; $i++) { same(array(), wpbono_rsvp_reminders_sent_map($i)); }
    },
    'repeat flag removal resets affected later indices' => function () {
        fixture(true); attendee(10, 1, array(0 => array(7), 1 => array(7)));
        delete_post_meta(1, 'evcal_repeat'); same(array(0 => array(7)), wpbono_rsvp_reminders_sent_map(10));
    },
);
foreach ($tests as $name => $test) { $test(); echo "PASS: $name\n"; }
echo count($tests) . " scheduling tests passed\n";
