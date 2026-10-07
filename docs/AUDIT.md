# Audit against the PRD, TRD and SDD

Audited the code in this repository against `Coaching Center Digital Platform: PRD v1.0`, `01 TRD` and `02 SDD` (all WordPress-first).

## How this was checked, and what was not

- Read: the three documents, `README.md`, the design specs in `docs/superpowers/specs/`, the payment, SMS, auth, portal, SEO, admin menu/roles and theme code, and the test layout. Lint (`php -l`, `node --check`) and every `tests/unit/*Test.php` pass.
- **Not run:** the Docker stack (no Docker daemon in the audit environment), the integration suites, curl e2e and Playwright journeys. Everything added in this pass is covered by dependency-free unit tests only. Run the full CI (`.github/workflows/ci.yml`) before merging.
- The matrix below reflects what the repository documents and what was spot-checked in code. It is not a line-by-line review of all ~18k lines.

## Defects found and fixed

| # | Finding | Fix |
|---|---|---|
| 1 | `tests/integration/refund-test.php` did not parse (`'' === x === false`), so the refund suite could never run, and it was missing from the CI suite list. | Corrected the assertion; added `refund` to CI and the README. |
| 2 | No real payment driver: only the fake gateway existed, so production payments (PRD-FUNC-003) returned 503. | `CC_Bkash_Gateway` (tokenized checkout). Callback route now accepts bKash's `paymentID`/`status`. |
| 3 | `settle()` compared only the amount. TRD FR-003 also requires invoice number and currency to match. | Compared whenever the gateway reports them (the fake gateway does not). |
| 4 | No real SMS driver: credentials, OTP and payment SMS (MVP-critical per CRL-2) could not be delivered. | BulkSMSBD and GreenWeb drivers on a shared HTTP base; fallback uses the existing retry logic. |
| 5 | JSON-LD missed `CourseInstance` (PRD-FUNC-013), emitted `ItemList` for fewer than 3 courses (not carousel-eligible) and had no `WebSite` node. | `CC_Seo` rebuilt; unit tested. |
| 6 | GA4 (PRD 27, TRD FR-023) was entirely absent. | Consent-gated browser events (`view_course_detail`, `begin_admission`, `complete_admission`, `payment_redirect`, `launch_live_class`) and a server-side `payment_success` through the Measurement Protocol. |
| 7 | No health endpoint for the uptime monitor and "payment stuck / cron stopped" alerts (SDD 14). | `GET /wp-json/cc/v1/health` and a reconciler heartbeat. |
| 8 | No admin view of failed SMS (SDD 9 "Logs"; README listed it as missing). | Owner-only **Astona > SMS log** (no message text, masked numbers). |

## Requirement status

| PRD | Requirement | Status |
|---|---|---|
| 001 | Course catalogue and detail | Done |
| 002 | Single-page admission, idempotency, Turnstile, private photo, encrypted ID | Done (plus phone-ownership proof, which the docs do not require) |
| 003 | bKash payment and reconciliation | Driver written and unit tested; **needs a sandbox run** (see below) |
| 004 | Student portal and dashboard | Done |
| 005 | Secure live-class launch | Done |
| 006 | Notice board (public and batch-targeted) | Done |
| 007 | SMS gateway | Drivers written and unit tested; **needs a live test account**; provider choice is still OQ-02 |
| 008 | Admin panel and RBAC | Done, except staff 2FA, the `/admin/login` slug and the idle timeout (see below) |
| 009-012 | Applications, courses/batches, roster, ledger | Done (nested editor uses the existing content screens, not React) |
| 013 | JSON-LD | Done (this pass) |
| 014-017 | Blog, PDF repository, gallery/results, contact | Done (V1 modules already present) |
| 018 | Media manager | Done |
| 019 | Nagad / aggregator | Not built: V1, and blocked on OQ-04/CRL-4 |
| 020-023 | Quiz, attendance, coupons, WhatsApp | Future/V2, correctly absent |

## Deliberately not done

These are either blocked on a decision in the documents or too risky to ship without the real stack to test against.

- **Changing the phone number from the profile (FR-020).** The phone is the login identity (`user_login`); changing it needs an OTP to the new number plus an identity-key rewrite that cannot be integration-tested without the Docker stack. It stays read-only ("contact us"), which is the safe state.
- **Staff 2FA, `/admin/login`, 60-minute admin idle timeout.** The TRD assigns 2FA to the Two Factor plugin; install and enforce it for `cc_owner`, `cc_staff` and `administrator` at deployment. A login-slug rewrite should be done with the rest of the proxy config.
- **React nested Batch→Module→Lesson editor (FR-013).** The existing screens cover the same data; the React screen is an ergonomics upgrade, not a missing capability.
- **Refund money movement, installments, waitlist, retention purge.** All are open questions (OQ-03, OQ-06, OQ-07, OQ-10). The schema allows them; no workflow is built, as the documents instruct.
- **PDF receipts (mPDF + Bangla shaping), Redis, Sentry, Nginx page cache.** Deployment/spike items. The repo runs Apache behind a TLS proxy (`docs/DEPLOYMENT.md`) instead of the SDD's Nginx FastCGI cache; the SDD's cache-bypass rules (`/student/*`, `/admin/*`, `/wp-json/cc/v1/me*`, `live-classes/*`, auth cookies) must be reproduced if a page cache is added.

## Deviations from the documents (intentional or inherited)

- Public legal pages are `/privacy/` and `/terms/`; the PRD sitemap lists one `/terms-and-privacy`.
- Secrets live in constants/environment, never in `wp_options` (TRD C-06). The Settings screen shows configured/not configured only.
- `merchantInvoiceNumber` sent to bKash is the invoice number, so a retried payment on the same invoice reuses it (TRD: retry creates a new gateway payment on the same invoice). If the sandbox rejects duplicates, append the payment id and strip it again when reading the report.
- The seeded FAQ still says online payment is "being enabled soon"; update it when the bKash sandbox run passes.

## Before launch (needs a person)

1. Run the bKash driver against the sandbox: endpoint paths and version, duplicate invoice numbers, session expiry versus `CC_Reconciler` constants, whether IPN is available (OQ-05). Register the callback URL.
2. Pick the SMS provider (OQ-02), confirm sender ID and Bangla Unicode behaviour, send a real OTP and credentials SMS.
3. Set the new environment variables (see `.env.example`): `BKASH_*`, `BULKSMSBD_*` or `GREENWEB_TOKEN`, optionally `GA4_MEASUREMENT_ID` / `GA4_API_SECRET`.
4. Point the uptime monitor at `/wp-json/cc/v1/health`.
5. Run the full CI and the Playwright journeys; a short UAT per the SDD (20 test admissions, offline verify, wrong-batch notice attempt, live-class join).
