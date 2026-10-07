# Astona — Admin (Sub-project 4) Design

Source: SDD §9 (admin system), §6 (RBAC), §7 (SEC-012 audit); RTM FR-011/012/014/015/022; WF-022, 025, 028, 029, 033, 034.
Staff work in native wp-admin with custom `WP_List_Table` screens (ADR-011). Offline payment path stays DISABLED (OQ-13): no
"mark paid". Out of scope: React course/batch editor (existing metabox stays), live-class scheduler and targeted notices (SP5),
media rules, blog/gallery, 2FA enforcement (plugin recommended in README only), SMS gateway admin UI.

## Tables (created in includes/class-migrations.php v4 — do not change)
cc_audit_log(actor_id, action, entity_type, entity_id, diff_hash, note, ip_hash, created_at), cc_batch_staff(user_id,batch_id).

## Capabilities and roles (owner: ADMIN-CORE)
Caps: `cc_review_applications`, `cc_view_students`, `cc_manage_students`, `cc_view_payments`, `cc_reconcile_payments`,
`cc_export_data`, `cc_view_audit`, `cc_manage_settings`, `cc_manage_staff`, `cc_view_dashboard`.
Roles: `cc_owner` (all caps + core `read`), `cc_staff` (review_applications, view_students, manage_students, view_payments,
export_data, view_dashboard — NOT reconcile/audit/settings/staff), `cc_instructor` (`read` + view_dashboard only; batch scope via
cc_batch_staff is future work — dashboard shows nothing financial). WordPress `administrator` is granted every cc_ cap too
(`user_has_cap`/role grant on activation + version check). Staff/instructor/owner must NOT be able to edit plugins/themes or
core settings (no extra core caps). Students (cc_student) must never gain any cc_ cap.

## Files (wp-content/plugins/coaching-platform/includes/admin/ — all loaded by coaching-platform.php; classes with init() are auto-called)
| File | Owner | Contract |
|---|---|---|
| class-admin-roles.php | CORE | `CC_Admin_Roles::init()` registers roles/caps (idempotent, re-applied when option `cc_admin_roles_version` changes), constants `CAP_*`, `CC_Admin_Roles::user_can_any(array $caps): bool` |
| class-audit.php | CORE | `CC_Audit::log(string $action, string $entity_type, int $entity_id = 0, array $diff = [], string $note = ''): void` — stores sha256 of canonical-JSON diff (NEVER the diff itself, no PII), ip hash (hmac with wp_salt) and actor = get_current_user_id(); `CC_Audit::query(array $args): array` (filters actor, action, entity_type, date range, paging) used by the viewer |
| class-admin-menu.php | CORE | `CC_Admin_Menu::init()` registers top-level menu 'Astona' (slug `cc-dashboard`, dashicon) with submenus (slug → cap → renderer): `cc-dashboard` (cc_view_dashboard → `CC_Admin_Dashboard::render`), `cc-applications` (cc_review_applications → `CC_Admin_Applications::render`), `cc-students` (cc_view_students → `CC_Admin_Students::render`), `cc-payments` (cc_view_payments → `CC_Admin_Payments::render`), `cc-audit` (cc_view_audit → `CC_Admin_Audit::render`), `cc-settings` (cc_manage_settings → `CC_Admin_Settings::render`); menu grouped by workflow; a renderer class that doesn't exist is skipped; pending-application count bubble on the Applications item; admin CSS enqueued from `assets/admin.css` under plugin dir (CORE creates it, small) |
| class-admin-settings.php | CORE | Settings page: institution name/phone/email/address (stored in option `cc_settings`, sanitised), feeds the theme through `add_filter('astona_contact', …)` (so footer/admissions use them), shows read-only status panel (payment gateway name, SMS drivers, Action Scheduler present, last reconciler run, private dir writable, ENC key configured — never values/secrets); POST via admin-post with nonce + cap; audited |
| class-admin-dashboard.php | CORE | `CC_Admin_Dashboard::render()` — cards: pending applications (link), applications today/7d, paid revenue (completed payments) today/30d/total, active enrollments, stuck payments (initiated/executing >10 min or reconcile_needed) with links, 5 most recent audit entries (owner only); instructor sees a reduced notice, never money; data helpers `CC_Admin_Dashboard::stats(): array` (testable) |
| class-csv.php | CORE | `CC_Csv::stream(string $filename, array $header, iterable $rows): never` — sends headers (text/csv UTF-8 BOM so Excel reads Bangla, no-store), neutralises formula injection (cells starting with = + - @ tab CR get a leading '), streams in chunks |
| class-admin-applications.php | APPS | WP_List_Table (`CC_Applications_Table`) + detail view; status tabs (All/Pending/Approved/Rejected/Cancelled) with counts, search (name, phone, ref, invoice), filter by batch and date range, sortable, per-page 20; row actions View, Reject (needs reason, only for pending, cap cc_review_applications, nonce, audited); detail panel: all application fields (ID number masked `****1234`, decrypt only for owner via explicit 'Reveal ID number' action that is audited), photo shown through an authenticated stream (admin-post action, cap check, never a public URL), invoice, payments timeline, enrollment/user link; bulk reject with confirm step; CSV export (cap cc_export_data, audited, excludes ID number and photo path). Public methods for tests: `CC_Admin_Applications::reject(int $id, string $reason, int $actor): bool|WP_Error`, `query(array $args): array` |
| class-admin-students.php | STUDENTS | `CC_Students_Table`: name, phone (masked option), enrolled batches, enrollment status, joined, SMS delivery status of last credentials; search; filter by batch/status; row actions: Resend credentials (cap cc_manage_students: only if must_change_pw still 1 → new temp password via the existing provisioner reset + SMS; otherwise sends OTP-login hint; rate limited 3/hour/student; audited), Deactivate/Reactivate enrollment (status deactivated/active; audited); detail view with enrollments, payments, SMS log (masked); CSV export (cc_export_data, audited). Public testable methods: `CC_Admin_Students::set_enrollment_status(int $enrollment_id, string $status, int $actor): bool|WP_Error`, `resend_credentials(int $user_id, int $actor): bool|WP_Error` |
| class-admin-payments.php | PAYMENTS | `CC_Payments_Table`: invoice no., student/application link, amount, gateway/method, status, trx id, created/settled; filters status + date range + gateway; flags stuck rows (initiated/executing >10 min, reconcile_needed); row action 'Reconcile now' (cap cc_reconcile_payments; calls `CC_Settlement::settle($payment_id,'admin')`; shows the result code; audited) ; totals bar (completed amount in filter); CSV export (cc_export_data, audited). Testable `CC_Admin_Payments::reconcile(int $payment_id, int $actor): array` |
| class-admin-audit.php | AUDIT | owner-only viewer over `CC_Audit::query` with filters + paging + CSV |

All admin POST/GET actions: `check_admin_referer`, capability check on every handler (never menu visibility alone), `esc_*` everywhere,
`$wpdb->prepare`, redirect with notice query arg + `add_settings_error`-style admin notice, no PII in URLs beyond ids. Phones shown
masked via CC_Phone::mask unless the actor has cc_manage_students. Mobile-friendly basic admin CSS only.

## Testing
tests/unit: CSV neutraliser, audit canonical-hash helper. tests/integration (wpcli eval-file): roles/caps matrix (owner/staff/
instructor/student/administrator × every cap, student has none), audit log write/query & no PII, applications reject flow +
list query filters, students resend/deactivate, payments reconcile + filters + stuck flag, dashboard stats, settings → astona_contact.
tests/e2e: curl with real admin cookie (wp user create via wpcli, login through wp-login.php): every admin page 200 for owner,
403/redirect for instructor/staff on forbidden pages, CSV exports content-type + formula neutralisation, nonce-less actions refused.
Browser: tests/e2e/admin-browser.mjs (Playwright) smoke at 1280 + 768 for the screens.
