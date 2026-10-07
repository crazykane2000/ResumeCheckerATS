<?php
require_once __DIR__ . '/lib/auth.php';
requireAuth();
require_once __DIR__ . '/lib/integrations.php';
require_once __DIR__ . '/lib/email_api_mailer.php';
require_once __DIR__ . '/lib/smtp_mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf'] ?? '')) {
    http_response_code(403);
    exit('Invalid request session.');
}

$pdo = db();
$user = currentUser();

$candidateId = trim((string)($_POST['candidate_id'] ?? ''));
$interviewTime = trim((string)($_POST['interview_time'] ?? ''));
$customSubject = trim((string)($_POST['subject'] ?? ''));
$customBody = trim((string)($_POST['body'] ?? ''));

if (!$candidateId || !$interviewTime || !$customSubject || !$customBody) {
    http_response_code(422);
    exit('All fields (Candidate, Interview Time, Subject, Email Body) are required.');
}

$candStmt = $pdo->prepare("
    SELECT c.*, j.id AS job_id, j.title AS job_title 
    FROM candidates c 
    LEFT JOIN jobs j ON j.id = c.job_id 
    WHERE c.id = ? 
    LIMIT 1
");
$candStmt->execute([$candidateId]);
$candidate = $candStmt->fetch(PDO::FETCH_ASSOC);

if (!$candidate) {
    http_response_code(404);
    exit('Candidate record not found.');
}

$recipientEmail = trim((string)($candidate['email'] ?? ''));
if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    exit('Candidate has no valid email address on file.');
}

$startsAt = date('Y-m-d H:i:s', strtotime($interviewTime));
$endsAt = date('Y-m-d H:i:s', strtotime($interviewTime . ' +30 minutes'));
$todayDate = date('Y-m-d');
$jobId = (int)($candidate['job_id'] ?? 0);

try {
    $pdo->beginTransaction();

    // 1. Get or Create an active interview batch
    $batchStmt = $pdo->prepare("
        SELECT id FROM interview_batches 
        WHERE created_by = ? AND (job_id = ? OR job_id IS NULL) 
        ORDER BY id DESC LIMIT 1
    ");
    $batchStmt->execute([$user['id'], $jobId]);
    $batchId = (int)$batchStmt->fetchColumn();

    if (!$batchId) {
        $createBatch = $pdo->prepare("
            INSERT INTO interview_batches 
                (job_id, created_by, name, availability_start, availability_end, daily_start, daily_end, slot_minutes, buffer_minutes, timezone, status) 
            VALUES (?, ?, ?, ?, DATE_ADD(?, INTERVAL 30 DAY), '09:00:00', '18:00:00', 30, 0, 'Asia/Kolkata', 'active')
        ");
        $createBatch->execute([$jobId ?: 1, $user['id'], 'Direct Schedule Batch', $todayDate, $todayDate]);
        $batchId = (int)$pdo->lastInsertId();
    }

    // 2. Create Interview Slot
    $slotStmt = $pdo->prepare("
        INSERT INTO interview_slots (batch_id, starts_at, ends_at, status, reserved_at) 
        VALUES (?, ?, ?, 'reserved', NOW())
    ");
    $slotStmt->execute([$batchId, $startsAt, $endsAt]);
    $slotId = (int)$pdo->lastInsertId();

    // 3. Create Interview Invitation
    $token = bin2hex(random_bytes(24));
    $tokenHash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

    $inviteStmt = $pdo->prepare("
        INSERT INTO interview_invitations 
            (batch_id, candidate_id, token_hash, status, recipient_email, subject, html_snapshot, sent_at, expires_at, confirmed_slot_id, notification_status, notification_sent_at) 
        VALUES (?, ?, ?, 'confirmed', ?, ?, ?, NOW(), ?, ?, 'sent', NOW())
    ");
    $inviteStmt->execute([
        $batchId, $candidateId, $tokenHash, $recipientEmail, 
        $customSubject, nl2br(htmlspecialchars($customBody)), $expiresAt, $slotId
    ]);
    $invitationId = (int)$pdo->lastInsertId();

    // Update slot with invitation_id
    $pdo->prepare("UPDATE interview_slots SET invitation_id = ? WHERE id = ?")->execute([$invitationId, $slotId]);

    // 4. Send Email to Candidate
    $htmlEmail = "
        <div style='font-family:Inter,Helvetica,Arial,sans-serif;font-size:14px;color:#1e293b;line-height:1.6;max-width:600px;margin:auto;padding:24px;border:1px solid #e2e8f0;border-radius:12px;'>
            <div style='text-align:center;margin-bottom:20px;'>
                <strong style='font-size:20px;color:#4f46e5;'>NonceBlox Hiring Team</strong>
            </div>
            <div style='background:#f8fafc;padding:16px;border-radius:8px;margin-bottom:16px;'>
                " . nl2br(htmlspecialchars($customBody)) . "
            </div>
            <div style='background:#f1f5f9;padding:12px 16px;border-radius:8px;font-size:13px;color:#475569;'>
                <strong>Scheduled Date & Time:</strong> " . date('l, d F Y \a\t g:i A', strtotime($startsAt)) . " (IST)
            </div>
        </div>
    ";

    $api = integrationConfig('email_api');
    if (($api['status'] ?? '') !== 'configured') {
        $api = ['status' => 'configured', 'url' => 'https://hrms-api.nonceblox.com/api/emails/send-multi-recipient'];
    }
    if (!empty($api['url'])) {
        emailApiSendHtml($api, [$recipientEmail], $customSubject, $htmlEmail);
    } else {
        $smtp = integrationConfig('smtp');
        if (($smtp['status'] ?? '') === 'configured') {
            smtpSendHtml($smtp, $recipientEmail, $customSubject, $htmlEmail);
        }
    }

    // 5. Update Candidate Stage
    if ($jobId) {
        $pdo->prepare("UPDATE candidates SET stage = 'Interview' WHERE id = ? AND job_id = ?")->execute([$candidateId, $jobId]);
    } else {
        $pdo->prepare("UPDATE candidates SET stage = 'Interview' WHERE id = ?")->execute([$candidateId]);
    }

    // 6. Record Audit Event for Candidate Detail Page Activity History
    $pdo->prepare("
        INSERT INTO audit_events (user_id, action, entity_type, entity_id, metadata_json) 
        VALUES (?, 'interview.direct_scheduled', 'candidate', ?, ?)
    ")->execute([
        $user['id'], (string)$candidateId, 
        json_encode([
            'starts_at' => $startsAt,
            'recipient_email' => $recipientEmail,
            'subject' => $customSubject,
            'invitation_id' => $invitationId
        ])
    ]);

    $pdo->commit();
    header('Location: interview_calendar.php?scheduled=1');
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    exit('Could not schedule interview: ' . $e->getMessage());
}
