# Astona — Public Site (Sub-project 1) Design

Client brand: **Astona** (formerly "Crystal Skill Development Center" in the NIMIKH proposal).
Source docs: PRD, TRD, SDD, UX, Wireframe/IA, RTM (Phases 1–2).

## Scope
Foundation + public website on WordPress. Later sub-projects: admission/payment, accounts/SMS/portal,
live class/notices gate, admin completion/hardening.

## Architecture
- Docker Compose: WordPress 6 (PHP 8.3), MariaDB 10.11, WP-CLI container. Redis deferred to hardening phase.
- `plugins/coaching-platform` — Catalog module: CPTs `cc_course`, `cc_faculty`, `cc_branch`, `cc_notice`;
  taxonomy `cc_category`; custom table `cc_batches` via versioned migration;
  status chip logic (Open / Filling Fast >=80% / Closed); REST `GET /wp-json/cc/v1/courses`;
  JSON-LD (EducationalOrganization, Course+Offer, ItemList); WP-CLI seeder; batch meta box.
- `mu-plugins/cc-hardening` — XML-RPC off, no user enumeration, no file editing.
- `themes/coaching-theme` — presentation only, mobile-first, CSS tokens, Noto Sans Bengali, vanilla JS, no jQuery.

## Pages
Home, About, Courses (filter), Course detail (batch selector, instructors, syllabus, sticky CTA), Notices list/detail,
Faculty, FAQ, Contact (static), Admissions (stub), Privacy, Terms. Blog/Gallery are V1 — not built.

## Testing
Unit tests for status chip, `php -l` lint, REST smoke via curl, browser check of key pages.

## Out of scope
Admission form, payments, SMS, accounts/portal, live-class gate, React admin editor.
