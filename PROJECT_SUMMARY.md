# ResumeCheckerATS / NonceATS — Project Summary

## 1. Product overview

ResumeCheckerATS, branded locally as NonceATS, is a recruiter-controlled applicant tracking and resume-analysis proof of concept. It accepts candidate resumes, extracts text and structured evidence, compares candidates with job requirements, organizes applications in a persistent hiring pipeline, synchronizes jobs and applicants from NonceBlox, and prepares recruiter-reviewed email previews.

Automated results are advisory. The application does not autonomously hire, reject, shortlist, or email candidates. Recruiters retain control over pipeline stages, wishlist status, candidate deletion, interview details, and email previews.

## 2. Technology and runtime

- PHP 8+ backend with server-rendered HTML.
- Vanilla JavaScript and shared CSS; no frontend framework.
- MySQL persistence through PDO prepared statements.
- Composer dependency: `smalot/pdfparser`.
- Preferred local environment: Windows with Laragon.
- Required PHP capabilities include PDO MySQL, `mbstring`, `fileinfo`, cURL, and `ZipArchive` for DOCX files.
- Optional external document tools include `pdftotext`, Antiword, Catdoc, or LibreOffice.

The main database connection is configured through `RESUMEIQ_DB_*` environment variables, with documented Laragon defaults in `.env.example`.

## 3. Main application areas

### Authentication and workspace

- First-account setup through `setup.php`.
- Session-based login, logout, password reset, and CSRF protection.
- Shared authenticated application shell in `views/partials/header.php` and `footer.php`.
- Global organization branding with organization name, domain, and PNG/JPG/WebP logo.
- Integration secrets are encrypted before database storage.

The current user table still has a simple `role` column. A complete granular permission-management UI and schema are not yet implemented. Owner-only destructive actions currently identify the first user record as the immutable workspace owner.

### Resume scan and extraction

- Uploads PDF, DOCX, or legacy DOC files with an 8 MB limit.
- Generates safe server-side filenames under `uploads/`.
- PDF extraction uses `smalot/pdfparser` with optional `pdftotext` fallback.
- DOCX extraction reads `word/document.xml` through `ZipArchive`.
- DOC extraction tries Antiword, Catdoc, and headless LibreOffice.
- Extracts email, phone, normalized skills, job-date ranges, experience estimates, and employment gaps.
- Preserves parser errors and flags short/image-only PDFs as requiring OCR.
- OCR itself is not implemented.

### Explainable matching

Two deterministic analysis surfaces currently exist:

1. The standard scan uses basic, unweighted JD token coverage.
2. The Temporary Evidence Lab provides a richer evidence-oriented score using required skills, relevant experience, preferred skills, role similarity, and evidence strength.

The Evidence Lab includes:

- A semicircular gradient score gauge with dynamic needle.
- Overview, Evidence, Career, and Resume Data tabs.
- Strong evidence, weaknesses, and summary sub-tabs.
- Requirement-level confidence and evidence.
- Work timeline, employment gaps, education, certifications, skills, raw text, normalized text, and structured audit JSON.

These scores are explainable screening aids, not universal ATS scores or hiring decisions.

### Candidates and secure resume access

- Candidate list and detail pages show job-specific evidence, skills, timeline, score context, source, and resume actions.
- Resume viewing and downloading go through the authenticated `resume_file.php` route.
- NonceBlox resumes can be securely downloaded on demand from the approved HTTPS host into `uploads/nonceblox/`.
- Remote downloads validate host, path, extension, response status, MIME type, and the 8 MB limit.
- The original remote URL remains in `source_url`; `stored_file` becomes a protected local relative path.
- Resume views, downloads, remote caching, and candidate deletion are audit logged.

### Jobs and pipeline

- Jobs are first-class MySQL records with external IDs, public URLs, location, employment type, experience limits, minimum commitment, required skills, preferred skills, and status.
- Candidates are assigned to jobs through `candidates.job_id`.
- Pipeline stages are Applied, Screening, Interview, Offer, and Rejected.
- Drag-and-drop stage changes save server-side and survive refresh.
- Dashboard job cards show applicant, wishlist, and selected counts.

### Dashboard and analytics

The dashboard includes:

- Resume, job, selected, interview, and offer metrics.
- Job cards ordered by applicant volume.
- Candidate location map with ISO country normalization and graceful SVG loading fallback.
- Resume source totals.
- Top detected skills.
- Experience distribution.
- Hiring funnel.

Typography uses a shared readable floor so page-specific styles cannot reduce operational text to unreadable sizes.

### NonceBlox synchronization

The configured NonceBlox MySQL integration supports:

- Remote job and applicant preview.
- Explicit, CSRF-protected synchronization.
- Real batch progress based on committed applicant counts.
- Persistent last-sync time and counts through the audit timeline.
- Job upsert through stable remote `career.id` / local `jobs.external_id` mapping.
- Candidate-to-job mapping through `career_request.career_id`.
- Normalized job-title fallback for older records whose remote career ID is missing.
- Source URL and resume provenance preservation.

Current local data was backfilled with the same mapping rules. Applicants whose source job no longer exists and whose title has no safe match remain intentionally unmapped.

### Wishlist, shortlist, and email preview

The Wishlist workflow is job-specific and includes:

- A clear job-profile selector instead of a long horizontal ribbon.
- Candidate and Email Composer tabs.
- Primary, substitute, and reserve ranking labels.
- Wishlist, selected, and blacklisted dispositions.
- A 35% composer / 65% rendered email-preview layout.
- Live placeholder rendering for candidate name, job title, interview date, time, and timezone.
- Branded HTML email templates using the Settings logo, organization name, and domain.
- Preview-only storage with deduplication; saving a preview does not send an email.

SMTP configuration exists, but production email delivery remains intentionally disabled unless explicitly implemented and enabled.

### Candidate deletion

The workspace owner can permanently delete a bogus candidate from Wishlist or Candidate Details. The operation:

- Requires authentication, CSRF validation, owner authorization, and explicit browser confirmation.
- Deletes the candidate record, wishlist entries, email-recipient rows, and protected cached resume.
- Recalculates email-batch recipient counts.
- Preserves an audit tombstone without storing candidate PII.

Deletion is irreversible and is not exposed to non-owner users.

## 4. Database model

The current schema contains:

- `users`
- `sources`
- `candidates`
- `jobs`
- `integrations`
- `organization_settings`
- `password_reset_tokens`
- `wishlist_profiles`
- `wishlist_items`
- `email_batches`
- `email_recipients`
- `audit_events`

`database/schema.sql` represents a fresh installation. Incremental changes are stored in `database/migrations/`, including the organization-domain migration.

Candidate data, resumes, integration credentials, and database backups are sensitive runtime data and must not be committed.

## 5. Security controls

Implemented controls include:

- Authentication checks on recruiter pages and protected file routes.
- CSRF verification for state-changing workflows.
- PDO prepared statements for application values.
- Escaped frontend output.
- Encrypted integration secrets.
- Server-generated upload names.
- Extension, MIME, path-boundary, and size checks for controlled resume storage.
- Restricted NonceBlox download host and HTTPS protocol.
- Explicit preview-only email status.
- Owner-only candidate deletion with confirmation and audit logging.
- Append-oriented operational audit events.

Important production gaps remain: comprehensive MIME validation is not consistently centralized across every historical upload path, granular permissions are incomplete, authentication hardening can be expanded, and deployment-specific web-server rules must prevent direct execution/exposure of uploaded files.

## 6. Important files

- `index.php` — upload, parsing, extraction, basic scoring, and scan result workflow.
- `dashboard.php` — recruiter dashboard, map, job volumes, skills, experience, and funnel.
- `candidates.php` / `candidate_detail.php` — candidate browsing and evidence detail.
- `pipeline.php` / `pipeline_api.php` — persistent drag-and-drop workflow.
- `source_sync.php` / `source_sync_api.php` — NonceBlox preview and incremental synchronization.
- `temp_evidence.php` / `lib/temp_evidence.php` — deterministic evidence analysis UI and rules.
- `wishlist.php` — shortlist management and branded HTML email previews.
- `candidate_delete.php` — owner-authorized candidate and related-data deletion.
- `resume_file.php` / `lib/resume_storage.php` — authenticated resume delivery and secure remote caching.
- `settings.php` / `branding_save.php` — workspace settings and branding.
- `lib/auth.php` — sessions, authentication, and CSRF helpers.
- `lib/workspace.php` — candidate loading, presentation, skills, and experience helpers.
- `lib/integrations.php` / `lib/secrets.php` — integration persistence and encryption.
- `database/schema.sql` / `database/migrations/` — database definition and incremental changes.
- `assets/app.css` — shared visual system and readable typography.

## 7. Local setup

1. Install PHP 8+, Composer, MySQL, and required PHP extensions.
2. Run `composer install`.
3. Create/import the `resumeiq` database from `database/schema.sql`.
4. Apply migrations in date/name order when upgrading an existing database.
5. Configure the `RESUMEIQ_DB_*` environment variables.
6. Start MySQL.
7. Start the app, for example: `php -S 127.0.0.1:8000`.
8. Open `setup.php` for the first account, then use `login.php`.
9. Configure organization branding and integrations through Settings/Integrations.

## 8. Testing and operational checks

Core checks include:

```text
php -l <changed-file.php>
php test_extraction.php
php test_form_post.php
```

Parser changes additionally require normal PDF, DOCX, legacy DOC, invalid/empty files, missing contact fields, unusual layouts, and scanned/image-only PDF checks. Browser flows should be exercised for uploads, sync, pipeline movement, Evidence Lab, resume access, Wishlist previews, and destructive confirmation.

## 9. Known limitations and next priorities

- OCR is not implemented for scanned PDFs.
- Legacy DOC parsing depends on external software.
- DOCX extraction may miss headers, footers, text boxes, and complex layouts.
- Standard JD matching remains basic token/sub-string coverage.
- Evidence Lab scoring is deterministic but still heuristic.
- Email is preview-only; controlled delivery, duplicate-send protection, and delivery telemetry need a dedicated implementation.
- Granular user roles/permissions and staff-management workflows are incomplete.
- Issue reporting and a full human-readable audit UI are not complete.
- Candidate/job history should continue evolving toward explicit immutable application-event records.
- Remote integration retry, webhook signing, idempotency, and retention policy need a formal production contract.
- Uploaded runtime data and private backups must be managed outside Git.

## 10. Product principle

NonceATS should remain explainable, deterministic where possible, secure with candidate data, and recruiter controlled. Unknown facts must remain unknown; sensitive personal attributes must never be inferred or scored; automated analysis must never silently produce an irreversible hiring decision.