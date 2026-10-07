<?php
require_once __DIR__ . '/lib/auth.php';
requireAuth();
require_once __DIR__ . '/lib/integrations.php';
require_once __DIR__ . '/lib/email_api_mailer.php';
require_once __DIR__ . '/lib/smtp_mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid session request.']);
    exit;
}

$pdo = db();
$user = currentUser();

$candidateId = trim((string)($_POST['candidate_id'] ?? ''));
$invitationId = (int)($_POST['invitation_id'] ?? 0);
$recipientEmail = trim((string)($_POST['recipient_email'] ?? ''));
$interviewTime = trim((string)($_POST['interview_time'] ?? ''));
$subject = trim((string)($_POST['subject'] ?? ''));
$bodyText = trim((string)($_POST['body'] ?? ''));
$actionType = trim((string)($_POST['action_type'] ?? 'send_candidate'));
$testEmail = trim((string)($_POST['test_email'] ?? ''));

if (!$candidateId || !$recipientEmail || !$subject || !$bodyText) {
    if ($actionType === 'send_test_ajax') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Candidate, subject, and body text are required.']);
        exit;
    }
    http_response_code(422);
    exit('Missing required fields.');
}

require_once __DIR__ . '/lib/interview_email_template.php';

// Fetch candidate name and job title
$candStmt = $pdo->prepare("SELECT c.name, c.role_title, j.title AS job_title FROM candidates c LEFT JOIN jobs j ON j.id=c.job_id WHERE c.id=? LIMIT 1");
$candStmt->execute([$candidateId]);
$candData = $candStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$candidateName = $candData['name'] ?? 'Candidate';
$jobTitle = $candData['job_title'] ?? $candData['role_title'] ?? 'Role';

// Determine Date, Time and Scheduling Link
$dateStr = '06 Oct - 11 Oct 2026';
$timeStr = 'Choose an available slot';
$bookingUrl = 'https://nonceblox.com/career.php';

if ($interviewTime !== '') {
    $dateStr = date('l, d F Y', strtotime($interviewTime));
    $timeStr = date('g:i A', strtotime($interviewTime));
}

if ($invitationId) {
    $tokenStmt = $pdo->prepare("SELECT ii.id, ii.status, ib.availability_start, ib.availability_end FROM interview_invitations ii JOIN interview_batches ib ON ib.id=ii.batch_id WHERE ii.id=?");
    $tokenStmt->execute([$invitationId]);
    $invRow = $tokenStmt->fetch(PDO::FETCH_ASSOC);
    if ($invRow && !empty($invRow['availability_start']) && !empty($invRow['availability_end']) && $interviewTime === '') {
        $dateStr = date('d M', strtotime($invRow['availability_start'])) . ' - ' . date('d M Y', strtotime($invRow['availability_end']));
    }
}

$htmlPayload = noncebloxInterviewEmailHtml($candidateName, $jobTitle, $dateStr, $timeStr, 'Asia/Kolkata', $bookingUrl, $bodyText);

// Helper function to send email via API or SMTP with NonceBlox fallback
function deliverCustomInterviewMail(string $targetEmail, string $mailSubject, string $mailBody): bool {
    $api = integrationConfig('email_api');
    if (($api['status'] ?? '') !== 'configured') {
        $api = ['status' => 'configured', 'url' => 'https://hrms-api.nonceblox.com/api/emails/send-multi-recipient'];
    }
    if (!empty($api['url'])) {
        emailApiSendHtml($api, [$targetEmail], $mailSubject, $mailBody);
        return true;
    }
    $smtp = integrationConfig('smtp');
    if (($smtp['status'] ?? '') === 'configured') {
        smtpSendHtml($smtp, $targetEmail, $mailSubject, $mailBody);
        return true;
    }
    throw new RuntimeException('Email delivery service is currently unavailable.');
}

// ----------------------------------------------------
// ACTION 1: SEND TEST SAMPLE EMAIL (AJAX / POST)
// ----------------------------------------------------
if ($actionType === 'send_test' || $actionType === 'send_test_ajax') {
    $testTarget = filter_var($testEmail, FILTER_VALIDATE_EMAIL) ? $testEmail : $user['email'];
    $testSubject = '[SAMPLE TEST] ' . $subject;

    try {
        deliverCustomInterviewMail($testTarget, $testSubject, $htmlPayload);
        if ($actionType === 'send_test_ajax') {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Sample test email delivered to ' . $testTarget]);
            exit;
        }
        header('Location: interview_calendar.php?test_sent=1&email=' . urlencode($testTarget));
        exit;
    } catch (Throwable $e) {
        if ($actionType === 'send_test_ajax') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Test email failed: ' . $e->getMessage()]);
            exit;
        }
        http_response_code(500);
        exit('Test email delivery failed: ' . $e->getMessage());
    }
}

// ----------------------------------------------------
// ACTION 2: SEND ACTUAL EMAIL TO CANDIDATE
// ----------------------------------------------------
try {
    $pdo->beginTransaction();

    // Deliver actual email to candidate
    deliverCustomInterviewMail($recipientEmail, $subject, $htmlPayload);

    // Update invitation if exists
    if ($invitationId) {
        $pdo->prepare("
            UPDATE interview_invitations 
            SET notification_status = 'sent', notification_sent_at = NOW(), subject = ?, html_snapshot = ? 
            WHERE id = ?
        ")->execute([$subject, $htmlPayload, $invitationId]);
    }

    // Update candidate stage
    $pdo->prepare("UPDATE candidates SET stage = 'Interview' WHERE id = ?")->execute([$candidateId]);

    // Record audit event for Candidate Profile Application & Activity History
    $pdo->prepare("
        INSERT INTO audit_events (user_id, action, entity_type, entity_id, metadata_json) 
        VALUES (?, 'interview.confirmation_email_resent', 'candidate', ?, ?)
    ")->execute([
        $user['id'], (string)$candidateId,
        json_encode([
            'invitation_id' => $invitationId,
            'recipient_email' => $recipientEmail,
            'subject' => $subject,
            'when' => $whenStr
        ])
    ]);

    $pdo->commit();
    header('Location: interview_calendar.php?email_sent=1');
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    exit('Could not deliver candidate email: ' . $e->getMessage());
}
