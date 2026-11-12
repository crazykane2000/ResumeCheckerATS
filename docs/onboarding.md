# Post-offer onboarding handoff

Recruiters enter optional annual INR salary components in the offer letter editor. Save the draft to retain components; the candidate portal, print and Word downloads include the annexure. A separate live annexure preview shows annual and monthly INR totals beside the editor. It reports mismatches against readable annual CTC values and asks for manual review when CTC cannot be parsed. The editable letter body remains separate so the annexure is not duplicated on downloads.

The workspace owner can review submissions below accepted offers on offer_letter.php. Verification and requests for re-upload require CSRF validation, check the offer/candidate association, and append an audit event transactionally. Re-upload requests require a reason, displayed in the candidate portal. Document access is owner-only until granular permissions exist. Checklist completion never automatically hires a candidate.

Apply database/migrations/20261112_onboarding.sql once after the offer-letter migrations, if not already installed. It adds the onboarding table and nullable salary column; do not rerun the ALTER on an already upgraded database.

Validation commands: PHP syntax checks for changed PHP files, `php test_onboarding.php`, `node test_salary_preview.cjs`, and `git diff --check`. Integration test results are recorded below.


## Dashboard and validation update

`onboarding.php` uses the shared application shell and is linked in global navigation. The workspace owner can view every accepted offer with candidate, role, joining date, and pending/submitted/verified/re-upload item counts. Clicking a summary filters offers containing items in that state. Counts include optional items; each accepted offer remains separate. Review links open the existing checklist. No stage changes or emails happen from the dashboard.

The configured database was checked, backed up privately under ignored `tmp/`, and the missing onboarding table and salary column were applied and rechecked. Backups contain sensitive data and must never be committed.

Validation completed with a synthetic candidate through actual local HTTP routes: draft creation and salary reload, acceptance, multipart PDF upload, request for re-upload, visible candidate reason, replacement upload, verification, dashboard filtering and persistence on fresh GET requests, protected file access, print and Word annexures, and rejected CSRF. Issuing a test token was simulated in the test fixture to avoid sending email. The candidate portal now starts the session before HTML output, fixing first-visit CSRF failures. This was an HTTP integration test, not an interactive browser visual test. JavaScript checks (`node test_salary_preview.cjs`) cover live totals, CTC match/mismatch, unreadable currency, invalid values and escaping. `php test_onboarding.php` covers helpers. No real email was sent.
