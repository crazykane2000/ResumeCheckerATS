<?php
/**
 * Job application deadlines (migration 20261121_job_deadline.sql).
 * Every helper is safe on databases where the column does not exist yet.
 */

function jobDeadlineSupported(PDO $pdo): bool {
    static $supported = null;
    if ($supported === null) {
        try {
            $supported = (bool)$pdo->query("SHOW COLUMNS FROM jobs LIKE 'deadline_at'")->fetch();
        } catch (Throwable $e) {
            $supported = false;
        }
    }
    return $supported;
}

/** @return array<int,string> job id => Y-m-d deadline, for jobs that have one */
function jobDeadlines(PDO $pdo): array {
    if (!jobDeadlineSupported($pdo)) {
        return [];
    }
    $map = [];
    foreach ($pdo->query('SELECT id, deadline_at FROM jobs WHERE deadline_at IS NOT NULL') as $row) {
        $map[(int)$row['id']] = (string)$row['deadline_at'];
    }
    return $map;
}

/**
 * Urgency for a deadline date: overdue, due today, ≤7 days (urgent), ≤14 days (soon), later.
 * @return array{label:string,tone:string,days:int,date:string}|null
 */
function jobDeadlineStatus(?string $deadline, ?DateTimeImmutable $today = null): ?array {
    $deadline = trim((string)$deadline);
    if ($deadline === '') {
        return null;
    }
    try {
        $date = new DateTimeImmutable($deadline);
    } catch (Throwable $e) {
        return null;
    }
    $today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
    $days = (int)$today->diff($date->setTime(0, 0))->format('%r%a');
    $pretty = $date->format('d M Y');
    if ($days < 0) {
        return ['label' => 'Overdue ' . abs($days) . 'd', 'tone' => 'overdue', 'days' => $days, 'date' => $pretty];
    }
    if ($days === 0) {
        return ['label' => 'Due today', 'tone' => 'overdue', 'days' => 0, 'date' => $pretty];
    }
    if ($days <= 7) {
        return ['label' => $days . 'd left', 'tone' => 'urgent', 'days' => $days, 'date' => $pretty];
    }
    if ($days <= 14) {
        return ['label' => $days . 'd left', 'tone' => 'soon', 'days' => $days, 'date' => $pretty];
    }
    return ['label' => 'Due ' . $date->format('d M'), 'tone' => 'ok', 'days' => $days, 'date' => $pretty];
}

function jobDeadlineBadge(?string $deadline): string {
    $status = jobDeadlineStatus($deadline);
    if ($status === null) {
        return '';
    }
    $colors = [
        'overdue' => 'background:#fff0f2;color:#b4233c;border-color:#ffccd5',
        'urgent' => 'background:#fff5e7;color:#8c610d;border-color:#f5d08a',
        'soon' => 'background:#f5f2ff;color:#6f45ff;border-color:#ddd6fe',
        'ok' => 'background:#eefaf6;color:#087a52;border-color:#bfe9d8',
    ];
    return '<span class="deadline-badge" style="display:inline-block;padding:1px 6px;border:1px solid;border-radius:6px;font-size:9px;font-weight:700;white-space:nowrap;'
        . $colors[$status['tone']] . '" title="Application deadline: ' . htmlspecialchars($status['date'], ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($status['label'], ENT_QUOTES, 'UTF-8') . '</span>';
}
