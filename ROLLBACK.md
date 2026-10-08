# Rollback checkpoint

Stable checkpoint before the 2026-09-29 dashboard/job-workspace improvements:

```powershell
git switch -c restore-pre-dashboard pre-dashboard-audit-2026-09-29
```

Local database backup:

`C:\Users\Asus\AppData\Local\Temp\resumeiq-2026-09-29-pre-dashboard-audit.sql`

Restore the database only when intentionally rolling back database changes:

```powershell
mysql -u root resumeiq < C:\Users\Asus\AppData\Local\Temp\resumeiq-2026-09-29-pre-dashboard-audit.sql
```

The tag is pushed to GitHub. The SQL backup is intentionally local because it contains private candidate data.

## Temporary evidence lab checkpoint

Stable checkpoint before the isolated evidence-based ATS prototype:

```powershell
git switch -c restore-pre-evidence-lab pre-temp-evidence-panel-2026-09-29
```

Private local database backup:

`C:\Users\Asus\AppData\Local\Temp\resumeiq-2026-09-29-pre-temp-evidence-panel.sql`

## Candidate Budget Filter Checkpoint (2026-10-08)

Stable checkpoint tag created before adding Candidate Budget/CTC filter & extraction:

```powershell
# Rollback code to pre-budget-filter state
git checkout pre-budget-filter-20261008

# Rollback SQL migration (removes current_ctc, expected_ctc, budget_max columns)
php -r "require 'lib/auth.php'; db()->exec(file_get_contents('database/migrations/20261008_candidate_job_budget_ctc_rollback.sql'));"
```

