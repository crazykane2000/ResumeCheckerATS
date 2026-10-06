<?php
require_once __DIR__ . '/lib/auth.php';
requireAuth();
require_once __DIR__ . '/lib/workspace.php';
require_once __DIR__ . '/lib/outreach_campaign.php';

header('Content-Type: application/json; charset=utf-8');

$user = currentUser();
$pdo = db();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'progress') {
        $campaignId = (int)($_GET['id'] ?? 0);
        if ($campaignId <= 0) {
            echo json_encode(['error' => 'Invalid campaign ID']);
            exit;
        }

        // Trigger worker iteration to keep queue moving
        try {
            processOutreachBatch($pdo, 10);
        } catch (Throwable $e) {}

        $progress = getCampaignProgress($pdo, $campaignId);
        echo json_encode($progress);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    if (!verifyCsrf($input['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF verification failed']);
        exit;
    }

    switch ($action) {
        case 'calculate_recipients':
            $selectedJobIds = (array)($input['selected_job_ids'] ?? []);
            $filters = (array)($input['filters'] ?? []);
            $data = calculateCampaignRecipients($pdo, $selectedJobIds, $filters);
            echo json_encode(['success' => true, 'data' => $data]);
            break;

        case 'preview':
            $selectedJobIds = array_map('intval', (array)($input['selected_job_ids'] ?? []));
            $candidateName = trim((string)($input['candidate_name'] ?? 'Jane Candidate'));
            
            $jobTitles = [];
            if (!empty($selectedJobIds)) {
                $inClause = implode(',', array_fill(0, count($selectedJobIds), '?'));
                $stmt = $pdo->prepare("SELECT title FROM jobs WHERE id IN ($inClause)");
                $stmt->execute($selectedJobIds);
                $jobTitles = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }
            if (empty($jobTitles)) {
                $jobTitles = ['Fullstack Developer - Web3 & AI', 'Blockchain Developer'];
            }

            $careerUrl = getCanonicalCareersUrl($pdo);
            $html = renderHiringOutreachEmailHtml($candidateName, $jobTitles, $careerUrl);
            $text = renderHiringOutreachEmailText($candidateName, $jobTitles, $careerUrl);

            echo json_encode([
                'success' => true,
                'html' => $html,
                'text' => $text,
                'career_url' => $careerUrl,
                'job_titles' => $jobTitles
            ]);
            break;

        case 'send_test':
            $testEmail = trim((string)($input['test_email'] ?? ''));
            if (!$testEmail || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['error' => 'Please provide a valid test recipient email address.']);
                exit;
            }

            $selectedJobIds = array_map('intval', (array)($input['selected_job_ids'] ?? []));
            $subject = trim((string)($input['subject'] ?? "We're hiring — explore opportunities at NonceBlox"));
            $mailSubject = '[TEST] ' . $subject;

            $jobTitles = [];
            if (!empty($selectedJobIds)) {
                $inClause = implode(',', array_fill(0, count($selectedJobIds), '?'));
                $stmt = $pdo->prepare("SELECT title FROM jobs WHERE id IN ($inClause)");
                $stmt->execute($selectedJobIds);
                $jobTitles = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }
            if (empty($jobTitles)) {
                $jobTitles = ['Fullstack Developer', 'Blockchain Developer'];
            }

            $careerUrl = getCanonicalCareersUrl($pdo);
            $html = renderHiringOutreachEmailHtml('Test Candidate', $jobTitles, $careerUrl);

            // Mail Delivery via system config
            $emailApi = integrationConfig('email_api');
            if (($emailApi['status'] ?? '') !== 'configured') {
                $emailApi = ['status' => 'configured', 'url' => 'https://hrms-api.nonceblox.com/api/emails/send-multi-recipient'];
            }
            $emailApiReady = !empty($emailApi['url']);
            $smtp = integrationConfig('smtp');

            if ($emailApiReady) {
                emailApiSendHtml($emailApi, [$testEmail], $mailSubject, $html);
            } else {
                smtpSendHtml($smtp, $testEmail, $mailSubject, $html);
            }

            // Audit Event for test send
            $auditStmt = $pdo->prepare("
                INSERT INTO audit_events (user_id, action, entity_type, entity_id, metadata_json, created_at)
                VALUES (?, 'email_campaign.test_sent', 'email_campaign', 'test', ?, NOW())
            ");
            $auditStmt->execute([$user['id'], json_encode(['recipient' => $testEmail, 'subject' => $mailSubject])]);

            echo json_encode(['success' => true, 'message' => 'Test email successfully sent to ' . $testEmail]);
            break;

        case 'create_campaign':
            $name = trim((string)($input['name'] ?? ''));
            $subject = trim((string)($input['subject'] ?? ''));
            $selectedJobIds = (array)($input['selected_job_ids'] ?? []);
            $filters = (array)($input['filters'] ?? []);

            if ($name === '') $name = 'Hiring Outreach ' . date('M d, Y');
            if ($subject === '') $subject = "We're hiring — explore opportunities at NonceBlox";

            $res = createHiringOutreachCampaign($pdo, [
                'name' => $name,
                'subject' => $subject,
                'selected_job_ids' => $selectedJobIds,
                'user_id' => $user['id'],
                'filters' => $filters
            ]);

            $campaignId = $res['id'];

            // Start Campaign
            startHiringOutreachCampaign($pdo, $campaignId, $user['id']);

            // Trigger worker iteration immediately
            try {
                processOutreachBatch($pdo, 15);
            } catch (Throwable $e) {}

            echo json_encode([
                'success' => true,
                'campaign_id' => $campaignId,
                'campaign_uuid' => $res['campaign_uuid'],
                'total_recipients' => $res['total_recipients']
            ]);
            break;

        case 'pause':
            $campaignId = (int)($input['campaign_id'] ?? 0);
            $ok = pauseHiringOutreachCampaign($pdo, $campaignId, $user['id']);
            echo json_encode(['success' => $ok]);
            break;

        case 'resume':
            $campaignId = (int)($input['campaign_id'] ?? 0);
            $ok = startHiringOutreachCampaign($pdo, $campaignId, $user['id']);
            if ($ok) {
                try {
                    processOutreachBatch($pdo, 15);
                } catch (Throwable $e) {}
            }
            echo json_encode(['success' => $ok]);
            break;

        case 'cancel':
            $campaignId = (int)($input['campaign_id'] ?? 0);
            $ok = cancelHiringOutreachCampaign($pdo, $campaignId, $user['id']);
            echo json_encode(['success' => $ok]);
            break;

        case 'retry_failed':
            $campaignId = (int)($input['campaign_id'] ?? 0);
            $stmt = $pdo->prepare("
                UPDATE email_campaign_recipients
                SET status = 'retry', updated_at = NOW()
                WHERE campaign_id = ? AND status = 'failed'
            ");
            $stmt->execute([$campaignId]);
            $resetCount = $stmt->rowCount();

            startHiringOutreachCampaign($pdo, $campaignId, $user['id']);

            try {
                processOutreachBatch($pdo, 15);
            } catch (Throwable $e) {}

            echo json_encode(['success' => true, 'reset_count' => $resetCount]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
} catch (Throwable $err) {
    http_response_code(500);
    echo json_encode(['error' => $err->getMessage()]);
}
