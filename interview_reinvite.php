<?php
// One-click re-invite for an interview invitation whose booking link expired unused.
// Cancels the stale invitation (history kept), releases only this candidate's invite lock,
// keeps them on the job wishlist, and opens the invitation page with just this candidate
// selected so the recruiter picks a new window and sends. Nothing is emailed here.
require_once __DIR__ . '/lib/auth.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf'] ?? '')) {
    http_response_code(403);
    exit('Invalid request.');
}
$pdo = db();
$user = currentUser();
$invitationId = (int)($_POST['invitation_id'] ?? 0);

$s = $pdo->prepare("SELECT ii.id, ii.candidate_id, ii.status, ii.expires_at, ib.id batch_id, ib.job_id, ib.profile_id, j.title job_title,
        (SELECT COUNT(*) FROM interview_slots fs WHERE fs.batch_id=ib.id AND fs.status='available' AND fs.starts_at>NOW()) open_slots
    FROM interview_invitations ii JOIN interview_batches ib ON ib.id=ii.batch_id JOIN jobs j ON j.id=ib.job_id
    WHERE ii.id=? LIMIT 1");
$s->execute([$invitationId]);
$inv = $s->fetch();
if (!$inv) {
    http_response_code(404);
    exit('Invitation not found.');
}
$expired = (!empty($inv['expires_at']) && strtotime($inv['expires_at']) < time()) || (int)$inv['open_slots'] === 0;
if ($inv['status'] !== 'awaiting_confirmation' || !$expired) {
    http_response_code(409);
    exit('Only expired, unanswered invitations can be re-invited. Use "Send / Resend Custom Email" for active ones.');
}

try {
    $pdo->beginTransaction();
    // The invitation page works from the recruiter's own wishlist profile for this job.
    $profileStmt = $pdo->prepare('SELECT id FROM wishlist_profiles WHERE job_id=? AND user_id=? ORDER BY id LIMIT 1');
    $profileStmt->execute([$inv['job_id'], $user['id']]);
    $profileId = (int)$profileStmt->fetchColumn();
    if (!$profileId) {
        $pdo->prepare('INSERT INTO wishlist_profiles(job_id,user_id,name) VALUES(?,?,?) ON DUPLICATE KEY UPDATE job_id=VALUES(job_id)')
            ->execute([$inv['job_id'], $user['id'], mb_substr((string)$inv['job_title'], 0, 160) ?: 'Interview candidates']);
        $profileStmt->execute([$inv['job_id'], $user['id']]);
        $profileId = (int)$profileStmt->fetchColumn();
    }
    $pdo->prepare("UPDATE interview_invitations SET status='cancelled' WHERE id=? AND status='awaiting_confirmation'")->execute([$invitationId]);
    $profiles = array_values(array_unique(array_filter([(int)$inv['profile_id'], $profileId])));
    $pdo->prepare('UPDATE interview_invite_locks SET active=0, reset_at=NOW(), reset_by=? WHERE candidate_id=? AND active=1 AND profile_id IN (' . implode(',', array_fill(0, count($profiles), '?')) . ')')
        ->execute([$user['id'], $inv['candidate_id'], ...$profiles]);
    $pdo->prepare("INSERT INTO wishlist_items(profile_id,candidate_id,disposition) VALUES(?,?,'wishlist') ON DUPLICATE KEY UPDATE disposition=IF(disposition='blacklisted',disposition,'wishlist')")
        ->execute([$profileId, $inv['candidate_id']]);
    $pdo->prepare("INSERT INTO audit_events(user_id,action,entity_type,entity_id,metadata_json) VALUES(?,'interview.reinvite_prepared','candidate',?,?)")
        ->execute([$user['id'], $inv['candidate_id'], json_encode(['previous_invitation_id' => $invitationId, 'job_id' => (int)$inv['job_id'], 'expired_at' => $inv['expires_at'], 'reason' => 'booking_link_expired'])]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    exit('Could not prepare the re-invitation.');
}
header('Location: interview_invite.php?job_id=' . (int)$inv['job_id'] . '&reinvite=' . rawurlencode($inv['candidate_id']));
exit;
