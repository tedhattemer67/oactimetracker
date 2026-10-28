<?php
/*
Plugin Name: OAC Bulk Password Reset
Description: Set a temporary password for one, some or all users from Tools → Bulk Password Reset. Those users must choose their own password the next time they log in.
Version: 1.0
Author: Ted Hattemer
*/

// Standalone plugin, separate from OAC Time Tracker: install this folder as its own plugin
// (wp-content/plugins/oac-password-reset/). WordPress does not scan this subfolder when it sits
// inside the time tracker plugin, so it is never loaded from there.

if (!defined('ABSPATH')) exit;

const OAC_PWR_FLAG    = 'oac_pwr_must_change'; // user meta: UTC timestamp of the admin reset
const OAC_PWR_LOG     = 'oac_pwr_log';         // option: newest-first list of log entries
const OAC_PWR_LOG_MAX = 500;
const OAC_PWR_MIN_LEN = 12;
const OAC_PWR_ACTION  = 'oac_change_password'; // wp-login.php?action=...

function oac_pwr_must_change($user_id) {
    return (bool) get_user_meta($user_id, OAC_PWR_FLAG, true);
}

function oac_pwr_log($event, $user) {
    $log = get_option(OAC_PWR_LOG, []);
    if (!is_array($log)) $log = [];
    array_unshift($log, [
        't'     => time(),
        'event' => $event, // 'reset' (by an admin) or 'changed' (by the user)
        'actor' => get_current_user_id(),
        'user'  => $user->ID,
        'login' => $user->user_login, // kept so the log still reads right if the user is deleted
    ]);
    update_option(OAC_PWR_LOG, array_slice($log, 0, OAC_PWR_LOG_MAX), false);
}

function oac_pwr_change_url($redirect_to = '') {
    $url = add_query_arg('action', OAC_PWR_ACTION, wp_login_url());
    return $redirect_to ? add_query_arg('redirect_to', rawurlencode($redirect_to), $url) : $url;
}

// --- Tools → Bulk Password Reset -------------------------------------------------------

add_action('admin_menu', function () {
    add_management_page('Bulk Password Reset', 'Bulk Password Reset', 'edit_users', 'oac-password-reset', 'oac_pwr_admin_page');
});

/** Handles the form post. Returns [notice class, message] or null. */
function oac_pwr_handle_post() {
    if (!isset($_POST['oac_pwr_submit'])) return null;
    check_admin_referer('oac_pwr_reset');

    $pw  = (string) wp_unslash($_POST['oac_pwr_password'] ?? '');
    $ids = array_map('intval', (array) ($_POST['oac_pwr_users'] ?? []));
    if (strlen($pw) < OAC_PWR_MIN_LEN) {
        return ['error', sprintf('The temporary password must be at least %d characters. No passwords were changed.', OAC_PWR_MIN_LEN)];
    }
    if (trim($pw) !== $pw) {
        return ['error', 'The temporary password can’t start or end with a space. No passwords were changed.'];
    }
    if (!$ids) return ['error', 'No users were selected.'];

    $done = [];
    $skipped = 0;
    foreach (array_unique($ids) as $id) {
        $user = get_userdata($id);
        // Never reset your own password from here, and respect per-user edit rights.
        if (!$user || $id === get_current_user_id() || !current_user_can('edit_user', $id)) {
            $skipped++;
            continue;
        }
        wp_set_password($pw, $id); // also invalidates the user's existing login cookies
        WP_Session_Tokens::get_instance($id)->destroy_all();
        update_user_meta($id, OAC_PWR_FLAG, time());
        oac_pwr_log('reset', $user);
        $done[] = $user->user_login;
    }

    if (!$done) return ['error', 'No passwords were changed.'];
    $msg = sprintf(
        _n('Reset the password for %d user: %s.', 'Reset passwords for %d users: %s.', count($done)),
        count($done), implode(', ', $done)
    );
    $msg .= ' They will be asked to choose a new password when they next log in.';
    if ($skipped) $msg .= sprintf(' Skipped %d user(s) that can’t be reset from here.', $skipped);
    return ['success', $msg];
}

function oac_pwr_admin_page() {
    if (!current_user_can('edit_users')) return;
    $notice = oac_pwr_handle_post();

    $show_admins = !empty($_GET['show_admins']);
    $args = ['orderby' => 'login', 'order' => 'ASC', 'exclude' => [get_current_user_id()]];
    if (!$show_admins) $args['role__not_in'] = ['administrator'];
    $users = get_users($args);
    $toggle_url = add_query_arg(['page' => 'oac-password-reset', 'show_admins' => $show_admins ? false : 1], admin_url('tools.php'));
    $role_names = wp_roles()->get_names();
    ?>
    <div class="wrap">
        <h1>Bulk Password Reset</h1>
        <?php if ($notice) printf('<div class="notice notice-%s"><p>%s</p></div>', esc_attr($notice[0]), esc_html($notice[1])); ?>
        <p>Give the selected users a temporary password. When they next log in, they must choose their own password
           (at least <?php echo OAC_PWR_MIN_LEN; ?> characters) before they can do anything else, and they get an email confirming the change.
           Resetting also logs them out everywhere.</p>

        <form method="post" id="oac-pwr-form">
            <?php wp_nonce_field('oac_pwr_reset'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="oac_pwr_password">Temporary password</label></th>
                    <td>
                        <input type="text" id="oac_pwr_password" name="oac_pwr_password" class="regular-text code"
                               minlength="<?php echo OAC_PWR_MIN_LEN; ?>" autocomplete="off" spellcheck="false" required>
                        <button type="button" class="button" id="oac-pwr-generate">Generate</button>
                        <p class="description">At least <?php echo OAC_PWR_MIN_LEN; ?> characters. Every selected user gets this same password, so hand it out only to the people you reset.</p>
                    </td>
                </tr>
            </table>

            <p>
                <a href="<?php echo esc_url($toggle_url); ?>"><?php echo $show_admins ? 'Hide administrators' : 'Show administrators'; ?></a>
                · Your own account is not listed.
            </p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <td class="manage-column column-cb check-column"><input type="checkbox" id="oac-pwr-all" aria-label="Select all users"></td>
                        <th>Username</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$users) : ?>
                    <tr><td colspan="6">No users to show.</td></tr>
                <?php endif; ?>
                <?php foreach ($users as $u) :
                    $flag  = (int) get_user_meta($u->ID, OAC_PWR_FLAG, true);
                    $roles = array_map(function ($r) use ($role_names) { return translate_user_role($role_names[$r] ?? $r); }, $u->roles);
                    ?>
                    <tr>
                        <th scope="row" class="check-column">
                            <input type="checkbox" name="oac_pwr_users[]" value="<?php echo intval($u->ID); ?>" id="oac-pwr-u<?php echo intval($u->ID); ?>">
                        </th>
                        <td><label for="oac-pwr-u<?php echo intval($u->ID); ?>"><strong><?php echo esc_html($u->user_login); ?></strong></label></td>
                        <td><?php echo esc_html($u->display_name); ?></td>
                        <td><?php echo esc_html($u->user_email); ?></td>
                        <td><?php echo esc_html(implode(', ', $roles)); ?></td>
                        <td><?php echo $flag
                            ? esc_html('Must change password (reset ' . wp_date('M j, Y g:i a', $flag) . ')')
                            : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary" name="oac_pwr_submit" value="1">Reset selected passwords</button>
            </p>
        </form>

        <?php oac_pwr_render_log(); ?>
    </div>

    <script>
    (function () {
        var form = document.getElementById('oac-pwr-form');
        var pw   = document.getElementById('oac_pwr_password');
        var all  = document.getElementById('oac-pwr-all');
        var boxes = function () { return form.querySelectorAll('input[name="oac_pwr_users[]"]'); };

        all.addEventListener('change', function () {
            boxes().forEach(function (b) { b.checked = all.checked; });
        });

        document.getElementById('oac-pwr-generate').addEventListener('click', function () {
            // No look-alike characters (0/O, 1/l/I) since this gets read out or typed by hand.
            var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%*?';
            var bytes = new Uint32Array(16), out = '';
            crypto.getRandomValues(bytes);
            bytes.forEach(function (n) { out += chars[n % chars.length]; });
            pw.value = out;
        });

        form.addEventListener('submit', function (e) {
            var n = Array.prototype.filter.call(boxes(), function (b) { return b.checked; }).length;
            if (!n) { e.preventDefault(); window.alert('Select at least one user.'); return; }
            if (!window.confirm('Reset the password for ' + n + ' user' + (n === 1 ? '' : 's') + '? They will be logged out and must choose a new password at their next login.')) {
                e.preventDefault();
            }
        });
    })();
    </script>
    <?php
}

function oac_pwr_render_log() {
    $log = get_option(OAC_PWR_LOG, []);
    $log = is_array($log) ? array_slice($log, 0, 100) : [];
    echo '<h2>Log</h2>';
    if (!$log) {
        echo '<p>Nothing logged yet.</p>';
        return;
    }
    $name = function ($id, $fallback = '') {
        $u = $id ? get_userdata($id) : null;
        return $u ? $u->user_login : ($fallback !== '' ? $fallback . ' (deleted)' : '—');
    };
    echo '<p class="description">Most recent 100 entries. Passwords are never logged.</p>';
    echo '<table class="widefat striped"><thead><tr><th>When</th><th>Event</th><th>User</th><th>By</th></tr></thead><tbody>';
    foreach ($log as $e) {
        $event = $e['event'] === 'reset' ? 'Temporary password set' : 'User chose a new password';
        printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
            esc_html(wp_date('M j, Y g:i a', $e['t'])),
            esc_html($event),
            esc_html($name($e['user'], $e['login'])),
            esc_html($e['event'] === 'reset' ? $name($e['actor']) : 'self')
        );
    }
    echo '</tbody></table>';
}

// --- Forcing the change at next login --------------------------------------------------

// Straight after login, send flagged users to the change screen, keeping wherever they were
// headed (runs after OAC Time Tracker's own login_redirect, so its manager redirect is kept).
add_filter('login_redirect', function ($redirect_to, $requested, $user) {
    if (!($user instanceof WP_User) || !oac_pwr_must_change($user->ID)) return $redirect_to;
    return oac_pwr_change_url($redirect_to);
}, 20, 3);

// Until they change it, every other page sends them back to the change screen.
add_action('init', function () {
    if (!is_user_logged_in() || !oac_pwr_must_change(get_current_user_id())) return;
    if (($GLOBALS['pagenow'] ?? '') === 'wp-login.php') return; // the change screen and Log out live here
    if (wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) return;

    if (wp_doing_ajax()) {
        wp_send_json_error(['message' => 'You must choose a new password before continuing.'], 403);
    }
    $here = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
        ? (is_ssl() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '')
        : '';
    wp_safe_redirect(oac_pwr_change_url($here));
    exit;
}, 1);

// The change screen itself: wp-login.php?action=oac_change_password
add_action('login_form_' . OAC_PWR_ACTION, function () {
    if (!is_user_logged_in()) {
        wp_safe_redirect(wp_login_url());
        exit;
    }
    $user        = wp_get_current_user();
    $redirect_to = wp_validate_redirect(wp_unslash($_REQUEST['redirect_to'] ?? ''), home_url('/'));
    if (!oac_pwr_must_change($user->ID)) {
        wp_safe_redirect($redirect_to);
        exit;
    }

    $errors = new WP_Error();
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        check_admin_referer('oac_pwr_change');
        $pass1 = (string) wp_unslash($_POST['pass1'] ?? '');
        $pass2 = (string) wp_unslash($_POST['pass2'] ?? '');

        if (strlen($pass1) < OAC_PWR_MIN_LEN) {
            $errors->add('short', sprintf('Your password must be at least %d characters.', OAC_PWR_MIN_LEN));
        } elseif (trim($pass1) !== $pass1) {
            $errors->add('spaces', 'Your password can’t start or end with a space.');
        } elseif ($pass1 !== $pass2) {
            $errors->add('mismatch', 'The two passwords don’t match.');
        } elseif (wp_check_password($pass1, $user->user_pass, $user->ID)) {
            $errors->add('same', 'Choose a password different from the temporary one you were given.');
        }

        if (!$errors->has_errors()) {
            wp_set_password($pass1, $user->ID);
            delete_user_meta($user->ID, OAC_PWR_FLAG);
            oac_pwr_log('changed', $user);
            oac_pwr_send_changed_email($user);
            // Changing the password ended this login; start a fresh one so they carry on.
            WP_Session_Tokens::get_instance($user->ID)->destroy_all();
            wp_set_auth_cookie($user->ID, false, is_ssl());
            wp_safe_redirect($redirect_to);
            exit;
        }
    }

    login_header('Choose a new password', '<p class="message">Your password was reset by an administrator. Choose a new password to continue.</p>', $errors);
    ?>
    <form name="oac-pwr-change" id="oac-pwr-change" action="<?php echo esc_url(oac_pwr_change_url($redirect_to)); ?>" method="post" autocomplete="off">
        <?php wp_nonce_field('oac_pwr_change'); ?>
        <input type="text" name="log" value="<?php echo esc_attr($user->user_login); ?>" autocomplete="username" style="display:none" readonly>
        <p>Logged in as <strong><?php echo esc_html($user->user_login); ?></strong></p>
        <p>
            <label for="pass1">New password</label>
            <input type="password" name="pass1" id="pass1" class="input" size="24" minlength="<?php echo OAC_PWR_MIN_LEN; ?>" autocomplete="new-password" spellcheck="false" required>
        </p>
        <p>
            <label for="pass2">Confirm new password</label>
            <input type="password" name="pass2" id="pass2" class="input" size="24" minlength="<?php echo OAC_PWR_MIN_LEN; ?>" autocomplete="new-password" spellcheck="false" required>
        </p>
        <p class="description indicator-hint">At least <?php echo OAC_PWR_MIN_LEN; ?> characters, and different from your temporary password.</p>
        <p class="submit">
            <input type="submit" class="button button-primary button-large" value="Save password">
        </p>
    </form>
    <p id="nav"><a href="<?php echo esc_url(wp_logout_url()); ?>">Log out</a></p>
    <?php
    login_footer('pass1');
    exit;
});

// If a flagged user resets their own password through "Lost your password?" instead, that counts.
add_action('after_password_reset', function ($user) {
    if (!oac_pwr_must_change($user->ID)) return;
    delete_user_meta($user->ID, OAC_PWR_FLAG);
    oac_pwr_log('changed', $user);
});

function oac_pwr_send_changed_email($user) {
    $site = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);
    $body = sprintf(
        "Hi %s,\n\n" .
        "This confirms that you set a new password for your %s account (%s) on %s.\n\n" .
        "If you did not do this, contact your administrator right away.\n\n" .
        "%s",
        $user->display_name ?: $user->user_login,
        $site,
        $user->user_login,
        wp_date('F j, Y \a\t g:i a T'),
        home_url('/')
    );
    wp_mail($user->user_email, sprintf('[%s] Your password was changed', $site), $body);
}
