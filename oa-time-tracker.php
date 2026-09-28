<?php
/*
Plugin Name: OAC Time Tracker Full Fixed
Description: Biweekly time and PTO tracking form with Timesheet CPT creation.
Version: 1.0
Author: Ted Hattemer
*/
require_once __DIR__ . '/vendor/dompdf/autoload.inc.php';
use Dompdf\Dompdf;

// Register Employee CPT
add_action('init','tt_register_employee_cpt');
function tt_register_employee_cpt(){
    $labels = [
        'name'=>'Employees','singular_name'=>'Employee',
        'add_new_item'=>'Add New Employee','edit_item'=>'Edit Employee',
        'new_item'=>'New Employee','all_items'=>'All Employees',
        'menu_name'=>'Employees',
    ];
    $args = [
        'labels'=>$labels,'public'=>false,'show_ui'=>true,
        'capability_type'=>'post','supports'=>['title'],
        'menu_icon'=>'dashicons-businessperson',
    ];
    register_post_type('employee',$args);
}
register_activation_hook(__FILE__,'tt_activation');
function tt_activation(){
    tt_register_employee_cpt();
    tt_register_timesheet_cpt();
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__,'tt_deactivation');
function tt_deactivation(){
    flush_rewrite_rules();
}

// Register Timesheet CPT
function tt_register_timesheet_cpt(){
    register_post_type('timesheet', [
        'labels'=>['name'=>'Timesheets','singular_name'=>'Timesheet'],
        'public'=>true,'has_archive'=>true,
        // Timesheets hold employee PII; keep them out of site search results.
        'exclude_from_search'=>true,
        'rewrite'=>['slug'=>'timesheets'],
        'supports'=>['title','editor'],
    ]);
}
add_action('init','tt_register_timesheet_cpt');

// Enqueue scripts and styles
add_action('wp_enqueue_scripts','oac_time_tracker_enqueue_assets');
function oac_time_tracker_enqueue_assets(){
    wp_enqueue_style('oac-time-tracker-css', plugin_dir_url(__FILE__).'css/time-tracker.css');
    wp_enqueue_style('oac-time-tracker-print', plugin_dir_url(__FILE__).'css/print.css', [], null, 'print');
    $tt_js = plugin_dir_path(__FILE__) . 'js/tt-time-tracker.js';
    wp_enqueue_script('tt-time-tracker', plugin_dir_url(__FILE__).'js/tt-time-tracker.js', ['jquery'], file_exists($tt_js) ? filemtime($tt_js) : '1.1.1', true);
    wp_localize_script('tt-time-tracker','TT_AJAX',[
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('tt_ajax_nonce'),
    ]);
}

// Shortcode for form
add_shortcode('time_tracker_form','oac_time_tracker_shortcode');
function oac_time_tracker_shortcode(){
    // Avoid page cache serving a logged-in version to logged-out users
    if(!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
    if(!defined('DONOTCACHEDB')) define('DONOTCACHEDB', true);
    if(!defined('DONOTMINIFY')) define('DONOTMINIFY', true);
    nocache_headers();

    if(!is_user_logged_in()){
        $login_url = wp_login_url(get_permalink());
        return '<p><strong>Please log in to fill out your timesheet.</strong> <a href="'.esc_url($login_url).'">Log in</a></p>';
    }


    // Query WP users instead of the Employee CPT so the dropdown reflects
    // actual site accounts. Exclude admins from the selectable list; they
    // can still submit their own sheets but are unlikely to be tracked employees.
    $emps = get_users([
        'orderby'      => 'display_name',
        'order'        => 'ASC',
        'role__not_in' => ['administrator'],
        'number'       => 999,
    ]);
    ob_start(); ?>
    <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="POST">
        <?php wp_nonce_field('tt_submit_timesheet','tt_nonce'); ?>
        <input type="hidden" name="action" value="submit_timesheet">
        <input type="hidden" name="timesheet_id" id="tt-timesheet-id" value="">
        <!-- Carries the selected employee's WP user ID so the submission handler
             can store the correct employee_user_id even on admin overrides. -->
        <input type="hidden" name="employee_user_id_override" id="tt-employee-uid-override" value="">
    <div id="tt-container">
      <?php echo tt_render_nav_bar('entry'); ?>
      <div id="print-container">
        <div class="print-header">
          <h4>Biweekly Employee Timesheet</h4>
          <p>Ohio Arts Council | 30 E Broad Street, Floor 33, Columbus, OH 43215</p>
          <p><strong>Employee:</strong> <span id="print-employee"></span>
             &nbsp;<strong>Period:</strong> <span id="print-period"></span></p>
        </div>
        <?php
        $tt_user = wp_get_current_user();
        $tt_emp_name = ($tt_user && $tt_user->exists()) ? $tt_user->display_name : '';
        $tt_emp_uid  = ($tt_user && $tt_user->exists()) ? $tt_user->ID : 0;
        $tt_can_choose_emp = current_user_can('edit_others_posts') || current_user_can('manage_options');
      ?>
      <label for="employee-select">Employee:</label>
      <?php if($tt_can_choose_emp): ?>
        <select id="employee-select" name="employee"
                onchange="document.getElementById('tt-employee-uid-override').value = this.options[this.selectedIndex].dataset.uid;">
          <?php foreach($emps as $emp): ?>
            <option value="<?php echo esc_attr($emp->display_name); ?>"
                    data-uid="<?php echo intval($emp->ID); ?>"
                    <?php selected($emp->ID, $tt_emp_uid); ?>>
              <?php echo esc_html($emp->display_name); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <script>
          // Seed the hidden UID field on page load so it's populated even without
          // the user touching the dropdown.
          (function(){
            var sel = document.getElementById('employee-select');
            var uid = document.getElementById('tt-employee-uid-override');
            if(sel && uid) uid.value = sel.options[sel.selectedIndex].dataset.uid || '';
          })();
        </script>
      <?php else: ?>
        <input type="hidden" name="employee" id="employee-select" value="<?php echo esc_attr($tt_emp_name); ?>">
        <p style="margin-top:0;"><strong><?php echo esc_html($tt_emp_name); ?></strong></p>
      <?php endif; ?>
        <label for="pay-period">Select Pay Period:</label>
        <select id="pay-period" name="pay_period"></select>

<!-- added div for style purposes table not resizing  -->
<div class="tt-table-wrapper">
        <h3>Hours Worked</h3>
        <table id="time-grid" class="widefat">
          <thead><tr>
            <th>Date</th><th>Time In 1</th><th>Time Out 1</th>
            <th>Time In 2</th><th>Time Out 2</th><th>Total Hours</th>
          </tr></thead>
          <tbody class="time-body"></tbody>
          <tfoot><tr>
            <th>Totals</th><th colspan="4"></th><th id="total-hours">0</th>
          </tr></tfoot>
        </table>
</div>

<div class="tt-table-wrapper">
        <h3>Paid Time Off</h3>
        <table id="pto-grid" class="widefat">
          <thead><tr>
            <th>Date</th><th>Regular Hours</th><th>Vacation</th>
            <th>Sick Leave</th><th>Personal Leave</th><th>Holiday Leave</th>
            <th>Unpaid Leave</th><th>Overtime</th><th>Total</th>
          </tr></thead>
          <tbody class="pto-body"></tbody>
          <tfoot><tr>
            <th>Totals</th>
            <th id="tot-reg">0</th><th id="tot-vac">0</th>
            <th id="tot-sick">0</th><th id="tot-pers">0</th>
            <th id="tot-hol">0</th><th id="tot-unp">0</th>
            <th id="tot-ot">0</th><th id="tot-all">0</th>
          </tr></tfoot>
        </table>
</div>
      </div>
    </div>

      <div class="tt-certify-block" id="tt-certify-block" style="margin-top:20px; padding:14px 16px; border:1px solid #c3c4c7; border-radius:6px; background:#f9f9f9; max-width:900px; margin-left:auto; margin-right:auto;">
        <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
          <input type="checkbox" name="tt_employee_certify" id="tt-employee-certify" value="1"
                 style="margin-top:3px; width:18px; height:18px; flex-shrink:0;">
          <span>I certify that the hours and leave reported on this timesheet are accurate and complete to the best of my knowledge.</span>
        </label>
        <p id="tt-certify-warning" style="display:none; color:#d63638; margin:8px 0 0; font-size:13px;">
          &#9888; You must check the certification box before submitting.
        </p>
      </div>

      <div class="tt-submit-actions" style="margin-top:16px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <button type="submit" name="tt_action" value="draft" class="button">Save Draft</button>
        <button type="submit" name="tt_action" value="submit" class="button button-primary" id="tt-submit-btn">Submit to Manager</button>
        <button type="button" id="tt-clear-btn" class="button" style="margin-left:8px;">Clear Form</button>
        <span id="tt-draft-status" style="align-self:center;"></span>
        <a href="<?php echo esc_url(wp_logout_url(get_permalink())); ?>"
           id="tt-logout-btn"
           class="button"
           style="margin-left:auto;"
           onclick="return confirm('Log out of your timesheet session?');">Log Out</a>
      </div>
</form>
<script>
(function(){
  var certify   = document.getElementById('tt-employee-certify');
  var warning   = document.getElementById('tt-certify-warning');
  var submitBtn = document.getElementById('tt-submit-btn');
  var certBlock = document.getElementById('tt-certify-block');

  // Hide certification block when sheet is locked (submitted/approved)
  function syncCertBlock(){
    var status = (document.getElementById('tt-draft-status') || {}).textContent || '';
    var locked = (status.indexOf('submitted') !== -1 || status.indexOf('approved') !== -1);
    if(certBlock) certBlock.style.display = locked ? 'none' : '';
  }
  var statusEl = document.getElementById('tt-draft-status');
  if(statusEl){
    new MutationObserver(syncCertBlock).observe(statusEl, {childList:true, subtree:true, characterData:true});
  }

  // Intercept submit click — require checkbox
  if(submitBtn){
    submitBtn.addEventListener('click', function(e){
      if(certify && !certify.checked){
        e.preventDefault();
        if(warning) warning.style.display = '';
        certify.focus();
      }
    });
  }
  if(certify && warning){
    certify.addEventListener('change', function(){
      if(this.checked) warning.style.display = 'none';
    });
  }
})();
</script>
    <?php
    return ob_get_clean();
}

// Handle form submission
add_action('admin_post_submit_timesheet','ttc_handle_submission');
function ttc_handle_submission(){
    if(!is_user_logged_in()){
        wp_die('You must be logged in to submit a timesheet.');
    }

    if(empty($_POST['tt_nonce'])||!wp_verify_nonce($_POST['tt_nonce'],'tt_submit_timesheet')){
        wp_die('Security check failed.');
    }
    $tt_action = sanitize_text_field($_POST['tt_action'] ?? 'submit');
    $start_raw = sanitize_text_field($_POST['pay_period']);

    // Normalize pay period to ISO Y-m-d (handles m/d/Y etc)
    $start_ts = strtotime($start_raw);
    if($start_ts){ $start_raw = date('Y-m-d', $start_ts); }
    // Normalize pay_period to Sunday (guardrail against Saturday regression)
    if($start_raw){
        $dt_guard = DateTime::createFromFormat('Y-m-d', $start_raw);
        if($dt_guard){
            $dow = intval($dt_guard->format('w')); // 0=Sun,6=Sat
            if($dow !== 0){
                if($dow === 6){
                    $dt_guard->modify('+1 day');
                } else {
                    $dt_guard->modify('last sunday');
                }
                $start_raw = $dt_guard->format('Y-m-d');
            }
        }
    }
    $start = DateTime::createFromFormat('Y-m-d', $start_raw);
    $end = clone $start; $end->modify('+13 days');
    $employee = sanitize_text_field($_POST['employee'] ?? '');
    $u = wp_get_current_user();
    $current_name = ($u && $u->exists()) ? $u->display_name : '';
    $can_override = current_user_can('edit_others_posts') || current_user_can('manage_options');

    // When an admin/manager submits on behalf of someone else, use the WP user ID
    // that was passed via the hidden employee_user_id_override field (populated by the
    // dropdown's data-uid attribute). Fall back to the current user for regular employees.
    $employee_uid_override = intval($_POST['employee_user_id_override'] ?? 0);
    if($can_override && $employee_uid_override){
        $override_user = get_userdata($employee_uid_override);
        if($override_user){
            $employee = $override_user->display_name;
            // $employee_uid_override is already set and will be used below
        }
    }

    // Enforce employee to current user unless admin/manager override
    if(!$can_override){
        $employee = $current_name ?: 'EMPLOYEE';
        $employee_uid_override = 0; // ensure non-admins cannot spoof this field
    }
    if(!$employee){
        $employee = $current_name ?: 'EMPLOYEE';
    }
    // Enforce employee to current user
    $title = $end->format('n-j-Y') . '-' . strtoupper(str_replace(' ','-',$employee));
        // Option A: one timesheet per user per pay period
    $existing_id = 0;
    $ts_id_from_form = intval($_POST['timesheet_id'] ?? 0);
    if($ts_id_from_form){
        $p = get_post($ts_id_from_form);
        if($p && $p->post_type==='timesheet'){
            $uid = get_current_user_id();
            $emp_uid = intval(get_post_meta($ts_id_from_form,'employee_user_id',true));
            if(intval($p->post_author)===$uid || $emp_uid===$uid){
                $existing_id = $ts_id_from_form;
            }
        }
    }
    if(!$existing_id){
        $q_exist = new WP_Query([
            'post_type'=>'timesheet',
            'author'=>get_current_user_id(),
            'post_status'=>['draft','publish'],
            'posts_per_page'=>1,
            'meta_key'=>'tt_period_start',
            'meta_value'=>$start_raw,
        ]);
        if($q_exist->have_posts()){
            $q_exist->the_post();
            $existing_id = get_the_ID();
            wp_reset_postdata();
        }
    }

    // Prevent editing submitted/approved sheets. Checked against the resolved
    // $existing_id (not just a posted timesheet_id) so clearing the form's hidden
    // ID can't be used to overwrite a locked sheet via the pay-period lookup.
    if($existing_id){
        $existing_state = get_post_meta($existing_id,'tt_state',true);
        if(in_array($existing_state, ['submitted','approved'], true)){
            wp_die(
                'Your timesheet for this pay period has already been '.esc_html($existing_state).' and is locked. '
                .($existing_state === 'submitted' ? 'Withdraw the submission first if you need to make changes. ' : '')
                .'<a href="'.esc_url(get_permalink($existing_id)).'">View timesheet</a>',
                'Timesheet locked',
                ['response' => 403]
            );
        }
    }

    if($existing_id){
        $post_id = $existing_id;
        wp_update_post(['ID'=>$post_id,'post_title'=>$title,'post_status'=>($tt_action==='draft'?'draft':'publish')]);
    } else {
        $post_id = wp_insert_post(['post_type'=>'timesheet','post_title'=>$title,'post_status'=>($tt_action === 'draft' ? 'draft' : 'publish'),'post_author'=>get_current_user_id()]);
    }
    
    // Catch insert/update errors before proceeding.
    if(is_wp_error($post_id)) wp_die('Error saving timesheet: ' . $post_id->get_error_message());

    // Determine whose timesheet this really is.
    // - Regular employees: always their own user ID.
    // - Admins/managers: use the override UID from the dropdown if provided,
    //   otherwise fall back to their own ID (e.g. admin filling their own sheet).
    $uid = get_current_user_id();
    $target_uid = ($can_override && $employee_uid_override) ? $employee_uid_override : $uid;

    // Ensure ownership is set correctly (used for draft loading and manager inbox queries).
    if($post_id){
        $p = get_post($post_id);
        if($p){
            // Set post_author to the employee being tracked, not necessarily the submitter.
            if(intval($p->post_author) !== $target_uid){
                wp_update_post(['ID'=>$post_id,'post_author'=>$target_uid]);
            }
        }
        update_post_meta($post_id,'employee_user_id', $target_uid);
    }

    // Build tt_data + totals, then generate canonical post_content HTML
    // Store normalized data and authoritative totals for admin edits
    $tt_days = [];
    $start_norm = DateTime::createFromFormat('Y-m-d', $start_raw);
    if(!$start_norm){
        try { $start_norm = new DateTime($start_raw); } catch(Exception $e){ $start_norm = null; }
    }
    $cursor = $start_norm ? clone $start_norm : null;
    for($i=0;$i<14;$i++){
        if($i>0 && $cursor) $cursor->modify('+1 day');
        $date_iso = $cursor ? $cursor->format('Y-m-d') : '';
        $tt_days[$i] = [
            'date' => $date_iso,
            'in1'  => sanitize_text_field($_POST["time_in_1_$i"] ?? ''),
            'out1' => sanitize_text_field($_POST["time_out_1_$i"] ?? ''),
            'in2'  => sanitize_text_field($_POST["time_in_2_$i"] ?? ''),
            'out2' => sanitize_text_field($_POST["time_out_2_$i"] ?? ''),
            'vac'  => floatval($_POST["vacation_$i"] ?? 0),
            'sick' => floatval($_POST["sick_$i"] ?? 0),
            'pers' => floatval($_POST["personal_$i"] ?? 0),
            'hol'  => floatval($_POST["holiday_$i"] ?? 0),
            'unp'  => floatval($_POST["unpaid_$i"] ?? 0),
            'ot'   => floatval($_POST["overtime_$i"] ?? 0),
        ];
    }
    $tt_data = [
        'period_start' => $start_norm ? $start_norm->format('Y-m-d') : '',
        'employee'     => $employee,
        'supervisor'   => sanitize_text_field($_POST['supervisor'] ?? ''),
        'days'         => $tt_days
    ];
    update_post_meta($post_id,'employee_user_id', $target_uid); // use target, not submitter
    $prev_state = get_post_meta($post_id,'tt_state',true);
    $new_state = ($tt_action === 'draft' ? ($prev_state==='needs_changes' ? 'needs_changes' : 'draft') : 'submitted');
    update_post_meta($post_id,'tt_state', $new_state);

    // Employee signature: record on submit, clear on draft/needs_changes
    if ( $new_state === 'submitted' ) {
        $submitter_uid = get_current_user_id();
        update_post_meta( $post_id, 'tt_employee_signed',    1 );
        update_post_meta( $post_id, 'tt_employee_signed_at', current_time('mysql', true) ); // UTC
        update_post_meta( $post_id, 'tt_employee_signed_by', $submitter_uid );
        // Clear any previous manager signature — the data changed
        delete_post_meta( $post_id, 'tt_manager_signed' );
        delete_post_meta( $post_id, 'tt_manager_signed_at' );
        delete_post_meta( $post_id, 'tt_manager_signed_by' );
    } else {
        // Draft or needs_changes: wipe employee sig so it must be re-certified
        delete_post_meta( $post_id, 'tt_employee_signed' );
        delete_post_meta( $post_id, 'tt_employee_signed_at' );
        delete_post_meta( $post_id, 'tt_employee_signed_by' );
    }
    update_post_meta($post_id,'tt_period_start', $start_raw);
    update_post_meta($post_id,'tt_employee_name', $employee);
    update_post_meta($post_id, 'tt_data', $tt_data);
    $tt_totals = [];
    if (function_exists('tt_calculate_totals')) {
        $tt_totals = tt_calculate_totals($tt_data);
        update_post_meta($post_id, 'tt_totals', $tt_totals);
    }
    $html = tt_build_timesheet_html($tt_data, $tt_totals);
    if($html){
        wp_update_post(['ID'=>$post_id,'post_content'=>$html]);
    }

    if($tt_action === 'draft'){
        $ref = wp_get_referer();
        if(!$ref) $ref = home_url('/');
        $ref = add_query_arg(['pay_period'=>$start_raw,'timesheet_id'=>$post_id], $ref);
        wp_safe_redirect($ref);
        exit;
    }

    wp_safe_redirect(get_permalink($post_id));
    exit;
}


function myplugin_enqueue_pdf_scripts() {
  // 1. html2pdf bundle
  wp_enqueue_script(
    'html2pdf',
    'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.9.2/html2pdf.bundle.min.js',
    [],
    '0.9.2',
    true
  );

  // 2. your custom JS
wp_enqueue_script(
  'myplugin-pdf-print',
  plugin_dir_url(__FILE__) . 'js/pdf-print.js',
  ['html2pdf','jquery'],
  time(), // ← forces fresh load every request
  true
);


  // 3. only on single timesheet posts
  if ( is_singular('timesheet') ) {
    $title = get_the_title();
    $slug  = sanitize_title( $title );
    wp_localize_script(
      'myplugin-pdf-print',
      'MyPluginPDF',
      [ 'filename' => $slug . '.pdf' ]
    );
  }
}
add_action( 'wp_enqueue_scripts', 'myplugin_enqueue_pdf_scripts' );




// ==== Admin edit support: normalized data + server-side totals ====

// 1) Register meta for raw data (tt_data) and computed totals (tt_totals)
add_action('init', function () {
    register_post_meta('timesheet', 'tt_data', [
        'type'         => 'object',
        'single'       => true,
        'show_in_rest' => true,
        'auth_callback'=> function(){ return current_user_can('edit_posts'); },
    ]);
    register_post_meta('timesheet', 'tt_totals', [
        'type'         => 'object',
        'single'       => true,
        'show_in_rest' => true,
        'auth_callback'=> function(){ return current_user_can('edit_posts'); },
    ]);
    // Signature meta fields
    foreach ( ['tt_employee_signed','tt_manager_signed'] as $key ) {
        register_post_meta('timesheet', $key, [
            'type'         => 'boolean',
            'single'       => true,
            'show_in_rest' => false,
        ]);
    }
    foreach ( ['tt_employee_signed_at','tt_manager_signed_at'] as $key ) {
        register_post_meta('timesheet', $key, [
            'type'         => 'string',
            'single'       => true,
            'show_in_rest' => false,
        ]);
    }
    foreach ( ['tt_employee_signed_by','tt_manager_signed_by'] as $key ) {
        register_post_meta('timesheet', $key, [
            'type'         => 'integer',
            'single'       => true,
            'show_in_rest' => false,
        ]);
    }
});

// 2) Metabox with editable grid (admin)
add_action('add_meta_boxes', function () {
    add_meta_box(
        'tt_timesheet_editor',
        __('Timesheet (Admin Editor)', 'oac'),
        'tt_render_timesheet_metabox',
        'timesheet',
        'normal',
        'high'
    );
});

function tt_render_timesheet_metabox(\WP_Post $post) {
    wp_nonce_field('tt_save_timesheet','tt_nonce');

    $data = get_post_meta($post->ID, 'tt_data', true);
    if (!is_array($data) || empty($data['days'])) { $data = tt_seed_data_if_missing($post->ID); if (!is_array($data)) $data = []; }
    $days = isset($data['days']) && is_array($data['days']) ? $data['days'] : [];

    echo '<div id="tt-admin-metabox" class="tt-admin-box">';
    echo '<p class="description">'.esc_html__('Edit user-submitted hours and PTO. Totals preview updates live; final totals are recalculated on Save.', 'oac').'</p>';

    echo '<table class="widefat striped" id="tt-admin-grid"><thead><tr>
            <th>'.esc_html__('Date','oac').'</th>
            <th>'.esc_html__('In 1','oac').'</th>
            <th>'.esc_html__('Out 1','oac').'</th>
            <th>'.esc_html__('In 2','oac').'</th>
            <th>'.esc_html__('Out 2','oac').'</th>
            <th>'.esc_html__('Vacation','oac').'</th>
            <th>'.esc_html__('Sick','oac').'</th>
            <th>'.esc_html__('Personal','oac').'</th>
            <th>'.esc_html__('Holiday','oac').'</th>
            <th>'.esc_html__('Unpaid','oac').'</th>
            <th>'.esc_html__('Overtime','oac').'</th>
            <th>'.esc_html__('Daily Total (preview)','oac').'</th>
          </tr></thead><tbody>';

    for ($i=0; $i<14; $i++) {
        $row = $days[$i] ?? [];
        $v = function($k) use ($row){ return esc_attr($row[$k] ?? ''); };
        echo '<tr class="tt-row">';
        echo '<td><input class="tt-date" type="date" name="tt_data[days]['.$i.'][date]" value="'.$v('date').'" /></td>';
        echo '<td><input class="tt-in1"  type="time" name="tt_data[days]['.$i.'][in1]"  value="'.$v('in1').'"  /></td>';
        echo '<td><input class="tt-out1" type="time" name="tt_data[days]['.$i.'][out1]" value="'.$v('out1').'" /></td>';
        echo '<td><input class="tt-in2"  type="time" name="tt_data[days]['.$i.'][in2]"  value="'.$v('in2').'"  /></td>';
        echo '<td><input class="tt-out2" type="time" name="tt_data[days]['.$i.'][out2]" value="'.$v('out2').'" /></td>';
        echo '<td><input class="tt-vac"  type="number" step="0.5" min="0" name="tt_data[days]['.$i.'][vac]"  value="'.$v('vac').'"  /></td>';
        echo '<td><input class="tt-sick" type="number" step="0.5" min="0" name="tt_data[days]['.$i.'][sick]" value="'.$v('sick').'" /></td>';
        echo '<td><input class="tt-pers" type="number" step="0.5" min="0" name="tt_data[days]['.$i.'][pers]" value="'.$v('pers').'" /></td>';
        echo '<td><input class="tt-hol"  type="number" step="0.5" min="0" name="tt_data[days]['.$i.'][hol]"  value="'.$v('hol').'"  /></td>';
        echo '<td><input class="tt-unp"  type="number" step="0.5" min="0" name="tt_data[days]['.$i.'][unp]"  value="'.$v('unp').'"  /></td>';
        echo '<td><input class="tt-ot"   type="number" step="0.5" min="0" name="tt_data[days]['.$i.'][ot]"   value="'.$v('ot').'"   /></td>';
        echo '<td class="tt-daily-total">0</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    // Ancillary fields
    echo '<div class="tt-flex">';
    echo '<p><label>'.esc_html__('Period Start','oac').' <input type="date" name="tt_data[period_start]" value="' . esc_attr($data['period_start'] ?? '') . '"></label></p>';
    echo '<p><label>'.esc_html__('Employee','oac').' <input type="text" name="tt_data[employee]" value="' . esc_attr($data['employee'] ?? '') . '"></label></p>';
    echo '<p><label>'.esc_html__('Supervisor','oac').' <input type="text" name="tt_data[supervisor]" value="' . esc_attr($data['supervisor'] ?? '') . '"></label></p>';
    echo '</div>';

    // Totals preview area
    echo '<h4>'.esc_html__('Totals Preview','oac').'</h4>';
    echo '<table class="widefat" id="tt-totals-preview"><tbody>';
    echo '<tr><th>'.esc_html__('Regular','oac').'</th><td class="tt-total-reg">0</td></tr>';
    echo '<tr><th>'.esc_html__('Vacation','oac').'</th><td class="tt-total-vac">0</td></tr>';
    echo '<tr><th>'.esc_html__('Sick','oac').'</th><td class="tt-total-sick">0</td></tr>';
    echo '<tr><th>'.esc_html__('Personal','oac').'</th><td class="tt-total-pers">0</td></tr>';
    echo '<tr><th>'.esc_html__('Holiday','oac').'</th><td class="tt-total-hol">0</td></tr>';
    echo '<tr><th>'.esc_html__('Unpaid','oac').'</th><td class="tt-total-unp">0</td></tr>';
    echo '<tr><th>'.esc_html__('Overtime','oac').'</th><td class="tt-total-ot">0</td></tr>';
    echo '<tr><th>'.esc_html__('Grand Total','oac').'</th><td class="tt-total-grand">0</td></tr>';
    echo '</tbody></table>';

    // Signature status (read-only in admin)
    $emp_signed    = get_post_meta( $post->ID, 'tt_employee_signed',    true );
    $emp_signed_at = get_post_meta( $post->ID, 'tt_employee_signed_at', true );
    $emp_signed_by = intval( get_post_meta( $post->ID, 'tt_employee_signed_by', true ) );
    $mgr_signed    = get_post_meta( $post->ID, 'tt_manager_signed',    true );
    $mgr_signed_at = get_post_meta( $post->ID, 'tt_manager_signed_at', true );
    $mgr_signed_by = intval( get_post_meta( $post->ID, 'tt_manager_signed_by', true ) );

    $fmt_sig = function( $signed, $signed_by, $signed_at ) {
        if ( $signed && $signed_by ) {
            $u  = get_userdata( $signed_by );
            $dt = DateTime::createFromFormat( 'Y-m-d H:i:s', $signed_at, new DateTimeZone('UTC') );
            if ( $dt ) $dt->setTimezone( new DateTimeZone('America/New_York') );
            $when = $dt ? $dt->format( 'M j, Y \a\t g:i A T' ) : esc_html( $signed_at );
            return '<span style="color:#006622;">&#10003; ' . esc_html( $u ? $u->display_name : "User #$signed_by" ) . ' &mdash; ' . esc_html( $when ) . '</span>';
        }
        return '<span style="color:#999; font-style:italic;">Not yet signed</span>';
    };

    echo '<h4>' . esc_html__( 'Signatures', 'oac' ) . '</h4>';
    echo '<table class="widefat" id="tt-signatures-admin"><tbody>';
    echo '<tr><th style="width:140px;">' . esc_html__( 'Employee', 'oac' ) . '</th><td>' . $fmt_sig( $emp_signed, $emp_signed_by, $emp_signed_at ) . '</td></tr>';
    echo '<tr><th>' . esc_html__( 'Manager', 'oac' ) . '</th><td>' . $fmt_sig( $mgr_signed, $mgr_signed_by, $mgr_signed_at ) . '</td></tr>';
    echo '</tbody></table>';
    echo '<p class="description" style="margin-top:6px;">' . esc_html__( 'Signatures are recorded automatically when an employee submits or a manager approves. They clear if the timesheet is sent back for changes.', 'oac' ) . '</p>';

    echo '</div>'; // end metabox
}

// 3) Save handler that recalculates authoritative totals in PHP
add_action('save_post_timesheet', function ($post_id) {
    // Prevent recursion when we call wp_update_post() inside this hook.
    static $ttc_in_progress = false;
    if ($ttc_in_progress) return;

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;
    if (!isset($_POST['tt_nonce']) || !wp_verify_nonce($_POST['tt_nonce'], 'tt_save_timesheet')) return;

    $data_in = isset($_POST['tt_data']) ? $_POST['tt_data'] : [];
    $clean   = tt_sanitize_timesheet($data_in);
    $totals  = tt_calculate_totals($clean);

    update_post_meta($post_id, 'tt_data',   $clean);
    update_post_meta($post_id, 'tt_totals', $totals);

    // Keep the front-end view + PDF source in sync with admin edits by regenerating
    // the canonical HTML table in post_content from tt_data + calculated totals.
    $html = tt_build_timesheet_html($clean, $totals);
    if ($html) {
        $ttc_in_progress = true;
        wp_update_post([
            'ID'           => $post_id,
            'post_content' => $html,
        ]);
        $ttc_in_progress = false;
    }
});

/**
 * Build the signature block HTML for a timesheet.
 * Used by the front-end view, the content filter, and the PDF generator.
 *
 * @param int    $post_id   Timesheet post ID.
 * @param bool   $for_pdf   When true, omits interactive elements.
 * @return string HTML fragment.
 */
function tt_build_signature_block( $post_id, $for_pdf = false ) {
    $emp_signed    = get_post_meta( $post_id, 'tt_employee_signed',    true );
    $emp_signed_at = get_post_meta( $post_id, 'tt_employee_signed_at', true );
    $emp_signed_by = intval( get_post_meta( $post_id, 'tt_employee_signed_by', true ) );
    $mgr_signed    = get_post_meta( $post_id, 'tt_manager_signed',    true );
    $mgr_signed_at = get_post_meta( $post_id, 'tt_manager_signed_at', true );
    $mgr_signed_by = intval( get_post_meta( $post_id, 'tt_manager_signed_by', true ) );

    $fmt_dt = function( $mysql_dt ) {
        if ( ! $mysql_dt ) return '';
        $dt = DateTime::createFromFormat( 'Y-m-d H:i:s', $mysql_dt, new DateTimeZone('UTC') );
        if ( $dt ) $dt->setTimezone( new DateTimeZone('America/New_York') );
        return $dt ? $dt->format( 'M j, Y \a\t g:i A T' ) : esc_html( $mysql_dt );
    };
    $user_name = function( $uid ) {
        if ( ! $uid ) return '';
        $u = get_userdata( $uid );
        return $u ? esc_html( $u->display_name ) : "User #$uid";
    };

    $emp_row = '';
    if ( $emp_signed && $emp_signed_by ) {
        $emp_row = '<tr style="color:#006622;">'
            . '<td style="padding:10px 14px; border:1px solid #ddd; font-weight:600; white-space:nowrap;">&#10003; Employee</td>'
            . '<td style="padding:10px 14px; border:1px solid #ddd;">' . $user_name( $emp_signed_by ) . '</td>'
            . '<td style="padding:10px 14px; border:1px solid #ddd;">' . esc_html( $fmt_dt( $emp_signed_at ) ) . '</td>'
            . '<td style="padding:10px 14px; border:1px solid #ddd; font-size:11px; color:#555;">I certify that the hours and leave reported are accurate and complete.</td>'
            . '</tr>';
    } else {
        $emp_row = '<tr style="color:#999;">'
            . '<td style="padding:10px 14px; border:1px solid #ddd; font-weight:600;">Employee</td>'
            . '<td colspan="3" style="padding:10px 14px; border:1px solid #ddd; font-style:italic;">Not yet signed</td>'
            . '</tr>';
    }

    $mgr_row = '';
    if ( $mgr_signed && $mgr_signed_by ) {
        $mgr_row = '<tr style="color:#006622;">'
            . '<td style="padding:10px 14px; border:1px solid #ddd; font-weight:600; white-space:nowrap;">&#10003; Manager</td>'
            . '<td style="padding:10px 14px; border:1px solid #ddd;">' . $user_name( $mgr_signed_by ) . '</td>'
            . '<td style="padding:10px 14px; border:1px solid #ddd;">' . esc_html( $fmt_dt( $mgr_signed_at ) ) . '</td>'
            . '<td style="padding:10px 14px; border:1px solid #ddd; font-size:11px; color:#555;">I have reviewed and approve this timesheet.</td>'
            . '</tr>';
    } else {
        $mgr_row = '<tr style="color:#999;">'
            . '<td style="padding:10px 14px; border:1px solid #ddd; font-weight:600;">Manager</td>'
            . '<td colspan="3" style="padding:10px 14px; border:1px solid #ddd; font-style:italic;">Not yet signed</td>'
            . '</tr>';
    }

    return '<div class="tt-signature-block" style="margin-top:28px;">'
        . '<h4 style="margin:0 0 8px; font-size:14px; font-weight:700; border-bottom:2px solid #ddd; padding-bottom:6px;">Signatures</h4>'
        . '<table style="width:100%; border-collapse:collapse; font-size:13px;">'
        . '<thead><tr style="background:#f5f5f5;">'
        . '<th style="padding:8px 14px; border:1px solid #ddd; text-align:left; width:110px;">Role</th>'
        . '<th style="padding:8px 14px; border:1px solid #ddd; text-align:left;">Signed By</th>'
        . '<th style="padding:8px 14px; border:1px solid #ddd; text-align:left; width:200px;">Date &amp; Time</th>'
        . '<th style="padding:8px 14px; border:1px solid #ddd; text-align:left;">Certification</th>'
        . '</tr></thead>'
        . '<tbody>' . $emp_row . $mgr_row . '</tbody>'
        . '</table>'
        . '</div>';
}


/**
 * Build the canonical HTML table used for the single timesheet view + PDF generation.
 * This keeps older behavior (post_content holds the rendered table) while allowing
 * admins to edit tt_data in the metabox and have the output update immediately.
 */
function tt_build_timesheet_html($clean, $totals) {
    if (!is_array($clean) || empty($clean['days']) || !is_array($clean['days'])) return '';

    $html = '<h3>Time Sheet</h3><table class="widefat" id="ts_format_table"><thead><tr>'
          .'<th>Date</th><th>Regular Hours</th><th>Vacation</th><th>Sick</th>'
          .'<th>Personal</th><th>Holiday</th><th>Unpaid</th><th>Overtime</th><th>Total</th>'
          .'</tr></thead><tbody>';

    // Per-day rows
    $by_day = isset($totals['by_day']) && is_array($totals['by_day']) ? $totals['by_day'] : [];
    for ($i=0; $i<14; $i++) {
        $d = $clean['days'][$i] ?? [];
        $iso = $d['date'] ?? '';
        $date_disp = $iso;
        if ($iso) {
            $dt = DateTime::createFromFormat('Y-m-d', $iso);
            if ($dt) $date_disp = $dt->format('n/j/Y');
        }

        $row = $by_day[$i] ?? [];
        $reg   = floatval($row['reg']   ?? 0);
        $vac   = floatval($row['vac']   ?? ($d['vac']  ?? 0));
        $sick  = floatval($row['sick']  ?? ($d['sick'] ?? 0));
        $pers  = floatval($row['pers']  ?? ($d['pers'] ?? 0));
        $hol   = floatval($row['hol']   ?? ($d['hol']  ?? 0));
        $unp   = floatval($row['unp']   ?? ($d['unp']  ?? 0));
        $ot    = floatval($row['ot']    ?? ($d['ot']   ?? 0));
        $total = floatval($row['total'] ?? 0);

        $html .= '<tr><td>'.esc_html($date_disp).'</td>'
               . '<td>'.number_format($reg,2).'</td>'
               . '<td>'.number_format($vac,2).'</td>'
               . '<td>'.number_format($sick,2).'</td>'
               . '<td>'.number_format($pers,2).'</td>'
               . '<td>'.number_format($hol,2).'</td>'
               . '<td>'.number_format($unp,2).'</td>'
               . '<td>'.number_format($ot,2).'</td>'
               . '<td>'.number_format($total,2).'</td></tr>';
    }

    // Footer totals
    $col = isset($totals['by_column']) && is_array($totals['by_column']) ? $totals['by_column'] : [];
    $html .= '</tbody><tfoot><tr><th>Totals</th>'
           . '<td>'.number_format(floatval($col['reg']  ?? 0),2).'</td>'
           . '<td>'.number_format(floatval($col['vac']  ?? 0),2).'</td>'
           . '<td>'.number_format(floatval($col['sick'] ?? 0),2).'</td>'
           . '<td>'.number_format(floatval($col['pers'] ?? 0),2).'</td>'
           . '<td>'.number_format(floatval($col['hol']  ?? 0),2).'</td>'
           . '<td>'.number_format(floatval($col['unp']  ?? 0),2).'</td>'
           . '<td>'.number_format(floatval($col['ot']   ?? 0),2).'</td>'
           . '<td>'.number_format(floatval($totals['grand_total'] ?? 0),2).'</td>'
           . '</tr></tfoot></table>';

    return $html;
}

// Helpers: sanitize + time math + totals
function tt_sanitize_timesheet($data) {
    $out = [
        'period_start' => isset($data['period_start']) ? sanitize_text_field($data['period_start']) : '',
        'employee'     => isset($data['employee']) ? sanitize_text_field($data['employee']) : '',
        'supervisor'   => isset($data['supervisor']) ? sanitize_text_field($data['supervisor']) : '',
        'days'         => []
    ];
    for ($i=0; $i<14; $i++) {
        $row = isset($data['days'][$i]) ? (array)$data['days'][$i] : [];
        $one = [
            'date' => sanitize_text_field($row['date'] ?? ''),
            'in1'  => sanitize_text_field($row['in1']  ?? ''),
            'out1' => sanitize_text_field($row['out1'] ?? ''),
            'in2'  => sanitize_text_field($row['in2']  ?? ''),
            'out2' => sanitize_text_field($row['out2'] ?? ''),
            'vac'  => floatval($row['vac']  ?? 0),
            'sick' => floatval($row['sick'] ?? 0),
            'pers' => floatval($row['pers'] ?? 0),
            'hol'  => floatval($row['hol']  ?? 0),
            'unp'  => floatval($row['unp']  ?? 0),
            'ot'   => floatval($row['ot']   ?? 0),
        ];
        $out['days'][$i] = $one;
    }
    return $out;
}

function tt_time_to_hours($t) {
    if (!$t || !preg_match('/^\d{1,2}:\d{2}$/', $t)) return null;
    list($h,$m) = array_map('intval', explode(':', $t));
    if ($h > 47 || $m > 59) return null;
    return $h + ($m/60.0);
}

function tt_span_hours($in, $out) {
    $a = tt_time_to_hours($in);
    $b = tt_time_to_hours($out);
    if ($a===null || $b===null) return 0.0;
    $d = $b - $a;
    return $d > 0 ? round($d, 2) : 0.0;
}

function tt_calculate_totals($clean) {
    $col = ['reg'=>0,'vac'=>0,'sick'=>0,'pers'=>0,'hol'=>0,'unp'=>0,'ot'=>0,'daily_total'=>0];
    $by_day = [];

    $days = isset($clean['days']) && is_array($clean['days']) ? $clean['days'] : [];
    foreach ($days as $i => $d) {
        $reg = tt_span_hours($d['in1'] ?? '', $d['out1'] ?? '') + tt_span_hours($d['in2'] ?? '', $d['out2'] ?? '');
        $vac = (float)($d['vac'] ?? 0);
        $sick= (float)($d['sick']?? 0);
        $pers= (float)($d['pers']?? 0);
        $hol = (float)($d['hol'] ?? 0);
        $unp = (float)($d['unp'] ?? 0);
        $ot  = (float)($d['ot']  ?? 0);

        $day_total = round($reg + $vac + $sick + $pers + $hol + $unp + $ot, 2);

        $by_day[$i] = [
            'date' => $d['date'] ?? '',
            'reg'  => round($reg,2),
            'vac'  => $vac, 'sick'=>$sick, 'pers'=>$pers, 'hol'=>$hol, 'unp'=>$unp, 'ot'=>$ot,
            'total'=> $day_total,
        ];

        $col['reg']  += $reg;
        $col['vac']  += $vac;
        $col['sick'] += $sick;
        $col['pers'] += $pers;
        $col['hol']  += $hol;
        $col['unp']  += $unp;
        $col['ot']   += $ot;
        $col['daily_total'] += $day_total;
    }

    foreach ($col as $k=>$v) $col[$k] = round($v,2);

    return [
        'by_day'      => $by_day,
        'by_column'   => $col,
        'grand_total' => $col['daily_total'],
    ];
}


// Attempt to seed tt_data from existing post_content table when missing (legacy posts).
function tt_seed_data_if_missing($post_id){
    $data = get_post_meta($post_id, 'tt_data', true);
    if (is_array($data) && !empty($data['days'])) return $data;

    $html = get_post_field('post_content', $post_id);
    if (empty($html)) return $data;

    // Very simple parser: pull tbody rows and extract 9 <td> values per row in expected order.
    $days = [];
    if (preg_match('/<tbody>(.*?)<\/tbody>/is', $html, $m)){
        $tbody = $m[1];
        if (preg_match_all('/<tr>(.*?)<\/tr>/is', $tbody, $rows)){
            $i = 0;
            foreach ($rows[1] as $tr){
                if ($i>=14) break;
                if (preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $tr, $cells)){
                    $cells = array_map(function($c){
                        $c = wp_strip_all_tags($c);
                        $c = html_entity_decode($c, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
                        return trim($c);
                    }, $cells[1]);
                    // Expect: [date, reg, vac, sick, pers, hol, unp, ot, total]
                    $date = $cells[0] ?? '';
                    // Normalize date to Y-m-d if possible
                    $date_iso = '';
                    if ($date){
                        // Accept formats like n/j/Y
                        $dt = DateTime::createFromFormat('n/j/Y', $date);
                        if (!$dt) $dt = DateTime::createFromFormat('Y-m-d', $date);
                        if ($dt) $date_iso = $dt->format('Y-m-d');
                    }
                    $days[$i] = [
                        'date' => $date_iso,
                        'in1'  => '', 'out1'=>'', 'in2'=>'', 'out2'=>'',
                        'vac'  => floatval($cells[2] ?? 0),
                        'sick' => floatval($cells[3] ?? 0),
                        'pers' => floatval($cells[4] ?? 0),
                        'hol'  => floatval($cells[5] ?? 0),
                        'unp'  => floatval($cells[6] ?? 0),
                        'ot'   => floatval($cells[7] ?? 0),
                    ];
                    $i++;
                }
            }
        }
    }

    if (!empty($days)){
        // Try to infer a period_start as the first date
        $period_start = $days[0]['date'] ?? '';
        $data = [
            'period_start' => $period_start,
            'employee'     => get_post_meta($post_id, 'employee', true), // may not exist
            'supervisor'   => get_post_meta($post_id, 'supervisor', true), // may not exist
            'days'         => $days
        ];
        update_post_meta($post_id, 'tt_data', $data);
        if (function_exists('tt_calculate_totals')){
            $totals = tt_calculate_totals($data);
            update_post_meta($post_id, 'tt_totals', $totals);
        }
        return $data;
    }
    return $data;
}

// 4) Admin JS + styles for live preview
add_action('admin_enqueue_scripts', function ($hook) {
    global $post_type;
    if ($post_type !== 'timesheet') return;
    wp_enqueue_script('tt-admin-editor', plugins_url('assets/admin-timesheet.js', __FILE__), ['jquery'], '1.0', true);
    wp_enqueue_style('tt-admin-editor-css', plugins_url('assets/admin-timesheet.css', __FILE__), [], '1.0');
});



// ==== Admin: View & Print (preview + server-side PDF) ====

// 5) Metabox with View/Print buttons
add_action('add_meta_boxes', function () {
    add_meta_box(
        'tt_view_print',
        __('View & Print','oac'),
        function(\WP_Post $post){
            $permalink = get_permalink($post);
            $pdf_url = add_query_arg([ 'tt_pdf' => 1, 'post_id' => $post->ID, '_wpnonce' => wp_create_nonce('tt_pdf_'.$post->ID) ], home_url('/'));
            echo '<p>'.esc_html__('Open the formatted view to print or download a PDF.', 'oac').'</p>';
            echo '<p><a class="button button-primary" target="_blank" href="'.esc_url($permalink).'">'.esc_html__('Open Printable View','oac').'</a> ';
            echo '<a class="button" target="_blank" href="'.esc_url($pdf_url).'">'.esc_html__('Download PDF (server-side)','oac').'</a></p>';
            echo '<p class="description">'.esc_html__('The Printable View includes your existing client-side Print to PDF button; the server-side download uses Dompdf for consistent output.', 'oac').'</p>';
        },
        'timesheet',
        'side',
        'high'
    );
});

// 6) After update, show admin notice with quick links
add_filter('redirect_post_location', function($location){
    if (isset($_POST['post_type']) && $_POST['post_type']==='timesheet') {
        $location = add_query_arg('tt_updated', 1, $location);
    }
    return $location;
});
add_action('admin_notices', function(){
    if (!isset($_GET['tt_updated']) || get_current_screen()->post_type!=='timesheet') return;
    $post_id = isset($_GET['post']) ? intval($_GET['post']) : 0;
    if (!$post_id) return;
    $permalink = get_permalink($post_id);
    $pdf_url = add_query_arg([ 'tt_pdf' => 1, 'post_id' => $post_id, '_wpnonce' => wp_create_nonce('tt_pdf_'.$post_id) ], home_url('/'));
    echo '<div class="notice notice-success is-dismissible"><p><strong>'.esc_html__('Timesheet updated.', 'oac').'</strong> ';
    echo '<a target="_blank" href="'.esc_url($permalink).'">'.esc_html__('Open Printable View','oac').'</a> | ';
    echo '<a target="_blank" href="'.esc_url($pdf_url).'">'.esc_html__('Download PDF','oac').'</a>';
    echo '</p></div>';
});

// 7) Public handler to stream a PDF with Dompdf
add_action('template_redirect', function(){
    if ( !isset($_GET['tt_pdf'], $_GET['post_id']) ) return;
    $post_id = intval($_GET['post_id']);
    if (!$post_id) return;

    // Security: timesheets contain employee PII, so always require login + nonce,
    // and only let the employee, their assigned manager, or editors/admins download.
    if ( !is_user_logged_in() ) {
        auth_redirect(); // redirects to login and exits
    }
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'tt_pdf_'.$post_id)) {
        wp_die(__('Invalid PDF request.', 'oac'), '', ['response' => 403]);
    }

    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'timesheet') return;

    if (!tt_user_can_view_timesheet($post_id)) {
        wp_die(__('You are not allowed to download this timesheet.', 'oac'), '', ['response' => 403]);
    }

    // Build HTML using the same data the frontend uses
    $tt_data   = get_post_meta($post_id, 'tt_data', true);
    $tt_totals = get_post_meta($post_id, 'tt_totals', true);
    if (empty($tt_totals) && is_array($tt_data)) {
        $tt_totals = tt_calculate_totals($tt_data);
    }

    // Minimal printable HTML (you can style this more to match your frontend template)
    ob_start();
    ?>
    <html>
    <head>
        <meta charset="<?php echo esc_attr(get_bloginfo('charset')); ?>">
        <style>
            body{ font-family: sans-serif; font-size:12px; }
            h1{ font-size:18px; margin: 0 0 10px; }
            table{ width:100%; border-collapse: collapse; margin-top:10px; }
            th, td{ border:1px solid #ccc; padding:6px; text-align:left; }
            thead th{ background:#f5f5f5; }
            tfoot th{ background:#f5f5f5; }
            .meta { margin-bottom:10px; }
        </style>
    </head>
    <body>
        <h1><?php echo esc_html(get_the_title($post_id)); ?></h1>
        <div class="meta">
            <strong><?php esc_html_e('Employee','oac'); ?>:</strong> <?php echo esc_html($tt_data['employee'] ?? ''); ?> &nbsp;|&nbsp;
            <strong><?php esc_html_e('Supervisor','oac'); ?>:</strong> <?php echo esc_html($tt_data['supervisor'] ?? ''); ?> &nbsp;|&nbsp;
            <strong><?php esc_html_e('Period Start','oac'); ?>:</strong> <?php echo esc_html($tt_data['period_start'] ?? ''); ?>
        </div>
        <table>
            <thead>
                <tr>
                    <th><?php esc_html_e('Date','oac'); ?></th>
                    <th><?php esc_html_e('Regular','oac'); ?></th>
                    <th><?php esc_html_e('Vacation','oac'); ?></th>
                    <th><?php esc_html_e('Sick','oac'); ?></th>
                    <th><?php esc_html_e('Personal','oac'); ?></th>
                    <th><?php esc_html_e('Holiday','oac'); ?></th>
                    <th><?php esc_html_e('Unpaid','oac'); ?></th>
                    <th><?php esc_html_e('Overtime','oac'); ?></th>
                    <th><?php esc_html_e('Daily Total','oac'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $by_day = $tt_totals['by_day'] ?? [];
                foreach ($by_day as $r){
                    echo '<tr>';
                    $dt=DateTime::createFromFormat('Y-m-d',$r['date']??''); echo '<td>'.($dt?$dt->format('l, Y-m-d'):esc_html($r['date']??'')) . '</td>';
                    echo '<td>'.esc_html(number_format_i18n($r['reg'] ?? 0, 2)).'</td>';
                    echo '<td>'.esc_html(number_format_i18n($r['vac'] ?? 0, 2)).'</td>';
                    echo '<td>'.esc_html(number_format_i18n($r['sick'] ?? 0, 2)).'</td>';
                    echo '<td>'.esc_html(number_format_i18n($r['pers'] ?? 0, 2)).'</td>';
                    echo '<td>'.esc_html(number_format_i18n($r['hol'] ?? 0, 2)).'</td>';
                    echo '<td>'.esc_html(number_format_i18n($r['unp'] ?? 0, 2)).'</td>';
                    echo '<td>'.esc_html(number_format_i18n($r['ot'] ?? 0, 2)).'</td>';
                    echo '<td>'.esc_html(number_format_i18n($r['total'] ?? 0, 2)).'</td>';
                    echo '</tr>';
                }
                ?>
            </tbody>
            <tfoot>
                <tr>
                    <th><?php esc_html_e('Totals','oac'); ?></th>
                    <th><?php echo esc_html(number_format_i18n($tt_totals['by_column']['reg'] ?? 0, 2)); ?></th>
                    <th><?php echo esc_html(number_format_i18n($tt_totals['by_column']['vac'] ?? 0, 2)); ?></th>
                    <th><?php echo esc_html(number_format_i18n($tt_totals['by_column']['sick'] ?? 0, 2)); ?></th>
                    <th><?php echo esc_html(number_format_i18n($tt_totals['by_column']['pers'] ?? 0, 2)); ?></th>
                    <th><?php echo esc_html(number_format_i18n($tt_totals['by_column']['hol'] ?? 0, 2)); ?></th>
                    <th><?php echo esc_html(number_format_i18n($tt_totals['by_column']['unp'] ?? 0, 2)); ?></th>
                    <th><?php echo esc_html(number_format_i18n($tt_totals['by_column']['ot'] ?? 0, 2)); ?></th>
                    <th><?php echo esc_html(number_format_i18n($tt_totals['grand_total'] ?? 0, 2)); ?></th>
                </tr>
            </tfoot>
        </table>
        <?php echo tt_build_signature_block( $post_id, true ); ?>
    </body>
    </html>
    <?php
    $html = ob_get_clean();

    // Stream with Dompdf
    $dompdf = new Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();
    $slug = sanitize_title(get_the_title($post_id));
    $dompdf->stream($slug.'.pdf', ['Attachment' => true]);
    exit;
});



// NOTE: A duplicate tt_build_timesheet_html($post_id, $include_heading) function and an
// old the_content filter that called it used to live here. Both were dead code — the
// filter passed a post ID into the real tt_build_timesheet_html($clean, $totals) defined
// above (line ~737), which always returned '' for a non-array $clean, and that empty
// result was silently overwritten by tt_render_timesheet_content_from_meta() further
// down anyway. Removed for clarity; see tt_render_timesheet_content_from_meta() for the
// actual single-timesheet-view renderer.



/**
 * Can this user view a timesheet? True for the employee it belongs to, and for anyone
 * who can edit it: admins/editors, plus a tt_manager for their assigned employees
 * (granted via tt_manager_timesheet_caps).
 */
function tt_user_can_view_timesheet($timesheet_id, $user_id = null) {
    if (!$user_id) $user_id = get_current_user_id();
    if (!$user_id) return false;
    $post = get_post($timesheet_id);
    if (!$post || $post->post_type !== 'timesheet') return false;
    $emp_uid = intval(get_post_meta($post->ID, 'employee_user_id', true));
    if ($user_id === intval($post->post_author) || ($emp_uid && $user_id === $emp_uid)) return true;
    return user_can($user_id, 'edit_post', $post->ID);
}

// Require login for pages that render the time tracker form (shortcode) and for Timesheet views.
add_action('template_redirect', 'tt_require_login_for_timesheet_pages');
function tt_require_login_for_timesheet_pages(){
    if (is_admin()) return;

    // Always require login for Timesheet post type on the front-end
    if (is_singular('timesheet') || is_post_type_archive('timesheet')) {
        if (!is_user_logged_in()) {
            wp_redirect(wp_login_url(home_url(add_query_arg([], $GLOBALS['wp']->request))));
            exit;
        }
        // Logged in isn't enough: only the employee, their manager, or editors/admins may view.
        if (is_singular('timesheet') && !tt_user_can_view_timesheet(get_queried_object_id())) {
            wp_die('You do not have permission to view this timesheet.', 'Not allowed', ['response' => 403]);
        }
        return;
    }

    // Require login for any page/post containing our shortcode
    if (is_singular()) {
        global $post;
        if ($post && isset($post->post_content) && has_shortcode($post->post_content, 'time_tracker_form')) {
            if (!is_user_logged_in()) {
                // Reduce caching issues for this page
                if(!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
                if(!defined('DONOTCACHEDB')) define('DONOTCACHEDB', true);
                nocache_headers();
                wp_redirect(wp_login_url(get_permalink($post)));
                exit;
            }
        }
    }

    // If the front page is the timesheet page (common setup), require login there too.
    if (is_front_page() && !is_user_logged_in()) {
        if(!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        if(!defined('DONOTCACHEDB')) define('DONOTCACHEDB', true);
        nocache_headers();
        wp_redirect(wp_login_url(home_url('/')));
        exit;
    }
}

// The /timesheets/ archive (and its feed) would otherwise list every employee's sheets
// to any logged-in user. Limit non-editors to their own; managers use the Manager Dashboard.
add_action('pre_get_posts', 'tt_limit_timesheet_archive');
function tt_limit_timesheet_archive($query){
    if (is_admin() || !$query->is_main_query() || !$query->is_post_type_archive('timesheet')) return;
    if (current_user_can('edit_others_posts')) return;
    // Logged-out users are redirected by tt_require_login_for_timesheet_pages; author__in [0] matches nothing meanwhile.
    $query->set('author__in', [get_current_user_id()]);
}

// Keep timesheet URLs/titles (which contain employee names) out of public discovery endpoints.
add_filter('wp_sitemaps_post_types', function($post_types){
    unset($post_types['timesheet']);
    return $post_types;
});
add_filter('oembed_response_data', function($data, $post){
    return ($post && $post->post_type === 'timesheet') ? false : $data;
}, 10, 2);



// =============================================================================
// === Top-of-page nav bar (My Timesheets | Biweekly Entry | Manager Dashboard)
// =============================================================================

/**
 * Render the three-link nav bar shown at the top of the Biweekly Entry page.
 *
 * @param string $active Which link is the current page: 'entry' | 'mine' | 'manager'.
 * @return string HTML for the nav bar.
 */
function tt_render_nav_bar($active = 'entry'){
    if(!is_user_logged_in()) return '';

    // Resolve link targets.
    // - My Timesheets page URL is configured in Settings → Timesheets (the admin
    //   creates the page manually and pastes its URL there). If unset, we fall
    //   back to a sensible /my-timesheets/ guess so the link still works once
    //   that page exists.
    $mine_url = get_option('tt_my_timesheets_url', '');
    if(!$mine_url){
        $mine_url = home_url('/my-timesheets/');
    }
    $entry_url   = get_permalink() ?: home_url('/');
    $manager_url = admin_url('edit.php?post_type=timesheet&page=tt-manager-inbox');

    $is_manager = function_exists('tt_user_is_manager') ? tt_user_is_manager() : false;

    $links = [
        'mine'    => ['url' => $mine_url,    'label' => 'My Timesheets',           'show' => true],
        'entry'   => ['url' => $entry_url,   'label' => 'Biweekly Employee Entry', 'show' => true],
        'manager' => ['url' => $manager_url, 'label' => 'Manager Dashboard',       'show' => $is_manager],
    ];

    ob_start();
    echo '<nav class="tt-nav" aria-label="Time Tracker navigation">';
    $first = true;
    foreach($links as $key => $link){
        if(!$link['show']) continue;
        if(!$first) echo '<span class="tt-nav-sep" aria-hidden="true">|</span>';
        $first = false;
        $is_active = ($key === $active);
        $class = 'tt-nav-link' . ($is_active ? ' tt-nav-link--active' : '');
        if($is_active){
            echo '<span class="'.esc_attr($class).'" aria-current="page">'.esc_html($link['label']).'</span>';
        } else {
            echo '<a class="'.esc_attr($class).'" href="'.esc_url($link['url']).'">'.esc_html($link['label']).'</a>';
        }
    }
    echo '</nav>';
    return ob_get_clean();
}

/**
 * Settings page: Timesheets → Settings.
 * Lets the admin set the URL of the front-end "My Timesheets" page they create manually.
 */
add_action('admin_menu', 'tt_add_settings_submenu');
function tt_add_settings_submenu(){
    add_submenu_page(
        'edit.php?post_type=timesheet',
        'Time Tracker Settings',
        'Settings',
        'manage_options',
        'tt-settings',
        'tt_render_settings_page'
    );
}

add_action('admin_init', 'tt_register_settings');
function tt_register_settings(){
    register_setting('tt_settings_group', 'tt_my_timesheets_url', [
        'type'              => 'string',
        'sanitize_callback' => 'esc_url_raw',
        'default'           => '',
    ]);
}

function tt_render_settings_page(){
    if(!current_user_can('manage_options')) return;
    ?>
    <div class="wrap">
        <h1>Time Tracker Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields('tt_settings_group'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="tt_my_timesheets_url">My Timesheets page URL</label></th>
                    <td>
                        <input type="url"
                               id="tt_my_timesheets_url"
                               name="tt_my_timesheets_url"
                               class="regular-text"
                               value="<?php echo esc_attr(get_option('tt_my_timesheets_url', '')); ?>"
                               placeholder="<?php echo esc_attr(home_url('/my-timesheets/')); ?>">
                        <p class="description">
                            Full URL of the page where you placed the <code>[my_timesheets]</code> shortcode.
                            Used by the nav bar at the top of the Biweekly Entry page.
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}


// Employee dashboard: list current user's timesheets
add_shortcode('my_timesheets', 'tt_my_timesheets_shortcode');
function tt_my_timesheets_shortcode($atts = []){
    if(!is_user_logged_in()){
        $login_url = wp_login_url(get_permalink());
        return '<p><strong>Please log in.</strong> <a href="'.esc_url($login_url).'">Log in</a></p>';
    }
    $atts = shortcode_atts([
        'form_url' => home_url('/'),
        'per_page' => 10,
    ], $atts);

    $per_page   = max(1, intval($atts['per_page']));
    $paged      = max(1, intval($_GET['ts_page'] ?? 1));
    $form_url   = $atts['form_url'];
    $current_url = get_permalink() ?: home_url('/');

    $q = new WP_Query([
        'post_type'      => 'timesheet',
        'author'         => get_current_user_id(),
        'post_status'    => ['draft','publish'],
        'posts_per_page' => $per_page,
        'paged'          => $paged,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);

    ob_start();
    echo '<h3>My Timesheets</h3>';
    if(!$q->have_posts()){
        echo '<p>No timesheets yet.</p>';
        return ob_get_clean();
    }

    $total_pages = intval($q->max_num_pages);

    echo '<table class="widefat striped tt-timesheets-table"><thead><tr><th>Pay Period</th><th>Status</th><th>Last Updated</th><th>Actions</th></tr></thead><tbody>';
    while($q->have_posts()){ $q->the_post();
        $id = get_the_ID();
        $data = get_post_meta($id, 'tt_data', true);
        $state = get_post_meta($id, 'tt_state', true);
        if(!$state){ $state = (get_post_status($id)==='draft') ? 'draft' : 'submitted'; }
        $start = '';
        if(is_array($data) && !empty($data['period_start'])) $start = $data['period_start'];
        $label = $start ? esc_html($start) : esc_html(get_the_date('Y-m-d'));
        $end_label = '';
        if($start){
            try{ $dt = DateTime::createFromFormat('Y-m-d', $start); if($dt){ $dt->modify('+13 days'); $end_label = $dt->format('Y-m-d'); } }catch(Exception $e){}
        }
        $period       = $start ? ($label.' – '.esc_html($end_label)) : esc_html(get_the_title());
        $continue_url = add_query_arg(['pay_period'=>$start, 'timesheet_id'=>$id], $form_url);
        $view_url     = get_permalink($id);

        // Status badge colour
        $badge_color = ['draft'=>'#888','submitted'=>'#0073aa','approved'=>'#46b450','needs_changes'=>'#d54e21'];
        $color = $badge_color[$state] ?? '#888';

        echo '<tr>';
        echo '<td>'.$period.'</td>';
        echo '<td><span style="display:inline-block;padding:2px 8px;border-radius:999px;background:'.esc_attr($color).';color:#fff;font-size:0.85em;">'.esc_html(ucwords(str_replace('_',' ', $state))).'</span></td>';
        echo '<td>'.esc_html(get_the_modified_date('Y-m-d g:ia')).'</td>';
        echo '<td>';
        if($start){
            echo '<a class="button" href="'.esc_url($continue_url).'">Continue</a> ';
        }
        echo '<a class="button" href="'.esc_url($view_url).'">View</a>';
        echo '</td>';
        echo '</tr>';
    }
    wp_reset_postdata();
    echo '</tbody></table>';

    // Pagination
    if($total_pages > 1){
        echo '<div class="tt-pagination" style="margin-top:10px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">';
        for($p = 1; $p <= $total_pages; $p++){
            $url    = add_query_arg('ts_page', $p, $current_url);
            $active = ($p === $paged);
            $style  = $active
                ? 'display:inline-block;padding:4px 10px;border-radius:4px;background:#0073aa;color:#fff;text-decoration:none;font-weight:bold;'
                : 'display:inline-block;padding:4px 10px;border-radius:4px;background:#f0f0f0;color:#333;text-decoration:none;border:1px solid #ccc;';
            echo '<a href="'.esc_url($url).'" style="'.esc_attr($style).'" aria-current="'.($active?'page':'false').'">'.esc_html($p).'</a>';
        }
        echo '<span style="color:#666;font-size:0.9em;">Page '.esc_html($paged).' of '.esc_html($total_pages).'</span>';
        echo '</div>';
    }

    return ob_get_clean();
}



add_action('wp_ajax_tt_get_my_timesheet', 'tt_ajax_get_my_timesheet');
function tt_ajax_get_my_timesheet(){
    check_ajax_referer('tt_ajax_nonce','nonce');
    if(!is_user_logged_in()){
        wp_send_json_error(['message'=>'not_logged_in'], 403);
    }

    $timesheet_id = intval($_POST['timesheet_id'] ?? 0);
    if($timesheet_id){
        $p = get_post($timesheet_id);
        if(!$p || $p->post_type !== 'timesheet'){
            wp_send_json_error(['message'=>'not_found'], 404);
        }
        $uid = get_current_user_id();
        $emp_uid = intval(get_post_meta($timesheet_id,'employee_user_id',true));
        if(intval($p->post_author) !== $uid && $emp_uid !== $uid){
            wp_send_json_error(['message'=>'not_allowed'], 403);
        }
        $data  = get_post_meta($timesheet_id,'tt_data',true);
        $state = get_post_meta($timesheet_id,'tt_state',true);
        if(!$state){ $state = (get_post_status($timesheet_id)==='draft') ? 'draft' : 'submitted'; }
        wp_send_json_success([
            'found'=>true,
            'timesheet_id'=>$timesheet_id,
            'state'=>$state,
            'data'=>$data,
        ]);
    }

    $pay_period = sanitize_text_field($_POST['pay_period'] ?? '');
    if(!$pay_period){
        wp_send_json_error(['message'=>'missing_pay_period'], 400);
    }

    $q = new WP_Query([
        'post_type' => 'timesheet',
        'author' => get_current_user_id(),
        'post_status' => ['draft','publish'],
        'posts_per_page' => 1,
        'meta_key' => 'tt_period_start',
        'meta_value' => $pay_period,
    ]);

    if(!$q->have_posts()){
        wp_send_json_success(['found'=>false]);
    }

    $q->the_post();
    $id = get_the_ID();
    $data = get_post_meta($id,'tt_data',true);
    $state = get_post_meta($id,'tt_state',true);
    if(!$state){ $state = (get_post_status($id)==='draft') ? 'draft' : 'submitted'; }
    wp_reset_postdata();

    wp_send_json_success([
        'found' => true,
        'timesheet_id' => $id,
        'state' => $state,
        'data' => $data,
    ]);
}



// ── Shared helper: build one page of the "My Timesheets" table rows + pagination bar ──
function tt_build_timesheets_table_html($form_url, $paged = 1, $per_page = 10){
    $paged    = max(1, intval($paged));
    $per_page = max(1, intval($per_page));

    $q = new WP_Query([
        'post_type'      => 'timesheet',
        'author'         => get_current_user_id(),
        'post_status'    => ['draft','publish'],
        'posts_per_page' => $per_page,
        'paged'          => $paged,
        'orderby'        => 'modified',
        'order'          => 'DESC',
    ]);

    if(!$q->have_posts()) return ['html'=>'', 'total_pages'=>0, 'paged'=>$paged];

    $total_pages = intval($q->max_num_pages);
    $badge_color = ['draft'=>'#888','submitted'=>'#0073aa','approved'=>'#46b450','needs_changes'=>'#d54e21'];

    ob_start();
    echo '<table class="widefat striped tt-timesheets-table"><thead><tr><th>Pay Period</th><th>Status</th><th>Last Updated</th><th>Actions</th></tr></thead><tbody>';
    while($q->have_posts()){
        $q->the_post();
        $id    = get_the_ID();
        $start = get_post_meta($id,'tt_period_start', true);
        // Fallback: check tt_data for period_start
        if(!$start){
            $d = get_post_meta($id,'tt_data',true);
            if(is_array($d) && !empty($d['period_start'])) $start = $d['period_start'];
        }
        $state = get_post_meta($id,'tt_state', true);
        if(!$state){ $state = (get_post_status($id)==='draft') ? 'draft' : 'submitted'; }
        $end = '';
        if($start){
            $dt = DateTime::createFromFormat('Y-m-d', $start);
            if($dt){ $dt->modify('+13 days'); $end = $dt->format('Y-m-d'); }
        }
        $period       = $start ? (esc_html($start).' – '.esc_html($end)) : esc_html(get_the_title());
        $continue_url = $start ? add_query_arg(['pay_period'=>$start,'timesheet_id'=>$id], $form_url) : $form_url;
        $color        = $badge_color[$state] ?? '#888';
        echo '<tr>';
        echo '<td>'.$period.'</td>';
        echo '<td><span style="display:inline-block;padding:2px 8px;border-radius:999px;background:'.esc_attr($color).';color:#fff;font-size:0.85em;">'.esc_html(ucwords(str_replace('_',' ',$state))).'</span></td>';
        echo '<td>'.esc_html(get_the_modified_date('Y-m-d g:ia')).'</td>';
        echo '<td>';
        if($start){ echo '<a class="button" href="'.esc_url($continue_url).'">Continue</a> '; }
        echo '<a class="button" href="'.esc_url(get_permalink($id)).'">View</a>';
        echo '</td>';
        echo '</tr>';
    }
    wp_reset_postdata();
    echo '</tbody></table>';

    // Pagination bar
    if($total_pages > 1){
        echo '<div class="tt-inline-pagination" style="margin-top:8px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">';
        // Prev
        if($paged > 1){
            echo '<button class="button tt-ts-page" data-page="'.($paged-1).'" style="padding:4px 10px;">&laquo; Prev</button>';
        }
        for($p = 1; $p <= $total_pages; $p++){
            $active = ($p === $paged);
            $style  = $active
                ? 'padding:4px 10px;border-radius:4px;background:#0073aa;color:#fff;border:none;font-weight:bold;cursor:default;'
                : 'padding:4px 10px;border-radius:4px;background:#f0f0f0;color:#333;border:1px solid #ccc;cursor:pointer;';
            echo '<button class="button tt-ts-page" data-page="'.esc_attr($p).'" style="'.esc_attr($style).'" '.($active?'disabled':'').'>'.esc_html($p).'</button>';
        }
        // Next
        if($paged < $total_pages){
            echo '<button class="button tt-ts-page" data-page="'.($paged+1).'" style="padding:4px 10px;">Next &raquo;</button>';
        }
        echo '<span style="color:#666;font-size:0.9em;margin-left:4px;">Page '.esc_html($paged).' of '.esc_html($total_pages).'</span>';
        echo '</div>';
    }

    return ['html'=>ob_get_clean(), 'total_pages'=>$total_pages, 'paged'=>$paged];
}

// ── Inline render (called from the employee form shortcode output) ──
function tt_render_my_timesheets_inline($form_url){
    $result = tt_build_timesheets_table_html($form_url, 1, 5);
    if(!$result['html']) return '';

    $nonce = wp_create_nonce('tt_ajax_nonce');
    ob_start();
    echo '<div class="tt-my-timesheets" style="margin:16px 0;" ';
    echo '     data-form-url="'.esc_attr($form_url).'" ';
    echo '     data-nonce="'.esc_attr($nonce).'" ';
    echo '     data-per-page="5">';
    echo '<h4>My Timesheets</h4>';
    echo '<div class="tt-timesheets-body">'.$result['html'].'</div>';
    echo '</div>';
    // Inline script: delegate pagination clicks to AJAX
    echo '<script>
(function(){
    document.addEventListener("click", function(e){
        var btn = e.target.closest(".tt-ts-page");
        if(!btn) return;
        var wrap = btn.closest(".tt-my-timesheets");
        if(!wrap) return;
        e.preventDefault();
        var page     = btn.getAttribute("data-page");
        var formUrl  = wrap.getAttribute("data-form-url");
        var nonce    = wrap.getAttribute("data-nonce");
        var perPage  = wrap.getAttribute("data-per-page") || 10;
        var body     = wrap.querySelector(".tt-timesheets-body");
        body.style.opacity = "0.5";
        var fd = new FormData();
        fd.append("action",   "tt_paginate_my_timesheets");
        fd.append("nonce",    nonce);
        fd.append("paged",    page);
        fd.append("per_page", perPage);
        fd.append("form_url", formUrl);
        fetch(TT_AJAX.ajax_url, {method:"POST", body:fd, credentials:"same-origin"})
            .then(function(r){ return r.json(); })
            .then(function(res){
                if(res.success && res.data && res.data.html){
                    body.innerHTML = res.data.html;
                }
                body.style.opacity = "1";
            })
            .catch(function(){ body.style.opacity = "1"; });
    });
})();
</script>';
    return ob_get_clean();
}

// ── AJAX handler for inline pagination ──
add_action('wp_ajax_tt_paginate_my_timesheets', 'tt_ajax_paginate_my_timesheets');
function tt_ajax_paginate_my_timesheets(){
    check_ajax_referer('tt_ajax_nonce','nonce');
    if(!is_user_logged_in()){ wp_send_json_error('not_logged_in', 403); }
    $paged    = max(1, intval($_POST['paged']   ?? 1));
    $per_page = max(1, intval($_POST['per_page'] ?? 10));
    $form_url = esc_url_raw($_POST['form_url']  ?? home_url('/'));
    $result   = tt_build_timesheets_table_html($form_url, $paged, $per_page);
    wp_send_json_success(['html'=>$result['html'], 'paged'=>$result['paged'], 'total_pages'=>$result['total_pages']]);
}



// Render Timesheet posts from saved meta (tt_data + tt_totals) so front-end/PDF never relies on post_content.
add_filter('the_content', 'tt_render_timesheet_content_from_meta', 20);
function tt_render_timesheet_content_from_meta($content){
    if(!is_singular('timesheet')) return $content;
    global $post;
    if(!$post || $post->post_type !== 'timesheet') return $content;

    $tt_data = get_post_meta($post->ID, 'tt_data', true);
    if(!is_array($tt_data) || empty($tt_data['days'])){
        return $content; // nothing to render
    }

    $state = get_post_meta($post->ID,'tt_state',true);
    if(!$state){ $state = (get_post_status($post->ID)==='draft') ? 'draft' : 'submitted'; }

    $tt_totals = get_post_meta($post->ID, 'tt_totals', true);
    if(!is_array($tt_totals) || empty($tt_totals)){
        if(function_exists('tt_calculate_totals')){
            $tt_totals = tt_calculate_totals($tt_data);
        } else {
            $tt_totals = [];
        }
    }

    $uid = get_current_user_id();
    $emp_uid = intval(get_post_meta($post->ID,'employee_user_id',true));
    $is_employee_owner = ($uid && ($uid===intval($post->post_author) || ($emp_uid && $uid===$emp_uid)));

    // Manager mapping lives on employee user: tt_manager_user_id
    $manager_id = $emp_uid ? intval(get_user_meta($emp_uid,'tt_manager_user_id',true)) : 0;
    $is_manager_for_employee = ($uid && $manager_id && $uid===$manager_id);

    $actions_html = '<div class="tt-actions" style="margin:12px 0;padding:10px;border:1px solid #ddd;border-radius:8px;background:#fafafa;">';
    $actions_html .= '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">';
    $actions_html .= '<strong>Status:</strong> <span class="tt-status-badge" style="padding:2px 8px;border-radius:999px;border:1px solid #ccc;background:#fff;">'.esc_html(ucwords(str_replace('_',' ',$state))).'</span>';

    // Employee: Withdraw if submitted and not approved
    if($is_employee_owner && $state==='submitted'){
        $withdraw_url = wp_nonce_url(admin_url('admin-post.php?action=tt_employee_withdraw&timesheet_id='.$post->ID), 'tt_employee_withdraw_'.$post->ID);
        $actions_html .= ' <a class="button" href="'.esc_url($withdraw_url).'" onclick="return confirm(\'Withdraw submission and return to Draft?\');">Withdraw Submission</a>';
    }

    // Manager/Admin: Approve / Request Changes (only when submitted or needs_changes)
    if( (current_user_can('manage_options') || current_user_can('edit_others_posts') || $is_manager_for_employee) && in_array($state, ['submitted','needs_changes'], true) ){
        $approve_url = wp_nonce_url(admin_url('admin-post.php?action=tt_manager_action&do=approve&timesheet_id='.$post->ID), 'tt_manager_action_'.$post->ID);
        $changes_url = wp_nonce_url(admin_url('admin-post.php?action=tt_manager_action&do=request_changes&timesheet_id='.$post->ID), 'tt_manager_action_'.$post->ID);
        $actions_html .= ' <a class="button button-primary" href="'.esc_url($approve_url).'" onclick="return confirm(\'Approve this timesheet?\');">Approve</a>';
        $actions_html .= ' <a class="button" href="'.esc_url($changes_url).'" onclick="return confirm(\'Send back to employee for changes?\');">Request Changes</a>';
    }

    $actions_html .= '</div></div>';

    $title = '<h2 style="margin-top:0;">'.esc_html(get_the_title($post)).'</h2>';
    $table_html = function_exists('tt_build_timesheet_html') ? tt_build_timesheet_html($tt_data, $tt_totals) : '';
    if(!$table_html){
        // Fallback: show raw JSON if builder missing (should not happen)
        $table_html = '<pre>'.esc_html(wp_json_encode($tt_data)).'</pre>';
    }

    $sig_html = function_exists('tt_build_signature_block') ? tt_build_signature_block( get_the_ID() ) : '';

    return $actions_html . $title . $table_html . $sig_html;
}



// === Manager mapping (employee -> manager) stored on user meta: tt_manager_user_id ===
add_action('show_user_profile','tt_user_profile_manager_field');
add_action('edit_user_profile','tt_user_profile_manager_field');
function tt_user_profile_manager_field($user){
    if(!current_user_can('manage_options')) return; // only admins set mapping
    $current = intval(get_user_meta($user->ID,'tt_manager_user_id',true));
    $managers = get_users(['role__in'=>['administrator','editor'], 'orderby'=>'display_name','order'=>'ASC']);
    echo '<h3>Time Tracker</h3>';
    echo '<table class="form-table"><tr><th><label for="tt_manager_user_id">Manager</label></th><td>';
    echo '<select name="tt_manager_user_id" id="tt_manager_user_id">';
    echo '<option value="0">— None —</option>';
    foreach($managers as $m){
        printf('<option value="%d" %s>%s</option>', intval($m->ID), selected($current, intval($m->ID), false), esc_html($m->display_name));
    }
    echo '</select>';
    echo '<p class="description">Assign a manager for this employee (used for approvals).</p>';
    echo '</td></tr></table>';
}
add_action('personal_options_update','tt_user_profile_manager_field_save');
add_action('edit_user_profile_update','tt_user_profile_manager_field_save');
function tt_user_profile_manager_field_save($user_id){
    if(!current_user_can('manage_options')) return;
    update_user_meta($user_id,'tt_manager_user_id', intval($_POST['tt_manager_user_id'] ?? 0));
}



// === Employee + Manager actions ===
add_action('admin_post_tt_employee_withdraw','tt_employee_withdraw_submission');
function tt_employee_withdraw_submission(){
    if(!is_user_logged_in()) wp_die('Not logged in');
    $tsid = intval($_GET['timesheet_id'] ?? 0);
    if(!$tsid) wp_die('Missing timesheet');
    if(!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'tt_employee_withdraw_'.$tsid)) wp_die('Security check failed');
    $p = get_post($tsid);
    if(!$p || $p->post_type!=='timesheet') wp_die('Not found');
    $uid = get_current_user_id();
    $emp_uid = intval(get_post_meta($tsid,'employee_user_id',true));
    if(intval($p->post_author)!==$uid && $emp_uid!==$uid) wp_die('Not allowed');
    $state = get_post_meta($tsid,'tt_state',true);
    if($state === 'approved') wp_die('Already approved; cannot withdraw.');
    if($state !== 'submitted') wp_die('Only submitted timesheets can be withdrawn.');
    update_post_meta($tsid,'tt_state','draft');
    // Clear signatures — employee will re-certify when they resubmit
    delete_post_meta($tsid, 'tt_employee_signed');
    delete_post_meta($tsid, 'tt_employee_signed_at');
    delete_post_meta($tsid, 'tt_employee_signed_by');
    // keep post published so manager can still access history if needed
    wp_safe_redirect(wp_get_referer() ?: get_permalink($tsid));
    exit;
}

add_action('admin_post_tt_manager_action','tt_manager_action_handler');
function tt_manager_action_handler(){
    if(!is_user_logged_in()) wp_die('Not logged in');
    $tsid = intval($_GET['timesheet_id'] ?? 0);
    $action = sanitize_text_field($_GET['do'] ?? '');
    if(!$tsid || !$action) wp_die('Missing data');
    $_nonce = $_GET['_wpnonce'] ?? '';
    $nonce_ok = wp_verify_nonce($_nonce, 'tt_manager_action_'.$action.'_'.$tsid) || wp_verify_nonce($_nonce, 'tt_manager_action_'.$tsid);
    if(!$nonce_ok) wp_die('Security check failed');
    $p = get_post($tsid);
    if(!$p || $p->post_type!=='timesheet') wp_die('Not found');
    $emp_uid = intval(get_post_meta($tsid,'employee_user_id',true));
    $manager_id = intval(get_user_meta($emp_uid,'tt_manager_user_id',true));
    $uid = get_current_user_id();
    $can_override = current_user_can('manage_options');
    if(!$can_override && $manager_id !== $uid) wp_die('Not allowed');

    if($action === 'approve'){
        update_post_meta($tsid,'tt_state','approved');
        // Record manager signature
        update_post_meta($tsid, 'tt_manager_signed',    1);
        update_post_meta($tsid, 'tt_manager_signed_at', current_time('mysql', true)); // UTC
        update_post_meta($tsid, 'tt_manager_signed_by', $uid);
    } elseif($action === 'request_changes'){
        update_post_meta($tsid,'tt_state','needs_changes');
        // Clear both signatures — employee must re-certify after making changes
        delete_post_meta($tsid, 'tt_employee_signed');
        delete_post_meta($tsid, 'tt_employee_signed_at');
        delete_post_meta($tsid, 'tt_employee_signed_by');
        delete_post_meta($tsid, 'tt_manager_signed');
        delete_post_meta($tsid, 'tt_manager_signed_at');
        delete_post_meta($tsid, 'tt_manager_signed_by');
    } else {
        wp_die('Unknown action');
    }

    wp_safe_redirect(wp_get_referer() ?: get_permalink($tsid));
    exit;
}



// Manager Inbox (shortcode)
add_shortcode('manager_timesheets','tt_manager_inbox_shortcode');
function tt_manager_inbox_shortcode($atts=[]){
    if(!is_user_logged_in()){
        $login_url = wp_login_url(get_permalink());
        return '<p><strong>Please log in.</strong> <a href="'.esc_url($login_url).'">Log in</a></p>';
    }
    $uid = get_current_user_id();
    // Find employees assigned to this manager
    $emps = get_users([
        'meta_key' => 'tt_manager_user_id',
        'meta_value' => $uid,
        'fields' => 'ID',
        'number' => 999,
    ]);
    if(empty($emps)) return '<p>No employees assigned.</p>';

    $q = new WP_Query([
        'post_type' => 'timesheet',
        'post_status' => ['publish','draft'],
        'posts_per_page' => 50,
        'meta_query' => [
            ['key'=>'tt_state','value'=>'submitted','compare'=>'='],
            ['key'=>'employee_user_id','value'=>$emps,'compare'=>'IN'],
        ],
        'orderby' => 'modified',
        'order' => 'DESC',
    ]);

    ob_start();
    echo '<h3>Manager Inbox</h3>';
    if(!$q->have_posts()){
        echo '<p>No submitted timesheets waiting for approval.</p>';
        return ob_get_clean();
    }
    echo '<table class="widefat striped"><thead><tr><th>Employee</th><th>Pay Period</th><th>Status</th><th>Last Updated</th><th>Actions</th></tr></thead><tbody>';
    while($q->have_posts()){ $q->the_post();
        $id = get_the_ID();
        $emp_uid = intval(get_post_meta($id,'employee_user_id',true));
        $emp = $emp_uid ? get_user_by('id',$emp_uid) : null;
        $start = get_post_meta($id,'tt_period_start',true);
        $end='';
        if($start){ $dt=DateTime::createFromFormat('Y-m-d',$start); if($dt){ $dt->modify('+13 days'); $end=$dt->format('Y-m-d'); } }
        $state = get_post_meta($id,'tt_state',true);
        $approve_url = wp_nonce_url(admin_url('admin-post.php?action=tt_manager_action&do=approve&timesheet_id='.$id), 'tt_manager_action_approve_'.$id);
        $chg_url = wp_nonce_url(admin_url('admin-post.php?action=tt_manager_action&do=request_changes&timesheet_id='.$id), 'tt_manager_action_request_changes_'.$id);
        echo '<tr>';
        echo '<td>'.esc_html($emp ? $emp->display_name : '—').'</td>';
        echo '<td>'.esc_html($start ? ($start.' – '.$end) : get_the_title()).'</td>';
        echo '<td>'.esc_html($state).'</td>';
        echo '<td>'.esc_html(get_the_modified_date('Y-m-d g:ia')).'</td>';
        echo '<td><a class="button" href="'.esc_url(get_permalink($id)).'">View</a> ';
        echo '<a class="button button-primary" href="'.esc_url($approve_url).'">Approve</a> ';
        echo '<a class="button" href="'.esc_url($chg_url).'">Request Changes</a></td>';
        echo '</tr>';
    }
    wp_reset_postdata();
    echo '</tbody></table>';
    return ob_get_clean();
}

// WP-Admin submenu for managers
add_action('admin_menu','tt_add_manager_inbox_menu');
function tt_add_manager_inbox_menu(){
    add_submenu_page('edit.php?post_type=timesheet','Manager Inbox','Manager Inbox','read','tt-manager-inbox','tt_manager_inbox_admin_page');
}
function tt_manager_inbox_admin_page(){
    if(!is_user_logged_in()) return;
    echo '<div class="wrap">';
    echo do_shortcode('[manager_timesheets]');
    echo '</div>';
}


// =============================================================================
// === USER ROLES: Custom 'tt_manager' Role ====================================
// =============================================================================

register_activation_hook(__FILE__, 'tt_add_manager_role');
function tt_add_manager_role() {
    // Remove first to avoid stale capability sets on re-activation
    remove_role('tt_manager');
    add_role('tt_manager', 'Timesheet Manager', [
        'read'                   => true,
        'edit_posts'             => false,
        'delete_posts'           => false,
        // Custom caps used by this plugin
        'tt_view_team_timesheets'   => true,
        'tt_approve_timesheets'     => true,
        'tt_edit_timesheets'        => true,
    ]);
}

// Also register the role on init in case it was removed or plugin was updated
add_action('init', 'tt_ensure_manager_role');
function tt_ensure_manager_role() {
    if (!get_role('tt_manager')) {
        tt_add_manager_role();
    }
}

register_deactivation_hook(__FILE__, 'tt_remove_manager_role');
function tt_remove_manager_role() {
    remove_role('tt_manager');
}

/**
 * Central capability check: can this user act as a manager?
 * True for admins, editors (legacy), and our new tt_manager role.
 */
function tt_user_is_manager($user_id = null) {
    if (!$user_id) $user_id = get_current_user_id();
    if (!$user_id) return false;
    $user = get_userdata($user_id);
    if (!$user) return false;
    return (
        user_can($user_id, 'manage_options') ||
        user_can($user_id, 'edit_others_posts') ||
        in_array('tt_manager', (array)$user->roles, true)
    );
}

/**
 * A manager can approve/edit if they are the assigned manager for the employee
 * or if they are a site admin.
 */
function tt_user_can_manage_timesheet($timesheet_id, $user_id = null) {
    if (!$user_id) $user_id = get_current_user_id();
    if (!$user_id) return false;
    if (user_can($user_id, 'manage_options')) return true;
    $emp_uid    = intval(get_post_meta($timesheet_id, 'employee_user_id', true));
    $manager_id = $emp_uid ? intval(get_user_meta($emp_uid, 'tt_manager_user_id', true)) : 0;
    return ($manager_id && $manager_id === $user_id);
}

// Grant tt_manager role users access to admin dashboard (read-only by default in WP)
add_filter('user_has_cap', 'tt_manager_admin_access', 10, 3);
function tt_manager_admin_access($allcaps, $caps, $args) {
    $user = wp_get_current_user();
    if (!$user || !in_array('tt_manager', (array)$user->roles, true)) return $allcaps;
    // Allow managers to log in to /wp-admin/ and read
    $allcaps['read'] = true;
    return $allcaps;
}

// Redirect managers away from the main dashboard to the timesheet manager inbox
add_action('admin_init', 'tt_manager_redirect_dashboard');
function tt_manager_redirect_dashboard() {
    $user = wp_get_current_user();
    if (!$user || !in_array('tt_manager', (array)$user->roles, true)) return;
    $screen = get_current_screen();
    if ($screen && $screen->id === 'dashboard') {
        wp_redirect(admin_url('edit.php?post_type=timesheet&page=tt-manager-inbox'));
        exit;
    }
}

// Hide irrelevant admin menu items for tt_manager role
add_action('admin_menu', 'tt_manager_clean_admin_menu', 999);
function tt_manager_clean_admin_menu() {
    $user = wp_get_current_user();
    if (!$user || !in_array('tt_manager', (array)$user->roles, true)) return;
    // Remove everything except our pages
    remove_menu_page('index.php');           // Dashboard
    remove_menu_page('edit.php');            // Posts
    remove_menu_page('upload.php');          // Media
    remove_menu_page('edit.php?post_type=page'); // Pages
    remove_menu_page('edit-comments.php');   // Comments
    remove_menu_page('themes.php');          // Appearance
    remove_menu_page('plugins.php');         // Plugins
    remove_menu_page('users.php');           // Users
    remove_menu_page('tools.php');           // Tools
    remove_menu_page('options-general.php'); // Settings
}


// =============================================================================
// === ADMIN: Assign Manager role users as managers on user profile =============
// =============================================================================

// Extend the manager dropdown to include tt_manager role users (not just editors/admins)
add_action('show_user_profile', 'tt_user_profile_manager_field_v2', 11);
add_action('edit_user_profile', 'tt_user_profile_manager_field_v2', 11);
function tt_user_profile_manager_field_v2($user) {
    // Remove the old hook output by hooking later; this replaces it
    // (The original function still runs at priority 10, so we output nothing extra here —
    //  instead we patch the manager query below via a filter.)
}

// Override the managers query in the original profile field to include tt_manager role
add_filter('pre_get_users', 'tt_expand_manager_query');
function tt_expand_manager_query($query) {
    // Only intercept the specific query in tt_user_profile_manager_field
    // We detect it by checking role__in contains 'administrator'
    if (!is_admin()) return $query;
    $role_in = $query->get('role__in');
    if (is_array($role_in) && in_array('administrator', $role_in, true) && in_array('editor', $role_in, true)) {
        $query->set('role__in', ['administrator', 'editor', 'tt_manager']);
    }
    return $query;
}


// =============================================================================
// === MANAGER DASHBOARD: Full history view with filters =======================
// =============================================================================

// Replace the old manager inbox admin page with a richer one
remove_action('admin_menu', 'tt_add_manager_inbox_menu'); // remove old registration

add_action('admin_menu', 'tt_add_manager_inbox_menu_v2');
function tt_add_manager_inbox_menu_v2() {
    // Determine capability: admins and tt_managers can access
    $cap = 'read'; // tt_manager has 'read'; we check role manually inside the page
    add_submenu_page(
        'edit.php?post_type=timesheet',
        'Manager Dashboard',
        'Manager Dashboard',
        $cap,
        'tt-manager-inbox',
        'tt_manager_dashboard_page'
    );
}

function tt_manager_dashboard_page() {
    if (!tt_user_is_manager()) {
        echo '<div class="wrap"><p>You do not have permission to view this page.</p></div>';
        return;
    }

    $uid = get_current_user_id();
    $is_admin = current_user_can('manage_options');

    // Find employees this manager is responsible for
    if ($is_admin) {
        // Admins can see all employees
        $emp_ids = get_users(['fields' => 'ID', 'number' => 999]);
    } else {
        $emp_ids = get_users([
            'meta_key'   => 'tt_manager_user_id',
            'meta_value' => $uid,
            'fields'     => 'ID',
            'number'     => 999,
        ]);
    }

    // --- Filter values ---
    $filter_emp    = intval($_GET['filter_emp']    ?? 0);
    $filter_status = sanitize_text_field($_GET['filter_status'] ?? '');
    $filter_from   = sanitize_text_field($_GET['filter_from']   ?? '');
    $filter_to     = sanitize_text_field($_GET['filter_to']     ?? '');

    // Build meta_query
    $meta_query = [['key' => 'employee_user_id', 'value' => empty($emp_ids) ? [0] : $emp_ids, 'compare' => 'IN']];
    if ($filter_status) {
        $meta_query[] = ['key' => 'tt_state', 'value' => $filter_status, 'compare' => '='];
    }
    if ($filter_from) {
        $meta_query[] = ['key' => 'tt_period_start', 'value' => $filter_from, 'compare' => '>=', 'type' => 'DATE'];
    }
    if ($filter_to) {
        $meta_query[] = ['key' => 'tt_period_start', 'value' => $filter_to, 'compare' => '<=', 'type' => 'DATE'];
    }
    if ($filter_emp && in_array($filter_emp, array_map('intval', $emp_ids))) {
        // Replace the broad employee filter with a single-employee filter
        $meta_query[0] = ['key' => 'employee_user_id', 'value' => $filter_emp, 'compare' => '='];
    }

    $per_page = 20;
    $paged    = max(1, intval($_GET['ts_paged'] ?? 1));

    $q = new WP_Query([
        'post_type'      => 'timesheet',
        'post_status'    => ['publish', 'draft'],
        'posts_per_page' => $per_page,
        'paged'          => $paged,
        'meta_query'     => $meta_query,
        'orderby'        => 'modified',
        'order'          => 'DESC',
    ]);

    // Build employee list for filter dropdown
    $emp_users = [];
    foreach ((array)$emp_ids as $eid) {
        $u = get_userdata(intval($eid));
        if ($u) $emp_users[] = $u;
    }
    usort($emp_users, fn($a, $b) => strcmp($a->display_name, $b->display_name));

    $status_labels = [
        ''              => 'All Statuses',
        'draft'         => 'Draft',
        'submitted'     => 'Submitted',
        'needs_changes' => 'Needs Changes',
        'approved'      => 'Approved',
    ];

    $current_url = admin_url('edit.php?post_type=timesheet&page=tt-manager-inbox');
    ?>
    <div class="wrap" id="tt-manager-dashboard">
        <h1 class="wp-heading-inline">Manager Dashboard</h1>
        <hr class="wp-header-end">

        <?php // ---- Filters ---- ?>
        <form method="GET" action="<?php echo esc_url($current_url); ?>" style="margin:16px 0 20px; display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end;">
            <input type="hidden" name="post_type" value="timesheet">
            <input type="hidden" name="page" value="tt-manager-inbox">

            <?php if (count($emp_users) > 1): ?>
            <div>
                <label style="display:block;font-weight:600;margin-bottom:3px;">Employee</label>
                <select name="filter_emp">
                    <option value="">All Employees</option>
                    <?php foreach ($emp_users as $eu): ?>
                        <option value="<?php echo intval($eu->ID); ?>" <?php selected($filter_emp, $eu->ID); ?>>
                            <?php echo esc_html($eu->display_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div>
                <label style="display:block;font-weight:600;margin-bottom:3px;">Status</label>
                <select name="filter_status">
                    <?php foreach ($status_labels as $val => $lbl): ?>
                        <option value="<?php echo esc_attr($val); ?>" <?php selected($filter_status, $val); ?>>
                            <?php echo esc_html($lbl); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label style="display:block;font-weight:600;margin-bottom:3px;">Period Start From</label>
                <input type="date" name="filter_from" value="<?php echo esc_attr($filter_from); ?>">
            </div>

            <div>
                <label style="display:block;font-weight:600;margin-bottom:3px;">Period Start To</label>
                <input type="date" name="filter_to" value="<?php echo esc_attr($filter_to); ?>">
            </div>

            <div>
                <button type="submit" class="button button-primary">Filter</button>
                <a href="<?php echo esc_url($current_url); ?>" class="button" style="margin-left:4px;">Reset</a>
            </div>
        </form>

        <?php // ---- Summary cards ---- ?>
        <?php
        // Quick counts across ALL this manager's employees (unfiltered)
        $all_q = new WP_Query([
            'post_type'      => 'timesheet',
            'post_status'    => ['publish', 'draft'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [['key' => 'employee_user_id', 'value' => empty($emp_ids) ? [0] : $emp_ids, 'compare' => 'IN']],
        ]);
        $counts = ['submitted' => 0, 'needs_changes' => 0, 'approved' => 0, 'draft' => 0];
        foreach ($all_q->posts as $pid) {
            $s = get_post_meta($pid, 'tt_state', true) ?: 'draft';
            if (isset($counts[$s])) $counts[$s]++;
        }
        ?>
        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;">
            <?php
            $card_colors = ['submitted'=>'#2271b1','needs_changes'=>'#d63638','approved'=>'#00a32a','draft'=>'#787c82'];
            $card_labels = ['submitted'=>'Awaiting Approval','needs_changes'=>'Needs Changes','approved'=>'Approved','draft'=>'Draft'];
            foreach ($counts as $s => $c):
                $active = ($filter_status === $s) ? 'outline:3px solid #000;' : '';
                $link = add_query_arg(['filter_status' => $s], $current_url);
            ?>
            <a href="<?php echo esc_url($link); ?>" style="text-decoration:none;">
                <div style="background:<?php echo $card_colors[$s]; ?>;color:#fff;border-radius:8px;padding:14px 22px;min-width:130px;text-align:center;<?php echo $active; ?>">
                    <div style="font-size:28px;font-weight:700;line-height:1;"><?php echo intval($c); ?></div>
                    <div style="font-size:12px;margin-top:4px;"><?php echo esc_html($card_labels[$s]); ?></div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>

        <?php // ---- Results table ---- ?>
        <?php if (empty($emp_ids)): ?>
            <div class="notice notice-warning inline"><p>No employees are assigned to you yet. Ask an admin to assign employees on their user profile pages.</p></div>
        <?php elseif (!$q->have_posts()): ?>
            <p>No timesheets found matching your filters.</p>
        <?php else: ?>
        <table class="wp-list-table widefat fixed striped" id="tt-manager-table">
            <thead>
                <tr>
                    <th style="width:160px;">Employee</th>
                    <th style="width:200px;">Pay Period</th>
                    <th style="width:110px;">Status</th>
                    <th style="width:80px;">Reg Hrs</th>
                    <th style="width:70px;">PTO</th>
                    <th style="width:80px;">Total Hrs</th>
                    <th style="width:150px;">Last Updated</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php while ($q->have_posts()): $q->the_post();
                $tsid     = get_the_ID();
                $emp_uid  = intval(get_post_meta($tsid, 'employee_user_id', true));
                $emp_u    = $emp_uid ? get_userdata($emp_uid) : null;
                $start    = get_post_meta($tsid, 'tt_period_start', true);
                $state    = get_post_meta($tsid, 'tt_state', true) ?: 'draft';
                $totals   = get_post_meta($tsid, 'tt_totals', true);
                $col      = is_array($totals) ? ($totals['by_column'] ?? []) : [];
                $grand    = is_array($totals) ? ($totals['grand_total'] ?? 0) : 0;
                $pto      = array_sum(array_map('floatval', array_intersect_key($col, array_flip(['vac','sick','pers','hol']))));
                $end      = '';
                if ($start) {
                    $dt = DateTime::createFromFormat('Y-m-d', $start);
                    if ($dt) { $dt->modify('+13 days'); $end = $dt->format('Y-m-d'); }
                }
                $state_colors = ['submitted'=>'#2271b1','needs_changes'=>'#d63638','approved'=>'#00a32a','draft'=>'#787c82'];
                $sc = $state_colors[$state] ?? '#787c82';

                $view_url    = get_permalink($tsid);
                $edit_url    = get_edit_post_link($tsid);
                $approve_url = wp_nonce_url(admin_url('admin-post.php?action=tt_manager_action&do=approve&timesheet_id='.$tsid), 'tt_manager_action_approve_'.$tsid);
                $chg_url     = wp_nonce_url(admin_url('admin-post.php?action=tt_manager_action&do=request_changes&timesheet_id='.$tsid), 'tt_manager_action_request_changes_'.$tsid);
            ?>
            <tr>
                <td><strong><?php echo esc_html($emp_u ? $emp_u->display_name : '—'); ?></strong></td>
                <td><?php echo $start ? esc_html($start . ' – ' . $end) : esc_html(get_the_title()); ?></td>
                <td>
                    <span style="display:inline-block;padding:2px 8px;border-radius:999px;background:<?php echo $sc; ?>;color:#fff;font-size:11px;white-space:nowrap;">
                        <?php echo esc_html(ucwords(str_replace('_', ' ', $state))); ?>
                    </span>
                </td>
                <td><?php echo number_format(floatval($col['reg'] ?? 0), 2); ?></td>
                <td><?php echo number_format($pto, 2); ?></td>
                <td><strong><?php echo number_format(floatval($grand), 2); ?></strong></td>
                <td><?php echo esc_html(get_the_modified_date('M j, Y g:ia')); ?></td>
                <td style="white-space:nowrap;">
                    <a class="button button-small" href="<?php echo esc_url($view_url); ?>" target="_blank">View</a>
                    <?php if (tt_user_can_manage_timesheet($tsid)): ?>
                        <a class="button button-small" href="<?php echo esc_url($edit_url); ?>">Edit</a>
                        <?php if (in_array($state, ['submitted', 'needs_changes'], true)): ?>
                            <a class="button button-small button-primary" href="<?php echo esc_url($approve_url); ?>"
                               onclick="return confirm('Approve this timesheet?');">Approve</a>
                            <a class="button button-small" href="<?php echo esc_url($chg_url); ?>"
                               onclick="return confirm('Send back for changes?');">Request Changes</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endwhile; wp_reset_postdata(); ?>
            </tbody>
        </table>
        <?php
        $total_pages = intval($q->max_num_pages);
        $total_found = intval($q->found_posts);
        $showing_from = (($paged - 1) * $per_page) + 1;
        $showing_to   = min($paged * $per_page, $total_found);
        ?>
        <p style="color:#666;font-size:12px;margin-top:8px;">
            Showing <?php echo esc_html($showing_from); ?>&#8211;<?php echo esc_html($showing_to); ?> of <?php echo esc_html($total_found); ?> timesheet(s).
        </p>
        <?php if ($total_pages > 1):
            $base_url = add_query_arg(array_filter([
                'filter_emp'    => $filter_emp    ?: null,
                'filter_status' => $filter_status ?: null,
                'filter_from'   => $filter_from   ?: null,
                'filter_to'     => $filter_to     ?: null,
            ]), $current_url);
        ?>
        <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:12px;">
            <?php if ($paged > 1): ?>
                <a class="button" href="<?php echo esc_url(add_query_arg('ts_paged', $paged - 1, $base_url)); ?>">&laquo; Prev</a>
            <?php endif; ?>
            <?php for ($p = 1; $p <= $total_pages; $p++):
                $is_active = ($p === $paged);
                $btn_style = $is_active
                    ? 'display:inline-block;padding:4px 10px;border-radius:4px;background:#2271b1;color:#fff;text-decoration:none;font-weight:bold;border:1px solid #2271b1;'
                    : 'display:inline-block;padding:4px 10px;border-radius:4px;background:#f0f0f0;color:#333;text-decoration:none;border:1px solid #ccc;';
            ?>
                <a href="<?php echo esc_url(add_query_arg('ts_paged', $p, $base_url)); ?>"
                   style="<?php echo esc_attr($btn_style); ?>"
                   aria-current="<?php echo $is_active ? 'page' : 'false'; ?>"><?php echo esc_html($p); ?></a>
            <?php endfor; ?>
            <?php if ($paged < $total_pages): ?>
                <a class="button" href="<?php echo esc_url(add_query_arg('ts_paged', $paged + 1, $base_url)); ?>">Next &raquo;</a>
            <?php endif; ?>
            <span style="color:#666;font-size:12px;margin-left:4px;">Page <?php echo esc_html($paged); ?> of <?php echo esc_html($total_pages); ?></span>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <style>
    #tt-manager-dashboard .wp-list-table td,
    #tt-manager-dashboard .wp-list-table th { vertical-align: middle; }
    #tt-manager-dashboard .button-small { font-size: 11px !important; padding: 2px 8px !important; height: auto !important; line-height: 1.6 !important; }
    </style>
    <?php
}


// =============================================================================
// === CAPABILITY: Allow tt_manager to edit timesheets in WP admin =============
// =============================================================================

add_filter('map_meta_cap', 'tt_manager_timesheet_caps', 10, 4);
function tt_manager_timesheet_caps($caps, $cap, $user_id, $args) {
    // Allow tt_managers to edit/read any timesheet post
    if (!in_array($cap, ['edit_post', 'read_post', 'delete_post'], true)) return $caps;
    if (empty($args[0])) return $caps;
    $post = get_post($args[0]);
    if (!$post || $post->post_type !== 'timesheet') return $caps;

    $user = get_userdata($user_id);
    if (!$user || !in_array('tt_manager', (array)$user->roles, true)) return $caps;

    // Only allow if this manager is assigned to the employee
    if (tt_user_can_manage_timesheet($post->ID, $user_id)) {
        return ['exist']; // effectively grants the cap
    }
    return $caps;
}

// Allow tt_managers to save timesheet meta (the save_post nonce check uses edit_post cap)
add_filter('user_has_cap', 'tt_manager_edit_timesheet_cap', 10, 4);
function tt_manager_edit_timesheet_cap($allcaps, $caps, $args, $user) {
    if (empty($user) || !in_array('tt_manager', (array)$user->roles, true)) return $allcaps;
    // Grant edit_posts so the admin metabox save handler works
    if (isset($args[0]) && in_array($args[0], ['edit_posts', 'edit_post'], true)) {
        // Only grant for timesheet context — we can't always tell here, so grant broadly for tt_manager
        $allcaps['edit_posts'] = true;
    }
    return $allcaps;
}


// =============================================================================
// === TEAM ROSTER: Admin submenu showing manager's employees ==================
// =============================================================================

add_action('admin_menu', 'tt_add_team_roster_menu');
function tt_add_team_roster_menu() {
    add_submenu_page(
        'edit.php?post_type=timesheet',
        'My Team',
        'My Team',
        'read',
        'tt-team-roster',
        'tt_team_roster_page'
    );
}

function tt_team_roster_page() {
    if (!tt_user_is_manager()) {
        echo '<div class="wrap"><p>You do not have permission to view this page.</p></div>';
        return;
    }

    $uid      = get_current_user_id();
    $is_admin = current_user_can('manage_options');
    $emp_ids  = $is_admin
        ? get_users(['fields' => 'ID', 'number' => 999])
        : get_users(['meta_key' => 'tt_manager_user_id', 'meta_value' => $uid, 'fields' => 'ID', 'number' => 999]);

    $current_url = admin_url('edit.php?post_type=timesheet&page=tt-manager-inbox');

    echo '<div class="wrap">';
    echo '<h1>My Team</h1>';

    if (empty($emp_ids)) {
        echo '<div class="notice notice-warning inline"><p>No employees are assigned to you. Ask an admin to assign employees via <strong>Users → Edit User → Manager</strong>.</p></div>';
        echo '</div>';
        return;
    }

    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr><th>Employee</th><th>Email</th><th>Timesheets</th><th>Last Submission</th><th>Actions</th></tr></thead>';
    echo '<tbody>';

    foreach ((array)$emp_ids as $eid) {
        $eu = get_userdata(intval($eid));
        if (!$eu) continue;

        // Count timesheets and find last submission date
        $ts_q = new WP_Query([
            'post_type'      => 'timesheet',
            'post_status'    => ['publish', 'draft'],
            'posts_per_page' => 1,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'meta_query'     => [['key' => 'employee_user_id', 'value' => intval($eid), 'compare' => '=']],
        ]);
        $ts_count = $ts_q->found_posts;
        $last_date = $ts_q->have_posts() ? get_the_modified_date('M j, Y', $ts_q->posts[0]) : '—';

        $filter_link = add_query_arg(['filter_emp' => intval($eid)], $current_url);

        echo '<tr>';
        echo '<td><strong>' . esc_html($eu->display_name) . '</strong></td>';
        echo '<td>' . esc_html($eu->user_email) . '</td>';
        echo '<td>' . intval($ts_count) . '</td>';
        echo '<td>' . esc_html($last_date) . '</td>';
        echo '<td><a class="button button-small" href="' . esc_url($filter_link) . '">View Timesheets</a></td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
}

