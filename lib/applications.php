<?php
// One person, many applications.
//
// Each candidates row is one application (one person applying to one job). The same
// person is recognised by their normalised email address; rows without an email are
// never linked (no guessing by name). Several rows for the SAME job are reported as
// possible duplicates for review on candidate_duplicates.php, never merged automatically.

function personEmailKey(?string $email): string
{
    $email = strtolower(trim((string)$email));
    return str_contains($email, '@') ? $email : '';
}

/**
 * All applications by the same person (same email), including $candidateId itself.
 *
 * @return array<int, array{id:string,name:string,job_id:?int,job_title:string,stage:string,score:int,source_name:string,created_at:string,is_self:bool,same_job:bool}>
 */
function personApplications(PDO $pdo, string $candidateId, ?string $email, ?int $jobId): array
{
    $key = personEmailKey($email);
    if ($key === '') return [];
    $s = $pdo->prepare('SELECT c.id, c.name, c.job_id, COALESCE(j.title, c.role_title) job_title, c.stage, c.score, c.source_name, c.created_at
        FROM candidates c LEFT JOIN jobs j ON j.id=c.job_id WHERE LOWER(TRIM(c.email))=? ORDER BY c.created_at DESC');
    $s->execute([$key]);
    $rows = [];
    foreach ($s->fetchAll() as $r) {
        $rows[] = [
            'id' => (string)$r['id'], 'name' => (string)$r['name'], 'job_id' => $r['job_id'] !== null ? (int)$r['job_id'] : null,
            'job_title' => (string)($r['job_title'] ?: 'No job'), 'stage' => (string)$r['stage'], 'score' => (int)$r['score'],
            'source_name' => (string)$r['source_name'], 'created_at' => (string)$r['created_at'],
            'is_self' => (string)$r['id'] === $candidateId,
            'same_job' => (string)$r['id'] !== $candidateId && $jobId !== null && (int)$r['job_id'] === $jobId,
        ];
    }
    return count($rows) > 1 ? $rows : [];
}

/**
 * For list pages: candidate id => ['count' => applications by this person, 'jobs' => distinct job titles of the OTHER applications].
 * Computed from already-loaded records (no extra queries).
 */
function personApplicationIndex(array $items): array
{
    $byEmail = [];
    foreach ($items as $c) {
        $key = personEmailKey($c['email'] ?? null);
        if ($key !== '') $byEmail[$key][] = $c;
    }
    $index = [];
    foreach ($byEmail as $group) {
        if (count($group) < 2) continue;
        foreach ($group as $c) {
            $others = array_filter($group, fn($o) => ($o['id'] ?? '') !== ($c['id'] ?? ''));
            $titles = array_values(array_unique(array_map(fn($o) => trim((string)($o['job_title'] ?? $o['role'] ?? '')) ?: 'No job', $others)));
            $index[(string)$c['id']] = ['count' => count($group), 'jobs' => $titles];
        }
    }
    return $index;
}
