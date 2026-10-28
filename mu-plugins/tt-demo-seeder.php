<?php
/*
Plugin Name: Time Tracker – Demo Data
Description: Seeds demo managers, employees and timesheets for Time Tracker, and removes them again. Tools → TT Demo Data, or `wp tt-demo seed|clear`. Not for production.
Version: 1.0
Author: Ted Hattemer
*/

// Must-use plugin: copy this file directly into wp-content/mu-plugins/ (not a subfolder) on the
// demo site. It is always on while present; delete the file after running Remove demo data.
// It lives in the repo's mu-plugins/ folder so WordPress doesn't list it as a regular plugin.

// Everything this creates is tagged with the meta key tt_demo_seed so Clear removes exactly that.
const TT_DEMO_TAG    = 'tt_demo_seed';
const TT_DEMO_ANCHOR = '2026-01-11'; // Same pay-period anchor as js/tt-time-tracker.js.

/** Demo org chart: manager display name => employee display names. */
function tt_demo_roster() {
    return [
        'Maria Alvarez' => ['Jordan Blake', 'Priya Natarajan', 'Sam Okafor', 'Leah Kim'],
        'David Chen'    => ['Chris Murphy', 'Aisha Thompson', 'Ben Kowalski', 'Rosa Delgado'],
    ];
}

function tt_demo_login($name) {
    return 'demo_' . strtolower(str_replace(' ', '.', $name));
}

function tt_demo_make_user($name, $role, $password) {
    $login = tt_demo_login($name);
    $existing = get_user_by('login', $login);
    if ($existing) return $existing->ID;
    list($first, $last) = explode(' ', $name, 2);
    $uid = wp_insert_user([
        'user_login'   => $login,
        'user_pass'    => $password,
        'user_email'   => $login . '@example.com',
        'display_name' => $name,
        'first_name'   => $first,
        'last_name'    => $last,
        'role'         => $role,
    ]);
    if (is_wp_error($uid)) return 0;
    update_user_meta($uid, TT_DEMO_TAG, 1);
    return $uid;
}

/** Pay-period start dates (Y-m-d Sundays), oldest first, ending with the current period. */
function tt_demo_periods($count) {
    $tz     = wp_timezone();
    $anchor = new DateTimeImmutable(TT_DEMO_ANCHOR, $tz);
    $today  = new DateTimeImmutable('today', $tz);
    $n      = max(0, intdiv((int) $anchor->diff($today)->format('%r%a'), 14));
    $out    = [];
    for ($k = max(0, $n - $count + 1); $k <= $n; $k++) {
        $out[] = $anchor->modify('+' . ($k * 14) . ' days')->format('Y-m-d');
    }
    return $out;
}

/** 14 days of plausible hours. $partial_until stops filling at that date (for the in-progress period). */
function tt_demo_days($start, $partial_until = null) {
    $days = [];
    $d = new DateTime($start);
    $off_day = mt_rand(0, 3) === 0 ? mt_rand(1, 12) : -1; // ~1 in 4 sheets has a PTO day
    $off_col = ['vac', 'vac', 'sick', 'pers'][mt_rand(0, 3)];
    for ($i = 0; $i < 14; $i++) {
        $iso = $d->format('Y-m-d');
        $row = ['date' => $iso, 'in1' => '', 'out1' => '', 'in2' => '', 'out2' => '',
                'vac' => 0, 'sick' => 0, 'pers' => 0, 'hol' => 0, 'unp' => 0, 'ot' => 0];
        $weekday = (int) $d->format('N') <= 5;
        $future  = $partial_until && $iso > $partial_until;
        if ($weekday && !$future) {
            if ($i === $off_day) {
                $row[$off_col] = 8;
            } else {
                $start_min = 8 * 60 + [0, 15, 30, 30, 45][mt_rand(0, 4)];
                $lunch_min = 12 * 60 + [0, 0, 30][mt_rand(0, 2)];
                $back_min  = $lunch_min + [30, 30, 60][mt_rand(0, 2)];
                $end_min   = $back_min + (8 * 60 - ($lunch_min - $start_min));
                if (mt_rand(0, 9) === 0) $end_min += 60; // the occasional long day
                $fmt = function ($m) { return sprintf('%02d:%02d', intdiv($m, 60), $m % 60); };
                $row['in1']  = $fmt($start_min);
                $row['out1'] = $fmt($lunch_min);
                $row['in2']  = $fmt($back_min);
                $row['out2'] = $fmt($end_min);
            }
        }
        $days[] = $row;
        $d->modify('+1 day');
    }
    return $days;
}

/** UTC mysql timestamp for a local date + hour. */
function tt_demo_utc($ymd, $hour) {
    $dt = new DateTime($ymd . sprintf(' %02d:%02d:00', $hour, mt_rand(0, 59)), wp_timezone());
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Y-m-d H:i:s');
}

function tt_demo_seed($periods = 6, $password = 'demo') {
    mt_srand(20261013); // Same data every run, so the demo is rehearsable.
    $all_periods = tt_demo_periods($periods);
    $today       = current_time('Y-m-d');
    $current     = end($all_periods);
    $stats       = ['users' => 0, 'timesheets' => 0];

    foreach (tt_demo_roster() as $mgr_name => $emps) {
        $mgr_id = tt_demo_make_user($mgr_name, 'tt_manager', $password);
        if (!$mgr_id) continue;
        $stats['users']++;

        foreach ($emps as $e_idx => $emp_name) {
            $uid = tt_demo_make_user($emp_name, 'subscriber', $password);
            if (!$uid) continue;
            $stats['users']++;
            update_user_meta($uid, 'tt_manager_user_id', $mgr_id);

            foreach ($all_periods as $p_idx => $start) {
                if (tt_find_timesheet_for($uid, $start)) continue;
                $from_end = count($all_periods) - 1 - $p_idx; // 0 = current period

                // Mix of states: current = draft (one employee hasn't started), last period =
                // mostly awaiting approval, one sent back, older = approved.
                if ($from_end === 0) {
                    if ($e_idx === 3) continue;
                    $state = 'draft';
                } elseif ($from_end === 1) {
                    $state = $e_idx === 2 ? 'needs_changes' : ($e_idx === 0 ? 'approved' : 'submitted');
                } else {
                    $state = 'approved';
                }

                $end = (new DateTime($start))->modify('+13 days')->format('Y-m-d');
                $data = [
                    'period_start' => $start,
                    'employee'     => $emp_name,
                    'supervisor'   => $mgr_name,
                    'days'         => tt_demo_days($start, $start === $current ? $today : null),
                ];
                $totals = tt_calculate_totals($data);
                $title  = (new DateTime($end))->format('n-j-Y') . '-' . strtoupper(str_replace(' ', '-', $emp_name));
                $post_date = min($end, $today) . ' 16:30:00';

                $post_id = wp_insert_post([
                    'post_type'    => 'timesheet',
                    'post_title'   => $title,
                    'post_status'  => $state === 'draft' ? 'draft' : 'publish',
                    'post_author'  => $uid,
                    'post_date'    => $post_date,
                    'post_content' => tt_build_timesheet_html($data, $totals),
                ], true);
                if (is_wp_error($post_id)) continue;

                $meta = [
                    TT_DEMO_TAG        => 1,
                    'employee_user_id' => $uid,
                    'tt_state'         => $state,
                    'tt_period_start'  => $start,
                    'tt_employee_name' => $emp_name,
                    'tt_data'          => $data,
                    'tt_totals'        => $totals,
                ];
                if (in_array($state, ['submitted', 'approved'], true)) {
                    $meta += ['tt_employee_signed' => 1, 'tt_employee_signed_at' => tt_demo_utc($end, 16), 'tt_employee_signed_by' => $uid];
                }
                if ($state === 'approved') {
                    $approved_on = (new DateTime($end))->modify('+' . mt_rand(1, 3) . ' days')->format('Y-m-d');
                    $meta += ['tt_manager_signed' => 1, 'tt_manager_signed_at' => tt_demo_utc(min($approved_on, $today), 10), 'tt_manager_signed_by' => $mgr_id];
                }
                if ($state === 'needs_changes') {
                    $meta += [
                        'tt_changes_note'         => 'Your second week looks short a day. Please check your hours and resubmit.',
                        'tt_changes_requested_by' => $mgr_id,
                        'tt_changes_requested_at' => tt_demo_utc(min((new DateTime($end))->modify('+1 day')->format('Y-m-d'), $today), 9),
                    ];
                }
                foreach ($meta as $k => $v) update_post_meta($post_id, $k, $v);
                $stats['timesheets']++;
            }
        }
    }
    return $stats;
}

function tt_demo_clear() {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    $user_ids = get_users(['meta_key' => TT_DEMO_TAG, 'fields' => 'ID', 'number' => -1]);

    // Seeded sheets, plus any sheets demo users created by hand during the demo.
    $meta_query = ['relation' => 'OR', ['key' => TT_DEMO_TAG, 'compare' => 'EXISTS']];
    if ($user_ids) $meta_query[] = ['key' => 'employee_user_id', 'value' => $user_ids, 'compare' => 'IN'];
    $post_ids = get_posts([
        'post_type' => 'timesheet', 'post_status' => 'any', 'fields' => 'ids',
        'numberposts' => -1, 'meta_query' => $meta_query,
    ]);
    if ($user_ids) {
        $post_ids = array_unique(array_merge($post_ids, get_posts([
            'post_type' => 'timesheet', 'post_status' => 'any', 'fields' => 'ids',
            'numberposts' => -1, 'author__in' => $user_ids,
        ])));
    }
    foreach ($post_ids as $id) wp_delete_post($id, true);
    foreach ($user_ids as $id) wp_delete_user($id);
    return ['users' => count($user_ids), 'timesheets' => count($post_ids)];
}

// --- Tools → TT Demo Data -------------------------------------------------------------

add_action('admin_menu', function () {
    add_management_page('TT Demo Data', 'TT Demo Data', 'manage_options', 'tt-demo-data', 'tt_demo_admin_page');
});

function tt_demo_admin_page() {
    if (!current_user_can('manage_options')) return;
    $msg = '';
    if (!function_exists('tt_calculate_totals')) {
        $msg = 'Time Tracker must be active.';
    } elseif (isset($_POST['tt_demo_do']) && check_admin_referer('tt_demo_data')) {
        if ($_POST['tt_demo_do'] === 'seed') {
            $pw = (string) wp_unslash($_POST['tt_demo_password'] ?? '');
            if ($pw === '') $pw = 'demo';
            $s = tt_demo_seed(max(1, min(26, intval($_POST['tt_demo_periods'] ?? 6))), $pw);
            $msg = sprintf('Seeded %d users and %d timesheets.', $s['users'], $s['timesheets']);
        } else {
            $s = tt_demo_clear();
            $msg = sprintf('Removed %d demo users and %d timesheets.', $s['users'], $s['timesheets']);
        }
    }
    $count = count(get_users(['meta_key' => TT_DEMO_TAG, 'fields' => 'ID', 'number' => -1]));
    ?>
    <div class="wrap">
        <h1>TT Demo Data</h1>
        <?php if ($msg) echo '<div class="notice notice-info"><p>' . esc_html($msg) . '</p></div>'; ?>
        <p>Demo users currently present: <strong><?php echo intval($count); ?></strong>.
           Logins are <code>demo_first.last</code> (for example <code>demo_maria.alvarez</code> for a manager and <code>demo_jordan.blake</code> for an employee).</p>
        <form method="post">
            <?php wp_nonce_field('tt_demo_data'); ?>
            <table class="form-table">
                <tr><th><label for="tt_demo_periods">Pay periods per employee</label></th>
                    <td><input type="number" id="tt_demo_periods" name="tt_demo_periods" value="6" min="1" max="26"></td></tr>
                <tr><th><label for="tt_demo_password">Password for demo users</label></th>
                    <td><input type="text" id="tt_demo_password" name="tt_demo_password" value="demo">
                        <p class="description">Only used when a demo user is first created.</p></td></tr>
            </table>
            <p>
                <button class="button button-primary" name="tt_demo_do" value="seed">Seed demo data</button>
                <button class="button" name="tt_demo_do" value="clear" onclick="return confirm('Delete all demo users and their timesheets?');">Remove demo data</button>
            </p>
        </form>
    </div>
    <?php
}

// --- WP-CLI: wp tt-demo seed [--periods=6] [--password=demo] | wp tt-demo clear ----------

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('tt-demo', new class {
        /** Seed demo users and timesheets. [--periods=<n>] [--password=<pw>] */
        public function seed($args, $assoc) {
            if (!function_exists('tt_calculate_totals')) WP_CLI::error('Time Tracker must be active.');
            $s =tt_demo_seed(intval($assoc['periods'] ?? 6), $assoc['password'] ?? 'demo');
            WP_CLI::success("Seeded {$s['users']} users and {$s['timesheets']} timesheets.");
        }
        /** Remove all demo users and their timesheets. */
        public function clear() {
            $s = tt_demo_clear();
            WP_CLI::success("Removed {$s['users']} users and {$s['timesheets']} timesheets.");
        }
    });
}
