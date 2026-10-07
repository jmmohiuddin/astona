# Legal drafts: Privacy Policy and Terms & Conditions

**These are drafts, not legal advice.** The author of the text is not a lawyer. The two pages (`/privacy/` and `/terms/`)
were written from what the system actually does, by reading the code. They **must be reviewed by a qualified Bangladeshi
lawyer before launch**. Bangladesh's data-protection regime is still evolving, so a lawyer should confirm what currently
applies to a coaching center that handles students' (often minors') data. The drafts deliberately cite no specific statutes.

Both pages start with a red banner: **"DRAFT — for legal review before launch"**. It is part of the page content so staff and
visitors cannot miss it.

Where to find the text: `CC_Seeder::legal_privacy_html()` and `CC_Seeder::legal_terms_html()` in
`wp-content/plugins/coaching-platform/includes/class-seeder.php`. Each has a short Bangla summary box at the top.
Please have a Bangla speaker check the summaries too.

## 1. How the drafts map to system behaviour

Each statement in the Privacy Policy is backed by code. If the code changes, update the policy.
Paths are under `wp-content/plugins/coaching-platform/` unless noted.

| Statement in the draft | Source |
|---|---|
| Application collects full name, gender, date of birth, ID type and number, student phone, guardian name and phone, optional email, institution, class, optional passing year, roll number, branch preference, consent time | `includes/admissions/class-rest-admissions.php` (field list at line 25, validation from line 269, `consent_at`); table `cc_applications` in `includes/class-migrations.php` |
| ID number is stored encrypted | `class-rest-admissions.php` (`CC_Crypto::encrypt` into `id_doc_enc`); `includes/support/class-crypto.php` (libsodium secretbox) |
| Student photo is kept in private storage, re-encoded (metadata stripped), outside the web root | `includes/admissions/class-photo-store.php` (`base_dir()`, `store()`) |
| Invoice and payment records: invoice number, amount, status, method, gateway ids, transaction id, response | tables `cc_invoices`, `cc_payments`, `cc_payment_events` in `class-migrations.php` |
| Card/MFS credentials are never seen (payment is on the gateway page) | `includes/payments/interface-payment-gateway.php`, `class-fake-gateway.php` (redirect model; no card fields exist in the form or tables). The real bKash driver is not yet written. |
| Payment is recorded by staff at the office until the gateway is connected | `method` enum includes `offline` in `cc_payments`; `includes/admin/class-admin-payments.php` |
| Account: phone as login, hashed password, name, guardian, institution, photo, enrolments | `includes/enrollment/class-provisioner.php`, `class-migrations.php` (`cc_students`, `cc_enrollments`); passwords by WordPress core hashing |
| SMS sent for credentials, OTP, receipts, enrolment, login hint, critical notices, application rejected | `includes/sms/class-sms.php` (`TEMPLATES`); call sites: `includes/auth/class-otp.php`, `includes/enrollment/class-provisioner.php`, `includes/admin/class-admin-students.php`, `includes/admin/class-admin-applications.php`, `includes/notices/class-notice-service.php` |
| Bodies of credential and OTP messages are never stored; other bodies are stored encrypted | `class-sms.php` (`SECRET_TEMPLATES = credentials, otp`; `body_enc` is null for secret templates, otherwise `CC_Crypto::encrypt`); `cc_sms_log` |
| OTP codes are stored only as a hash and expire in 5 minutes | `includes/auth/class-otp.php` (`hash()`, `TTL_SECONDS = 300`) |
| OTP rows are deleted automatically about an hour after expiry | `class-otp.php` (`cleanup()`, `CLEANUP_GRACE = 3600`, daily cron) |
| Live-class join log: class, user, time, hashed IP | `includes/live/class-rest-live.php` (line 85), table `cc_join_log` |
| Audit log: actor, action, time, hashed IP | `includes/admin/class-audit.php`, table `cc_audit_log` |
| Contact inquiries: name, phone, optional email, topic, course, message, hashed IP | `includes/contact/class-inquiry-repository.php`, `class-rest-contact.php`, table `cc_inquiries` |
| Inquiries handled or spam are deleted after 365 days; unhandled after 730 days | `class-inquiry-repository.php` (`RETENTION_DAYS = 365`, `NEW_RETENTION_MULTIPLIER = 2`, `purge_older_than()`, daily cron) |
| Results and gallery published only when verified and consent-confirmed | `includes/gallery/class-results.php` (header docblock; consent meta `cc_result_consent`) |
| Meeting links are encrypted and never in public pages | `includes/live/class-live-repository.php` (docblock: encrypted at rest, only `meeting_url()` returns it) |
| Access limited by role; instructors see only their batches | `includes/admin/class-admin-roles.php`, `includes/enrollment/class-roles.php`, table `cc_batch_staff` |
| Rate limits on login, OTP, contact, admissions, live join | `includes/support/class-rate-limiter.php`, `class-otp.php`, `class-rest-contact.php`, `class-rest-live.php` |
| Cloudflare Turnstile on the Contact form when keys are set | `includes/contact/class-rest-contact.php` (`verify_turnstile`), theme `functions.php` (`astona_turnstile_site_key`), `page-contact.php` |
| Map on the Contact page from OpenStreetMap or Google Maps only | `includes/contact/class-branches.php` (`EMBED_PATTERN`) |
| No analytics, ad or tracking cookies; fonts are self-hosted | grep of `wp-content/themes` and the plugin found no analytics, tag-manager, pixel or third-party font code; `functions.php` lines 83 to 94 and `main.css` `@font-face` (self-hosted woff2) |
| Contact details on the Contact page come from the Settings page | `includes/admin/class-admin-settings.php` (`astona_contact` filter), theme `astona_contact()` |
| Full payment at admission; seats limited; closed when full | `includes/admissions/class-rest-admissions.php` (`batch_full` check, line 188), `includes/class-status-chip.php`; README "Admission and payment" |
| Rejected applications are told by SMS | `includes/admin/class-admin-applications.php` (line 219) |

## 2. Things the code does NOT do (so the drafts do not promise them)

- No automatic deletion for applications, enrolments, student accounts, payments, invoices, SMS log, join log or audit log.
  The draft says retention is `[OWNER TO CONFIRM]`.
- No refund tool exists; `refunded` is only a payment status label (README "Not built yet").
- No self-service data export or deletion. Requests are handled by staff through the contact details.
- No analytics or marketing cookies and no consent banner is needed today. If analytics are added later, update the cookie
  section and add consent handling first.

## 3. Facts that could not be confirmed from code

- Whether visitors who are not logged in receive any cookie from WordPress, the theme or plugins. None was found, but check
  a live browser's cookie jar on the production site (including anything the hosting layer or Cloudflare adds).
- The server location and what the hosting provider and Cloudflare log: unknown until hosting is chosen (OQ-10).
- Result photos are stored privately and shown only through short-lived signed links, and only while the result is published,
  verified and consent-confirmed (see the README section "Private result photos"). Gallery-item images are different: they
  are ordinary public uploads once the item is published. Do not put anything in the gallery that should not be public.
- That the real bKash and SMS gateways keep to the "we never see card details" and "OTP not stored" promises once their
  drivers exist. Re-check the policy when drivers are added.
- Exact SMS retention: the system has no purge for `cc_sms_log`.

## 4. `[OWNER TO CONFIRM]` list

Search both pages for `[OWNER TO CONFIRM` to find them in context. Decisions needed:

Privacy Policy

1. Date of the final version.
2. Registered business name and registered address.
3. Wording for guardian consent and the treatment of children under 18 (who may apply, any proof of guardianship).
4. Payment gateway name (bKash planned, not connected).
5. SMS provider (not chosen).
6. Hosting company and server location.
7. Any other recipients (auditors, government bodies).
8. Retention period for: applications, enrolments, student accounts, payment and invoice records, SMS log, join log, audit log;
   and what happens after (delete or anonymise).
9. Breach-notification process.
10. Time to respond to access, correction and deletion requests; which records are kept after a deletion request.
11. How users are told about changes.
12. Governing law and courts.
13. Data-protection contact: person or role and a dedicated email.
14. Refund policy (OQ-06), referred to in section 8.

Terms & Conditions

15. Date of the final version.
16. Waiting-list or next-batch arrangements when a batch is full.
17. Fee amounts shown per course, and whether any discount or instalment policy exists (the system takes full payment today, OQ-03).
18. Refund policy (OQ-06): refundable or not, when, time limits, deductions, how refunds are paid. Until decided, the page makes
    no refund promise.
19. Recording policy for live classes.
20. How a student stops non-essential SMS.
21. Limitation-of-liability wording as advised by the lawyer.
22. Governing law and courts.

Related open questions: OQ-06 (refund policy), OQ-10 (data residency, retention, legal).

## 5. How to publish the final text

1. Send the draft pages to the lawyer. They can read the live pages at `/privacy/` and `/terms/`, or the source in
   `class-seeder.php`.
2. Update the text as advised and answer every `[OWNER TO CONFIRM]` item. If the code behaviour changes in the process
   (for example a retention purge is added), update the system too.
3. In wp-admin go to **Pages**, open "Privacy Policy" and "Terms & Conditions", and paste the final text. Switch the editor
   to the code view if the draft box styling gets in the way.
4. **Delete the red draft banner box** (the block starting "DRAFT — for legal review before launch") and the Bangla summary if
   you no longer want it (or keep it, updated and checked by a Bangla speaker). Remove every `[OWNER TO CONFIRM...]` marker.
5. Set the "Last updated" date. Tell enrolled students about material changes by notice or SMS.
6. Check `/privacy/` and `/terms/` in a private window. Search the page for "DRAFT" and "OWNER TO CONFIRM"; both must return nothing.
7. Confirm the Contact page and **Astona > Settings** contain the real phone, email and address (the policies point there).

Once the banner is gone and the text is edited, the system will never overwrite it (see section 6).

## 6. Refreshing the drafts on an existing database

`wp cc seed` creates the pages only when they are missing, so databases seeded before these drafts existed still hold the old
one-line placeholders. To bring them up to date:

```bash
docker compose run --rm -T wpcli cc refresh-legal            # update
docker compose run --rm -T wpcli cc refresh-legal --dry-run  # report only
```

The command (in `class-seeder.php`, `CC_Seeder::refresh_legal()`) updates the `privacy` and `terms` pages **only if** the page
is empty, still contains the old placeholder sentence, or still contains the text "DRAFT — for legal review before launch".
Any page whose content has been replaced by owner-edited final text is skipped and reported. Running it twice is harmless
("already up to date"). If a page is missing, it tells you to run `wp cc seed`.

Consequence: until the banner is removed, running `refresh-legal` after a code update will replace the page with the newest
draft, discarding in-progress edits made inside the page. Do the final edit only once the lawyer has signed off.
