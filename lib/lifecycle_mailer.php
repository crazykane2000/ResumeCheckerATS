<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/branding.php';
require_once __DIR__ . '/integrations.php';
require_once __DIR__ . '/smtp_mailer.php';
require_once __DIR__ . '/email_api_mailer.php';

/**
 * Returns available lifecycle email templates by stage.
 */
function getLifecycleTemplates(): array {
    return [
        'welcome' => [
            'key' => 'welcome',
            'stage' => 'Applied',
            'label' => 'Application Received (Welcome)',
            'icon' => 'fa-envelope-open',
            'badge_color' => '#64748b',
            'default_subject' => 'Application Received — {{job_title}} at {{company_name}}',
            'headline' => 'Thank you for your interest in {{company_name}}',
            'intro' => 'We have received your application for the position of {{job_title}}. Our talent acquisition team is actively reviewing your qualifications and work experience.',
            'next_steps' => 'If your profile matches our requirements, we will reach out directly to schedule an introductory discussion. You can explore more about our work and culture on our careers portal.',
            'cta_text' => 'Visit Careers Portal',
            'cta_url' => 'https://nonceblox.com/career.php'
        ],
        'screening' => [
            'key' => 'screening',
            'stage' => 'Screening',
            'label' => 'Profile Shortlisted',
            'icon' => 'fa-filter-circle-dollar',
            'badge_color' => '#0284c7',
            'default_subject' => 'Update on your application: Shortlisted for {{job_title}}',
            'headline' => 'Great news! Your profile has been shortlisted',
            'intro' => 'We are pleased to inform you that your application for {{job_title}} at {{company_name}} has passed initial screening and has been shortlisted for technical evaluation.',
            'next_steps' => 'Our hiring team is currently coordinating interviewer schedules. We will send you scheduling details and available slots shortly.',
            'cta_text' => 'View Company Overview',
            'cta_url' => 'https://nonceblox.com/'
        ],
        'round1' => [
            'key' => 'round1',
            'stage' => 'Interview',
            'label' => 'Interview Invitation — Round 1 (Screening / Technical)',
            'icon' => 'fa-calendar-check',
            'badge_color' => '#7c3aed',
            'default_subject' => 'Interview Invitation: Round 1 for {{job_title}}',
            'headline' => 'You are invited to Round 1 Interview',
            'intro' => 'Thank you for your patience. We were impressed by your background and would like to invite you to Round 1 interview discussion for the {{job_title}} role.',
            'next_steps' => 'The discussion will take approximately 30–45 minutes and focus on your core skills, recent projects, and relevant experience. Please ensure you have a stable internet connection and quiet environment.',
            'cta_text' => 'Confirm Interview Slot',
            'cta_url' => 'https://nonceblox.com/career.php'
        ],
        'round2' => [
            'key' => 'round2',
            'stage' => 'Interview',
            'label' => 'Interview Invitation — Round 2 (Advanced / Leadership)',
            'icon' => 'fa-star',
            'badge_color' => '#9333ea',
            'default_subject' => 'Congratulations! Invitation to Round 2 — {{job_title}}',
            'headline' => 'Advancing to Round 2 Interview',
            'intro' => 'Congratulations on successfully completing Round 1! We received positive feedback from the team and would like to advance you to Round 2 interview for the {{job_title}} position.',
            'next_steps' => 'This session will be an in-depth technical / leadership discussion with our senior engineering team. A calendar invite with meeting link details will follow shortly.',
            'cta_text' => 'Explore Our Engineering Stack',
            'cta_url' => 'https://nonceblox.com/'
        ],
        'offer' => [
            'key' => 'offer',
            'stage' => 'Offer',
            'label' => 'Job Offer / Selection Announcement',
            'icon' => 'fa-trophy',
            'badge_color' => '#16a34a',
            'default_subject' => 'Job Offer: {{job_title}} at {{company_name}} 🎉',
            'headline' => 'Congratulations! We would love to have you onboard',
            'intro' => 'On behalf of the entire leadership team at {{company_name}}, we are thrilled to extend an offer of employment for the position of {{job_title}}.',
            'next_steps' => 'We were truly impressed by your skills, experience, and energy throughout our discussions. Our HR team will reach out with the detailed offer letter, compensation breakdown, and joining formalities.',
            'cta_text' => 'Acknowledge Offer',
            'cta_url' => 'https://nonceblox.com/'
        ],
        'rejected' => [
            'key' => 'rejected',
            'stage' => 'Rejected',
            'label' => 'Respectful Rejection (Not Moving Forward)',
            'icon' => 'fa-circle-xmark',
            'badge_color' => '#dc2626',
            'default_subject' => 'Update regarding your application for {{job_title}}',
            'headline' => 'Thank you for speaking with our team',
            'intro' => 'Thank you for taking the time to interview and explore opportunities with {{company_name}} for the {{job_title}} role. We truly appreciate the effort you put into the evaluation process.',
            'next_steps' => 'After careful consideration across all candidate profiles, we have decided to proceed with another applicant whose experience more closely matches our immediate project needs. We will keep your resume on file for future openings that match your skills.',
            'cta_text' => 'Explore Future Roles',
            'cta_url' => 'https://nonceblox.com/career.php'
        ]
    ];
}

/**
 * Renders a high-end, responsive HTML email with organization branding.
 */
function renderLifecycleEmailHtml(string $templateKey, array $data, array $brand): string {
    $templates = getLifecycleTemplates();
    $tpl = $templates[$templateKey] ?? $templates['welcome'];

    $brandName = htmlspecialchars($brand['name'] ?: 'NonceBlox', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $baseUrl = (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'])
        ? (($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . $_SERVER['HTTP_HOST'] . '/')
        : 'http://127.0.0.1:8000/';
    $logoUrl = publicBrandLogoUrl($brand, $baseUrl);

    $candName = trim((string)($data['candidate_name'] ?? 'Candidate'));
    $safeName = htmlspecialchars($candName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $greeting = ($safeName !== '' && strcasecmp($safeName, 'Candidate') !== 0) ? "Hi {$safeName}," : "Hello,";

    $jobTitle = htmlspecialchars(trim((string)($data['job_title'] ?? 'Role')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    // Custom body text or template intro/next_steps
    $customMessage = trim((string)($data['custom_message'] ?? ''));
    if ($customMessage !== '') {
        $bodyHtml = nl2br(htmlspecialchars($customMessage, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    } else {
        $intro = str_replace(['{{job_title}}', '{{company_name}}'], [$jobTitle, $brandName], $tpl['intro']);
        $next = str_replace(['{{job_title}}', '{{company_name}}'], [$jobTitle, $brandName], $tpl['next_steps']);
        $bodyHtml = '<p style="margin:0 0 16px;">' . htmlspecialchars($intro, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>'
                  . '<p style="margin:0 0 16px;">' . htmlspecialchars($next, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>';
    }

    $headline = str_replace(['{{job_title}}', '{{company_name}}'], [$jobTitle, $brandName], $tpl['headline']);
    $safeHeadline = htmlspecialchars($headline, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $ctaText = htmlspecialchars($tpl['cta_text'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $ctaUrl = htmlspecialchars($data['custom_cta_url'] ?? $tpl['cta_url'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $badgeColor = $tpl['badge_color'];

    $logoHtml = ($logoUrl !== '')
        ? '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '" alt="' . $brandName . '" style="max-height:42px; max-width:220px; display:block; object-fit:contain;">'
        : '<span style="font-size:22px; font-weight:800; color:#1e1934; letter-spacing:-0.5px;">' . $brandName . '</span>';

    return '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>' . htmlspecialchars($data['subject'] ?? $brandName, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</title>
</head>
<body style="margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', -apple-system, BlinkMacSystemFont, Roboto, Helvetica, Arial, sans-serif; color:#334155; -webkit-font-smoothing:antialiased;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc; padding:32px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%; max-width:600px; background-color:#ffffff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; box-shadow:0 10px 25px -5px rgba(15,23,42,0.06);">
          
          <!-- Header Bar with Logo & Category Tag -->
          <tr>
            <td style="padding:28px 36px 20px; background-color:#ffffff; border-bottom:1px solid #f1f5f9;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td align="left" style="vertical-align:middle;">
                    ' . $logoHtml . '
                  </td>
                  <td align="right" style="vertical-align:middle;">
                    <span style="display:inline-block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; padding:4px 10px; border-radius:20px; background:' . $badgeColor . '15; color:' . $badgeColor . ';">
                      ' . htmlspecialchars($tpl['label'], ENT_QUOTES | ENT_HTML5, 'UTF-8') . '
                    </span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Body Content Area -->
          <tr>
            <td style="padding:36px; font-size:15px; line-height:1.75; color:#334155;">
              <h2 style="margin:0 0 18px; font-size:20px; font-weight:800; color:#0f172a; letter-spacing:-0.3px;">
                ' . $safeHeadline . '
              </h2>

              <p style="margin:0 0 20px; font-size:16px; font-weight:600; color:#1e293b;">
                ' . $greeting . '
              </p>

              <!-- Role Context Pill -->
              <div style="background:#f8fafc; border:1px solid #e2e8f0; border-left:4px solid ' . $badgeColor . '; border-radius:8px; padding:12px 18px; margin-bottom:24px; color:#1e293b; font-weight:600; font-size:14px;">
                Position: <span style="color:#7c3aed;">' . $jobTitle . '</span>
              </div>

              <!-- Main Message Body -->
              <div style="font-size:15px; color:#475569; margin-bottom:28px; line-height:1.8;">
                ' . $bodyHtml . '
              </div>

              <!-- CTA Button (Optional) -->
              <table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
                <tr>
                  <td align="center" style="background:#7c3aed; border-radius:8px; padding:12px 28px;">
                    <a href="' . $ctaUrl . '" target="_blank" rel="noopener noreferrer" style="color:#ffffff; font-weight:700; font-size:14px; text-decoration:none; display:inline-block; letter-spacing:0.2px;">
                      ' . $ctaText . ' &rarr;
                    </a>
                  </td>
                </tr>
              </table>

              <p style="margin:20px 0 0; font-size:14px; color:#64748b;">
                If you have any questions, simply reply directly to this email and our recruitment team will assist you.
              </p>
            </td>
          </tr>

          <!-- Signature & Footer -->
          <tr>
            <td style="padding:22px 36px; background-color:#fafbfe; border-top:1px solid #f1f5f9; color:#64748b; font-size:12px; line-height:1.6;">
              <strong style="color:#1e293b; font-size:13px;">' . $brandName . ' Talent Acquisition Team</strong><br>
              Official hiring portal: <a href="https://nonceblox.com/career.php" style="color:#7c3aed; text-decoration:none;">nonceblox.com/career.php</a><br>
              <span style="font-size:11px; color:#94a3b8;">This is a controlled communication sent regarding your active job application.</span>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>';
}

/**
 * Dispatches a lifecycle email using the configured delivery transport.
 */
function sendLifecycleEmail(PDO $pdo, array $params): array {
    $to = trim((string)($params['recipient_email'] ?? ''));
    if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Invalid recipient email address.'];
    }

    $candidateId = (string)($params['candidate_id'] ?? '');
    $stage = trim((string)($params['stage'] ?? 'Interview'));
    $templateKey = trim((string)($params['template_key'] ?? 'round1'));
    $subject = trim((string)($params['subject'] ?? 'Hiring Update'));
    $html = (string)($params['html'] ?? '');
    $userId = (int)($params['user_id'] ?? 1);

    require_once __DIR__ . '/branding.php';
    $brand = organizationBrand();

    // Check delivery config
    $emailApi = integrationConfig('email_api');
    if (($emailApi['status'] ?? '') !== 'configured') {
        $emailApi = ['status' => 'configured', 'url' => 'https://hrms-api.nonceblox.com/api/emails/send-multi-recipient'];
    }
    $emailApiReady = !empty($emailApi['url']);
    $smtp = integrationConfig('smtp');
    $smtpReady = ($smtp['status'] ?? '') === 'configured' && !empty($smtp['host']);

    $emailTesting = integrationConfig('email_testing');
    $testCopyEnabled = !array_key_exists('enabled', $emailTesting) || !empty($emailTesting['enabled']);
    $testCopyEmail = trim((string)($emailTesting['email'] ?? 'kishan.sharma@nonceblox.com'));

    $transportUsed = 'none';
    $deliverySuccess = false;
    $errorMessage = null;

    try {
        if ($emailApiReady) {
            $transportUsed = 'email_api';
            emailApiSendHtml($emailApi, [$to], $subject, $html);
            $deliverySuccess = true;
            if ($testCopyEnabled && filter_var($testCopyEmail, FILTER_VALIDATE_EMAIL) && strcasecmp($to, $testCopyEmail) !== 0) {
                try { emailApiSendHtml($emailApi, [$testCopyEmail], '[TEST COPY] ' . $subject, $html); } catch (Throwable $e) {}
            }
        } elseif ($smtpReady) {
            $transportUsed = 'smtp';
            smtpSendHtml($smtp, $to, $subject, $html);
            $deliverySuccess = true;
            if ($testCopyEnabled && filter_var($testCopyEmail, FILTER_VALIDATE_EMAIL) && strcasecmp($to, $testCopyEmail) !== 0) {
                try { smtpSendHtml($smtp, $testCopyEmail, '[TEST COPY] ' . $subject, $html); } catch (Throwable $e) {}
            }
        } else {
            throw new RuntimeException('Neither NonceBlox Email API nor SMTP is configured.');
        }
    } catch (Throwable $e) {
        $deliverySuccess = false;
        $errorMessage = $e->getMessage();
    }

    // Log in audit_events for timeline presentation
    try {
        $auditStmt = $pdo->prepare("
            INSERT INTO audit_events (user_id, action, entity_type, entity_id, metadata_json, created_at)
            VALUES (?, 'candidate.stage_email_sent', 'candidate', ?, ?, NOW())
        ");
        $auditStmt->execute([
            $userId,
            $candidateId,
            json_encode([
                'stage' => $stage,
                'template_key' => $templateKey,
                'subject' => $subject,
                'recipient' => $to,
                'transport' => $transportUsed,
                'outcome' => $deliverySuccess ? 'sent' : 'failed',
                'error' => $errorMessage
            ])
        ]);
    } catch (Throwable $e) {}

    return [
        'ok' => $deliverySuccess,
        'transport' => $transportUsed,
        'error' => $errorMessage
    ];
}
