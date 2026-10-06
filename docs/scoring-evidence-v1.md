# Evidence score v1

## Purpose

Evidence score v1 is an advisory, deterministic comparison score for a candidate's existing application to one job. It does not shortlist, reject, or move a candidate and does not use AI.

## Previous behavior

`profileSkillMatch()` calculates exact normalized required-skill coverage:

`matched required skills / configured required skills * 100`

It reports matched, missing, and additional skills. Skill-list evidence and dated professional experience have the same effect on this legacy score.

## New behavior

Application-facing candidate lists, details, job profiles, wishlists, dashboards, and analytics now use Evidence v1 instead of the stored legacy keyword percentage. Evidence v1 calculates:

- required-skill evidence: 40 points;
- requirement-level relevant experience: 25 points;
- preferred skills: 10 points;
- role-title similarity: 10 points;
- evidence strength: 15 points.

Each required skill is mapped as `experience_backed`, `skills_only`, `related_review`, or `not_found`. Related technologies receive limited credit and remain explicitly marked for review. Relevant experience is averaged across every required skill instead of using only the single highest skill duration. Overlapping employment periods remain handled by the existing structured experience analysis.

Jobs without configured required skills do not receive an evidence score. Candidates without structured skill or experience analysis are marked `needs_analysis` instead of receiving a misleading zero. Candidates are compared only with the job referenced by their application `job_id`; role-title similarity is no longer used to associate an application with a job. The original database `candidates.score` value is retained as `legacy_score` for compatibility and is not overwritten by this display-layer change.

## Reason

The change prevents exact skill-list coverage from being presented beside richer evidence-based rankings as if both were current application scores. Recruiters get one deterministic, explainable job-specific score while retaining human control; the score never moves, shortlists, rejects, or otherwise decides an application.

## Affected files

- `lib/application_match.php`: evidence v1 mapping and score calculation.
- `lib/workspace.php`: attaches canonical job data and the Evidence v1 result to each application record; retains the stored score as `legacy_score`.
- `dashboard.php`: job-scoped Evidence v1 ranking.
- `dashboard2.php`: evidence score, mapping details, ranking, filtering, and explicit analysis state.
- `candidates.php`: Evidence v1 display, analysis state, and canonical job filtering.
- `candidate_detail.php`: job-specific Evidence v1 score and requirement mapping.
- `job_profile.php`: job-scoped Evidence v1 top-five ranking.
- `wishlist.php`: job-scoped Evidence v1 ordering and analysis state.
- `analytics.php`: job-scoped Evidence v1 averages and experience-backed skill coverage.

No database schema, stored candidate score, pipeline stage, shortlist, or rejection behavior is changed.
