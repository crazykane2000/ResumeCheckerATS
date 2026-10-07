<?php
require_once __DIR__ . '/auth.php';

function fetchRecruiterNotifications(PDO $pdo, int $limit = 10): array {
    $notifications = [];

    // 1. Confirmed Interview Slots
    try {
        $slotsStmt = $pdo->prepare("
            SELECT ii.id, ii.candidate_id, c.name AS candidate_name, j.title AS job_title, s.starts_at, ii.updated_at
            FROM interview_invitations ii
            JOIN candidates c ON c.id = ii.candidate_id
            JOIN interview_batches ib ON ib.id = ii.batch_id
            JOIN jobs j ON j.id = ib.job_id
            JOIN interview_slots s ON s.id = ii.confirmed_slot_id
            WHERE ii.status = 'confirmed'
            ORDER BY ii.updated_at DESC
            LIMIT ?
        ");
        $slotsStmt->bindValue(1, $limit, PDO::PARAM_INT);
        $slotsStmt->execute();
        foreach ($slotsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $notifications[] = [
                'id' => 'slot_' . $row['id'],
                'type' => 'interview_confirmed',
                'title' => 'Interview Slot Accepted',
                'message' => 'Candidate ' . $row['candidate_name'] . ' accepted interview slot for ' . date('M j, Y \a\t g:i A', strtotime($row['starts_at'])) . ' (' . ($row['job_title'] ?: 'Interview') . ').',
                'url' => 'candidate_detail.php?id=' . urlencode($row['candidate_id']),
                'at' => $row['updated_at'],
                'icon' => 'fa-circle-check',
                'color' => '#15803d'
            ];
        }
    } catch (Throwable $e) {}

    // 2. New Candidate Applications
    try {
        $candStmt = $pdo->prepare("
            SELECT c.id, c.name, c.created_at, j.title AS job_title, c.source
            FROM candidates c
            LEFT JOIN jobs j ON j.id = c.job_id
            ORDER BY c.created_at DESC
            LIMIT ?
        ");
        $candStmt->bindValue(1, $limit, PDO::PARAM_INT);
        $candStmt->execute();
        foreach ($candStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $notifications[] = [
                'id' => 'cand_' . $row['id'],
                'type' => 'candidate_new',
                'title' => 'New Candidate Applied',
                'message' => 'New candidate ' . ($row['name'] ?: 'Applicant') . ' applied for ' . ($row['job_title'] ?: 'General Role') . '.',
                'url' => 'candidate_detail.php?id=' . urlencode($row['id']),
                'at' => $row['created_at'],
                'icon' => 'fa-user-plus',
                'color' => '#7c3aed'
            ];
        }
    } catch (Throwable $e) {}

    // 3. Scorecard Evaluations
    try {
        $scoreStmt = $pdo->prepare("
            SELECT sc.id, sc.candidate_id, sc.overall_score, sc.recommendation, sc.created_at, c.name AS candidate_name, u.name AS interviewer_name
            FROM interview_scorecards sc
            LEFT JOIN candidates c ON c.id = sc.candidate_id
            LEFT JOIN users u ON u.id = sc.interviewer_id
            ORDER BY sc.created_at DESC
            LIMIT ?
        ");
        $scoreStmt->bindValue(1, $limit, PDO::PARAM_INT);
        $scoreStmt->execute();
        foreach ($scoreStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $notifications[] = [
                'id' => 'score_' . $row['id'],
                'type' => 'scorecard_submitted',
                'title' => 'Scorecard Evaluation Submitted',
                'message' => ($row['interviewer_name'] ?: 'Interviewer') . ' submitted scorecard for ' . ($row['candidate_name'] ?: 'candidate') . ' (⭐ ' . number_format($row['overall_score'], 1) . ' - ' . $row['recommendation'] . ').',
                'url' => 'candidate_detail.php?id=' . urlencode($row['candidate_id']) . '#scorecard',
                'at' => $row['created_at'],
                'icon' => 'fa-star',
                'color' => '#d97706'
            ];
        }
    } catch (Throwable $e) {}

    usort($notifications, static fn($a, $b) => strcmp($b['at'], $a['at']));
    return array_slice($notifications, 0, $limit);
}

function relativeTimeAgo(string $datetime): string {
    if (empty($datetime)) return 'Recently';
    try {
        $date = new DateTimeImmutable($datetime);
        $now = new DateTimeImmutable('now');
        $diff = $date->diff($now);
        if ($diff->y > 0) return $diff->y . 'y ago';
        if ($diff->m > 0) return $diff->m . 'm ago';
        if ($diff->d > 0) return $diff->d . 'd ago';
        if ($diff->h > 0) return $diff->h . 'h ago';
        if ($diff->i > 0) return $diff->i . 'm ago';
        return 'Just now';
    } catch (Throwable $e) {
        return 'Recently';
    }
}
