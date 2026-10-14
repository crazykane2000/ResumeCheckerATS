<?php
require_once __DIR__ . '/lib/auth.php';
requireAuth();
require_once __DIR__ . '/lib/workspace.php';
require_once __DIR__ . '/lib/lifecycle_mailer.php';
require_once __DIR__ . '/lib/application_match.php';
require_once __DIR__ . '/lib/candidate_activity.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$user = currentUser();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    // 1. Fetch Candidate Drawer Details for Sliding Overlay
    if ($action === 'get_drawer') {
        $candidateId = trim((string)($_GET['candidate_id'] ?? ''));
        if ($candidateId === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Candidate ID is required.']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT c.*, j.title AS job_title, j.description AS job_description,
                   j.min_experience AS job_min_experience, j.max_experience AS job_max_experience,
                   j.required_skills_json AS job_required_skills_json, j.preferred_skills_json AS job_preferred_skills_json
            FROM candidates c
            LEFT JOIN jobs j ON j.id = c.job_id
            WHERE c.id = ?
            LIMIT 1
        ");
        $stmt->execute([$candidateId]);
        $cand = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cand) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Candidate not found.']);
            exit;
        }

        $candName = candidatePresentation($cand);
        $exp = json_decode($cand['experience_json'] ?? '{}', true) ?: [];
        $skills = json_decode($cand['skills_json'] ?? '[]', true) ?: [];

        $job = $cand['job_id'] ? [
            'id' => (int)$cand['job_id'],
            'title' => $cand['job_title'],
            'description' => $cand['job_description'],
            'min_experience' => $cand['job_min_experience'],
            'max_experience' => $cand['job_max_experience'],
            'required_skills_json' => $cand['job_required_skills_json'],
            'preferred_skills_json' => $cand['job_preferred_skills_json']
        ] : null;

        $match = applicationEvidenceMatch($cand, $job ?? []);
        $cSource = normalizeCandidateSource($cand['source_name'] ?? '');
        $isOld = candidateIsOldApplication($cand);
        $age = candidateApplicationAge($cand);

        // Fetch Recruiter Evaluation Notes
        $notesStmt = $pdo->prepare("
            SELECT id, stage, feedback, strengths, recommendation, author_name, created_at 
            FROM candidate_notes 
            WHERE candidate_id = ? 
            ORDER BY created_at DESC
        ");
        $notesStmt->execute([$candidateId]);
        $notes = $notesStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch System Activity Timeline
        $timelineEvents = candidateActivityTimeline($pdo, $cand);

        $candScore = (int)($cand['score'] ?? 0);
        $matchScore = (int)($match['score'] ?? 0);
        $score = (!empty($match['analyzed']) && $matchScore > 0) ? $matchScore : ($candScore > 0 ? $candScore : $matchScore);
        $isAnalyzed = $score > 0 || !empty($match['analyzed']);
        $isConfigured = true;

        $breakdown = $match['breakdown'] ?? [];
        $maximum = $match['maximum'] ?? [
            'required_skills' => 40,
            'relevant_experience' => 25,
            'preferred_skills' => 10,
            'role_similarity' => 10,
            'evidence_strength' => 15
        ];

        // If breakdown sum is 0 but we have a valid score, generate proportional composition
        if (array_sum($breakdown) <= 0 && $score > 0) {
            $breakdown = [
                'required_skills' => min(40, (int)round($score * 0.40)),
                'relevant_experience' => min(25, (int)round($score * 0.25)),
                'preferred_skills' => min(10, (int)round($score * 0.10)),
                'role_similarity' => min(10, (int)round($score * 0.10)),
                'evidence_strength' => min(15, (int)round($score * 0.15))
            ];
        }

        $matchedReqs = array_column(array_filter($match['mapping'] ?? [], fn($item) => ($item['status'] ?? '') === 'experience_backed'), 'requirement');
        $reviewReqs = array_column(array_filter($match['mapping'] ?? [], fn($item) => in_array($item['status'] ?? '', ['skills_only', 'related_review'], true)), 'requirement');
        $missingReqs = array_column(array_filter($match['mapping'] ?? [], fn($item) => ($item['status'] ?? '') === 'not_found'), 'requirement');
        $benefits = array_slice($match['preferred_matched'] ?? [], 0, 16);
        if (empty($benefits) && !empty($skills)) {
            $benefits = array_slice($skills, 0, 16);
        }

        echo json_encode([
            'ok' => true,
            'candidate' => [
                'id' => $cand['id'],
                'name' => $candName['name'],
                'role' => $candName['role'],
                'job' => $cand['job_title'] ?: ($candName['role'] ?: 'Open Role'),
                'email' => $cand['email'] ?: 'Not available',
                'phone' => $cand['phone'] ?: 'Not available',
                'country' => $cand['country_name'] ?: 'Not available',
                'ctc' => ($cand['expected_ctc'] !== null ? '₹' . $cand['expected_ctc'] . ' LPA' : ($cand['current_ctc'] !== null ? '₹' . $cand['current_ctc'] . ' LPA' : 'Not specified')),
                'source' => $cSource ?: 'Direct upload',
                'stage' => $cand['stage'] ?? 'Applied',
                'created_at' => (string)($cand['created_at'] ?? ''),
                'age' => $age,
                'old' => $isOld,
                'score' => $score,
                'configured' => $isConfigured,
                'analyzed' => $isAnalyzed,
                'breakdown' => $breakdown,
                'maximum' => $maximum,
                'matched' => $matchedReqs,
                'review' => $reviewReqs,
                'missing' => $missingReqs,
                'benefits' => $benefits,
                'months' => (int)($exp['total_months'] ?? 0),
                'confidence' => $exp['confidence'] ?? 'Not available',
                'jobs' => $exp['jobs'] ?? [],
                'gaps' => $exp['gaps'] ?? [],
                'skill_months' => $exp['skill_months'] ?? [],
                'detail_url' => 'candidate_detail.php?id=' . urlencode($cand['id']),
                'resume_url' => 'resume_file.php?id=' . urlencode($cand['id']),
                'text_url' => 'candidate_drawer_api.php?id=' . urlencode($cand['id']),
                'notes' => $notes,
                'activity' => $timelineEvents
            ]
        ]);
        exit;
    }

    // 2. Add Recruiter Note from Drawer
    if ($action === 'add_note' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?: $_POST;

        if (!verifyCsrf($input['csrf'] ?? '')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'CSRF verification failed.']);
            exit;
        }

        $candidateId = trim((string)($input['candidate_id'] ?? ''));
        $stage = trim((string)($input['stage'] ?? 'Interview'));
        $feedback = trim((string)($input['feedback'] ?? ''));
        $strengths = trim((string)($input['strengths'] ?? ''));
        $recommendation = trim((string)($input['recommendation'] ?? 'Advance'));

        if ($candidateId === '' || $feedback === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Candidate ID and feedback note are required.']);
            exit;
        }

        $authorName = $user['name'] ?: 'Recruiter';
        $stmt = $pdo->prepare("
            INSERT INTO candidate_notes (candidate_id, user_id, author_name, stage, feedback, strengths, recommendation, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$candidateId, $user['id'], $authorName, $stage, $feedback, $strengths, $recommendation]);
        $noteId = $pdo->lastInsertId();

        // Audit Trail
        $pdo->prepare("
            INSERT INTO audit_events (user_id, action, entity_type, entity_id, metadata_json, created_at)
            VALUES (?, 'candidate.evaluation_note', 'candidate', ?, ?, NOW())
        ")->execute([
            $user['id'],
            $candidateId,
            json_encode([
                'note_id' => $noteId,
                'stage' => $stage,
                'author' => $authorName,
                'feedback' => $feedback,
                'strengths' => $strengths,
                'recommendation' => $recommendation
            ])
        ]);

        // Return updated notes list
        $notesStmt = $pdo->prepare("SELECT * FROM candidate_notes WHERE candidate_id = ? ORDER BY created_at DESC");
        $notesStmt->execute([$candidateId]);
        $updatedNotes = $notesStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['ok' => true, 'notes' => $updatedNotes]);
        exit;
    }

    // 3. Email Preview Generation
    if ($action === 'get_preview' || ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($action))) {
        $candidateId = trim((string)($_GET['candidate_id'] ?? ''));
        $targetStage = trim((string)($_GET['stage'] ?? 'Interview'));
        $templateKey = trim((string)($_GET['template_key'] ?? ''));

        if ($candidateId === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Candidate ID is required.']);
            exit;
        }

        // Fetch candidate details
        $stmt = $pdo->prepare("
            SELECT c.id, c.name, c.email, c.stage, c.role_title, j.title AS job_title
            FROM candidates c
            LEFT JOIN jobs j ON j.id = c.job_id
            WHERE c.id = ?
            LIMIT 1
        ");
        $stmt->execute([$candidateId]);
        $cand = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cand) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Candidate not found.']);
            exit;
        }

        $allTemplates = getLifecycleTemplates();

        if ($templateKey === '' || !isset($allTemplates[$templateKey])) {
            $stageMap = [
                'Applied' => 'welcome',
                'Screening' => 'screening',
                'Invited' => 'round1',
                'Round 1' => 'round1',
                'Interview' => 'round1',
                'Round 2' => 'round2',
                'Offer' => 'offer',
                'Rejected' => 'rejected'
            ];
            $templateKey = $stageMap[$targetStage] ?? 'round1';
        }

        $tpl = $allTemplates[$templateKey];
        $brand = organizationBrand();
        $brandName = $brand['name'] ?: 'NonceBlox';
        $jobTitle = $cand['job_title'] ?: ($cand['role_title'] ?: 'Open Role');

        $candidateName = $cand['name'];
        if ($candidateName === '' || $candidateName === 'Unknown candidate') {
            $d = candidatePresentation(['name' => $cand['name'], 'role' => $cand['role_title']]);
            $candidateName = $d['name'];
        }

        $defaultSubject = str_replace(
            ['{{job_title}}', '{{company_name}}', '{{candidate_name}}'],
            [$jobTitle, $brandName, $candidateName],
            $tpl['default_subject']
        );

        $defaultIntro = str_replace(
            ['{{job_title}}', '{{company_name}}', '{{candidate_name}}'],
            [$jobTitle, $brandName, $candidateName],
            $tpl['intro']
        );

        $defaultNext = str_replace(
            ['{{job_title}}', '{{company_name}}', '{{candidate_name}}'],
            [$jobTitle, $brandName, $candidateName],
            $tpl['next_steps']
        );

        $defaultBodyText = $defaultIntro . "\n\n" . $defaultNext;

        $html = renderLifecycleEmailHtml($templateKey, [
            'candidate_name' => $candidateName,
            'job_title' => $jobTitle,
            'subject' => $defaultSubject,
            'custom_message' => ''
        ], $brand);

        echo json_encode([
            'ok' => true,
            'candidate' => [
                'id' => $cand['id'],
                'name' => $candidateName,
                'email' => $cand['email'],
                'current_stage' => $cand['stage'],
                'job_title' => $jobTitle
            ],
            'target_stage' => $targetStage,
            'template_key' => $templateKey,
            'subject' => $defaultSubject,
            'body_text' => $defaultBodyText,
            'html' => $html,
            'all_templates' => $allTemplates
        ]);
        exit;
    }

    // 4. Execute Stage Move, Record Evaluation Note & Send Email
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true);
        if (!is_array($input)) {
            $input = $_POST;
        }

        if (!verifyCsrf($input['csrf'] ?? '')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'CSRF verification failed.']);
            exit;
        }

        $candidateId = trim((string)($input['candidate_id'] ?? ''));
        $targetStage = trim((string)($input['stage'] ?? ''));
        $sendEmail = !empty($input['send_email']);
        $templateKey = trim((string)($input['template_key'] ?? ''));
        $customSubject = trim((string)($input['subject'] ?? ''));
        $customMessage = trim((string)($input['message_text'] ?? ''));

        // Recruiter Evaluation Notes
        $evalFeedback = trim((string)($input['eval_feedback'] ?? ''));
        $evalStrengths = trim((string)($input['eval_strengths'] ?? ''));
        $evalRecommendation = trim((string)($input['eval_recommendation'] ?? 'Advance'));

        $allowedStages = ['Applied', 'Screening', 'Invited', 'Round 1', 'Interview', 'Round 2', 'Offer', 'Rejected'];
        if (!in_array($targetStage, $allowedStages, true)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Invalid target stage: ' . $targetStage]);
            exit;
        }

        // Fetch candidate
        $stmt = $pdo->prepare("
            SELECT c.id, c.name, c.email, c.role_title, c.stage, j.title AS job_title
            FROM candidates c
            LEFT JOIN jobs j ON j.id = c.job_id
            WHERE c.id = ?
            LIMIT 1
        ");
        $stmt->execute([$candidateId]);
        $cand = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cand) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Candidate not found.']);
            exit;
        }

        $oldStage = $cand['stage'];

        // 1. Update Candidate Stage in DB
        $updateStmt = $pdo->prepare("UPDATE candidates SET stage = ? WHERE id = ?");
        $updateStmt->execute([$targetStage, $candidateId]);

        // 2. Save Evaluation Note if provided
        $authorName = $user['name'] ?: 'Recruiter';
        if ($evalFeedback !== '' || $evalStrengths !== '') {
            $nStmt = $pdo->prepare("
                INSERT INTO candidate_notes (candidate_id, user_id, author_name, stage, feedback, strengths, recommendation, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $nStmt->execute([
                $candidateId,
                $user['id'],
                $authorName,
                $targetStage,
                $evalFeedback ?: ('Stage moved from ' . $oldStage . ' to ' . $targetStage),
                $evalStrengths,
                $evalRecommendation
            ]);
        }

        // 3. Audit Stage Move with Evaluation Notes
        $pdo->prepare("
            INSERT INTO audit_events (user_id, action, entity_type, entity_id, metadata_json, created_at)
            VALUES (?, 'candidate.stage_changed', 'candidate', ?, ?, NOW())
        ")->execute([
            $user['id'],
            $candidateId,
            json_encode([
                'old_stage' => $oldStage,
                'new_stage' => $targetStage,
                'author' => $authorName,
                'email_sent' => $sendEmail,
                'eval_feedback' => $evalFeedback,
                'eval_strengths' => $evalStrengths,
                'recommendation' => $evalRecommendation
            ])
        ]);

        $emailResult = null;

        // 4. Dispatch Email if requested
        if ($sendEmail && !empty($cand['email'])) {
            $brand = organizationBrand();
            $brandName = $brand['name'] ?: 'NonceBlox';
            $jobTitle = $cand['job_title'] ?: ($cand['role_title'] ?: 'Open Role');

            $candidateName = $cand['name'];
            if ($candidateName === '' || $candidateName === 'Unknown candidate') {
                $d = candidatePresentation(['name' => $cand['name'], 'role' => $cand['role_title']]);
                $candidateName = $d['name'];
            }

            if ($customSubject === '') {
                $templates = getLifecycleTemplates();
                $customSubject = str_replace(
                    ['{{job_title}}', '{{company_name}}', '{{candidate_name}}'],
                    [$jobTitle, $brandName, $candidateName],
                    $templates[$templateKey]['default_subject'] ?? 'Hiring Update'
                );
            }

            $renderedHtml = renderLifecycleEmailHtml($templateKey, [
                'candidate_name' => $candidateName,
                'job_title' => $jobTitle,
                'subject' => $customSubject,
                'custom_message' => $customMessage
            ], $brand);

            $emailResult = sendLifecycleEmail($pdo, [
                'recipient_email' => $cand['email'],
                'candidate_id' => $candidateId,
                'stage' => $targetStage,
                'template_key' => $templateKey,
                'subject' => $customSubject,
                'html' => $renderedHtml,
                'user_id' => $user['id']
            ]);
        }

        echo json_encode([
            'ok' => true,
            'candidate_id' => $candidateId,
            'stage' => $targetStage,
            'email_sent' => $sendEmail,
            'email_result' => $emailResult
        ]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
