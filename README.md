# ResumeCheckerATS / NonceATS

ResumeCheckerATS is a PHP/MySQL applicant-tracking and evidence-based resume review workspace. It combines resume parsing, deterministic job matching, job-specific candidate ranking, recruiter-controlled pipelines, shortlists, interview-email previews, source tracking, analytics, and NonceBlox career-site integration.

The core application works without an AI provider. AI integrations are optional enrichment and must not replace deterministic results or recruiter decisions.

## Main features

- Responsive recruiter dashboard with real database counts.
- Master-detail 40/60 shortlist workspace on `wishlist.php` with live searchable job profiles and candidate disposition tabs.
- Recruiter interview hub on `interview_calendar.php` with mini month picker, date filtering, 1-click Google Calendar add links, and live `.ics` iCal subscription feed (`interview_calendar_feed.php`).
- Full-width recruitment intelligence analytics on `analytics.php` with live candidate counts, skill shortage heatmaps, experience donut distribution, data quality metrics, and interactive SVG candidate location world map.
- Job creation and editing with a live candidate-facing preview.
- Rich job-description editing with sanitized HTML.
- Required and preferred skills, experience range, location, employment type, status, and expected minimum commitment.
- Controlled publishing to the NonceBlox `career` table with duplicate protection, audit logging, external ID storage, and public URL generation.
- Multiple resume upload for PDF, DOCX, and legacy DOC files.
- Candidate details, contact information, source attribution, detected skills, experience timeline, gaps, and skill-wise experience.
- Persistent job-specific Kanban pipeline: Applied, Screening, Interview, Offer, and Rejected.
- Candidate filters for job, skill, experience, source, score, stage, and country.
- Candidate sorting by score or experience.
- Interactive country map with resume counts, percentages, tooltips, and candidate filtering.
- Organization name, logo, and favicon configuration.
- Encrypted integration settings for SMTP, Google services, AI providers, and the NonceBlox source database.
- Permission-aware admin accounts and audit records for sensitive actions.

## Temporary Evidence ATS Lab

The sidebar flask icon opens `temp_evidence.php`. This is an isolated, read-only prototype used to compare the current scorer with a more explainable deterministic approach.

It provides:

- Candidate identity and contact details.
- Resume section detection.
- Work timeline and unique experience calculation.
- Strong, explicit, skills-only, related/inferred, and missing skill evidence.
- Requirement-level confidence and experience evidence.
- Deterministic score breakdown.
- Strong Holds, Weaknesses, and Summary tabs.
- Review flags, gaps, overlaps, education, certifications, normalized text, raw text, and structured debug JSON.
- Browser-local temporary favorites.
- `AI status: disabled` fallback behavior.

The lab does not overwrite production candidate scores or analysis records. Promote its scoring into the main ATS only after recruiter validation.

## Technology

- PHP 8+
- MySQL 8 / MariaDB-compatible SQL where possible
- Server-rendered HTML and vanilla JavaScript
- Composer package: `smalot/pdfparser`
- Font Awesome and Inter for the interface

No PHP framework, Node.js runtime, React application, Redis, or vector database is required.

## Requirements

- PHP 8.1 or newer
- MySQL/MariaDB
- Composer
- PHP extensions: `pdo_mysql`, `mbstring`, `fileinfo`, and `zip`
- Optional PDF fallback: Poppler `pdftotext`
- Optional legacy DOC tools: Antiword, Catdoc, or LibreOffice

Scanned/image-only PDFs are flagged for OCR review; OCR is not silently fabricated.

## Local installation

```powershell
git clone https://github.com/crazykane2000/ResumeCheckerATS.git
cd ResumeCheckerATS
composer install
```

Create a MySQL database named `resumeiq`, then import:

1. `database/schema.sql`
2. Every migration in `database/migrations/` in date order

Configure environment variables using `.env.example` as a reference. Do not commit a real `.env` file or credentials.

Start the application:

```powershell
php -S 127.0.0.1:8001
```

Open:

```text
http://127.0.0.1:8001/setup.php
```

After creating the first account, use `login.php` and then open `dashboard.php`.

## Database configuration

```text
RESUMEIQ_DB_HOST=127.0.0.1
RESUMEIQ_DB_PORT=3306
RESUMEIQ_DB_NAME=resumeiq
RESUMEIQ_DB_USER=root
RESUMEIQ_DB_PASS=
```

Production credentials should be supplied by environment variables or configured through the encrypted Integrations UI. Never add passwords to the repository.

## Resume parsing

- PDF: `smalot/pdfparser`, with an optional `pdftotext` fallback.
- DOCX: reads Word XML through `ZipArchive`.
- DOC: tries Antiword, Catdoc, or headless LibreOffice.

Parsing failures, short/scanned PDFs, and unavailable DOC converters are reported. Unknown information stays unknown.

Uploaded resumes are sensitive. They are ignored by Git and should be served only through the authenticated `resume_file.php` route. Resume views and downloads are audit logged.

## Job and candidate workflow

1. Create or sync a job.
2. Configure required and preferred skills plus experience requirements.
3. Upload or sync candidate resumes.
4. Review evidence and job-specific ranking.
5. Add candidates to the correct job wishlist.
6. Move candidates through the persistent pipeline.
7. Prepare interview date/time and email previews.
8. Record recruiter selection or rejection explicitly.

Automated analysis is advisory. The system must never silently hire, reject, blacklist, or email a candidate.

## NonceBlox integration

The private database integration can:

- Read jobs and applicants from the NonceBlox `career` and `career_request` tables.
- Preserve source attribution and original resume URLs.
- Download and analyze available resumes.
- Publish a saved local job through the explicit **Publish to NonceBlox** action.
- Store the returned external career ID and generated public job URL.
- Prevent duplicate publishing by external ID and job title.
- Write an audit event for a successful publish.

Remote publishing is an explicit external action and is never triggered by an ordinary local Save.

## Security notes

- Authentication and CSRF validation protect state-changing routes.
- Session initialization is shared and guarded against duplicate starts.
- Integration secrets are encrypted at rest by the application configuration layer.
- SQL writes use prepared statements.
- Resume downloads validate resolved paths and MIME types.
- Rich job-description HTML is allowlisted and sanitized before storage.
- Candidate data, database backups, uploaded resumes, API keys, and passwords must never be committed.
- Admin and download/publish actions should remain auditable.

## Backups and rollback

See [ROLLBACK.md](ROLLBACK.md) for known stable Git tags and local private database backups.

Important tags currently include:

- `pre-dashboard-audit-2026-09-29`
- `pre-temp-evidence-panel-2026-09-29`

Database backups contain personal data and remain local; they are intentionally not pushed to GitHub.

## Known limitations

- OCR for scanned PDFs is not yet implemented.
- Legacy DOC quality depends on external conversion tools.
- Email is preview/control oriented until SMTP delivery is explicitly enabled and verified.
- Google Calendar/Drive production OAuth flows require configured credentials and consent.
- The Evidence ATS Lab remains experimental and does not replace production scoring yet.
- Country/location accuracy depends on source data supplied by candidates or the career form.

## Development principles

- Preserve recruiter control.
- Keep scoring deterministic and explainable.
- Never invent resume facts.
- Do not infer sensitive personal attributes.
- Avoid double-counting overlapping employment periods.
- Treat employment gaps, overlaps, and career transitions as review context, not automatic rejection reasons.
- Keep AI optional with a deterministic fallback.

## Repository

[github.com/crazykane2000/ResumeCheckerATS](https://github.com/crazykane2000/ResumeCheckerATS)
