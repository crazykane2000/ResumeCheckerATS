<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/workspace.php';
require_once __DIR__ . '/integrations.php';
require_once __DIR__ . '/smtp_mailer.php';
require_once __DIR__ . '/email_api_mailer.php';

function getCanonicalCareersUrl(PDO $pdo): string {
    static $url = null;
    if ($url !== null) return $url;

    // Check if configured in organization_settings or integrations
    try {
        $stmt = $pdo->query("SELECT domain FROM organization_settings WHERE id = 1 LIMIT 1");
        $domain = trim((string)($stmt->fetchColumn() ?: ''));
        if ($domain !== '' && filter_var($domain, FILTER_VALIDATE_URL)) {
            $url = $domain;
            return $url;
        }
    } catch (Throwable $e) {}

    // Fallback to canonical NonceBlox Careers URL
    $url = 'https://nonceblox.com/career.php';
    return $url;
}

function getEligibleHiringJobs(PDO $pdo): array {
    $stmt = $pdo->query("
        SELECT j.*, COUNT(c.id) AS applicant_count
        FROM jobs j
        LEFT JOIN candidates c ON c.job_id = j.id
        WHERE j.status = 'open' OR j.status IS NULL OR j.status = ''
        GROUP BY j.id
        ORDER BY j.id DESC
    ");
    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($jobs)) {
        // Fallback query if no 'open' filter matches
        $stmt = $pdo->query("
            SELECT j.*, COUNT(c.id) AS applicant_count
            FROM jobs j
            LEFT JOIN candidates c ON c.job_id = j.id
            GROUP BY j.id
            ORDER BY j.id DESC
        ");
        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return $jobs;
}

function getSuppressedEmails(PDO $pdo): array {
    static $suppressed = null;
    if ($suppressed !== null) return $suppressed;
    try {
        $stmt = $pdo->query("SELECT LOWER(email) FROM email_suppressions");
        $suppressed = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        $suppressed = [];
    }
    return $suppressed;
}

function calculateCampaignRecipients(PDO $pdo, array $selectedJobIds, array $filters = []): array {
    $allCandidates = loadCandidateRecords();
    $suppressed = getSuppressedEmails($pdo);

    $jobIdSet = array_flip(array_map('intval', $selectedJobIds));

    $totalFound = 0;
    $eligible = [];
    $excluded = [];
    $seenEmails = [];

    $excludeStages = array_flip($filters['exclude_stages'] ?? []); // e.g. ['Offered', 'Joined', 'Rejected']
    $manualExcludedIds = array_flip($filters['manual_excluded_ids'] ?? []);
    $skillQuery = strtolower(trim((string)($filters['skill_query'] ?? '')));
    $minExp = isset($filters['min_exp']) && $filters['min_exp'] !== '' ? (float)$filters['min_exp'] : null;
    $maxExp = isset($filters['max_exp']) && $filters['max_exp'] !== '' ? (float)$filters['max_exp'] : null;

    foreach ($allCandidates as $candidate) {
        $candidateJobId = (int)($candidate['job_id'] ?? 0);

        // If specific jobs were selected, match candidate job_id
        if (!empty($jobIdSet) && !isset($jobIdSet[$candidateJobId])) {
            continue;
        }

        $totalFound++;

        $id = (string)($candidate['id'] ?? '');
        $name = trim((string)($candidate['name'] ?? 'Candidate'));
        $email = strtolower(trim((string)($candidate['email'] ?? '')));
        $stage = trim((string)($candidate['stage'] ?? 'Applied'));

        // Safety Exclusions
        if ($email === '') {
            $excluded[] = ['id' => $id, 'name' => $name, 'email' => $email, 'stage' => $stage, 'reason' => 'Missing Email Address'];
            continue;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $excluded[] = ['id' => $id, 'name' => $name, 'email' => $email, 'stage' => $stage, 'reason' => 'Invalid Email Format (' . $email . ')'];
            continue;
        }

        if (isset($suppressed[$email])) {
            $excluded[] = ['id' => $id, 'name' => $name, 'email' => $email, 'stage' => $stage, 'reason' => 'Email Suppressed / Unsubscribed'];
            continue;
        }

        if (isset($seenEmails[$email])) {
            $excluded[] = ['id' => $id, 'name' => $name, 'email' => $email, 'stage' => $stage, 'reason' => 'Duplicate Email in Campaign Selection'];
            continue;
        }

        // Optional stage exclusions
        if (!empty($excludeStages) && isset($excludeStages[$stage])) {
            $excluded[] = ['id' => $id, 'name' => $name, 'email' => $email, 'stage' => $stage, 'reason' => 'Excluded Stage (' . $stage . ')'];
            continue;
        }

        // Manual check exclusion
        if (isset($manualExcludedIds[$id])) {
            $excluded[] = ['id' => $id, 'name' => $name, 'email' => $email, 'stage' => $stage, 'reason' => 'Manually Unchecked by Recruiter'];
            continue;
        }

        // Skill query filter if specified
        if ($skillQuery !== '') {
            $candidateSkills = strtolower(is_array($candidate['skills_json'] ?? null) ? implode(' ', $candidate['skills_json']) : (string)($candidate['skills_json'] ?? ''));
            if (strpos($candidateSkills, $skillQuery) === false) {
                $excluded[] = ['id' => $id, 'name' => $name, 'email' => $email, 'stage' => $stage, 'reason' => 'Does not match skill keyword (' . $skillQuery . ')'];
                continue;
            }
        }

        $seenEmails[$email] = true;
        $eligible[] = [
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'stage' => $stage,
            'job_id' => $candidateJobId,
            'role_title' => $candidate['role_title'] ?? ''
        ];
    }

    return [
        'total_found' => $totalFound,
        'eligible_count' => count($eligible),
        'excluded_count' => count($excluded),
        'eligible' => $eligible,
        'excluded' => $excluded
    ];
}

function renderHiringOutreachEmailHtml(string $candidateName, array $jobTitles, string $careerUrl): string {
    require_once __DIR__ . '/branding.php';
    $brand = organizationBrand();
    $brandName = htmlspecialchars($brand['name'] ?: 'NonceBlox ATS', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $logoPath = trim((string)($brand['logo_path'] ?? ''));

    $logoHtml = '';
    if ($logoPath !== '') {
        $baseUrl = (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST']) ? (($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . $_SERVER['HTTP_HOST'] . '/') : 'http://127.0.0.1:8000/';
        $fullLogoUrl = publicBrandLogoUrl($brand, $baseUrl);
        $logoHtml = '<img src="' . htmlspecialchars($fullLogoUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '" alt="' . $brandName . '" style="max-height:45px; max-width:240px; display:block; object-fit:contain;">';
    } else {
        $logoHtml = '<span style="font-size:20px; font-weight:800; color:#1e1934; letter-spacing:-0.3px;">' . $brandName . '</span>';
    }

    $safeName = trim(htmlspecialchars($candidateName, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $greeting = ($safeName !== '' && strcasecmp($safeName, 'Candidate') !== 0) ? "Hi {$safeName}," : "Hi there,";
    $safeUrl = htmlspecialchars($careerUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $jobItemsHtml = '';
    foreach ($jobTitles as $title) {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $jobItemsHtml .= '<div style="background:#f8fafc; border:1px solid #e2e8f0; border-left:4px solid #7c3aed; border-radius:8px; padding:14px 18px; margin-bottom:10px; color:#1e293b; font-weight:600; font-size:14px;">' . $safeTitle . '</div>';
    }

    return '<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>We\'re hiring — ' . $brandName . '</title>
</head>
<body style="margin:0; padding:0; background-color:#f1f5f9; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; color:#334155; -webkit-font-smoothing:antialiased;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f1f5f9; padding:32px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%; max-width:600px; background-color:#ffffff; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden; box-shadow:0 10px 25px -5px rgba(0,0,0,0.05);">
          <!-- Header Bar with Logo -->
          <tr>
            <td style="padding:28px 36px; background-color:#ffffff; border-bottom:1px solid #f1f5f9;">
              ' . $logoHtml . '
            </td>
          </tr>
          <!-- Body Content -->
          <tr>
            <td style="padding:36px; font-size:15px; line-height:1.75; color:#334155;">
              <p style="margin-top:0; margin-bottom:20px; font-size:17px; font-weight:700; color:#0f172a;">' . $greeting . '</p>
              <p style="margin-bottom:24px;">NonceBlox is currently looking for talented professionals to join our team across several key engineering and growth roles.</p>
              
              <div style="margin-bottom:28px;">
                <div style="font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:1px; color:#64748b; margin-bottom:12px;">Current Openings</div>
                ' . $jobItemsHtml . '
              </div>

              <p style="margin-bottom:28px;">If any of these opportunities align with your background and career goals, explore our active job listings and submit your profile directly.</p>

              <!-- CTA Button -->
              <table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:20px;">
                <tr>
                  <td align="center" style="background:#7c3aed; border-radius:8px; padding:14px 32px;">
                    <a href="' . $safeUrl . '" target="_blank" style="color:#ffffff; font-weight:700; font-size:15px; text-decoration:none; display:inline-block; letter-spacing:0.3px;">Explore Opportunities &rarr;</a>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <!-- Footer -->
          <tr>
            <td style="padding:24px 36px; background-color:#f8fafc; border-top:1px solid #f1f5f9; color:#64748b; font-size:13px; text-align:center;">
              Regards,<br>
              <strong style="color:#1e293b;">NonceBlox Hiring Team</strong>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';
}

function renderHiringOutreachEmailText(string $candidateName, array $jobTitles, string $careerUrl): string {
    $safeName = trim($candidateName);
    $greeting = ($safeName !== '' && strcasecmp($safeName, 'Candidate') !== 0) ? "Hi {$safeName}," : "Hi there,";

    $jobsList = implode("\n- ", $jobTitles);

    return "{$greeting}\n\n"
        . "NonceBlox is currently looking for talented professionals to join our team across several key engineering and growth roles.\n\n"
        . "CURRENT OPENINGS:\n- {$jobsList}\n\n"
        . "If any of these opportunities align with your experience, explore our active job listings and submit your profile directly through our official careers page:\n\n"
        . "{$careerUrl}\n\n"
        . "Regards,\nNonceBlox Hiring Team";
}

function createHiringOutreachCampaign(PDO $pdo, array $params): array {
    $name = trim((string)($params['name'] ?? 'Hiring Outreach Campaign'));
    $subject = trim((string)($params['subject'] ?? "We're hiring — explore opportunities at NonceBlox"));
    $selectedJobIds = (array)($params['selected_job_ids'] ?? []);
    $userId = (int)($params['user_id'] ?? 1);
    $filters = (array)($params['filters'] ?? []);

    if (empty($selectedJobIds)) {
        throw new InvalidArgumentException('At least one job must be selected for the campaign.');
    }

    $careerUrl = getCanonicalCareersUrl($pdo);

    // Fetch snapshot of selected job titles
    $inClause = implode(',', array_fill(0, count($selectedJobIds), '?'));
    $stmt = $pdo->prepare("SELECT id, title FROM jobs WHERE id IN ($inClause)");
    $stmt->execute(array_values($selectedJobIds));
    $jobRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($jobRows)) {
        throw new InvalidArgumentException('Selected jobs could not be found.');
    }

    $jobTitleSnapshots = [];
    foreach ($jobRows as $row) {
        $jobTitleSnapshots[(int)$row['id']] = $row['title'];
    }

    // Calculate eligible recipients
    $recipientsData = calculateCampaignRecipients($pdo, array_keys($jobTitleSnapshots), $filters);
    $eligibleRecipients = $recipientsData['eligible'];

    if (empty($eligibleRecipients)) {
        throw new InvalidArgumentException('No eligible candidates found for the selected jobs and filters.');
    }

    // Generate HTML and Text Snapshots
    $htmlSnapshot = renderHiringOutreachEmailHtml('{{candidate_name}}', array_values($jobTitleSnapshots), $careerUrl);
    $textSnapshot = renderHiringOutreachEmailText('{{candidate_name}}', array_values($jobTitleSnapshots), $careerUrl);

    $campaignUuid = sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );

    $totalRecipients = count($eligibleRecipients);

    $pdo->beginTransaction();
    try {
        // Insert campaign header
        $stmt = $pdo->prepare("
            INSERT INTO email_campaigns (
                campaign_uuid, name, subject, template_key, template_version,
                html_snapshot, text_snapshot, career_url, created_by, status,
                total_recipients, pending_count, created_at, updated_at
            ) VALUES (?, ?, ?, 'hiring_outreach_dark_v1', '1.0.0', ?, ?, ?, ?, 'draft', ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $campaignUuid, $name, $subject,
            $htmlSnapshot, $textSnapshot, $careerUrl, $userId,
            $totalRecipients, $totalRecipients
        ]);
        $campaignId = (int)$pdo->lastInsertId();

        // Snapshot Jobs
        $jobStmt = $pdo->prepare("INSERT INTO email_campaign_jobs (campaign_id, job_id, job_title_snapshot) VALUES (?, ?, ?)");
        foreach ($jobTitleSnapshots as $jId => $jTitle) {
            $jobStmt->execute([$campaignId, $jId, $jTitle]);
        }

        // Snapshot Recipients with idempotency key
        $recipStmt = $pdo->prepare("
            INSERT INTO email_campaign_recipients (
                campaign_id, candidate_id, candidate_name_snapshot, email,
                status, idempotency_key, created_at, updated_at
            ) VALUES (?, ?, ?, ?, 'pending', ?, NOW(), NOW())
        ");

        foreach ($eligibleRecipients as $recip) {
            $idempotencyKey = 'cmp_' . $campaignId . '_cand_' . $recip['id'];
            $recipStmt->execute([
                $campaignId,
                $recip['id'],
                $recip['name'],
                $recip['email'],
                $idempotencyKey
            ]);
        }

        // Audit Event
        $auditStmt = $pdo->prepare("
            INSERT INTO audit_events (user_id, action, entity_type, entity_id, metadata_json, created_at)
            VALUES (?, 'email_campaign.created', 'email_campaign', ?, ?, NOW())
        ");
        $auditStmt->execute([
            $userId,
            (string)$campaignId,
            json_encode([
                'campaign_uuid' => $campaignUuid,
                'name' => $name,
                'total_recipients' => $totalRecipients,
                'job_count' => count($jobTitleSnapshots)
            ])
        ]);

        $pdo->commit();

        return [
            'id' => $campaignId,
            'campaign_uuid' => $campaignUuid,
            'name' => $name,
            'total_recipients' => $totalRecipients
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function startHiringOutreachCampaign(PDO $pdo, int $campaignId, int $userId): bool {
    $stmt = $pdo->prepare("
        UPDATE email_campaigns
        SET status = 'sending', started_at = COALESCE(started_at, NOW()), updated_at = NOW()
        WHERE id = ? AND status IN ('draft', 'paused', 'queued')
    ");
    $stmt->execute([$campaignId]);
    $affected = $stmt->rowCount() > 0;

    if ($affected) {
        $auditStmt = $pdo->prepare("
            INSERT INTO audit_events (user_id, action, entity_type, entity_id, metadata_json, created_at)
            VALUES (?, 'email_campaign.started', 'email_campaign', ?, ?, NOW())
        ");
        $auditStmt->execute([$userId, (string)$campaignId, json_encode(['status' => 'sending'])]);
    }

    return $affected;
}

function pauseHiringOutreachCampaign(PDO $pdo, int $campaignId, int $userId): bool {
    $stmt = $pdo->prepare("
        UPDATE email_campaigns
        SET status = 'paused', paused_at = NOW(), updated_at = NOW()
        WHERE id = ? AND status = 'sending'
    ");
    $stmt->execute([$campaignId]);
    $affected = $stmt->rowCount() > 0;

    if ($affected) {
        $auditStmt = $pdo->prepare("
            INSERT INTO audit_events (user_id, action, entity_type, entity_id, metadata_json, created_at)
            VALUES (?, 'email_campaign.paused', 'email_campaign', ?, ?, NOW())
        ");
        $auditStmt->execute([$userId, (string)$campaignId, json_encode(['status' => 'paused'])]);
    }

    return $affected;
}

function cancelHiringOutreachCampaign(PDO $pdo, int $campaignId, int $userId): bool {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            UPDATE email_campaigns
            SET status = 'cancelled', cancelled_at = NOW(), updated_at = NOW()
            WHERE id = ? AND status IN ('draft', 'sending', 'paused', 'queued')
        ");
        $stmt->execute([$campaignId]);

        // Cancel all pending/retry recipients
        $recipStmt = $pdo->prepare("
            UPDATE email_campaign_recipients
            SET status = 'cancelled', updated_at = NOW()
            WHERE campaign_id = ? AND status IN ('pending', 'retry')
        ");
        $recipStmt->execute([$campaignId]);

        // Refresh campaign counts
        refreshCampaignCounts($pdo, $campaignId);

        $auditStmt = $pdo->prepare("
            INSERT INTO audit_events (user_id, action, entity_type, entity_id, metadata_json, created_at)
            VALUES (?, 'email_campaign.cancelled', 'email_campaign', ?, ?, NOW())
        ");
        $auditStmt->execute([$userId, (string)$campaignId, json_encode(['status' => 'cancelled'])]);

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function refreshCampaignCounts(PDO $pdo, int $campaignId): void {
    $stmt = $pdo->prepare("
        SELECT status, COUNT(*) as cnt
        FROM email_campaign_recipients
        WHERE campaign_id = ?
        GROUP BY status
    ");
    $stmt->execute([$campaignId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $counts = [
        'pending' => 0,
        'processing' => 0,
        'sent' => 0,
        'failed' => 0,
        'skipped' => 0,
        'suppressed' => 0
    ];

    foreach ($rows as $r) {
        $st = $r['status'];
        if (isset($counts[$st])) {
            $counts[$st] = (int)$r['cnt'];
        }
    }

    $updateStmt = $pdo->prepare("
        UPDATE email_campaigns
        SET pending_count = ?, processing_count = ?, sent_count = ?, failed_count = ?, skipped_count = ?, suppressed_count = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $updateStmt->execute([
        $counts['pending'],
        $counts['processing'],
        $counts['sent'],
        $counts['failed'],
        $counts['skipped'],
        $counts['suppressed'],
        $campaignId
    ]);

    // Check if campaign is completed
    $remStmt = $pdo->prepare("SELECT status, total_recipients FROM email_campaigns WHERE id = ?");
    $remStmt->execute([$campaignId]);
    $cmp = $remStmt->fetch(PDO::FETCH_ASSOC);

    if ($cmp && $cmp['status'] === 'sending' && ($counts['pending'] + $counts['processing']) === 0) {
        $finalStatus = ($counts['failed'] > 0) ? 'completed_with_errors' : 'completed';
        $doneStmt = $pdo->prepare("UPDATE email_campaigns SET status = ?, completed_at = NOW() WHERE id = ?");
        $doneStmt->execute([$finalStatus, $campaignId]);
    }
}

function getCampaignProgress(PDO $pdo, int $campaignId): array {
    refreshCampaignCounts($pdo, $campaignId);

    $stmt = $pdo->prepare("SELECT * FROM email_campaigns WHERE id = ?");
    $stmt->execute([$campaignId]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$c) {
        return ['error' => 'Campaign not found'];
    }

    $total = (int)$c['total_recipients'];
    $sent = (int)$c['sent_count'];
    $failed = (int)$c['failed_count'];
    $skipped = (int)$c['skipped_count'];
    $suppressed = (int)$c['suppressed_count'];
    $pending = (int)$c['pending_count'];
    $processing = (int)$c['processing_count'];

    $processed = $sent + $failed + $skipped + $suppressed;
    $percent = ($total > 0) ? min(100, (int)round(($processed / $total) * 100)) : 0;

    return [
        'id' => (int)$c['id'],
        'campaign_uuid' => $c['campaign_uuid'],
        'name' => $c['name'],
        'status' => $c['status'],
        'total' => $total,
        'processed' => $processed,
        'sent' => $sent,
        'failed' => $failed,
        'skipped' => $skipped,
        'suppressed' => $suppressed,
        'pending' => $pending,
        'processing' => $processing,
        'percent' => $percent
    ];
}

function processOutreachBatch(PDO $pdo, int $batchSize = 15): int {
    // Find active campaigns in 'sending' status
    $stmt = $pdo->query("SELECT id FROM email_campaigns WHERE status = 'sending' ORDER BY id ASC");
    $activeCampaignIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($activeCampaignIds)) {
        return 0;
    }

    $processedCount = 0;

    // Load mail configuration
    $emailApi = integrationConfig('email_api');
    if (($emailApi['status'] ?? '') !== 'configured') {
        $emailApi = ['status' => 'configured', 'url' => 'https://hrms-api.nonceblox.com/api/emails/send-multi-recipient'];
    }
    $emailApiReady = !empty($emailApi['url']);
    $smtp = integrationConfig('smtp');

    $deliverOne = function(string $to, string $subject, string $html) use ($emailApiReady, $emailApi, $smtp) {
        if ($emailApiReady) {
            emailApiSendHtml($emailApi, [$to], $subject, $html);
        } else {
            smtpSendHtml($smtp, $to, $subject, $html);
        }
    };

    foreach ($activeCampaignIds as $campaignId) {
        // Fetch campaign snapshots
        $cStmt = $pdo->prepare("SELECT * FROM email_campaigns WHERE id = ?");
        $cStmt->execute([$campaignId]);
        $campaign = $cStmt->fetch(PDO::FETCH_ASSOC);

        if (!$campaign || $campaign['status'] !== 'sending') continue;

        // Fetch snapshot job titles
        $jStmt = $pdo->prepare("SELECT job_title_snapshot FROM email_campaign_jobs WHERE campaign_id = ? ORDER BY id ASC");
        $jStmt->execute([$campaignId]);
        $jobTitles = $jStmt->fetchAll(PDO::FETCH_COLUMN);

        $careerUrl = $campaign['career_url'];
        $subject = $campaign['subject'];

        // Atomically claim pending/retry recipient rows
        $claimStmt = $pdo->prepare("
            SELECT id, candidate_id, candidate_name_snapshot, email, attempt_count
            FROM email_campaign_recipients
            WHERE campaign_id = ? AND status IN ('pending', 'retry')
            ORDER BY id ASC
            LIMIT {$batchSize}
            FOR UPDATE
        ");
        
        $pdo->beginTransaction();
        try {
            $claimStmt->execute([$campaignId]);
            $recipients = $claimStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($recipients)) {
                $pdo->commit();
                refreshCampaignCounts($pdo, $campaignId);
                continue;
            }

            $recipIds = array_column($recipients, 'id');
            $inClause = implode(',', array_fill(0, count($recipIds), '?'));
            $markStmt = $pdo->prepare("UPDATE email_campaign_recipients SET status = 'processing', last_attempt_at = NOW() WHERE id IN ($inClause)");
            $markStmt->execute($recipIds);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            continue;
        }

        // Process dispatches individually
        foreach ($recipients as $recip) {
            $rId = (int)$recip['id'];
            $candName = $recip['candidate_name_snapshot'];
            $toEmail = $recip['email'];
            $attempt = (int)$recip['attempt_count'] + 1;

            $html = renderHiringOutreachEmailHtml($candName, $jobTitles, $careerUrl);

            try {
                $deliverOne($toEmail, $subject, $html);

                // Success
                $upd = $pdo->prepare("
                    UPDATE email_campaign_recipients
                    SET status = 'sent', sent_at = NOW(), attempt_count = ?, error_code = NULL, error_message = NULL, updated_at = NOW()
                    WHERE id = ?
                ");
                $upd->execute([$attempt, $rId]);
                $processedCount++;
            } catch (Throwable $err) {
                $errMsg = mb_substr($err->getMessage(), 0, 490);
                // Differentiate temporary vs permanent error
                $isPermanent = (stristr($errMsg, 'invalid') !== false || stristr($errMsg, 'rejected') !== false);
                $nextStatus = ($isPermanent || $attempt >= 3) ? 'failed' : 'retry';

                $upd = $pdo->prepare("
                    UPDATE email_campaign_recipients
                    SET status = ?, attempt_count = ?, error_code = 'DELIVERY_ERROR', error_message = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $upd->execute([$nextStatus, $attempt, $errMsg, $rId]);
                $processedCount++;
            }
        }

        refreshCampaignCounts($pdo, $campaignId);
    }

    return $processedCount;
}
