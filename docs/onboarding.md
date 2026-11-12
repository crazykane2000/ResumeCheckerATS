# Post-offer onboarding handoff

Recruiters enter optional annual INR salary components in the offer letter editor. Save the draft to retain components; the candidate portal, print and Word downloads include the annexure. The editable letter body preview does not include the annexure. Compare its total with the free-text annual CTC before sending.

The workspace owner can review submissions below accepted offers on offer_letter.php. Verification and requests for re-upload require CSRF validation, check the offer/candidate association, and append an audit event transactionally. Re-upload requests require a reason, displayed in the candidate portal. Document access is owner-only until granular permissions exist. Checklist completion never automatically hires a candidate.

Apply database/migrations/20261112_onboarding.sql once after the offer-letter migrations, if not already installed. It adds the onboarding table and nullable salary column; do not rerun the ALTER on an already upgraded database. No migration was executed during this change.

Validation: PHP syntax checks for changed PHP files, test_onboarding.php helper assertions, and git diff --check. The local database/schema check was unavailable, so authenticated browser uploads, review persistence, and generated downloads still require an environment with the configured database. No email was sent.
