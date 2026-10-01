# Hostinger deployment: resume.nonceblox.com

## hPanel setup

1. Point `resume.nonceblox.com` to the Hostinger site and enable SSL.
2. Use PHP 8.2 or newer. Enable `curl`, `fileinfo`, `mbstring`, `openssl`, `pdo_mysql`, `zip`, and `xml`.
3. Create the MySQL database/user in hPanel. Do not store its password in Git.
4. Upload the application into the subdomain document root. Upload Composer's `vendor/` folder too, or run `composer install --no-dev --optimize-autoloader` over SSH.
5. Import the production SQL dump through phpMyAdmin. Importing the dump already includes all migrations and existing records.
6. Upload existing `uploads/` separately and preserve its nested paths.
7. Create writable directories `tmp/sessions`, `data`, `uploads`, `uploads/branding`, and `uploads/nonceblox`. Prefer permissions `0770`; use the least-permissive value supported by Hostinger.

## Runtime environment

Configure these values outside Git using Hostinger environment variables or a private server-level configuration:

```text
RESUMEIQ_DB_HOST=localhost
RESUMEIQ_DB_PORT=3306
RESUMEIQ_DB_NAME=<hostinger database>
RESUMEIQ_DB_USER=<hostinger database user>
RESUMEIQ_DB_PASS=<hostinger database password>
RESUMEIQ_APP_KEY=<long random production secret>
RESUMEIQ_AI_MODE=rules
```

If hPanel does not expose environment variables, copy `config/database.local.example.php` to `config/database.local.php` on the server and enter the database values there. This filename is Git-ignored and the root Apache rules block browser access to `config/`.

After deployment, open `/login.php`; do not run `/setup.php` because the existing user database is being migrated.

## Standalone booking page (separate deployment)

Upload `deploy/nonceblox/interview-schedule.php` as `https://nonceblox.com/interview-schedule.php`. On that host set:

```text
RESUMEIQ_SCHEDULING_API_URL=https://resume.nonceblox.com/public_interview_api.php
```

In ResumeIQ Settings, keep the external booking URL as `https://nonceblox.com/interview-schedule.php`. Test one invitation token end-to-end after both sites have valid SSL.

## Production verification

- Confirm unauthenticated dashboard requests redirect to login.
- Confirm `/config/`, `/database/`, `/scripts/`, SQL files, and directory listings return 403/404.
- Test PDF and DOCX parsing; DOC requires a server-side legacy parser and scanned PDFs still require OCR.
- Test one non-delivery email preview first, then one controlled real invitation.
- Verify candidate booking, confirmation emails, calendar, outcome update, and pipeline persistence.
- Rotate any credential shared through chat and retain an offline database/uploads backup.
