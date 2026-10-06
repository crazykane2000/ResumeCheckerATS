ResumeCheckerATS / NonceATS
===========================

The complete project documentation is maintained in README.md.

Quick start:
1. Run `composer install`.
2. Create/import the MySQL database using `database/schema.sql` and the files in `database/migrations/`.
3. Configure database environment variables (see `.env.example`).
4. Run `php -S 127.0.0.1:8001` from the project directory.
5. Open `http://127.0.0.1:8001/setup.php` for first-time setup, then log in.

Never commit production passwords, API keys, database dumps, resumes, or candidate personal data.
