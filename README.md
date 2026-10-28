# Time Tracker

A WordPress plugin for the Ohio Arts Council. Employees fill in and sign biweekly timesheets, which include hours worked and leave (vacation, sick, personal, holiday, unpaid and overtime). Managers review and approve their team's timesheets, and approved sheets can be downloaded as PDFs.

## How it works

1. An employee opens the timesheet form, picks a pay period and enters hours for each day. They can save a draft and come back later.
2. When they're done, they sign and submit it. The sheet locks; they can withdraw it if they need to make changes.
3. Their manager reviews it on the Manager Dashboard and either **approves** it (adding the manager sign-off) or **requests changes** with a note, which sends it back to the employee.
4. Approved timesheets can be viewed and downloaded as PDFs.

Pay periods are two weeks long, Sunday to Saturday. They count forward from Sunday, January 11, 2026.

## Installation

1. Copy this folder to `wp-content/plugins/` (or upload it as a zip under **Plugins → Add New → Upload Plugin**).
2. Activate **Time Tracker** under **Plugins**. Activation creates the **Timesheet Manager** role.
3. Create the front-end pages below and put a shortcode on each.
4. Go to **Timesheets → Settings** and enter the URLs of those pages.
5. Assign each employee a manager on their user profile.

## Pages and shortcodes

| Shortcode | Use it on | What it shows |
|---|---|---|
| `[time_tracker_form]` | Timesheet page | The timesheet entry form |
| `[my_timesheets]` | My Timesheets page | The logged-in employee's timesheets and their status |
| `[tt_manager_dashboard]` | Manager Dashboard page | The manager's team and their timesheets to approve |

`[manager_timesheets]` is an older manager inbox, kept for existing pages. Use `[tt_manager_dashboard]` for new pages.

## Users and roles

- **Employees** are regular WordPress users (for example, Subscribers).
- **Managers** have the **Timesheet Manager** role. They can approve only the employees assigned to them, and they work entirely on the front end: after login they go to the Manager Dashboard, and they're kept out of wp-admin.
- **Administrators** can view and edit any timesheet under **Timesheets** in wp-admin. If an admin edits a timesheet that's already been signed, it's reopened for signing.

## Admin screens

- **Timesheets**: all timesheets, with an editor for each one.
- **Timesheets → Manager Dashboard / My Team**: wp-admin versions of the manager views.
- **Timesheets → Settings**: the URLs of the My Timesheets and Manager Dashboard pages.

## PDF downloads

The **in-browser PDF button** on a single timesheet page depends on the site's theme, not this plugin. The theme's single-timesheet template must include a button with the ID `pdf-button` (or `pdf-button-v2`) and wrap the printable timesheet in an element with the ID `printable-area`. The plugin loads `js/pdf-print.js` (and html2pdf.js from cdnjs) on single timesheet pages and wires up that button. If the theme changes, add that markup to the new theme's template or the button won't appear.

The plugin also generates PDFs on the server with Dompdf; that doesn't depend on the theme.

## Companion tools

These are separate plugins, kept in this repo but installed on their own.

- **`oac-password-reset/`: Bulk Password Reset.** Under **Tools → Bulk Password Reset**, an admin can give one, some or all users a temporary password. Those users have to choose their own password (at least 12 characters) the next time they log in, and they get an email confirming the change. Every reset and change is logged on that page.
- **`mu-plugins/tt-demo-seeder.php`: Demo Data.** Creates demo managers, employees and timesheets for training or demonstrations, and removes them again (**Tools → TT Demo Data**, or `wp tt-demo seed` / `wp tt-demo clear`). Install it as a must-use plugin, and **don't use it on the live site.**

## Project layout

| Path | Contents |
|---|---|
| `oa-time-tracker.php` | The plugin: data model, form handling, approvals, admin pages and PDFs |
| `js/tt-time-tracker.js` | Timesheet form behavior and pay-period calculation |
| `js/pdf-print.js` | PDF download in the browser |
| `assets/` | Admin timesheet editor script and styles |
| `css/` | Front-end and print styles |
| `vendor/dompdf/` | Dompdf 3.1.0, used to generate PDFs on the server |

## License

MIT. See [LICENSE](LICENSE).
