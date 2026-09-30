# Evidence score v1

## Purpose

Evidence score v1 is an advisory, deterministic comparison score for a candidate's existing application to one job. It does not shortlist, reject, or move a candidate and does not use AI.

## Previous behavior

`profileSkillMatch()` calculates exact normalized required-skill coverage:

`matched required skills / configured required skills * 100`

It reports matched, missing, and additional skills. Skill-list evidence and dated professional experience have the same effect on this legacy score.

## New comparison behavior

The legacy score remains unchanged. Dashboard comparison additionally calculates:

- required-skill evidence: 40 points;
- requirement-level relevant experience: 25 points;
- preferred skills: 10 points;
- role-title similarity: 10 points;
- evidence strength: 15 points.

Each required skill is mapped as `experience_backed`, `skills_only`, `related_review`, or `not_found`. Related technologies receive limited credit and remain explicitly marked for review. Relevant experience is averaged across every required skill instead of using only the single highest skill duration. Overlapping employment periods remain handled by the existing structured experience analysis.

Jobs without configured required skills do not receive an evidence score. Candidates are compared only with the job referenced by their application `job_id`.

## Reason

The comparison makes the difference between keyword coverage and evidence-supported suitability visible without silently replacing the established score. It gives recruiters an explainable preview while preserving human control.

## Affected files

- `lib/application_match.php`: evidence v1 mapping and score calculation.
- `dashboard.php`: job-scoped legacy/evidence comparison preview.

No database schema, stored candidate score, pipeline stage, shortlist, or rejection behavior is changed.
