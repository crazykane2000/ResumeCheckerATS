# ResumeCheckerATS Agent Guide

## Project purpose

ResumeCheckerATS is a recruiter-controlled PHP proof of concept for uploading a candidate resume, extracting its text, detecting basic contact and skill information, and comparing the text with a specific Job Description (JD). Automated analysis is advisory: it must never silently make an irreversible hiring, rejection, or shortlist decision.

The intended product scope includes explainable job-match scoring, matched and missing requirements, qualification and experience analysis, recruiter review, controlled shortlist/reject workflows, and optional controlled candidate email. Most of that intended scope is not implemented in the current proof of concept.

## Current architecture

- Backend: PHP 8+ with PDO MySQL persistence and structured modular libraries under `lib/`.
- Frontend: server-rendered responsive HTML with vanilla JavaScript, modern dashboard CSS (`assets/app.css`), and shared authenticated layouts (`views/partials/header.php`, `views/partials/footer.php`).
- Key subsystems:
  - Resume Parsing & Ingestion: `index.php` (PDF, DOCX, DOC with automatic OCR.space cloud fallback for scanned PDFs).
  - Hiring Pipeline: `pipeline.php` (drag-and-drop Kanban, batch/job filters, candidate profile overlay drawer, stage transitions, evaluation notes).
  - Pipeline API & Drawer: `pipeline_lifecycle_api.php` (drawer candidate details, evaluation notes CRUD, stage email dispatches).
  - Candidate Evaluation Notes: `candidate_notes` table (interview round feedback, strengths, recommendations, author, timestamps).
  - Lifecycle Email Service: `lib/lifecycle_mailer.php` (NonceBlox branded templates for 6 hiring stages with dual API/SMTP dispatch).
  - Shortlist & Wishlist: `wishlist.php` (ranking, disposition, email previews).
  - Candidate Intelligence & Detail: `candidates.php`, `candidate_detail.php`, `temp_evidence.php`.
  - Interview Scheduling & Calendar: `interview_invite.php`, `interview_calendar.php`, `public_interview_api.php`.
  - Remote Sync: `source_sync.php` (NonceBlox jobs & applicant synchronization).
  - Dashboard & Analytics: `dashboard.php`, `analytics.php`.
- Persistence: MySQL via PDO prepared statements (`config/database.php`).
- Dependency management: Composer (`smalot/pdfparser`).
- Local environment: Windows/Laragon with PHP 8.1+, PDO MySQL, `mbstring`, `fileinfo`, `curl`, and `zip`.

Request flow:

1. `index.php` accepts multipart resumes and a JD (or links to an existing job).
2. It validates upload success, extension (`pdf`, `docx`, or `doc`), and the 8 MB size limit.
3. It saves the file under `uploads/` with a sanitized server-side name.
4. It routes extraction by extension:
   - PDF: parses via `smalot/pdfparser` / `pdftotext`. If text < 40 chars, triggers OCR.space API fallback.
   - DOCX: extracts via `ZipArchive`.
   - DOC: parses via Antiword / Catdoc / LibreOffice.
5. It extracts email, phone, and normalized skills from available text.
6. It computes explainable JD match coverage.
7. It creates/updates the candidate record in MySQL, linking it to the pipeline stage.
8. It renders metadata, processing status, matches, gaps, and raw extracted text.

## Important files and directories

- `index.php`: upload, parsing, extraction with OCR.space fallback, basic scoring, and scan result workflow.
- `pipeline.php` / `pipeline_lifecycle_api.php`: Kanban hiring pipeline with Batch/Job filters, Candidate Profile Overlay Drawer, and stage action handling.
- `lib/lifecycle_mailer.php`: NonceBlox branded lifecycle email service (Welcome, Screening, Round 1, Round 2, Offer, Rejection) with dual API/SMTP dispatch.
- `candidates.php` / `candidate_detail.php`: candidate browsing, detail dossier, and stage actions.
- `temp_evidence.php` / `lib/temp_evidence.php`: deterministic evidence analysis UI and scoring rules.
- `database/migrations/20261014_candidate_notes.sql`: schema for interview evaluation notes.
- `composer.json` / `composer.lock`: dependency declaration (`smalot/pdfparser`).
- `test_extraction.php`: CLI extraction smoke test with OCR fallback.
- `test_form_post.php`: CLI scoring/contact smoke script.
- `uploads/`: runtime candidate documents (sensitive, never committed).

## Parser behavior

- PDF: uses `smalot/pdfparser`; falls back to the `pdftotext` CLI when available. When extracted text is shorter than 40 characters (e.g. image-only / scanned documents), it automatically triggers OCR fallback via the OCR.space cloud API (`https://api.ocr.space/parse/image`), extracting raw text and setting `ocr_used => true`.
- DOCX: reads `word/document.xml` through PHP `ZipArchive`; headers, footers, text boxes, and some complex layouts may not be captured.
- DOC: attempts `antiword`, then `catdoc`, then headless LibreOffice/`soffice`. It cannot parse legacy DOC without one of those external tools.
- All extraction results return text, an OCR flag, and an error. Never convert an extraction failure into an empty success or invent missing content.

## Current scoring behavior

`basicMatch()` lowercases the JD, removes punctuation and a fixed stop-word list, deduplicates the remaining tokens, and checks each token as a substring of the resume text. The displayed percentage is matched tokens divided by total JD tokens. It is only a basic candidate-to-specific-JD keyword coverage score—not an accurate ATS score or universal candidate-quality score.

Do not silently change tokenization, stop words, substring matching, weighting, thresholds, or score labels. Any requested scoring change must document the old behavior, new behavior, reason, and affected files, and remain deterministic and explainable.

## Technology boundaries

Use PHP 8+, MySQL for future persistence, HTML, CSS, and vanilla JavaScript. Do not introduce Laravel, React, Vue, Angular, a Node.js or Python backend, Docker as a dependency, Redis, MongoDB, Qdrant, or a framework migration without explicit approval. Do not replace the existing stack merely for convenience.

Add Composer packages only when the existing project and platform cannot reasonably provide the required capability. Explain the need and add the minimum package; do not perform unrelated dependency upgrades.

## Coding and modification rules

- Inspect a file and its callers/dependencies before editing it.
- Make the smallest cohesive change and do not rewrite the application for a small feature.
- Do not modify unrelated files, remove working behavior, rename routes/public URLs/configuration keys, or redesign the UI unless explicitly requested.
- Preserve support for exactly PDF, DOCX, and DOC unless the task changes that scope.
- Preserve the original upload and extracted raw text when implementing persistence. Expose clear `processed`, `failed`, or `needs review`/OCR status and parser errors.
- Keep recruiter control visible. AI may assist structured extraction, JD extraction, skill normalization, or uncertain experience identification, but may not independently hire, reject, or shortlist.
- UI direction is a clean, futuristic-minimal ATS dashboard: use a white base, restrained mint/lavender accents, compact data visualization, clear hierarchy, and responsive layouts.
- UI controls and surfaces may use restrained corner rounding up to `12px`; keep dense data surfaces closer to `6px` and avoid pill-heavy styling. Circular data visualizations may use SVG or `clip-path`.
- All product pages must use the shared global shell in `views/partials/header.php` and `views/partials/footer.php`, with design tokens and common components from `assets/app.css`. Keep Scan, Pipeline, Candidates, and Sources navigation present on every page; do not create page-specific color or typography systems.
- The candidate pipeline is a persistent drag-and-drop Kanban flow. Its canonical stages are Applied, Screening, Interview, Offer, and Rejected; moving a card must save the new stage server-side and survive refresh.
- A successful resume scan must create or update its candidate pipeline record. Keep candidate source attribution and experience-analysis provenance with that record.
- Never fabricate resume facts. Keep unknown or ambiguous data unknown and identify it for review.
- Do not infer or score religion, caste, gender, marital status, ethnicity, political views, health status, photographs, or inferred personality.
- Calculate experience from employment date ranges when possible, avoid overlapping-job double counts, and mark unreliable results uncertain. Do not invent dates or simply trust a claimed total when structured dates exist.

## Security rules

- Treat resumes, contact details, raw extracted text, and uploaded filenames as sensitive candidate data.
- Validate both extension and server-detected MIME/content type before parsing. The current application validates only extension and size; do not describe that as sufficient validation.
- Generate safe server-side names, escape all frontend output, use prepared statements for any future SQL, and prevent uploaded files from being executable or directly exposed by the web server.
- Add CSRF protection before exposing state-changing workflows beyond this local proof of concept.
- Do not expose filesystem paths or raw parser exceptions to untrusted users.
- Never commit `.env`, API keys, SMTP/database passwords, private keys, access tokens, GitHub tokens, uploaded resumes, logs, or local editor/system files.
- Email sending must be explicit and controlled, prevent duplicates, and remain disabled during development/testing unless the user specifically requests it.
- Never drop tables, truncate data, delete large datasets, or reset a database without explicit approval. Prefer backward-compatible schema changes and provide migration SQL separately with an explanation.

## Testing rules

Before declaring parser work complete, run syntax checks and test at least: a normal text PDF, DOCX, legacy DOC, invalid file, empty file, missing email, missing phone, unusual formatting, and a scanned/image-only PDF. Clearly report unavailable platform dependencies and unsupported OCR.

Baseline commands:

```text
php -l index.php
php test_extraction.php
php test_form_post.php
```

Also exercise the browser upload flow when it is changed. Do not treat fixture generators as assertions, and do not claim all formats work merely because files with those extensions are accepted. If modifying duplicated extraction logic, either update both runtime and smoke-test code consistently or first refactor under an explicitly approved scope.

## Git rules

- Permanent branch protection rule: never commit, merge, push, cherry-pick, or otherwise place this current workspace/version on the Git `main` branch. The existing older version on `main` must remain unchanged. This restriction applies even if a future request generally asks to commit or push; changing `main` requires a new, explicit user instruction that specifically revokes this rule.
- If this current workspace/version must be stored in Git, use only a separate non-`main` branch or a separate repository after obtaining explicit user approval. Never merge that branch into `main` automatically.
- Never commit the `u157534802_resume1.sql` database dump or runtime candidate data, uploads, sessions, credentials, secrets, or private backups to any branch or repository.
- Before editing, inspect `git status --short --branch`, the current branch, and configured remotes. This directory may initially be supplied without Git metadata; report that before initializing or attaching it to a remote.
- Preserve unrelated user changes. Never run `git reset --hard`, `git clean -fd`, `git checkout .`, `git restore .`, force-push, or rewrite history unless the user explicitly requests the specific destructive action.
- Review the complete diff and status before committing. Keep generated dependencies, runtime uploads, secrets, and unrelated binaries out of commits.
- Use focused commit messages such as `docs: add project agent boundaries and development rules`.
- Push through `origin` only after verifying it points to the intended repository. If authentication is unavailable, leave local changes intact and report the exact failed command and error; never request a password or store a token in source files.

## Areas requiring special care

Do not casually modify `index.php`, parser fallbacks, upload storage, score/token logic, Composer files, or future database/email configuration. These areas affect candidate data, security, platform compatibility, or the meaning of recruiter-facing results.

## Product requirements to preserve

- Workspace settings must allow the owner to set the organization name and upload a safe PNG, JPG, or WebP logo. Branding is global and must render through the shared header; uploaded branding files remain runtime data and must not be committed.
- Support individual staff accounts with separate logins. The first account is the immutable workspace owner; the owner may invite or create admins/recruiters/viewers. Never share one administrator password between people.
- Authorization must be permission-based and enforced server-side, not only hidden in the UI. At minimum separate candidate view/edit, pipeline move, email send, job manage, source manage, integration manage, user manage, report issue, export/download, database backup, and delete permissions.
- Non-owner staff must not delete records, export candidate data, download resumes, download database backups, or change owner/security/integration settings unless the owner explicitly grants the corresponding permission. Destructive actions remain owner-controlled and require confirmation.
- Maintain an append-only audit timeline for authentication, profile/permission changes, candidate views and updates, pipeline moves, wishlist/blacklist changes, mail previews/sends, interview scheduling/rescheduling, downloads/exports, backups, job/source/integration changes, and failures. Store actor, action, target type/id, timestamp, IP/user agent where available, request/correlation ID, outcome, and non-secret metadata. Audit entries must not expose passwords, API keys, OAuth tokens, full resume text, or other secrets.
- Provide an issue-reporting workflow tied to the audit timeline. Reports need severity, status, reporter, owner, affected candidate/job/integration, description, timestamps, and resolution history.
- Candidate/application history is a timeline and must remain recoverable: ingestion, analysis, recruiter actions, messages, interviews, reschedules, decisions, imports, failures, and source changes must not overwrite prior events.
- Every candidate application must retain explicit provenance: `upload`, `website`, `google_drive`, `external_api`, or `other`, plus source name, external reference ID, source URL when appropriate, original filename/Drive file ID, ingestion timestamp, job ID, and consent evidence when supplied. Never infer a source after ingestion.
- Jobs are first-class records. A candidate may apply to multiple jobs, but the same candidate/resume must not create duplicate applications for one job. Scores, stages, wishlists, messages, and decisions belong to the job application rather than the global person record.
- Interactive Candidate Profile Overlay Drawer: clicking any candidate card in `pipeline.php` must open the full-context sliding drawer without navigating away. The drawer includes a visual speedometer gauge match meter with proportional score breakdown (skills, experience, education, role alignment) and 5 functional tabs: Overview, Evidence, Career, Timeline & Notes, and Resume Data.
- Recruiter Interview Evaluation Notes: recruiters and interviewers must be able to log structured round evaluation notes ("Kya hua" general feedback, "Kya achha tha" strengths/highlights, recommendation rating from Strong Yes to Strong No, author, and timestamp). Notes are stored in `candidate_notes` with chronological display on the candidate timeline.
- Branded NonceBlox Lifecycle Mailer: stage movements (Screening, Round 1 Interview, Round 2 Interview, Offer, Rejection) provide recruiter-controlled email preview and dispatch with live candidate placeholders. Sending is dispatched through the NonceBlox Email API with fallback to SMTP.
- Planned NonceBlox integration targets `https://nonceblox.com/career.php` for job listings and `career-inner.php` role/application pages. ResumeIQ will create/publish jobs and ingest applicants through an authenticated API or signed webhook, not by scraping private submissions. Preserve NonceBlox job/application IDs and form consent, contact, qualification, CTC, joining-availability, and multi-resume provenance. Keep this integration disabled until the remote API contract, authentication, webhook signing, retry/idempotency rules, and data-retention policy are explicitly configured.
