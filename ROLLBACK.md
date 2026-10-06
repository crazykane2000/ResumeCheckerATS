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
