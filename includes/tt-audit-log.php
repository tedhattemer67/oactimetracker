<?php
/**
 * Time Tracker audit log.
 *
 * A permanent, append-only history of what happens to timesheets: saves and submissions
 * (with before/after values for every changed cell), withdrawals, approvals, requests for
 * changes, un-approvals, admin edits, trash/restore/delete, and manager-assignment changes.
 *
 * - Stored in its own table ({prefix}tt_audit_log) so entries outlive a deleted timesheet
 *   or user. Names are copied into each row for the same reason.
 * - The plugin only ever INSERTs rows; there is no screen or code path that edits or
 *   deletes them. (The demo seeder removes rows about demo users/sheets when it clears
 *   demo data; nothing else does.)
 * - Shown as a History section on each timesheet (website only, never in the PDF) to whoever
 *   may view that sheet: the employee, their manager and admins. Admins also get
 *   Timesheets → Activity Log with filters and CSV export.
 */

if (!defined('ABSPATH')) exit;

const TT_AUDIT_DB_VERSION = '1';

function tt_audit_table() {
    global $wpdb;
    return $wpdb->prefix . 'tt_audit_log';
}

// Create/upgrade the table. Runs on activation and, for sites where the plugin is already
// active, the first time any request sees an out-of-date schema version.
function tt_audit_install() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table   = tt_audit_table();
    $charset = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE $table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        created_at datetime NOT NULL,
        actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
        actor_name varchar(250) NOT NULL DEFAULT '',
        timesheet_id bigint(20) unsigned NOT NULL DEFAULT 0,
        employee_id bigint(20) unsigned NOT NULL DEFAULT 0,
        employee_name varchar(250) NOT NULL DEFAULT '',
        period_start date DEFAULT NULL,
        event varchar(40) NOT NULL,
        details longtext NOT NULL,
        PRIMARY KEY  (id),
        KEY timesheet_id (timesheet_id),
        KEY employee_id (employee_id),
        KEY actor_id (actor_id),
        KEY created_at (created_at),
        KEY event (event)
    ) $charset;");
    update_option('tt_audit_db_version', TT_AUDIT_DB_VERSION);
}
add_action('init', function () {
    if (get_option('tt_audit_db_version') !== TT_AUDIT_DB_VERSION) tt_audit_install();
}, 5);

/** Human labels for each event type (also the Activity Log filter list). */
function tt_audit_event_labels() {
    return [
        'draft_saved'       => 'Draft saved',
        'submitted'         => 'Submitted',
        'withdrawn'         => 'Withdrawn',
        'approved'          => 'Approved',
        'changes_requested' => 'Changes requested',
        'unapproved'        => 'Un-approved & sent back',
        'admin_edited'      => 'Edited by admin',
        'trashed'           => 'Moved to trash',
        'restored'          => 'Restored from trash',
        'deleted'           => 'Deleted permanently',
        'manager_changed'   => 'Manager assignment changed',
    ];
}

/**
 * Record one event. $tsid may be 0 for events about a user rather than a sheet
 * (pass $employee_id then). Never throws: a logging failure must not block the action.
 */
function tt_audit_log($event, $tsid, array $details = [], $employee_id = null) {
    global $wpdb;
    $tsid = intval($tsid);
    if ($employee_id === null) {
        $employee_id = $tsid ? tt_timesheet_employee_id($tsid) : 0;
    }
    $employee_id = intval($employee_id);
    $actor_id    = get_current_user_id();
    $period      = $tsid ? (string)get_post_meta($tsid, 'tt_period_start', true) : '';

    $employee_name = $employee_id ? tt_user_display_name($employee_id, '') : '';
    if ($employee_name === '' && $tsid) $employee_name = (string)get_post_meta($tsid, 'tt_employee_name', true);

    $wpdb->insert(tt_audit_table(), [
        'created_at'    => current_time('mysql', true), // UTC
        'actor_id'      => $actor_id,
        'actor_name'    => $actor_id ? tt_user_display_name($actor_id, "User #$actor_id") : 'System',
        'timesheet_id'  => $tsid,
        'employee_id'   => $employee_id,
        'employee_name' => $employee_name,
        'period_start'  => preg_match('/^\d{4}-\d{2}-\d{2}$/', $period) ? $period : null,
        'event'         => sanitize_key($event),
        'details'       => wp_json_encode($details),
    ]);
}

// --- Cell-by-cell differences ---------------------------------------------------------

function tt_audit_field_labels() {
    return [
        'in1' => 'Time In 1', 'out1' => 'Time Out 1', 'in2' => 'Time In 2', 'out2' => 'Time Out 2',
        'vac' => 'Vacation', 'sick' => 'Sick', 'pers' => 'Personal', 'hol' => 'Holiday',
        'unp' => 'Unpaid', 'ot' => 'Overtime',
    ];
}

/** Display a stored cell value: "13:30" → "1:30 PM", hours → "4", blank → "(blank)". */
function tt_audit_fmt_value($field, $v) {
    if ($v === '' || $v === null) return '(blank)';
    if (in_array($field, ['in1', 'out1', 'in2', 'out2'], true)) {
        $ts = strtotime('2000-01-01 ' . $v);
        return $ts ? date('g:i A', $ts) : (string)$v;
    }
    if (is_numeric($v)) return (floatval($v) == 0) ? '0' : rtrim(rtrim(number_format(floatval($v), 2), '0'), '.');
    return (string)$v;
}

/**
 * Every cell that differs between two tt_data arrays, as
 * [['day' => 'Tue 11/4', 'field' => 'Time Out 1', 'from' => '4:30 PM', 'to' => '5:00 PM'], ...].
 * Days are matched by position (day 1 ↔ day 1). Leave/overtime 0 and blank count as equal.
 */
function tt_audit_diff($old, $new) {
    $old = is_array($old) ? tt_sanitize_timesheet($old) : tt_sanitize_timesheet([]);
    $new = is_array($new) ? tt_sanitize_timesheet($new) : tt_sanitize_timesheet([]);
    $changes = [];
    for ($i = 0; $i < 14; $i++) {
        $o = $old['days'][$i];
        $n = $new['days'][$i];
        $date = $n['date'] ?: $o['date'];
        $day  = $date ? date('D n/j', strtotime($date)) : 'Day ' . ($i + 1);
        foreach (tt_audit_field_labels() as $f => $label) {
            $a = $o[$f];
            $b = $n[$f];
            $is_time = in_array($f, ['in1', 'out1', 'in2', 'out2'], true);
            $same = $is_time ? ((string)$a === (string)$b) : (floatval($a) == floatval($b));
            if ($same) continue;
            $changes[] = ['day' => $day, 'field' => $label, 'from' => tt_audit_fmt_value($f, $a), 'to' => tt_audit_fmt_value($f, $b)];
        }
    }
    if ($old['supervisor'] !== $new['supervisor']) {
        $changes[] = ['day' => '', 'field' => 'Supervisor', 'from' => $old['supervisor'] ?: '(blank)', 'to' => $new['supervisor'] ?: '(blank)'];
    }
    return $changes;
}

/** Grand total hours of a tt_totals array, as a float. */
function tt_audit_total($totals) {
    return round(floatval(is_array($totals) ? ($totals['grand_total'] ?? 0) : 0), 2);
}

// --- Events that aren't logged from inside the plugin's own handlers ------------------

add_action('wp_trash_post', function ($post_id) {
    if (get_post_type($post_id) !== 'timesheet') return;
    tt_audit_log('trashed', $post_id, ['state' => get_post_meta($post_id, 'tt_state', true), 'total' => tt_audit_total(get_post_meta($post_id, 'tt_totals', true))]);
});
add_action('untrashed_post', function ($post_id) {
    if (get_post_type($post_id) !== 'timesheet') return;
    tt_audit_log('restored', $post_id);
});
add_action('before_delete_post', function ($post_id) {
    if (get_post_type($post_id) !== 'timesheet') return;
    tt_audit_log('deleted', $post_id, [
        'title' => get_the_title($post_id),
        'state' => get_post_meta($post_id, 'tt_state', true),
        'total' => tt_audit_total(get_post_meta($post_id, 'tt_totals', true)),
    ]);
});

// --- Rendering ----------------------------------------------------------------------

/** "Nov 14, 2026 4:52 PM" in Eastern time from a UTC MySQL datetime. */
function tt_audit_fmt_time($utc) {
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', (string)$utc, new DateTimeZone('UTC'));
    if (!$dt) return (string)$utc;
    $dt->setTimezone(new DateTimeZone('America/New_York'));
    return $dt->format('M j, Y g:i A');
}

/** "11/1/2026 – 11/14/2026" from a period start, or ''. */
function tt_audit_fmt_period($start) {
    $dt = $start ? DateTime::createFromFormat('Y-m-d', $start) : false;
    if (!$dt) return '';
    $end = (clone $dt)->modify('+13 days');
    return $dt->format('n/j/Y') . ' – ' . $end->format('n/j/Y');
}

/** One-line summary of an entry (plain text; escape before output). */
function tt_audit_summary($row) {
    $d = json_decode($row->details, true) ?: [];
    $labels = tt_audit_event_labels();
    $s = $labels[$row->event] ?? $row->event;
    $hrs = function ($h) { return number_format(floatval($h), 2) . ' hrs'; };
    switch ($row->event) {
        case 'draft_saved':
        case 'submitted':
            if (!empty($d['created'])) $s .= ' (new timesheet)';
            if (isset($d['total'])) $s .= ', ' . $hrs($d['total']);
            if (!empty($d['on_behalf'])) $s .= ', on behalf of ' . $row->employee_name;
            break;
        case 'approved':
            if (!empty($d['self_approved'])) $s .= ' (self-approved)';
            elseif (!empty($d['same_person'])) $s .= ' (filed and approved by same person)';
            if (isset($d['total'])) $s .= ', ' . $hrs($d['total']);
            break;
        case 'admin_edited':
            if (isset($d['total_from'], $d['total_to']) && $d['total_from'] != $d['total_to']) {
                $s .= ', total ' . $hrs($d['total_from']) . ' → ' . $hrs($d['total_to']);
            }
            if (!empty($d['period_from'])) $s .= ', period moved from ' . tt_audit_fmt_period($d['period_from']) . ' to ' . tt_audit_fmt_period($d['period_to']);
            if (!empty($d['reopened'])) $s .= ', sent back to employee (signatures cleared)';
            break;
        case 'trashed':
        case 'deleted':
            if (!empty($d['state'])) $s .= ' (was ' . str_replace('_', ' ', $d['state']) . ')';
            break;
        case 'manager_changed':
            $s .= ': ' . ($d['from_name'] ?: 'none') . ' → ' . ($d['to_name'] ?: 'none');
            break;
    }
    return $s;
}

/** HTML for the note and cell changes of an entry ('' if none). */
function tt_audit_details_html($row) {
    $d = json_decode($row->details, true) ?: [];
    $html = '';
    if (!empty($d['note'])) {
        $html .= '<div style="margin:2px 0;"><em>Note:</em> ' . nl2br(esc_html($d['note'])) . '</div>';
    }
    $changes = $d['changes'] ?? [];
    if ($changes) {
        $items = '';
        foreach ($changes as $c) {
            $items .= '<li>' . esc_html(trim(($c['day'] ? $c['day'] . ' ' : '') . $c['field'])) . ': '
                . esc_html($c['from']) . ' → <strong>' . esc_html($c['to']) . '</strong></li>';
        }
        $n = count($changes);
        $list = '<ul style="margin:4px 0 0 18px; padding:0;">' . $items . '</ul>';
        $html .= $n > 5
            ? '<details><summary style="cursor:pointer;">' . $n . ' cells changed</summary>' . $list . '</details>'
            : $list;
    }
    return $html;
}

/** Plain-text details for CSV. */
function tt_audit_details_text($row) {
    $d = json_decode($row->details, true) ?: [];
    $parts = [];
    if (!empty($d['note'])) $parts[] = 'Note: ' . $d['note'];
    foreach ($d['changes'] ?? [] as $c) {
        $parts[] = trim(($c['day'] ? $c['day'] . ' ' : '') . $c['field']) . ': ' . $c['from'] . ' -> ' . $c['to'];
    }
    return implode('; ', $parts);
}

/** All entries for one timesheet, oldest first. */
function tt_audit_rows_for($tsid) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        'SELECT * FROM ' . tt_audit_table() . ' WHERE timesheet_id = %d ORDER BY created_at ASC, id ASC', $tsid
    ));
}

/**
 * The History section for a timesheet. Caller must already have checked that the
 * current user may view the sheet. Kept out of PDFs and printouts.
 */
function tt_audit_history_html($tsid) {
    $rows = tt_audit_rows_for($tsid);
    $cell = 'padding:6px 10px; border:1px solid #ddd; vertical-align:top;';
    $html = '<style>@media print{.tt-history{display:none!important}}</style>'
        . '<div class="tt-history" data-html2canvas-ignore="true" style="margin-top:28px;">'
        . '<h4 style="margin:0 0 8px; font-size:14px; font-weight:700; border-bottom:2px solid #ddd; padding-bottom:6px;">History</h4>';
    if (!$rows) {
        return $html . '<p style="color:#777; font-style:italic; font-size:13px;">No history recorded yet. (Activity before the history feature was added isn\'t available.)</p></div>';
    }
    $html .= '<table style="width:100%; border-collapse:collapse; font-size:13px;"><thead><tr style="background:#f5f5f5;">'
        . '<th style="' . $cell . ' text-align:left; width:170px;">When (Eastern)</th>'
        . '<th style="' . $cell . ' text-align:left; width:160px;">Who</th>'
        . '<th style="' . $cell . ' text-align:left;">What</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $html .= '<tr><td style="' . $cell . ' white-space:nowrap;">' . esc_html(tt_audit_fmt_time($r->created_at)) . '</td>'
            . '<td style="' . $cell . '">' . esc_html($r->actor_name) . '</td>'
            . '<td style="' . $cell . '">' . esc_html(tt_audit_summary($r)) . tt_audit_details_html($r) . '</td></tr>';
    }
    return $html . '</tbody></table></div>';
}

// History in the admin editor too.
add_action('add_meta_boxes', function () {
    add_meta_box('tt_history', 'History', function (\WP_Post $post) {
        echo tt_audit_history_html($post->ID);
    }, 'timesheet', 'normal', 'default');
});

// --- Admin: Timesheets → Activity Log -----------------------------------------------

add_action('admin_menu', function () {
    add_submenu_page('edit.php?post_type=timesheet', 'Activity Log', 'Activity Log', 'manage_options', 'tt-activity-log', 'tt_audit_admin_page');
});

/** Filters from the request (shared by the page and the CSV export). */
function tt_audit_filters_from_request() {
    $g = function ($k) { return sanitize_text_field(wp_unslash($_GET[$k] ?? '')); };
    $date = function ($v) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : ''; };
    return [
        'employee' => intval($g('employee')),
        'actor'    => intval($g('actor')),
        'event'    => array_key_exists($g('event'), tt_audit_event_labels()) ? $g('event') : '',
        'from'     => $date($g('from')),
        'to'       => $date($g('to')),
        'period'   => $date($g('period')),
    ];
}

/** WHERE clause + args for the filters. Dates are Eastern calendar days. */
function tt_audit_where($f) {
    global $wpdb;
    $w = ['1=1'];
    if ($f['employee']) $w[] = $wpdb->prepare('employee_id = %d', $f['employee']);
    if ($f['actor'])    $w[] = $wpdb->prepare('actor_id = %d', $f['actor']);
    if ($f['event'])    $w[] = $wpdb->prepare('event = %s', $f['event']);
    if ($f['period'])   $w[] = $wpdb->prepare('period_start = %s', $f['period']);
    $tz = new DateTimeZone('America/New_York');
    if ($f['from']) {
        $d = new DateTime($f['from'] . ' 00:00:00', $tz); $d->setTimezone(new DateTimeZone('UTC'));
        $w[] = $wpdb->prepare('created_at >= %s', $d->format('Y-m-d H:i:s'));
    }
    if ($f['to']) {
        $d = new DateTime($f['to'] . ' 00:00:00', $tz); $d->modify('+1 day'); $d->setTimezone(new DateTimeZone('UTC'));
        $w[] = $wpdb->prepare('created_at < %s', $d->format('Y-m-d H:i:s'));
    }
    return implode(' AND ', $w);
}

/** [id => name] of everyone who appears in the log in $col (employee_id or actor_id). */
function tt_audit_people($col) {
    global $wpdb;
    $name = $col === 'actor_id' ? 'actor_name' : 'employee_name';
    $rows = $wpdb->get_results('SELECT ' . $col . ' AS id, MAX(' . $name . ') AS name FROM ' . tt_audit_table()
        . ' WHERE ' . $col . ' > 0 GROUP BY ' . $col . ' ORDER BY name');
    $out = [];
    foreach ($rows as $r) $out[intval($r->id)] = $r->name ?: 'User #' . $r->id;
    return $out;
}

function tt_audit_admin_page() {
    global $wpdb;
    if (!current_user_can('manage_options')) return;
    $f     = tt_audit_filters_from_request();
    $where = tt_audit_where($f);
    $table = tt_audit_table();
    $per   = 50;
    $page  = max(1, intval($_GET['paged'] ?? 1));
    $total = intval($wpdb->get_var("SELECT COUNT(*) FROM $table WHERE $where"));
    $rows  = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE $where ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $per, ($page - 1) * $per));

    $sel = function ($name, $options, $current, $none) {
        $h = '<select name="' . esc_attr($name) . '"><option value="">' . esc_html($none) . '</option>';
        foreach ($options as $v => $label) $h .= '<option value="' . esc_attr($v) . '"' . selected((string)$current, (string)$v, false) . '>' . esc_html($label) . '</option>';
        return $h . '</select>';
    };
    $csv_url = wp_nonce_url(add_query_arg(array_filter($f) + ['action' => 'tt_audit_csv'], admin_url('admin-post.php')), 'tt_audit_csv');

    echo '<div class="wrap"><h1 class="wp-heading-inline">Activity Log</h1>';
    echo ' <a class="page-title-action" href="' . esc_url($csv_url) . '">Export CSV</a>';
    echo '<p class="description">Every timesheet action since this log was added. Entries can\'t be edited or deleted, and they remain after a timesheet is deleted. Dates are Eastern time.</p>';

    echo '<form method="get" style="margin:12px 0; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">';
    echo '<input type="hidden" name="post_type" value="timesheet"><input type="hidden" name="page" value="tt-activity-log">';
    echo $sel('employee', tt_audit_people('employee_id'), $f['employee'], 'All employees');
    echo $sel('actor', tt_audit_people('actor_id'), $f['actor'], 'Done by anyone');
    echo $sel('event', tt_audit_event_labels(), $f['event'], 'All events');
    echo '<label>Pay period starting <input type="date" name="period" value="' . esc_attr($f['period']) . '"></label>';
    echo '<label>From <input type="date" name="from" value="' . esc_attr($f['from']) . '"></label>';
    echo '<label>To <input type="date" name="to" value="' . esc_attr($f['to']) . '"></label>';
    echo '<button class="button">Filter</button>';
    echo ' <a class="button-link" href="' . esc_url(admin_url('edit.php?post_type=timesheet&page=tt-activity-log')) . '">Clear</a>';
    echo '</form>';

    echo '<p>' . intval($total) . ' ' . ($total === 1 ? 'entry' : 'entries') . '</p>';
    echo '<table class="widefat striped"><thead><tr><th style="width:150px;">When</th><th style="width:140px;">Done by</th><th style="width:150px;">Employee</th><th style="width:170px;">Pay period</th><th>What</th></tr></thead><tbody>';
    if (!$rows) echo '<tr><td colspan="5"><em>No entries match.</em></td></tr>';
    foreach ($rows as $r) {
        $period = tt_audit_fmt_period($r->period_start);
        if ($r->timesheet_id && get_post_type($r->timesheet_id) === 'timesheet') {
            $period = '<a href="' . esc_url(get_edit_post_link($r->timesheet_id)) . '">' . esc_html($period ?: '#' . $r->timesheet_id) . '</a>';
        } else {
            $period = esc_html($period) . ($r->timesheet_id ? ' <span style="color:#999;">(deleted)</span>' : '');
        }
        echo '<tr><td>' . esc_html(tt_audit_fmt_time($r->created_at)) . '</td>'
            . '<td>' . esc_html($r->actor_name) . '</td>'
            . '<td>' . esc_html($r->employee_name) . '</td>'
            . '<td>' . $period . '</td>'
            . '<td>' . esc_html(tt_audit_summary($r)) . tt_audit_details_html($r) . '</td></tr>';
    }
    echo '</tbody></table>';

    $pages = (int)ceil($total / $per);
    if ($pages > 1) {
        echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links([
            'base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $page, 'total' => $pages,
        ]) . '</div></div>';
    }
    echo '</div>';
}

add_action('admin_post_tt_audit_csv', function () {
    global $wpdb;
    if (!current_user_can('manage_options')) wp_die('Not allowed', '', ['response' => 403]);
    check_admin_referer('tt_audit_csv');
    $where = tt_audit_where(tt_audit_filters_from_request());
    $rows  = $wpdb->get_results('SELECT * FROM ' . tt_audit_table() . " WHERE $where ORDER BY created_at ASC, id ASC");

    nocache_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="timesheet-activity-' . gmdate('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // so Excel reads UTF-8
    // Leading = + - @ would be run as a formula by Excel; prefix them.
    $safe = function ($v) { $v = (string)$v; return preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v; };
    fputcsv($out, ['When (Eastern)', 'Event', 'Summary', 'Done by', 'Employee', 'Pay period start', 'Timesheet ID', 'Details']);
    $labels = tt_audit_event_labels();
    foreach ($rows as $r) {
        fputcsv($out, array_map($safe, [
            tt_audit_fmt_time($r->created_at), $labels[$r->event] ?? $r->event, tt_audit_summary($r),
            $r->actor_name, $r->employee_name, $r->period_start, $r->timesheet_id ?: '', tt_audit_details_text($r),
        ]));
    }
    fclose($out);
    exit;
});
