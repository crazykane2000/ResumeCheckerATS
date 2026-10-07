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

// Format Date string if provided
$whenStr = '';
if ($interviewTime !== '') {
    $whenStr = date('l, d F Y \a\t g:i A', strtotime($interviewTime)) . ' (IST)';
}

// Build Branded NonceBlox HTML Template
function buildBrandedCustomInterviewHtml(string $subjectTitle, string $message, string $timeLine): string {
    $logoHeader = '
        <div style="background:linear-gradient(135deg,#6f45ff 0%,#8d63ff 100%);padding:24px 28px;border-top-left-radius:12px;border-top-right-radius:12px;display:flex;align-items:center;justify-content:space-between;">
            <span style="font-size:24px;font-weight:900;color:#ffffff;letter-spacing:-0.02em;">NonceBlox</span>
            <span style="font-size:12px;color:#e0e7ff;font-weight:600;background:rgba(255,255,255,0.2);padding:4px 10px;border-radius:20px;">Careers Hub</span>
        </div>
    ';

    $timeBlock = '';
    if ($timeLine !== '') {
        $timeBlock = '
            <div style="margin:20px 0;padding:16px 20px;background:#f5f3ff;border:1px solid #ddd6fe;border-radius:10px;color:#4338ca;">
                <strong style="font-size:13px;display:block;margin-bottom:4px;color:#3730a3;">🗓️ Interview Date & Time:</strong>
                <span style="font-size:15px;font-weight:700;">' . htmlspecialchars($timeLine) . '</span>
            </div>
        ';
    }

    $formattedContent = nl2br(htmlspecialchars($message));

    return '<!doctype html>
    <html>
    <head><meta charset="utf-8"></head>
    <body style="margin:0;padding:0;background:#f4f5f9;font-family:Inter,Arial,sans-serif;color:#1e293b;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:32px 14px;">
            <tr>
                <td align="center">
                    <table role="presentation" width="640" cellpadding="0" cellspacing="0" style="width:100%;max-width:640px;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,0.05);">
                        <tr><td>' . $logoHeader . '</td></tr>
                        <tr>
                            <td style="padding:32px 28px;font-size:14px;line-height:1.75;color:#334155;">
                                ' . $timeBlock . '
                                <div style="font-size:14px;color:#334155;line-height:1.8;">' . $formattedContent . '</div>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:18px 28px;background:#f8fafc;border-top:1px solid #e2e8f0;border-bottom-left-radius:12px;border-bottom-right-radius:12px;font-size:12px;color:#64748b;text-align:center;">
                                NonceBlox Hiring & Interview Hub · Official Notice
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>';
}

$htmlPayload = buildBrandedCustomInterviewHtml($subject, $bodyText, $whenStr);

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
