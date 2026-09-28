# ResumeCheckerATS Agent Guide

## Project purpose

ResumeCheckerATS is a recruiter-controlled PHP proof of concept for uploading a candidate resume, extracting its text, detecting basic contact and skill information, and comparing the text with a specific Job Description (JD). Automated analysis is advisory: it must never silently make an irreversible hiring, rejection, or shortlist decision.

The intended product scope includes explainable job-match scoring, matched and missing requirements, qualification and experience analysis, recruiter review, controlled shortlist/reject workflows, and optional controlled candidate email. Most of that intended scope is not implemented in the current proof of concept.

## Current architecture

- Backend: PHP 8+ with a single web entry point, `index.php`.
- Frontend: server-rendered HTML with inline CSS and small vanilla JavaScript helpers in `index.php`.
- Dependency management: Composer. The only direct package is `smalot/pdfparser`.
- Persistence: uploaded files are stored under `uploads/`; there is no database, model layer, API, authentication, email integration, or environment/configuration layer yet.
- Local environment: Windows/Laragon is preferred. PHP must provide `mbstring`; DOCX extraction additionally needs `zip`/`ZipArchive`.

Request flow:

1. `index.php` accepts one multipart resume and a JD.
2. It checks upload success, extension (`pdf`, `docx`, or `doc`), and an 8 MB size limit.
3. It saves the file with a generated name under `uploads/`.
4. It routes extraction by extension.
5. It extracts email, phone, and normalized skills from available text.
6. It computes a basic, unweighted JD-token coverage percentage.
7. It renders metadata, processing status, matches, gaps, and raw extracted text.

## Important files and directories

- `index.php`: all production extraction, upload, matching, rendering, CSS, and JavaScript. Treat changes here as high impact.
- `composer.json` / `composer.lock`: dependency declaration and reproducible versions.
- `README.txt`: setup instructions and the proof-of-concept feature boundaries.
- `test_extraction.php`: CLI extraction smoke test. It duplicates parser functions from `index.php`, so keep that drift risk in mind.
- `test_form_post.php`: CLI scoring/contact smoke script; requiring `index.php` also emits the page HTML.
- `create_test_samples.php` and `create_valid_pdfs.php`: fixture generators, not runtime application entry points.
- `test_samples/`: deliberately generated PDF, DOCX, scanned-PDF, and legacy-DOC fixtures.
- `uploads/`: runtime candidate documents. Its contents are sensitive and must not be committed.
- `vendor/`: Composer-generated dependencies; do not edit or commit it.

## Parser behavior

- PDF: uses `smalot/pdfparser`; falls back to the `pdftotext` CLI when available. Text shorter than 40 characters is flagged as requiring OCR. OCR itself is not implemented.
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

- Before editing, inspect `git status --short --branch`, the current branch, and configured remotes. This directory may initially be supplied without Git metadata; report that before initializing or attaching it to a remote.
- Preserve unrelated user changes. Never run `git reset --hard`, `git clean -fd`, `git checkout .`, `git restore .`, force-push, or rewrite history unless the user explicitly requests the specific destructive action.
- Review the complete diff and status before committing. Keep generated dependencies, runtime uploads, secrets, and unrelated binaries out of commits.
- Use focused commit messages such as `docs: add project agent boundaries and development rules`.
- Push through `origin` only after verifying it points to the intended repository. If authentication is unavailable, leave local changes intact and report the exact failed command and error; never request a password or store a token in source files.

## Areas requiring special care

Do not casually modify `index.php`, parser fallbacks, upload storage, score/token logic, Composer files, or future database/email configuration. These areas affect candidate data, security, platform compatibility, or the meaning of recruiter-facing results.
