# Audit against the PRD, TRD and SDD

Audited the code in this repository against `Coaching Center Digital Platform: PRD v1.0`, `01 TRD` and `02 SDD` (all WordPress-first), then built what was missing. Two passes: the first audit and fixes, then the remaining TRD/SDD items and the open business questions.

## How this was checked, and what was not

- **Real stack.** Everything was run against a real WordPress 6.8.11 (core from Composer) and MariaDB 10.11, served by PHP, with Playwright and Chromium for browser journeys. Docker was not available, so the compose files, Apache config, Cloudflare/Redis and the GitHub Actions workflow are untested here.
- **Passing here:** `php -l` on every PHP file, `node --check` on every JS file, all `tests/unit/*Test.php`, **all 29 integration suites** (`tests/integration/*-test.php`), and the browser journeys `admission-browser.mjs` and `course-editor-browser.mjs`. See the results note at the end for the other journeys.
- **Not verified:** the bKash driver (incl. refunds), BulkSMSBD and GreenWeb against the real providers (only a scripted transport), Cloudflare Turnstile, the Nginx/Apache production setup, backups, and live 2FA sign-in in the compose stack (the compose file runs staff security relaxed, see README).
- The requirement matrix reflects the repository's documentation and spot-checked code; it is not a line-by-line review of all ~20k lines.

## Defects found and fixed (first pass)

| # | Finding | Fix |
|---|---|---|
| 1 | `tests/integration/refund-test.php` did not parse and was missing from CI. | Fixed; in CI. |
| 2 | No real payment driver: production payments returned 503. | `CC_Bkash_Gateway` (tokenized checkout); callback accepts `paymentID`/`status`. |
| 3 | `settle()` compared only the amount. | Also invoice number and currency when the gateway reports them. |
| 4 | No real SMS driver. | BulkSMSBD and GreenWeb drivers; fallback via the existing retry logic. |
| 5 | JSON-LD lacked `CourseInstance`, listed courses with fewer than 3, no `WebSite`. | `CC_Seo` rebuilt; unit tested. |
| 6 | GA4 absent. | Consent-gated browser events and server-side `payment_success`. |
| 7 | No health endpoint. | `GET /wp-json/cc/v1/health` and a reconciler heartbeat. |
| 8 | No admin view of failed SMS. | Owner-only **Astona > SMS log**. |

## Built in the second pass

| Item | What exists | Notes |
|---|---|---|
| Staff 2FA (FR-011) | Built-in TOTP (RFC 6238 vectors tested), recovery codes, replay protection, rate limits, enforced enrolment gate, `wp cc 2fa-reset` | No QR code: key or `otpauth://` link. |
| `/admin/login` | wp-login.php 404s; all WordPress login/logout/reset links rewritten | Cloudflare/proxy rules in `docs/DEPLOYMENT.md`. |
| Idle timeout, re-auth | 60 min idle sign-out for staff; password re-confirmation before every CSV export | |
| Phone change (FR-020) | OTP to the new number, password/OTP re-auth, sessions rewritten, old number notified, no number probing | HTTP session behaviour tested end to end. |
| Course editor (FR-013) | One-screen Batch > Module > Lesson editor on `wp.element` with a transactional, revision-checked REST save | Browser-tested at 1280 and 390 px. |
| PDF receipts (FR-019) | mPDF (optional Composer dependency) with bundled Noto Sans Bengali; part-payment receipts | Visually checked: Bangla conjuncts shape correctly. Falls back to HTML. |
| Installments (A-05) | Per-batch two-part payment, balance payments through the same `settle()`, reminders, optional access pause | Defaults, owner to confirm. |
| Waitlist (OQ-07) | Per-batch waitlist, FIFO offers, expiry, auto-offer on freed seats | Defaults, owner to confirm. |
| Refunds (OQ-06) | Gateway refund (bKash + fake), outside-refund recording, window setting, kind-aware refunds | bKash refund fields unverified. |
| Data retention (OQ-10) | Applications 12 months, students 3 years, payment records 7 years, logs 12 months; dry run by default | Switched off until the owner enables it; periods need counsel. |

## Requirement status

| PRD | Requirement | Status |
|---|---|---|
| 001 | Course catalogue and detail | Done |
| 002 | Single-page admission | Done (plus phone proof, payment plan choice, waitlist) |
| 003 | bKash payment and reconciliation | Driver written; **needs a sandbox run** |
| 004 | Student portal and dashboard | Done (phone change, balances, PDF receipts) |
| 005 | Secure live-class launch | Done |
| 006 | Notice board | Done |
| 007 | SMS gateway | Drivers written; **need a live test account** (OQ-02) |
| 008 | Admin panel and RBAC | Done, incl. 2FA, `/admin/login`, idle timeout, re-auth |
| 009-012 | Applications, courses/batches, roster, ledger | Done (course editor added; waitlist tab; part payments in the ledger) |
| 013 | JSON-LD | Done |
| 014-017 | Blog, PDF repository, gallery/results, contact | Done |
| 018 | Media manager | Done |
| 019 | Nagad / aggregator | Not built (V1, OQ-04) |
| 020-023 | Quiz, attendance, coupons, WhatsApp | Future/V2, correctly absent |

## Still open

- **bKash/SMS live verification.** Sandbox run for payments and refunds; a real SMS account; sender ID and Bangla rules.
- **Policy decisions.** Installment split and pausing, waitlist offer lifetime, refund window, retention periods: implemented as settings with defaults. The owner and counsel must confirm; retention deletion is off until enabled.
- **Deployment.** Redis, page cache, Cloudflare rules, Sentry, backup drills; Apache is used behind a TLS proxy instead of the SDD's Nginx FastCGI cache (the cache-bypass list in `docs/DEPLOYMENT.md` applies if one is added).
- **Parent accounts** (TRD C-11: not planned), **Nagad/aggregator** (V1).

## Deviations from the documents (intentional or inherited)

- Public legal pages are `/privacy/` and `/terms/`; the PRD sitemap lists one `/terms-and-privacy`.
- Secrets live in constants/environment, never in `wp_options` (TRD C-06).
- 2FA is built in rather than the Two Factor plugin the TRD names (the plugin could not be installed for testing, and the logic is small, tested and has no plugin lock-in).
- The course editor is `wp.element` without a build step, not `@wordpress/scripts`.
- `merchantInvoiceNumber` sent to bKash is the invoice number, so a retried or balance payment on the same invoice reuses it. If the sandbox rejects duplicates, append the payment id and strip it again when reading the report.
- The seeded FAQ still says online payment is "being enabled soon"; update it when the bKash sandbox run passes.

## Before launch (needs a person)

1. Run the bKash driver (create, execute, query, refund) against the sandbox; register the callback URL.
2. Pick the SMS provider (OQ-02), confirm sender ID and Bangla behaviour, send a real OTP and credentials SMS.
3. Set the environment variables in `.env.example`; do not set `CC_STAFF_SECURITY_RELAXED`.
4. Enrol every staff account in 2FA; store recovery codes.
5. Confirm installment, waitlist, refund and retention defaults with the owner (and retention periods with counsel).
6. Run `composer install --no-dev` in the release build for PDF receipts.
7. Point the uptime monitor at `/wp-json/cc/v1/health`.
8. Run the full CI and a short UAT (20 test admissions, offline verify, wrong-batch notice attempt, live-class join).
