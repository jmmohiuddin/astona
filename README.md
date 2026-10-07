# Astona Public Site

WordPress public website for the Astona coaching center: course catalogue, batches, faculty and notices. This repository contains sub-projects 1 to 6: the public site, admission and payment (bKash Tokenized Checkout driver, plus a fake gateway for local use; the bKash driver is unit-tested against a scripted transport only and still needs a sandbox run), accounts, SMS and the student portal (BulkSMSBD and GreenWeb drivers, plus a fake driver for local use; the real drivers are unit-tested against a scripted transport only and still need a live account), the staff admin area (**Astona** menu in wp-admin), course content, live classes and targeted notices, and the v1 content modules (Blog, Gallery, Results, Contact and Inquiries, Media). Hosting and launch are not done (see [Not built yet](#not-built-yet)).

**For coaching-center staff:** see the [Staff guide](docs/STAFF-GUIDE.md) (plain language, no code).

## Quick start

Requires Docker with Compose, plus `bash` and `openssl`.

```bash
docker compose up -d
./scripts/setup.sh
```

Open http://localhost:8080. `setup.sh` starts the containers, creates the private upload directory (`/var/www/private`, owned by uid 33, mode 750), installs WordPress, activates the theme and plugin, installs and activates the Action Scheduler plugin, sets pretty permalinks and runs `wp cc seed`. On a fresh install it prints the admin login (`Admin login: admin / <random password>`). Save it: it is not stored. Log in at http://localhost:8080/wp-admin (staff land on the **Astona dashboard**).

`setup.sh` accepts optional env vars `SITE_URL`, `ADMIN_USER`, `ADMIN_PASSWORD` and `ADMIN_EMAIL`.

## Stack

- WordPress 6 on PHP 8.3 (Apache; `docker/php/uploads.ini` is mounted to raise `upload_max_filesize` to 12M, `post_max_size` to 16M and `memory_limit` to 256M so 10 MB lesson PDFs can be uploaded), MariaDB 10.11, WP-CLI container (`wpcli`, Compose profile `tools`).
- Plugin `coaching-platform` (requires PHP 8.1+): catalogue, admission, payment, SMS, student accounts, portal, admin screens, course content, live classes, notices, blog, gallery/results, inquiries and media rules. Uses libsodium (encryption) and GD (photo re-encoding).
- Action Scheduler plugin (installed by `setup.sh`): runs the payment reconciler every minute, student provisioning and SMS sending. Without it these fall back to WP-Cron. In production the scheduler must be driven by a real cron (see [Production requirements](#production-requirements-for-sub-project-3)).
- Theme `coaching-theme`: presentation only (including the `student-*.php` portal templates).
- MU-plugin `cc-hardening`: XML-RPC off, application passwords off, no user enumeration, generator tag removed. Student accounts get further lockdown from the plugin (see [Student lockdown](#student-lockdown)).

## Repository layout

```
docker-compose.yml                         db, wordpress (port 8080), wpcli
docker/php/uploads.ini                     PHP upload and memory limits (12M upload, 16M post, 256M memory)
scripts/setup.sh                           one-shot local setup
tests/                                     unit/, integration/, e2e/ (see Tests)
docker/wpcli/                              WP-CLI config and DB client config
wp-content/
  plugins/coaching-platform/               catalogue module
    includes/class-post-types.php          cc_course, cc_faculty, cc_branch, cc_notice, cc_category
    includes/class-batch-metabox.php       wp-admin fields for courses, batches, faculty
    includes/class-batch-repository.php    cc_batches table access, course summaries
    includes/class-status-chip.php         Open / Filling Fast / Closed rules
    includes/class-rest-courses.php        GET /wp-json/cc/v1/courses
    includes/class-migrations.php          versioned schema migrations
    includes/class-seo.php                 JSON-LD structured data
    includes/class-seeder.php              `wp cc seed`
    includes/support/                      CC_Crypto, CC_Phone, CC_Rate_Limiter, CC_Idempotency
    includes/payments/                     gateway interface, fake gateway, factory, settlement, reconciler, payment REST routes
    includes/admissions/                   application repository, photo store, admission REST routes
    includes/sms/                          SMS driver interface, factory, fake driver, queue/sender (CC_Sms)
    includes/enrollment/                   student role, enrollment repository, provisioner, student lockdown
    includes/auth/                         OTP, login/OTP/password REST routes, student guard
    includes/portal/                       /student/ routing, portal data, course/lesson/PDF access, PATCH /me/profile
    includes/admin/                        Astona wp-admin screens, roles (cc_owner/cc_staff/cc_instructor), audit log, CSV export
    includes/content/                      modules, lessons, private lesson PDFs
    includes/live/                         live classes and the Join endpoint
    includes/notices/                      targeted notices, critical-notice SMS
    includes/blog/                         cc_article post type, SEO fields, archive
    includes/gallery/                      cc_gallery_item and cc_result post types, private result photos (store, signed route, migration)
    includes/contact/                      contact form endpoint, inquiries, branch details
    includes/media/                        upload rules (types, sizes, alt text, EXIF stripping, WebP)
  assets/admin-media.js                    upload UI for Astona > Media
  themes/coaching-theme/                   templates, CSS, vanilla JS (student-*.php, assets/js/student-*.js)
  mu-plugins/cc-hardening.php              baseline hardening
docs/superpowers/specs/2026-10-06-public-site-design.md       sub-project 1 design spec
docs/superpowers/specs/2026-10-06-admission-payment-design.md  sub-project 2 design spec
docs/superpowers/specs/2026-10-07-accounts-sms-portal-design.md  sub-project 3 design spec
docs/superpowers/specs/2026-10-07-admin-design.md             sub-project 4 design spec (admin)
docs/superpowers/specs/2026-10-07-content-live-notices-design.md  sub-project 5 design spec
docs/superpowers/specs/2026-10-07-v1-content-design.md        sub-project 6 design spec (blog, gallery, contact, media)
docs/STAFF-GUIDE.md                                            guide for non-technical staff and owners
```

## Managing content in wp-admin

Day-to-day staff tasks are explained in plain language in the [Staff guide](docs/STAFF-GUIDE.md). Staff sign in at `/wp-login.php` and land on **Astona > Dashboard**. Admin screens under the **Astona** menu are: Dashboard, Applications, Students, Course content, Live classes, Notices, Inquiries, Media, Payments, Audit log and Settings (each shown only if the role holds its `cc_` capability). Courses, Faculty, Branches, Blog, Gallery and Results use the native WordPress editors described below.

Roles (`cc_owner`, `cc_staff`, `cc_instructor`) and their capabilities are defined in `includes/admin/class-admin-roles.php`. Owner-only: Audit log, Settings, reconciling payments, revealing an applicant's ID number. Instructors only see the dashboard.

**Courses** (Courses > Add New)
- Title, description, excerpt, featured image and **Course Category** in the standard editor.
- **Course details** box: **Duration** (e.g. "6 months"), **Syllabus** (one topic per line), **Instructors** (multi-select from Faculty).
- **Batches (schedule, price, capacity)** box, one row per batch: Name, Delivery mode (Physical / Online / Hybrid), Capacity, Price (BDT), Start, End (optional), Schedule text (e.g. "Sat, Mon, Wed · 6:00–7:30 PM"), Status (Draft / Open / Closed / Completed) and **Applications open**.
- Draft batches are hidden from the public site. Blank rows are provided for adding batches.
- **Seats taken** is not editable here. It is incremented only by payment settlement (see [Admission and payment](#admission-and-payment-sub-project-2)), and saving a course never overwrites it.

**Faculty** (Faculty > Add New): name, bio, photo, plus **Subject** and **Credentials**.

**Notices**: use **Astona > Notices** (draft, audience preview, schedule, archive; batch-targeted notices are visible only to enrolled students and a critical one also sends an SMS once). Public notices appear under `/notices/`.

**Branches** (Branches > Add New): title, plus **Address**, **Phone**, **Opening hours** and **Map embed URL** (Google Maps or OpenStreetMap embed links only). Branches feed the Contact page and structured data.

**Blog** (Blog > Add New Article): SEO title and meta description fields, featured image by Astona Media ID, **Archive** row action. **Gallery** and **Results** (alt text required to publish a gallery item; a result is public only when published, verified and consent-confirmed; a result's photo is uploaded in the result form and kept private, see [Private result photos](#private-result-photos)). **Astona > Media** is the upload screen (JPEG, PNG, WebP, GIF up to 5 MB; PDF up to 10 MB; alt text required for images).

Pages (About, FAQ, Contact, Privacy, Terms) are ordinary WordPress pages edited in the page editor.

### Status chip rules

Each batch gets a chip, computed (never typed in):

| Chip | When |
|---|---|
| Closed | batch Status is not Open, **or** Applications open is unticked, **or** capacity is 0, **or** seats taken >= capacity |
| Filling Fast | otherwise, and seats taken / capacity >= 80% |
| Open | otherwise |

A course shows the best chip across its batches: any Open batch wins, then Filling Fast, then Closed.

## Private result photos

A student's result photo is never a media-library file and never lives in the public uploads directory, so no web server URL exists for it. This closes the earlier gap where a non-consented student's photo could be fetched by anyone who held the raw `wp-content/uploads/` address.

- **Storage.** Under `CC_PRIVATE_DIR/result-photos/` (same directory and fail-closed rule as applicant photos: without `CC_PRIVATE_DIR` outside `local`, saving a photo fails and nothing is written to the web root). JPEG, PNG or WebP, 5 MB at most, 8000 px on a side and 40 megapixels at most. The image is re-encoded (EXIF and any appended payload are removed), saved as `<random 32 hex>.jpg|png|webp` (mode 0640) and, for JPEG and PNG, gets a `.webp` sibling in the same private directory. The relative path is in the result's post meta `cc_result_photo_path`; the required alt text is in `cc_result_photo_alt`.
- **Staff control.** In the result form (**Results > Add Result / Edit**) the photo is a file upload, not an attachment ID. Alt text is required with a photo (a new file without it is refused). Choosing a new file replaces the old one; **Remove the current photo** deletes it. Replaced, removed and permanently deleted results delete the private files including the WebP sibling. Set, replace and remove are written to the audit log (size only, never file contents). Only users with `cc_manage_gallery` can do this; a trashed result keeps its files so it can be restored.
- **Serving.** Only through `/cc-result-photo/{result_id}/{expiry}/{signature}.{jpg|png|webp}`. The signature is HMAC-SHA256 (`wp_salt('auth')`) over `result_id|expiry|variant` (compared in constant time); a URL lives 10 minutes and a longer expiry is refused even if correctly signed. On every request the result is read again: the photo streams only if the result is published AND verified AND consent-confirmed, or if the viewer is a manager (`cc_manage_gallery`), who may preview any state. Everything else (unknown result, not public, bad signature, expired, wrong extension, missing file, any method but GET/HEAD) returns one identical 404 (same status, headers, body `Not found`). Turning consent or verification off, unpublishing or trashing therefore stops URLs that were already handed out at once. Headers: real MIME type from the file content, `Content-Disposition: inline`, `X-Content-Type-Options: nosniff`, `Cache-Control: private, max-age=300` (never public or immutable), `X-Robots-Tag: noindex`. Files are opened only after `realpath` containment inside `result-photos/`.
- **Cache trade-off.** The Results page puts freshly signed URLs in its HTML, so a copy of that page older than 10 minutes would show broken photos. The page therefore sends `Cache-Control: private, no-cache` when it shows at least one photo; do not put a page cache or CDN rule in front of `/results/` that overrides it, and let `/cc-result-photo/*` pass through uncached. The alternative (a long-lived listing signature) was rejected because it would lengthen how long a revoked URL keeps working. What remains after revocation is a browser's own copy of an image it already loaded, for at most 5 minutes.
- **Existing photos.** Results that still use an attachment (meta `cc_result_photo`) are migrated automatically when the plugin database version changes (first request after the update; with more than 5 such results the work is left to the cron event below), or by hand: `docker compose run --rm -T wpcli eval 'print_r( CC_Result_Photo_Migration::run() );'`. Per result (under a per-result lock, so concurrent requests cannot create duplicate files) it copies the file into the private store, sets the new meta, removes the old meta, then deletes the old attachment with its generated sizes and `.webp` siblings from public uploads. It is idempotent. An attachment that something else still uses (a gallery item, a featured image, post content) is kept (`kept_shared`): the result already points at its private copy and the log says the original stays public because it is used elsewhere. A photo that cannot be copied keeps its old meta (`failed`, logged with attachment and result id). While any legacy photo remains: its attachment is hidden from the public (REST, oEmbed, redirects, attachment page, URL helpers) like an unpublished gallery image, the option `cc_result_photo_migration_pending` is set, users with `cc_manage_gallery` see an admin notice with a **Retry now** link, and the hourly event `cc_result_photo_migration_retry` re-runs the migration until none is left (then the flag and the event are cleared). The raw `wp-content/uploads/` file URL of such a photo is still served by the web server until it is migrated. A password-protected result is never shown publicly (list or photo), even if verified and consented.
- **Seeding.** `wp cc seed` gives each sample result a generated placeholder portrait in the private store (once per record).
- **Not changed.** Gallery item images are still ordinary media-library files: public once their item is published, and hidden from REST, oEmbed, redirects and listings while it is not (the raw file URL of an unpublished gallery image is still served by the web server).

## REST API

`GET /wp-json/cc/v1/courses` is public and returns `{ "items": [...] }`, with header `Cache-Control: public, max-age=60`.

| Param | Type | Description |
|---|---|---|
| `category` | string | Course Category slug, e.g. `academic`, `admission`, `skill`, `language` |
| `mode` | string | `physical`, `online` or `hybrid`. Any other value returns a validation error. |

Each item has: `id`, `title`, `url`, `excerpt`, `thumbnail`, `categories`, `modes`, `min_price`, `price_display`, `status`, `status_label`.

```bash
curl "http://localhost:8080/wp-json/cc/v1/courses?category=academic&mode=online"
```

## Seeding sample content

```bash
docker compose run --rm wpcli cc seed
```

`wp cc seed` is idempotent and also runs inside `setup.sh`. It creates sample categories, four faculty members, sample courses with batches, notices, the pages (Home, About, FAQ, Contact, Privacy, Terms, Admissions), sets the front page and builds the menu. Through the `cc_seed_extra` hook it also adds the sample blog articles, gallery items, results (including hidden unverified and non-consented samples), branches, and the Blog, Gallery and Results menu items.

## Admission and payment (sub-project 2)

The Admissions page (`/admissions/?batch=<id>`) shows the application form for an open batch. All admission logic lives in the plugin; the theme (`page-admissions.php`, `assets/js/admission.js`) only renders it.

### Flow

0. **Phone verification.** In the Contact section the applicant presses **Verify number**, receives a 6-digit code by SMS, and enters it. The server returns a signed, single-use `phone_proof` (see [Phone ownership proof](#phone-ownership-proof-at-application)). **Submit and pay** stays disabled until the number is verified, and the server refuses an application without a valid proof regardless.
1. The browser fetches a token (`GET /cc/v1/forms/admission-token`) and submits the form (`POST /cc/v1/applications`) with an `Idempotency-Key` header and the `phone_proof`.
2. The server validates the form, stores the photo, encrypts the ID number, and creates an application (`pending`) and an invoice (`INV-YYYYMMDD-<id>`) in one transaction.
3. The server asks the gateway to create a payment and returns `{ref, redirect_url}`. The browser goes to `redirect_url` (the gateway's checkout).
4. After paying, the gateway sends the payer to `GET /cc/v1/payments/callback?pid=...`. The `pid` is the only input and is **not trusted**: the server calls `execute()` and then `CC_Settlement::settle()`.
5. `settle()` runs in one DB transaction with row locks (payment, invoice, application, batch). It re-queries the gateway with `query()`, checks the amount equals the invoice amount, and checks the invoice is unpaid and the application is `pending`. On success it marks the payment `completed`, the invoice `paid`, the application `approved`, and increments `seats_taken` (only while `seats_taken < capacity`). After commit it fires `do_action( 'cc_application_settled', $application_id, $payment_id )` once.
6. The callback redirects to `/admissions/?ref=<ref>&paid=1` (or `paid=0`). That page polls `GET /cc/v1/applications/{ref}/status` every 3 seconds for up to 60 seconds and then shows success, failure (with **Retry payment**), or a pending message.

`settle()` results: `settled`, `already_settled` (idempotent repeat), `not_completed`, `mismatch` (wrong amount, void/paid invoice or non-pending application), `batch_full`. `mismatch` and `batch_full` set the payment to `reconcile_needed` and leave the application `pending`; they need a human.

**Reconciler.** `CC_Reconciler::run()` runs every minute (Action Scheduler recurring action, or WP-Cron event `cc_reconcile` if Action Scheduler is absent). It:

- settles payments in `initiated`/`executing` that are older than 3 minutes (for when the callback never arrived);
- cancels such payments once they are older than 30 minutes and still not paid;
- re-queries payments cancelled within the last 24 hours (at most once per 10 minutes each), so a payer who pays late is still approved. **The 24-hour late-payment window is a placeholder: verify against the real gateway** (its session expiry) before go-live. It is the `LATE_PAYMENT_WINDOW` constant in `class-reconciler.php`.

Each run handles at most 50 payments. It also runs the provisioning recovery sweep (see [Provisioning and recovery](#provisioning-and-recovery)).

### REST endpoints

Base: `/wp-json/cc/v1`. All are public (no login); abuse is limited by the idempotency key, a honeypot field (`company_site`, must be empty) and rate limits. Errors use `{ "code", "message", "details"? }`. Responses are `private, no-store`.

| Method and path | Purpose | Notes |
|---|---|---|
| `GET /forms/admission-token` | Returns `{ idempotency_key, honeypot }` (a UUID v4 and the honeypot field name). | |
| `POST /applications/verify-phone/request` | Body `{phone}`. Sends a verification code by SMS to the number. Returns `{ ok, phone (masked), expires_in: 300, resend_after: 60 }`. | Same `200` for registered and unregistered numbers. `422 invalid_phone`, `429 rate_limited`, `429 locked` (15 minute lock), `429 locked_daily` (24 hour lock), `429 resend_too_soon` (a code was requested for this phone in the last 60 seconds; body has `retry_after`, header `Retry-After`), `400 bot_check_failed` (captcha required, see below). Limits below. |
| `POST /applications/verify-phone/confirm` | Body `{phone, code}`. Returns `{ phone_proof, expires_in: 900 }`. | `422 invalid_code` (wrong, expired or already used), `429 locked` (5 wrong codes: 15 minutes), `429 locked_daily` (24 hours: 10 wrong guesses per phone per day coming from at least 3 different IP buckets), `429 rate_limited` (30 per IP per 15 minutes, or this IP made 10 wrong guesses in an hour). |
| `POST /applications` | Submit an application (multipart form with a `photo` file). Returns `{ ref, redirect_url }`. | Header `Idempotency-Key: <uuid v4>` required. Needs a valid `phone_proof` for `student_phone` (the guardian phone needs none): otherwise `422` with `details.phone_proof` ("Verify your phone number first."). `201` on create, `200` replaying the same key (same student phone and batch, no second application, no second proof needed), `409 idempotency_conflict` when the key is replayed with a different student phone or batch (the first applicant's ref is never returned). Limit 5 per IP per 10 minutes. |
| `POST /applications/{ref}/payment` | Start a new payment for an unpaid application. Returns `{ ref, redirect_url }`. | `{ref}` is the 26-character application reference. Limit 5 per ref per hour and 20 per IP per hour. |
| `GET /applications/{ref}/status` | Returns `{ status, payment_status }` only. | Limit 30 per minute per IP and ref, 120 per minute per IP. |
| `GET /payments/callback?pid=<gateway_payment_id>` | Payer return URL. Verifies and settles server-side, then `302` to `/admissions/?ref=<ref>&paid=<0 or 1>`. | Unknown `pid` returns `404`. Gateway outage redirects to `/admissions/?paid=0`. |
| `GET` and `POST /payments/fake/checkout` | Fake gateway page with Pay / Fail / Cancel buttons. | Registered only when the fake gateway is allowed (local/development and `CC_PAYMENT_GATEWAY=fake`). |

Form fields for `POST /applications`: `batch_id`, `full_name`, `gender` (`m`, `f`, `o`), `dob` (`YYYY-MM-DD`), `id_doc_type` (`nid`, `birth_cert`, `passport`), `id_doc_number` (5 to 30 letters, digits or dashes), `photo` (JPEG or PNG, up to 2 MB), `student_phone`, `phone_proof` (from `verify-phone/confirm`, bound to `student_phone`), `guardian_name`, `guardian_phone` (Bangladeshi mobile numbers, normalised to `+8801XXXXXXXXX`), `institution`, `class_level`, `consent` (required), and optional `email`, `passing_year`, `roll_no`, `branch_pref`.

### Phone ownership proof at application

An application attaches the student phone to a login account when it is provisioned, and an existing student's phone gets the new enrollment, the receipt and a "new course added" SMS. So the applicant must prove they control the number first. Implemented in `includes/admissions/class-rest-phone-proof.php` (routes), `class-phone-proof.php` (token) and the `apply` purpose of `CC_Otp`.

- **Code.** `POST /applications/verify-phone/request` sends the `apply_otp` SMS ("phone verification code for your admission application", English and Bangla) through `CC_Otp` with purpose `apply`: 6 digits, valid 5 minutes, only an HMAC stored, 5 wrong attempts lock that code for 15 minutes, single use. `apply` has its own failure counters, separate from login OTP, so a stranger guessing on the admission form cannot lock a student out of OTP login. Wrong guesses are counted per IP bucket (10 per hour, across phones: that IP alone is then refused, `rate_limited`), per phone and IP, and per phone. The 24 hour phone lock (`locked_daily`, message "Too many attempts. Try again tomorrow or contact us.") needs 10 wrong guesses on the phone from at least 3 distinct IP buckets, so one client cannot lock a victim out; the per-code 5 attempts (15 minute `locked`) is the phone-wide guess control. The real owner on another IP can still request and confirm a code while an attacker's IP is blocked.
- **Limits** (`CC_Rate_Limiter`): one code per phone per 60 seconds (server-side, `429 resend_too_soon` with `Retry-After`); per hour 3 requests per phone and IP, 10 per phone, 10 per IP, 300 in total; 15 per phone per day. Confirm: 30 per IP per 15 minutes. IPv6 clients are keyed on their /64 prefix (`CC_Rate_Limiter::ip_bucket_key()`; IPv4 unchanged). The request answer never depends on whether the phone is registered, and the registered state is not read at all.
- **Captcha and the global cap.** `request` runs the `cc_verify_captcha` filter (Cloudflare Turnstile, see the contact form) when `TURNSTILE_SECRET` is configured and, whatever the config, once the global bucket is past 70% of its hourly cap. The final `POST /applications` runs the same filter. The first time a window passes 90% one `error_log` line (no personal data) says so. **Turnstile on the form:** when `TURNSTILE_SITE_KEY` is set the admission page renders the widget (explicit render, `api.js` loaded only on this page) and `admission.js` sends the token as `cf-turnstile-response` with `verify-phone/request` (JSON body) and with the application POST (form field). A token is single use, so the widget is reset after every request attempt, and `Verify number` and `Submit and pay` stay disabled until a fresh token exists. Without a site key (local dev) no widget is rendered and the form behaves as before. **Set `TURNSTILE_SITE_KEY` and `TURNSTILE_SECRET` together:** a secret without a site key means no applicant can get a code or submit. Rendering is covered by `tests/integration/admission-turnstile-render-test.php` (run with `key` and `nokey`, as `tests/e2e/admission.sh` does); the server side by the Turnstile cases in `tests/integration/phone-proof-test.php`.
- **Behind a reverse proxy.** The limiter uses `REMOTE_ADDR` only (forwarded headers are client-controlled). Behind a proxy that is the proxy's address, so every client would share one bucket: configure the real client IP at the proxy/Apache layer (`mod_remoteip` with the Cloudflare ranges) BEFORE relying on any per-IP limit.
- **Proof.** `phone_proof` = `base64url({p: phone, e: expiry, n: nonce}) . hmac-sha256(key, payload)` with `key = hmac-sha256(wp_salt('auth'), 'cc_phone_proof')`. OTP hashes use `hmac-sha256(wp_salt('auth'), 'cc_otp')` the same way. This domain separation shipped without a fallback: proofs (15 min) and codes (5 min) issued before the deploy simply expire, so an applicant mid-verification during the deploy requests a new code. (Audit/inquiry/live IP hashes still use the raw salt.) Valid 15 minutes, bound to the normalised phone, unforgeable without the salt. It is single use: when the application is created the nonce is claimed with one `INSERT IGNORE` into `wp_options` (`cc_pp_used_<nonce>`; removed by the daily OTP cleanup). The claim is handed back if no application results (for example a duplicate-phone `409`), so the applicant can retry. A replay with the same `Idempotency-Key` returns the original response without a proof.
- **Not stored or logged.** No code, no proof token, no SMS body (the `apply_otp` template is a secret template like `otp`: variables are held encrypted in a short-lived transient).
- **Form.** The **Verify number** button, code field (`inputmode="numeric"`, `autocomplete="one-time-code"`), 60 second resend countdown, a polite live region for every state, and the number locked read-only once verified (**Change number** unlocks it and drops the proof). Editing the number invalidates the proof; the proof also expires client-side after about 15 minutes.
- **Provisioner.** `cc_applications.phone_verified_at` (UTC) is set when an application is created with a valid proof. `CC_Provisioner` attaches an application to an EXISTING student account only when it is set. Otherwise nothing is created or sent, one `application.attach_blocked` audit row is written (application id only, no personal data), the application stays approved and paid, and the admin Applications screen shows "Needs manual review: phone not verified" for it. A phone not yet registered still gets a new account (unchanged). Applications that predate phone proof have `NULL` and are covered by the same rule.
- **Backfill report (one-off).** `wp eval 'CC_Provisioner::audit_unverified_pending();'` prints, for pending and approved applications with `phone_verified_at IS NULL` whose student phone is already a student login, a count and the application ids (no phone numbers). Review them before approving.
- **Commit uncertainty.** If the application commit reports a failure, the proof and photo are released only when no application exists for that `Idempotency-Key`; otherwise they stay with it and the client's retry replays it.
- **Schema.** Migration v7 adds `apply` to the `cc_otp.purpose` ENUM (expand-only). `dbDelta` applies the ENUM change on its own; `run_v7` also has a guarded `ALTER TABLE` that is a no-op when the value exists.

Error codes seen by clients:

| Status and code | Meaning |
|---|---|
| 400 `idempotency_key_required` | Missing or invalid `Idempotency-Key`. |
| 400 `bot_check_failed` | Honeypot filled or `cc_verify_captcha` returned false. |
| 422 `validation_failed` | Field errors in `details` (field name to message), including closed batch and bad photo. |
| 409 `duplicate_phone` | Same student phone already has an application for this batch. |
| 409 `batch_closed` / `batch_full` | Batch no longer accepts applications / no seats left. |
| 429 `rate_limited` | Too many requests. |
| 503 `gateway_unavailable` | Gateway not configured or failed. Safe to try again shortly. |
| 404 `not_found` | Unknown application reference. |

### Retry rules (`POST /applications/{ref}/payment`)

Allowed only while the invoice is `unpaid` and the application is `pending`. Otherwise:

| Response | When | What to do |
|---|---|---|
| 409 `not_payable` | Invoice is not unpaid or application is not pending. | Nothing to pay. |
| 409 `already_paid` | The existing open payment was found completed on re-check. | Show success. |
| 409 `payment_needs_review` | Any earlier payment is `completed`, `reconcile_needed` or `refunded`, or the re-check ended in `mismatch`/`batch_full`. | Do not retry. Staff must resolve it; the applicant is told to contact the center. |
| 409 `payment_in_progress` | An earlier payment is still open (younger than 30 minutes) or another retry holds the per-invoice lock. | Wait a few minutes, then poll status. |
| 409 `batch_full` | No seats left. | Contact the center. |
| 503 `gateway_unavailable` | Re-check or payment creation failed. | Try again shortly. |

An older open payment is re-checked with the gateway before a new one is created, so an applicant cannot be charged twice for the same invoice.

### Environment variables

| Name | Required | Default | Description |
|---|---|---|---|
| `CC_PAYMENT_GATEWAY` | Yes | none (payments return 503 `gateway_unavailable` when unset) | Driver name. Only `fake` exists today. Read from a PHP constant first, then the environment. |
| `CC_ENC_KEY` | Yes outside `local` | none | Base64 of 32 random bytes; encrypts ID numbers and SMS payloads/bodies. In `local` only, a dev key derived from `wp_salt('auth')` is used. Generate with `openssl rand -base64 32`. |
| `CC_PRIVATE_DIR` | Yes outside `local` | `WP_CONTENT_DIR/../private` in `local` only | Directory for applicant photos, lesson PDFs and result photos (`photos/`, `resources/`, `result-photos/`), outside the web root. Docker sets `/var/www/private` (volume `private_data`). Uploads fail with a server error if unset elsewhere. |
| `CC_RATE_LIMIT_DISABLED` | No | unset | `1` disables rate limits, including login, OTP and password-change limits. **Local only**: ignored unless `WP_ENVIRONMENT_TYPE=local`. Needed for the admission e2e tests. |
| `CC_SMS_PRIMARY` | Yes (SMS fails without it) | none | Primary SMS driver name. Only `fake` exists today; read from a PHP constant first, then the environment. |
| `CC_SMS_FALLBACK` | No | none | Fallback SMS driver name, tried after the primary fails 3 times. |
| `TURNSTILE_SECRET` | Yes outside `local`/`development` | none | Cloudflare Turnstile secret for the Contact and Admission forms. Without it, contact submissions are refused outside local/development. Set it together with `TURNSTILE_SITE_KEY` (the admission page only renders the widget when the site key is set). PHP constant first, then environment. |

`CC_PAYMENT_GATEWAY`, `CC_ENC_KEY` and `TURNSTILE_SECRET` may also be defined as constants in `wp-config.php`. The compose file sets `CC_PAYMENT_GATEWAY`, `CC_PRIVATE_DIR`, `CC_RATE_LIMIT_DISABLED` and `CC_SMS_PRIMARY` for local use; it does not set `CC_ENC_KEY`. SMS variables are listed under [SMS module](#sms-module).

### The gateway interface

`wp-content/plugins/coaching-platform/includes/payments/interface-payment-gateway.php`:

| Method | Contract |
|---|---|
| `id(): string` | Short driver name, stored on each payment (e.g. `fake`). |
| `create_payment( array $invoice, string $callback_url ): array` | `$invoice` has `id`, `number`, `amount`, `currency`, `application_ref`, `payment_id`. Return `[ gateway_payment_id, redirect_url ]`. |
| `execute( string $gateway_payment_id ): array` | Called from the callback. Return `[ 'status' => completed\|failed\|cancelled\|pending, 'trx_id' => string, 'amount' => float, 'raw' => array ]`. |
| `query( string $gateway_payment_id ): array` | Same shape as `execute()`. Called by `settle()` as the source of truth. |

### bKash driver (`CC_Bkash_Gateway`)

Implemented in `includes/payments/class-bkash-gateway.php` and selected with `CC_PAYMENT_GATEWAY=bkash`. Settings (constants or environment, never the database): `BKASH_MODE` (`sandbox` or `live`, required), `BKASH_APP_KEY`, `BKASH_APP_SECRET`, `BKASH_USERNAME`, `BKASH_PASSWORD`. Register `https://<site>/wp-json/cc/v1/payments/callback` with bKash. The callback route accepts `paymentID` (bKash) as well as `pid` (fake) and treats the `status` query parameter only as a hint to skip `execute`; `settle()` always re-queries bKash. Settlement also checks the merchant invoice number and currency that bKash echoes. Calls use a 5 second timeout, the `id_token` is cached in a transient until 60 s before expiry, `execute` is never retried blindly (a failure falls back to `query`), and any ambiguous answer counts as `pending`, never `completed`.

**Verify in the bKash sandbox before launch** (written from the published tokenized-checkout API, never run against bKash): endpoint paths and `v1.2.0-beta` base URL, whether a retried payment on the same invoice may reuse `merchantInvoiceNumber`, the real session expiry (see `CC_Reconciler` constants), and the IPN question (OQ-05). The adapter notes below stay valid for any further gateway (Nagad, aggregator).

### Adding another payment driver

1. Create `includes/payments/class-<name>-gateway.php` with a class implementing `CC_Payment_Gateway` (`CC_Bkash_Gateway` is the reference), and load it where the other payment classes are required (see `coaching-platform.php`).
2. Register it in `CC_Gateway_Factory::make()` (`includes/payments/class-gateway-factory.php`): add a branch for the new name that returns the new class. Unknown names throw.
3. Set `CC_PAYMENT_GATEWAY=bkash` and put bKash credentials in secrets (not in compose or the repo).
4. **Use a short HTTP timeout, 5 seconds or less, on every gateway call** (e.g. `wp_remote_request( ..., array( 'timeout' => 5 ) )`). `query()` runs inside `settle()` while row locks are held on the payment, invoice, application and batch; a slow gateway would stall other settlements. On failure, throw: the callback and reconciler catch it and retry later.
5. Callback URL and `pid`: `create_payment()` receives `$callback_url` (`/wp-json/cc/v1/payments/callback`). Make bKash send the payer back to it with `pid=<gateway_payment_id>` appended, using the same `gateway_payment_id` you returned from `create_payment()`. The callback looks the payment up by `gateway` = `id()` and that `pid`. If bKash returns its own identifiers (for example a payment ID in different query parameters), you must map them so the route receives `pid`, which currently is the only parameter the route accepts. That mapping is not built.
6. `amount` returned by `query()` must be the amount actually charged; `settle()` compares it with the invoice amount to the cent.
7. Re-check the reconciler constants (`STALE_SECONDS`, `CANCEL_SECONDS`, `LATE_PAYMENT_WINDOW`) against bKash's real session expiry.
8. **Remove the fake gateway in production.** It is already refused unless the environment is `local` or `development` and `CC_PAYMENT_GATEWAY=fake`, and its checkout route is not registered otherwise. Still, do not deploy it: delete `class-fake-gateway.php` and its factory branch from the production build and set `WP_ENVIRONMENT_TYPE=production`.

Add tests for the new driver in the same style as `tests/integration/settlement-test.php`.

### Privacy: photo and ID number

- **ID document number**: encrypted before storage (libsodium secretbox via `CC_Crypto`, blob prefix `k1:`) in `id_doc_enc`. It is never returned by any endpoint. Losing `CC_ENC_KEY` makes stored numbers unrecoverable; keep it backed up outside the database. There is no key rotation tooling yet (the `k1` prefix only leaves room for it).
- **Photo**: MIME type is checked by content (JPEG/PNG only, 2 MB, 4000 px max), then decoded and **re-encoded** with GD, which removes EXIF data and any embedded payload. It is saved as `photos/<random>.jpg|png` (mode 0640) under `CC_PRIVATE_DIR`, outside the web root, and is not served by any route. Staff view photos through an authenticated admin stream (**Astona > Applications > View**); no public route serves them.
- The public status endpoint returns only `status` and `payment_status`. The application reference is a 26-character ULID and acts as the access token for status and retry.
- Client IP for rate limits is `REMOTE_ADDR` only (IPv6 keyed on the /64). Behind a proxy or load balancer this must be addressed first: restore the real client IP at the proxy/Apache layer (`mod_remoteip` with the Cloudflare ranges), see checklist.
- Phone numbers are stored normalised. `CC_Phone::mask()` exists for display.
- **Inquiries** (Contact form): a daily event `cc_inquiry_purge` deletes handled and spam inquiries older than 365 days and inquiries still `new` after 730 days (`CC_Inquiry_Repository::RETENTION_DAYS` and `NEW_RETENTION_MULTIPLIER`).

### Pre-production checklist

From the security reviews. None of this is done in this repository.

- [ ] Rename the `admin` user (login names are guessable) and use a strong password.
- [ ] Add login throttling and 2FA for wp-admin.
- [ ] Set HSTS and a Content-Security-Policy at the web server or proxy.
- [ ] Move all secrets out of `docker-compose.yml` (DB passwords, `CC_ENC_KEY`, bKash credentials) into a secret manager or untracked env.
- [ ] Turn `display_errors` off and set `WP_ENVIRONMENT_TYPE=production`.
- [ ] Set `CC_ENC_KEY` and `CC_PRIVATE_DIR` (outside the web root, not publicly served, backed up) and confirm `CC_RATE_LIMIT_DISABLED` is not set.
- [ ] Set `CC_PAYMENT_GATEWAY=bkash`, remove the fake gateway, and verify the reconciler's late-payment window against bKash.
- [ ] If behind a proxy, make rate limiting see the real client IP (currently `REMOTE_ADDR` only).
- [ ] Add a CAPTCHA (Turnstile) through the `cc_verify_captcha` filter if bots become a problem.
- [ ] Decide who (owner) resolves `reconcile_needed` payments: **Astona > Payments > Needs attention > Reconcile now**.
- [ ] Complete the [production requirements for sub-project 3](#production-requirements-for-sub-project-3) (real cron, real SMS driver, no fake drivers, object cache) and keep the SMS driver healthy: the admission form needs working SMS for [phone verification](#phone-ownership-proof-at-application) (risk L4 is mitigated by it).

## Accounts, SMS and student portal (sub-project 3)

A paid application becomes a student account that signs in with the phone number. Design spec: `docs/superpowers/specs/2026-10-07-accounts-sms-portal-design.md`. All logic is in the plugin; the theme only renders the pages.

### Student journey

1. Applicant pays (sub-project 2). `CC_Settlement::settle()` approves the application and fires `cc_application_settled`.
2. `CC_Provisioner` hears the hook and schedules the async action `cc_provision_student` (Action Scheduler, WP-Cron single event if Action Scheduler is absent).
3. Provisioning creates the WordPress user (role `cc_student`, login = phone digits, e.g. `8801XXXXXXXXX`), a `cc_students` row, an enrollment (status `active`) and links the application to the user. A new user gets a random 10-character temporary password (no look-alike characters), valid 72 hours, and must change it on first login.
4. A `credentials` SMS (phone, temporary password, login URL) is queued. A student who already has an account gets only the new enrollment and a "new course added" SMS (`enrolled`); their password is not touched.
5. The student opens `/student/login/`, signs in with phone + temporary password (or an OTP), and is sent to `/student/profile/?change=1` until the password is changed.
6. After that: dashboard, payments, receipt and profile.

The admission confirmation page tells the applicant an SMS with login details is on the way and links to `/student/login/`.

### Portal routes

Pages (rewrite rules from `CC_Portal_Router`, templates in the theme). All send `Cache-Control: private, no-store` and `X-Robots-Tag: noindex, nofollow`. Every page except the login page redirects to `/student/login/?redirect=...` when not signed in as a student.

| Path | Template | Content |
|---|---|---|
| `/student/` | `student-dashboard.php` | My courses, schedule (today and coming up this week), notices and quick links |
| `/student/login/` | `student-login.php` | Phone + password, and "Login with OTP" |
| `/student/payments/` | `student-payments.php` | Own payments (date, amount, status, transaction id) with receipt links |
| `/student/payments/receipt/?id=<payment id>` | `student-receipt.php` | Printable receipt; only for the owner's own payment |
| `/student/profile/` | `student-profile.php` | Name and phone read-only, editable guardian name, guardian phone and email, password change |
| `/student/courses/` | `student-courses.php` | Enrolled courses |
| `/student/courses/<batch id>/` | `student-course.php` | Live classes (Join) and lessons for one batch |
| `/student/notices/` | `student-notices.php` | Public and batch-targeted notices for the student |
| `/student/resources/<lesson id>/` | (streamed file) | Lesson PDF download, only for enrolled students |

REST (base `/wp-json/cc/v1`, all responses `private, no-store`, errors `{ "code", "message", "details"? }`):

| Method and path | Auth | Purpose |
|---|---|---|
| `POST /auth/login` | public | Body `{phone, password, remember?}`. Returns `{redirect}`. |
| `POST /auth/otp/request` | public | Body `{phone}`. Always the same `200` for registered and unregistered phones. |
| `POST /auth/otp/verify` | public | Body `{phone, code, remember?}`. Returns `{redirect}`. |
| `POST /auth/logout` | logged in + `X-WP-Nonce` | Destroys the session. |
| `POST /me/password` | student + `X-WP-Nonce` | Body `{current_password, new_password}`. New password 10 to 128 characters. |
| `PATCH /me/profile` | student + `X-WP-Nonce` | Any of `guardian_name`, `guardian_phone`, `email`. Returns `{message, profile}`. |
| `POST /live-classes/{id}/join` | student | Returns the meeting link only for an enrolled student, from 15 minutes before the start until the end. Limit 10 per minute per user. |
| `POST /contact` | public | Contact form (`name`, `phone`, `email`, `topic`, `course_id`, `message`). Honeypot `company_site`, Turnstile, rate limits. Stores an inquiry. |

`redirect` is `/student/`, or `/student/profile/?change=1` while the temporary password is still active. Sessions use the WordPress defaults (2 days, 14 with `remember`). Error codes: `invalid_credentials` (401), `invalid_code` (401), `rate_limited` (429), `invalid_phone` (422, OTP request only), `wrong_password` (403), `weak_password` (422), `same_password` (422), `validation_failed` (422, profile, field messages in `details`).

Profile email: the applicant's email is unverified, so it is **never** used as the WordPress login email (that is a placeholder `<digits>@students.invalid`). It is stored in user meta `cc_application_email`. If another account already uses the same email the update is skipped silently, so the response never reveals registered emails.

### OTP rules

Implemented in `includes/auth/class-otp.php`.

- 6 digits, valid 5 minutes, single use. Only `hmac-sha256(hmac-sha256(wp_salt('auth'), 'cc_otp'), phone . code)` is stored; requesting a new code invalidates the previous one.
- 5 wrong attempts on a code lock that phone for 15 minutes (a new code cannot reset the counter).
- 10 wrong guesses per phone within 24 hours lock OTP request and verify for that phone for 24 hours. Failures are counted, not requests.
- Request limits: 3 per phone and IP per hour, 10 per phone per hour, 10 per IP per hour. Verify: 30 per IP per 15 minutes.
- Generic errors: an unknown phone, a wrong code, an expired code and a locked phone all return the same `Invalid or expired code`. The request endpoint returns the same `200` whether or not the phone is registered, and every request takes at least 0.3 seconds so timing does not reveal it.
- Purpose `apply` (admission phone verification) follows the same rules with its own failure counters (per IP bucket, per phone and IP, per phone; the 24 hour lock needs failures from 3 distinct IP buckets), and uses the `apply_otp` SMS text. Its request limits are in [Phone ownership proof at application](#phone-ownership-proof-at-application).
- Expired OTP rows (and used phone-proof claims) are removed by a daily cleanup event.

### Login throttling and back-off

Implemented in `includes/auth/class-rest-auth.php` using `CC_Rate_Limiter` (successful logins are never counted).

- Per IP: 10 login attempts per 15 minutes.
- Per phone and IP: 5 failed attempts per 15 minutes. A stranger cannot use up the owner's allowance because the owner is on a different IP.
- Per phone, any IP: after 5 failures in 24 hours, further attempts from IPs that have not signed in successfully for that phone in the last 30 days are refused for `30 s x 2^n` (n = failures beyond the first 5, counted from 0), capped at 15 minutes. Refused attempts are not evaluated, so they cannot be used to guess. A phone and IP that signed in before is exempt, so an attacker cannot lock the real owner out of their usual network.
- Unknown phone, wrong password, expired temporary password and non-student account all return `Invalid phone or password`. An unknown phone still costs one password hash check.
- Over a limit: `429 rate_limited` with `Too many attempts. Please wait a while and try again.`
- Password change: 10 attempts per user per 15 minutes.

### Password change and OTP re-authentication

`POST /me/password` needs the current password. A student who signed in with an OTP can leave `current_password` empty instead: the OTP login marks that login session as recently authenticated for 15 minutes. The mark is bound to that one session and is used up by the change (a password change destroys all sessions and signs the browser back in with a fresh one). The response includes a new REST `nonce`, because the old one is tied to the old session. This is also how a student whose temporary password expired gets back in: OTP login, then set a new password.

### Student lockdown

`CC_Roles` and `CC_Student_Lockdown` keep `cc_student` accounts out of every core WordPress path:

- No wp-admin: students are redirected to `/student/` (admin-ajax is allowed) and have no admin bar. A student opening `wp-login.php` is redirected to the portal (logout still works).
- No `wp-login.php` authentication: a student's credentials are rejected there with a generic error.
- No lost-password: reset is refused, an old reset link is redirected to the student login, and the response matches an unknown account.
- No application passwords, and no core user REST: `/wp/v2/users` is removed for students and returns 403, including writes.

Students sign in only through `/cc/v1/auth/*`.

### SMS module

Code in `includes/sms/`.

- **Driver interface** `CC_Sms_Driver`: `id(): string` and `send( string $to_e164, string $body ): array` returning `[ ok (bool), provider_id (string), error (string) ]`.
- **Selection**: `CC_Sms_Factory` reads `CC_SMS_PRIMARY` and `CC_SMS_FALLBACK` (PHP constant first, then environment variable). Unset primary or an unknown name makes sending mark the log row `failed` with a clear error; it never crashes a request. Unset fallback means no fallback.
- **Fake driver** (`fake`): writes messages, including credentials and OTP bodies, to the non-autoloaded option `cc_fake_sms_outbox` (last 50). It refuses to run unless the environment is `local` or `development` **and** `CC_SMS_PRIMARY` or `CC_SMS_FALLBACK` is `fake`. Read it with `docker compose run --rm -T wpcli option get cc_fake_sms_outbox --format=json`.
- **Templates** (`CC_Sms::TEMPLATES`, English and Bangla): `credentials`, `otp`, `apply_otp`, `receipt`, `enrolled`. Pass `lang => 'bn'` in the variables for Bangla.
- **Queue**: `CC_Sms::queue( $to, $template, $vars, $related_type, $related_id )` writes a `cc_sms_log` row and schedules the `cc_send_sms` action.
- **Secrets**: for `credentials`, `otp` and `apply_otp` the message text is never stored. `cc_sms_log` keeps only a random hash; the variables are held encrypted (`CC_Crypto`) in a transient for 1 hour, the body is rendered at send time, and the transient is deleted when the message is sent or finally fails. Error text from drivers has the secret values replaced with `***`. `receipt` and `enrolled` bodies are stored encrypted in `body_enc`. Failure logs show only masked phone numbers.
- **Retry**: up to 3 attempts on the primary (30 seconds before attempt 2, 120 seconds before attempt 3), then one attempt on the fallback, then status `failed` (written to `error_log` without secrets). A `failed` credentials SMS does not lock the student out: they can use OTP login, if that code SMS can be delivered.
- **Segments**: `CC_Sms::segments()` counts GSM-7 as 160 per message (153 per part when split) and anything else as UCS-2 at 70 (67 per part). The count is saved in `cc_sms_log.segments`.

#### SMS drivers: BulkSMSBD, GreenWeb and adding another

Built in: `bulksmsbd` (`BULKSMSBD_API_KEY`, `BULKSMSBD_SENDER_ID`) and `greenweb` (`GREENWEB_TOKEN`), both on `CC_Sms_Http_Driver`. Set `CC_SMS_PRIMARY` and optionally `CC_SMS_FALLBACK` to one of them (or one each, as the SDD recommends). A missing credential makes `send()` fail with the setting name (never a value), so the retry and fallback logic applies. To add a different gateway:

1. Create `wp-content/plugins/coaching-platform/includes/sms/class-sms-<name>-driver.php` with a class that implements `CC_Sms_Driver`. `send()` returns `[ true, '<provider message id>', '' ]` on success and `[ false, '', '<short error>' ]` on failure. Do not put secrets in the error text. Exceptions are caught and treated as a failed attempt.
2. Load the file by adding it to the list in `coaching-platform.php` (next to `sms/class-sms-fake-driver`) and register it: `CC_Sms_Factory::register_driver( '<name>', static fn() => new CC_Sms_<Name>_Driver() );`. Names are matched lower-case. Register it before the first send (for example at the end of the driver file or on `plugins_loaded`).
3. Set `CC_SMS_PRIMARY=<name>` and, if wanted, `CC_SMS_FALLBACK=<other name>`. Put gateway credentials in secrets (constants or environment), not in the repo or compose file.
4. **Use a short HTTP timeout on every gateway call**, for example `wp_remote_post( ..., array( 'timeout' => 5 ) )`. SMS sending runs in the scheduler; a slow gateway delays other queued jobs. Return `ok = false` or throw on failure so the retry and fallback logic applies.
5. **Bangla needs Unicode.** Send Bangla bodies as UCS-2 (gateways usually have a flag or message type for Unicode): 70 characters in one segment, 67 per part when split. The Bangla templates are longer than one segment. Check the gateway's sender ID and Unicode rules (OQ-02).
6. The recipient is E.164 (`+8801XXXXXXXXX`); convert to the gateway's expected format inside the driver if needed.
7. Add tests in the style of `tests/integration/sms-test.php`.

### Provisioning and recovery

`CC_Provisioner::provision( $application_id )` is idempotent and holds a MySQL lock per application. A replayed hook yields one user, one enrollment and one SMS.

- The application must be `approved` with a valid student phone. If the login already belongs to a non-student account, provisioning fails (it never converts that account).
- A user created by this application is tagged with user meta `cc_provisioned_by_application`. The credentials/enrolled SMS is the last step. If a retry finds that user but no `cc_sms_log` row for the application (`related_type` `application`), the temporary password was never delivered: it is reset once and the SMS queued. Once an SMS row exists, nothing is touched again. A row with status `failed` is a delivery problem, not a provisioning one, and is not resent.
- Failures are logged (application id, attempt, exception class only) and retried by the scheduler with exponential backoff, at most 5 attempts per queued run.
- **Reconciler sweep.** Each reconciler run (every minute) looks for applications that are `approved`, have no user, and were last updated more than 120 seconds ago (up to 20 per run, oldest first), and queues provisioning for them unless a provisioning action is already pending. This covers a crash between the settlement commit and the `cc_application_settled` hook. An application that can never be provisioned (for example a non-student account owns the phone) is picked up again on later runs; watch the PHP error log for `cc provisioning failed`.

### Risk L4 (mitigated): phone ownership proof at application

Earlier, a stranger could apply and pay with someone else's registered phone number, which attached an enrollment, a receipt and a "new course added" SMS to that person's account. Mitigation: the applicant must prove ownership of the student phone with an SMS code before an application can be created (see [Phone ownership proof at application](#phone-ownership-proof-at-application)). The server enforces it on `POST /applications`. Residual points: the code travels by SMS, so a SIM swap or a shared phone defeats it; the guardian phone is not verified (it is never used to create or find an account).

### Production requirements for sub-project 3

- **Run the scheduler from a real cron.** WP-Cron only fires when someone visits the site, so provisioning, SMS and the reconciler stall on a quiet site. Add `define( 'DISABLE_WP_CRON', true );` to `wp-config.php` and run, every minute, either `wp action-scheduler run` or a system cron request to `wp-cron.php`:
  ```bash
  * * * * * cd /path/to/wordpress && wp action-scheduler run >/dev/null 2>&1
  ```
  Docker compose in this repo does not set `DISABLE_WP_CRON`; locally the queue runs on page traffic.
- **`CC_ENC_KEY` is required.** Without it outside `local`, encrypted SMS payloads cannot be written or read.
- **Remove local-only settings:** do not set `CC_RATE_LIMIT_DISABLED`, set `CC_SMS_PRIMARY` to a real driver (never `fake`), and remove the fake SMS and payment drivers from the production build. `WP_ENVIRONMENT_TYPE` must not be `local` or `development`.
- **Register a real SMS driver** (see above) and set `CC_SMS_PRIMARY`, optionally `CC_SMS_FALLBACK`.
- **Use an external object cache** (Redis or Memcached) for the rate limiter. Without one, counters are rows in `wp_options` (atomic, but extra database writes on every login, OTP and status request).
- Rate limiting and trust checks use `REMOTE_ADDR`; behind a proxy this must be addressed (see the checklist above).

### Admin roles (`cc_owner`, `cc_staff`, `cc_instructor`)

The plugin creates these roles and their `cc_` capabilities. When the plugin bumps `CC_Admin_Roles::VERSION`, the roles are reset to the definitions in code: capabilities an owner removed from one of these roles by hand are added back (and extra ones are removed). Give additional access to individual users rather than editing the roles.

## Tests

Start the stack first (`docker compose up -d` and `./scripts/setup.sh`) for everything except unit tests.

```bash
# Unit tests: run every tests/unit/*Test.php in a throwaway php:8.3-cli container (no stack needed)
tests/unit/run.sh

# Integration tests: run inside the wpcli container against the dev database.
# They insert fixture rows and remove them afterwards.
docker compose run --rm -T wpcli eval-file /tests/integration/settlement-test.php
docker compose run --rm -T wpcli eval-file /tests/integration/admission-test.php
docker compose run --rm -T wpcli eval-file /tests/integration/sms-test.php
docker compose run --rm -T wpcli eval-file /tests/integration/provision-test.php
docker compose run --rm -T wpcli eval-file /tests/integration/auth-test.php
docker compose run --rm -T wpcli eval-file /tests/integration/phone-proof-test.php
docker compose run --rm -T wpcli eval-file /tests/integration/portal-test.php
docker compose run --rm -T wpcli eval-file /tests/integration/security-test.php
docker compose run --rm -T wpcli eval-file /tests/integration/admin-core-test.php
docker compose run --rm -T wpcli eval-file /tests/integration/admin-applications-test.php
docker compose run --rm -T wpcli eval-file /tests/integration/admin-students-test.php
docker compose run --rm -T wpcli eval-file /tests/integration/admin-payments-test.php
docker compose run --rm -T wpcli eval-file /tests/integration/refund-test.php
docker compose run --rm -T wpcli eval-file /tests/integration/content-test.php
docker compose run --rm -T wpcli eval-file /tests/integration/live-test.php
docker compose run --rm -T wpcli eval-file /tests/integration/notices-test.php
docker compose run --rm -T wpcli eval-file /tests/integration/portal-courses-test.php
docker compose run --rm -T wpcli eval-file /tests/integration/blog-test.php
docker compose run --rm -T wpcli eval-file /tests/integration/gallery-test.php
docker compose run --rm -T wpcli eval-file /tests/integration/results-photo-test.php
docker compose run --rm -T wpcli eval-file /tests/integration/contact-test.php
docker compose run --rm -T wpcli eval-file /tests/integration/media-test.php
docker compose run --rm -T wpcli eval-file /tests/integration/seat-recount-test.php

# Smoke test: public pages, courses REST, JSON-LD, user enumeration
BASE_URL=http://localhost:8080 tests/e2e/smoke.sh

# Admission API end to end with curl and the fake gateway (reads the verification code from the fake SMS outbox via wpcli)
BASE_URL=http://localhost:8080 OPEN_BATCH=2 CLOSED_BATCH=7 tests/e2e/admission.sh

# Student login, password change, OTP and lockdown over HTTP (creates and deletes a throwaway student)
BASE_URL=http://localhost:8080 tests/e2e/auth.sh

# More curl-based e2e scripts (each creates throwaway fixtures through the wpcli container and removes them on exit)
BASE_URL=http://localhost:8080 tests/e2e/admin.sh              # admin screens (throwaway owner, batch, applications)
BASE_URL=http://localhost:8080 tests/e2e/live.sh               # live-class Join window and privacy
BASE_URL=http://localhost:8080 tests/e2e/portal-courses.sh     # student courses, lessons, PDF access
BASE_URL=http://localhost:8080 tests/e2e/notices-privacy.sh    # targeted notices must not leak on public surfaces
BASE_URL=http://localhost:8080 tests/e2e/blog.sh               # blog states (published, archived, scheduled, draft)
BASE_URL=http://localhost:8080 tests/e2e/gallery.sh            # gallery and results visibility rules
BASE_URL=http://localhost:8080 tests/e2e/media-privacy.sh      # images of unpublished gallery items
BASE_URL=http://localhost:8080 tests/e2e/results-photo.sh       # private result photos, signed URLs, 404s, nothing under uploads
BASE_URL=http://localhost:8080 tests/e2e/contact.sh            # contact form and inquiry (needs CC_RATE_LIMIT_DISABLED=1)

# Browser tests in headless Chromium (need Node 18+ and Playwright; see below)
npm i playwright && npx playwright install chromium    # once
BASE_URL=http://localhost:8080 BATCH=2 node tests/e2e/admission-browser.mjs
BASE_URL=http://localhost:8080 BATCH=2 node tests/e2e/student-browser.mjs
BASE_URL=http://localhost:8080 BATCH=2 node tests/e2e/admin-browser.mjs          # Astona admin as owner/staff/instructor
BASE_URL=http://localhost:8080 BATCH_A=4 BATCH_B=6 node tests/e2e/content-live-browser.mjs   # two open batches with no enrollments
BASE_URL=http://localhost:8080 node tests/e2e/v1-browser.mjs                     # Media, Blog, Gallery, Results, Contact, Inquiries
```

**Running the browser scripts.** Playwright is not a dependency of this repo (there is no `package.json`). Install it in any directory, then run `node` from that directory with the path to the script. Each script first tries to import `playwright` from its own location and then from the current working directory, so for example:

```bash
mkdir -p ~/pw && cd ~/pw && npm i playwright && npx playwright install chromium
BASE_URL=http://localhost:8080 BATCH=2 node "/path/to/astona-site/tests/e2e/student-browser.mjs"
```

`student-browser.mjs` reads the fake SMS outbox through `docker compose run --rm -T wpcli ...` and must therefore see the repo's compose file. Run it with the repo root as the working directory, or set `WPCLI` to a command that works from where you are (the script runs it from the repo root).

- Unit tests (`tests/unit/*Test.php`, run by `tests/unit/run.sh`) cover the status chip, the foundation classes, SMS rendering, admin core, live-class state, content, blog, gallery, contact and media rules.
- The integration tests added with sub-projects 4 to 6 cover admin roles/audit/settings/dashboard, applications, students, payments, course content, live classes, notices, portal courses, blog, gallery and results, contact and inquiries, and media.
- The browser scripts for sub-projects 4 to 6 need the seeded open batches (`BATCH`, or `BATCH_A` and `BATCH_B` with no enrollments) and the fake gateway and SMS driver. They create and remove their own users and fixtures.
- Integration tests cover settlement (happy path, double settle, mismatch, batch full), the reconciler and admission create/retry guards.
- `admission.sh` and `admission-browser.mjs` need `CC_PAYMENT_GATEWAY=fake` and `CC_RATE_LIMIT_DISABLED=1` (both set in `docker-compose.yml`). `OPEN_BATCH` and `CLOSED_BATCH` / `BATCH` are batch IDs; the defaults (2 and 7) must exist and be open / closed in your seeded data, otherwise pass the right IDs.
- `admission-browser.mjs` covers the happy path at 375px and 1280px, server-side validation errors, duplicate phone, and Fail then Retry then Pay, plus a phone verification journey (submit disabled before verify, wrong code, success locks the field, editing the phone resets it, resend cooldown, lockout after 5 wrong codes, a bad proof refused by the server, keyboard use). The 429 rate-limit message is not exercised there because the limiter is off locally; `phone-proof-test.php` covers the limits. It exits 1 on any failure.
- `phone-proof-test.php` covers request/confirm (happy path, wrong code, expiry, lockout, daily cap), identical answers for registered and unregistered phones, proof binding to the phone, forgery and tampering, expiry, single use, applications refused without or with another phone's proof, idempotent replay, the rate limits, and that no code, proof or SMS body is stored or logged. Application creation is exercised over HTTP from inside the compose network (`http://wordpress/`).
- Every e2e script that creates an application verifies the phone first through shared helpers: `tests/e2e/lib/phone-proof.sh` (curl scripts) and `tests/e2e/lib/phone-proof.mjs` (browser scripts), which read the code through `tests/e2e/lib/read-apply-code.php` (wpcli, fake SMS driver, local only).
- `sms-test.php` covers queue and send via the fake driver, that credentials/OTP secrets are not persisted, segments, retry then fallback then failed, backoff rescheduling, the driver factory and fake-driver gate, and Action Scheduler running a queued send. `provision-test.php` covers new student, replay idempotency, existing student with a second course, retry after a partial failure, guards, repository and roles, and the reconciler recovery sweep. `auth-test.php` covers OTP and login flows (hashing, expiry, lockouts, uniform errors, throttling). `portal-test.php` covers portal data including owner-only receipts. `security-test.php` covers student lockdown and the account-takeover fixes (it also calls `http://wordpress/` from inside the compose network).
- `auth.sh` covers the login page, login and forced password change, session and nonce handling, no-store headers, and wp-admin blocking for a student.
- `student-browser.mjs` runs at 375px and 1280px: apply, pay with the fake gateway, read the temporary password from the fake SMS outbox, log in, forced password change, dashboard, payments, receipt, profile validation, logout, relogin; at 1280px also OTP login and lockout, uniform errors, redirects, wp-admin block, another student's receipt, keyboard-only login, accessibility checks, console errors, overflow and Bangla text. It needs the same open batch (`BATCH`, default 2) and `CC_PAYMENT_GATEWAY=fake`, `CC_SMS_PRIMARY=fake` (both set in `docker-compose.yml`), and clears rate-limit transients before each phase (dev only).
- Unit tests also cover the SMS renderer and segment counter (`SmsTest.php`), the BulkSMSBD and GreenWeb drivers (`SmsDriversTest.php`), the bKash driver with a scripted HTTP transport (`BkashGatewayTest.php`), and GA4 server events plus the health check (`Ga4HealthTest.php`). `refund-test.php` covers marking payments refunded.
- Each script exits non-zero on failure.

## Operations and troubleshooting

- **The demo form says "batch full" after many test runs.** Test runs can leave `cc_batches.seats_taken` drifted. Run `docker compose run --rm -T wpcli cc recount-seats` (options: `--batch=<id>`, `--dry-run` to only print before/after). It sets each batch to its approved applications plus the seeded baseline (seats the sample data starts with, recorded by `scripts/setup.sh` right after seeding into option `cc_seat_baseline`; `wp cc recount-seats --capture-baseline` records it again on a freshly seeded database). Approved applications left behind by tests still count, so remove those rows first if they are what fills the batch. CI runs it before the curl e2e suites.
- **Running the browser journeys.** Headless-Chromium end-to-end journeys live in `tests/e2e/`: `admission-browser.mjs` (apply form, phone verification, payment; about 30 s), `student-browser.mjs` (login, portal, receipts, a11y; about 1 min), `admin-browser.mjs` (admin screens and role gating), `v1-browser.mjs` (media, blog, gallery, results, contact; several minutes) and `content-live-browser.mjs` (content visible on the public site). They are not part of CI and need the docker compose dev stack. Playwright is not a project dependency: install it once in a scratch directory (`npm i playwright && npx playwright install chromium`), then run from that directory `BASE_URL=http://localhost:8080 node "/path/to/astona-site/tests/e2e/<journey>.mjs"` (Playwright is resolved from the current directory). Each journey creates its own throwaway fixtures (users, applications, content) and deletes them when it finishes; exit code 0 means all checks passed. If a run reports "batch full" or leaves seat counts drifted, run `docker compose run --rm -T wpcli cc recount-seats`.
- **Result photo migration pending notice.** See "Private result photos > Existing photos".

## Before launch: replace sample content

Everything seeded is synthetic.

- **Contact details**: the owner sets them in **Astona > Settings** (they override the theme defaults). Also the seeded Contact page text (edit in Pages), and the fallback defaults in `astona_contact()` in `wp-content/themes/coaching-theme/functions.php` (`+880 1700-000000`, `info@astona.example`, "Sample address, Dhaka").
- **Privacy Policy and Terms**: DRAFT text built from what the system does, with a visible draft banner. A Bangladeshi lawyer must review it, and the owner must fill the `[OWNER TO CONFIRM]` items (OQ-10; refund policy OQ-06). See [docs/LEGAL-DRAFTS.md](docs/LEGAL-DRAFTS.md).
- **Brand**: logo, colours and typography use neutral tokens until brand guidelines arrive (OQ-12). See [docs/BRANDING.md](docs/BRANDING.md) ("apply your brand in 15 minutes").
- **Courses, batches, faculty, notices, FAQ, About**: sample data. Replace with real Bangla/English content.
- **Credentials**: the Compose DB passwords are dev-only. The admin password from `setup.sh` is for local use. See [Pre-production checklist](#pre-production-checklist).
- `docker-compose.yml` sets `WP_ENVIRONMENT_TYPE=local`. Production hosting is not defined in this repo.

### Before launch: owner inputs

Nothing below can be decided by the developers. None of it has been provided yet.

- [ ] Brand: colours (hex), logo files, typeface, Bangla typeface preference, tone ([docs/BRANDING.md](docs/BRANDING.md), section 8).
- [ ] Legal: lawyer reviews the Privacy Policy and Terms, owner answers the `[OWNER TO CONFIRM]` list, draft banner removed ([docs/LEGAL-DRAFTS.md](docs/LEGAL-DRAFTS.md)).
- [ ] Refund policy (OQ-06) and data-retention periods for applications, payments and logs (OQ-10).
- [ ] Registered business name and address, data-protection contact and email, governing law.
- [ ] Real contact details in **Astona > Settings** (phone, email, address) and branch details.
- [ ] Emergency contacts, owners and escalation order ([docs/DEPLOYMENT.md](docs/DEPLOYMENT.md), section 11).
- [ ] Payment: bKash merchant account (OQ-05). SMS: provider, sender ID, Unicode rules (OQ-02).
- [ ] Hosting provider and server location (OQ-10), recovery-time target for backups.
- [ ] Real courses, batches, faculty, FAQ, About text (Bangla and English).

## Not built yet

Not part of sub-projects 1 to 6:

- **Admin completion and hardening.** The admin screens, roster, ledger, live classes, targeted notices and course content exist. The owner now has an **SMS log** screen (failed messages first, numbers masked, no message text). Still missing: refund policy and any money-moving refund (OQ-06).
- **Live verification of the real drivers.** The bKash (`CC_Bkash_Gateway`), BulkSMSBD and GreenWeb drivers exist and are unit-tested with a scripted transport, but have never talked to the providers. Run them against the bKash sandbox and a test SMS account first. Which SMS provider is primary is still OQ-02; sender ID approval and Bangla (Unicode) rules must be confirmed.
- **CAPTCHA on admission.** Turnstile is wired in (widget shown when `TURNSTILE_SITE_KEY` is set, enforced when `TURNSTILE_SECRET` is set; see the admission section). Without keys only a honeypot and rate limits apply, and only in local/development for the submit.
- **Waitlist.** A full batch hard-closes.
- **Installments.** Full payment only.
- **Refund workflow.** Staff with the reconcile capability can mark a completed payment refunded (releases the seat, revokes access, audit-logged; see `tests/integration/refund-test.php`). Nothing moves money back through bKash and no refund policy is encoded (OQ-06).
- **Phone change.** The phone is shown read-only ("contact us").
- **SMS password recovery beyond OTP login.** There is no separate reset flow; students use OTP login and then change the password.
- **Parent accounts.**
- **Staff 2FA, `/admin/login` slug and the React nested course editor** (TRD FR-011/FR-013): not built; use the Two Factor plugin and the existing course content screens meanwhile.
- Also out of scope so far: Redis, Nginx cache, Sentry, PDF receipts (HTML receipts only), retention purge for applications and payments (OQ-10).

Remaining work (from `03_RTM_ADR_Questions_Plan.md`) and the open questions blocking it:

| Next sub-project | Blocked by |
|---|---|
| Sandbox verification of the bKash driver | OQ-05 (bKash merchant account, IPN), OQ-06 (refund policy) |
| Live verification of the SMS drivers | OQ-02 (SMS gateways, sender ID) |
| Launch review of lesson PDF downloads | OQ-09 (PDF lesson downloads at launch) |
| Admin completion and hardening (React editor, Redis, Nginx cache) | Depends on earlier sub-projects |
| Hosting and launch | OQ-10 (data residency, retention, legal) |

OQ-12 (brand guidelines) blocks the final visual design. Each question has a default assumption in the planning doc, so work can proceed before answers arrive. Defaults used here: full payment (OQ-03), hard close when full (OQ-07), refund as status only (OQ-06), no reliance on IPN (OQ-05).

## Contributing

No branch strategy or CI is defined in this repository yet. Missing info below.

## License

Not specified.

## Missing info

- License and CI setup.
- Branch and PR conventions.
- Staging and production URLs and deploy process.
- Real contact details, legal text and brand assets.
- Production hosting, the production `CC_ENC_KEY` custody and the real bKash credentials and callback registration.
- bKash session expiry (needed to confirm the 24-hour late-payment window).
- `TURNSTILE_SECRET` value and site key for the Contact form.
- Chosen SMS gateway, sender ID, its credentials and Unicode (Bangla) rules (OQ-02).
- Production cron setup (who runs `wp action-scheduler run` every minute) and the object cache provider.
