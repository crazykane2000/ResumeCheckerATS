<?php
// Offer / hiring letters, editable document templates and company policies.
//
// - Templates (offer letter, hiring/appointment letter, policies) are stored in
//   document_templates and edited by recruiters on documents.php.
// - Each issued letter is an immutable snapshot in offer_letters (body_html), so later
//   template edits never change a letter a candidate already received.
// - Candidates get a tokenised link (offer.php?token=...) to view, print/save as PDF,
//   download a Word (.docx) copy, read policies and accept/decline. Only the SHA-256
//   hash of the token is stored.
// - Nothing here hires, rejects or emails on its own; every send/hire is a recruiter action.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/branding.php';

const OFFER_LETTER_TYPES = ['offer_letter' => 'Offer letter', 'hiring_letter' => 'Hiring / appointment letter'];

function offerPlaceholders(): array
{
    return [
        'candidate_name' => 'Candidate full name',
        'designation' => 'Role / designation offered',
        'company_name' => 'Organisation name (Settings)',
        'ctc' => 'Annual CTC as entered',
        'joining_date' => 'Date of joining',
        'location' => 'Work location',
        'reporting_to' => 'Reporting manager',
        'respond_by' => 'Last date to accept',
        'offer_date' => 'Date the letter is issued',
        'signatory_name' => 'Signing person',
        'signatory_title' => 'Signing person title',
    ];
}

/** Professional starter templates. Recruiters should review the wording with HR/legal before use. */
function defaultDocumentTemplates(): array
{
    $offer = <<<'HTML'
<p>{{offer_date}}</p>
<p><strong>{{candidate_name}}</strong></p>
<h2>Offer of Employment — {{designation}}</h2>
<p>Dear {{candidate_name}},</p>
<p>Thank you for the time and energy you invested in our selection process. We are delighted to offer you the position of <strong>{{designation}}</strong> at <strong>{{company_name}}</strong>. We were impressed by your experience and believe you will make a meaningful contribution to the team.</p>
<h3>Key terms</h3>
<table>
<tr><th>Designation</th><td>{{designation}}</td></tr>
<tr><th>Annual CTC</th><td>{{ctc}}</td></tr>
<tr><th>Date of joining</th><td>{{joining_date}}</td></tr>
<tr><th>Work location</th><td>{{location}}</td></tr>
<tr><th>Reporting to</th><td>{{reporting_to}}</td></tr>
</table>
<p>A detailed compensation breakup and your appointment letter will be shared on or before your date of joining. This offer is subject to satisfactory verification of your educational qualifications, previous employment and identity documents.</p>
<p>Please review the company policies shared with this letter. To accept, use the <strong>Accept offer</strong> button on the offer page on or before <strong>{{respond_by}}</strong>. If we do not hear from you by then, this offer may lapse.</p>
<p>We look forward to welcoming you to {{company_name}}.</p>
<p>Warm regards,</p>
<p><strong>{{signatory_name}}</strong><br>{{signatory_title}}<br>{{company_name}}</p>
HTML;

    $hiring = <<<'HTML'
<p>{{offer_date}}</p>
<p><strong>{{candidate_name}}</strong></p>
<h2>Letter of Appointment — {{designation}}</h2>
<p>Dear {{candidate_name}},</p>
<p>With reference to your acceptance of our offer, we are pleased to appoint you as <strong>{{designation}}</strong> at <strong>{{company_name}}</strong> on the following terms and conditions.</p>
<h3>1. Commencement</h3>
<p>Your appointment is effective from <strong>{{joining_date}}</strong>. You will be based at <strong>{{location}}</strong> and will report to <strong>{{reporting_to}}</strong>, or to any other person the company designates.</p>
<h3>2. Compensation</h3>
<p>Your annual cost to company (CTC) will be <strong>{{ctc}}</strong>, payable monthly and subject to applicable statutory deductions. The detailed salary structure is shared separately.</p>
<h3>3. Probation</h3>
<p>You will be on probation for six (6) months from your date of joining. On satisfactory completion, your services will be confirmed in writing.</p>
<h3>4. Working hours and leave</h3>
<p>Working hours, holidays and leave entitlement are governed by the company's Leave &amp; Attendance Policy, as amended from time to time.</p>
<h3>5. Confidentiality and intellectual property</h3>
<p>You will keep all confidential information of the company and its clients strictly confidential during and after your employment. All work product created in the course of your employment belongs to the company, as set out in the Confidentiality &amp; IP Policy.</p>
<h3>6. Notice period</h3>
<p>During probation, either party may end the employment with fifteen (15) days' written notice. After confirmation, the notice period is sixty (60) days, or salary in lieu as the company may decide.</p>
<h3>7. Policies</h3>
<p>You agree to abide by the company's Code of Conduct and all policies in force, which may be updated from time to time.</p>
<p>Please sign and return a copy of this letter as a token of your acceptance.</p>
<p>We wish you a rewarding career with {{company_name}}.</p>
<p>For {{company_name}},</p>
<p><strong>{{signatory_name}}</strong><br>{{signatory_title}}</p>
<p>&nbsp;</p>
<p>Accepted by: ______________________ &nbsp; Date: ______________</p>
HTML;

    $conduct = <<<'HTML'
<h2>Code of Conduct</h2>
<p>{{company_name}} expects every team member to act with integrity, respect and professionalism.</p>
<ul>
<li>Treat colleagues, candidates, clients and partners with respect. Harassment and discrimination of any kind are not tolerated.</li>
<li>Avoid conflicts of interest and disclose any outside work or investment that could affect your judgement.</li>
<li>Use company systems, data and assets only for legitimate business purposes.</li>
<li>Comply with all applicable laws and report concerns to your manager or HR without fear of retaliation.</li>
</ul>
HTML;

    $leave = <<<'HTML'
<h2>Leave &amp; Attendance Policy</h2>
<ul>
<li>Standard working days are Monday to Friday. Core hours and remote-work arrangements are agreed with your reporting manager.</li>
<li>Leave entitlement (earned, sick and casual leave) and the annual holiday calendar are published by HR each year.</li>
<li>Planned leave should be applied for in advance through the HR system; unplanned absence must be informed to your manager as early as possible.</li>
<li>Attendance and leave records are maintained by HR and form part of payroll processing.</li>
</ul>
HTML;

    $confidentiality = <<<'HTML'
<h2>Confidentiality &amp; Intellectual Property Policy</h2>
<ul>
<li>Confidential information includes source code, product plans, client data, financials and personal data of employees and candidates.</li>
<li>Do not share confidential information outside the company without written authorisation, during or after employment.</li>
<li>All inventions, code, designs and documents created in the course of employment are the property of {{company_name}}.</li>
<li>Return all company devices, documents and access credentials on your last working day.</li>
</ul>
HTML;

    return [
        ['doc_type' => 'offer_letter', 'name' => 'Standard offer letter', 'body_html' => $offer, 'sort_order' => 1],
        ['doc_type' => 'hiring_letter', 'name' => 'Standard appointment letter', 'body_html' => $hiring, 'sort_order' => 1],
        ['doc_type' => 'policy', 'name' => 'Code of Conduct', 'body_html' => $conduct, 'sort_order' => 1],
        ['doc_type' => 'policy', 'name' => 'Leave & Attendance Policy', 'body_html' => $leave, 'sort_order' => 2],
        ['doc_type' => 'policy', 'name' => 'Confidentiality & IP Policy', 'body_html' => $confidentiality, 'sort_order' => 3],
    ];
}

/** Insert the starter templates when none exist. Returns the number inserted. */
function installDefaultDocumentTemplates(PDO $pdo, ?int $userId): int
{
    if ((int)$pdo->query('SELECT COUNT(*) FROM document_templates')->fetchColumn() > 0) return 0;
    $ins = $pdo->prepare('INSERT INTO document_templates(doc_type,name,body_html,sort_order,is_active,updated_by) VALUES(?,?,?,?,1,?)');
    $n = 0;
    foreach (defaultDocumentTemplates() as $t) {
        $ins->execute([$t['doc_type'], $t['name'], $t['body_html'], $t['sort_order'], $userId]);
        $n++;
    }
    return $n;
}

function documentTemplates(PDO $pdo, ?string $type = null, bool $activeOnly = true): array
{
    $sql = 'SELECT * FROM document_templates WHERE 1=1' . ($type !== null ? ' AND doc_type=?' : '') . ($activeOnly ? ' AND is_active=1' : '') . ' ORDER BY doc_type, sort_order, id';
    $s = $pdo->prepare($sql);
    $s->execute($type !== null ? [$type] : []);
    return $s->fetchAll();
}

/**
 * Allow-list sanitiser for letter/policy HTML (recruiter-edited content that is shown
 * to candidates and emailed). Unknown elements are unwrapped (text kept); every
 * attribute is dropped except safe href on links and colspan/rowspan on cells.
 */
function sanitizeLetterHtml(string $html): string
{
    $html = trim($html);
    if ($html === '') return '';
    $allowed = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'hr', 'a', 'blockquote', 'span', 'div'];
    $doc = new DOMDocument('1.0', 'UTF-8');
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="__root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $root = $doc->getElementById('__root');
    if (!$root) return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');

    $walk = function (DOMNode $node) use (&$walk, $allowed, $doc): void {
        for ($child = $node->firstChild; $child !== null; $child = $next) {
            $next = $child->nextSibling;
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'img', 'svg', 'math', 'link', 'meta'], true)) {
                    $node->removeChild($child);
                    continue;
                }
                $walk($child);
                if (!in_array($tag, $allowed, true)) {
                    while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                    $node->removeChild($child);
                    continue;
                }
                $keep = [];
                if ($tag === 'a') {
                    $href = trim((string)$child->getAttribute('href'));
                    if (preg_match('#^(https?://|mailto:)#i', $href)) $keep['href'] = $href;
                }
                if (in_array($tag, ['td', 'th'], true)) {
                    foreach (['colspan', 'rowspan'] as $a) {
                        $v = (int)$child->getAttribute($a);
                        if ($v > 1 && $v < 20) $keep[$a] = (string)$v;
                    }
                }
                while ($child->attributes->length) $child->removeAttribute($child->attributes->item(0)->name);
                foreach ($keep as $k => $v) $child->setAttribute($k, $v);
                if ($tag === 'a') {
                    $child->setAttribute('rel', 'noopener');
                    $child->setAttribute('target', '_blank');
                }
            } elseif ($child instanceof DOMComment) {
                $node->removeChild($child);
            }
        }
    };
    $walk($root);
    $out = '';
    foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
    return trim($out);
}

/** Replace {{placeholders}} with HTML-escaped values. Unknown placeholders are left visible for review. */
function renderLetterTemplate(string $html, array $vars): string
{
    return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', static function ($m) use ($vars) {
        if (!array_key_exists($m[1], $vars)) return $m[0];
        $v = trim((string)$vars[$m[1]]);
        return $v === '' ? '—' : htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }, $html);
}

function offerLetterVars(array $offer, array $candidate, array $brand): array
{
    $date = static fn(?string $d): string => ($d && strtotime($d)) ? date('d F Y', strtotime($d)) : '';
    return [
        'candidate_name' => (string)($candidate['name'] ?? ''),
        'designation' => (string)($offer['designation'] ?? ''),
        'company_name' => (string)($brand['name'] ?? ''),
        'ctc' => (string)($offer['ctc_text'] ?? ''),
        'joining_date' => $date($offer['joining_date'] ?? null),
        'location' => (string)($offer['location'] ?? ''),
        'reporting_to' => (string)($offer['reporting_to'] ?? ''),
        'respond_by' => $date($offer['respond_by'] ?? null),
        'offer_date' => $date($offer['offer_date'] ?? date('Y-m-d')),
        'signatory_name' => (string)($offer['signatory_name'] ?? ''),
        'signatory_title' => (string)($offer['signatory_title'] ?? ''),
    ];
}

/** Public base URL used in candidate emails (the deployed app, never localhost). */
function offerPublicBaseUrl(): string
{
    $url = trim((string)getenv('RESUMEIQ_PUBLIC_URL'));
    if ($url === '' || !preg_match('#^https://#i', $url)) $url = 'https://resume.nonceblox.com';
    return rtrim($url, '/');
}

function offerPublicLink(string $token): string
{
    return offerPublicBaseUrl() . '/offer.php?token=' . rawurlencode($token);
}

function newOfferToken(): array
{
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    return [$token, hash('sha256', $token)];
}

function findOfferByToken(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[A-Za-z0-9_-]{40,64}$/', $token)) return null;
    try {
        $s = $pdo->prepare('SELECT o.*, c.name candidate_name, c.email candidate_email FROM offer_letters o JOIN candidates c ON c.id=o.candidate_id WHERE o.token_hash=? LIMIT 1');
        $s->execute([hash('sha256', $token)]);
        return $s->fetch() ?: null;
    } catch (Throwable $e) {
        return null; // tables not installed yet
    }
}

/** Local filesystem path of the PNG/JPG logo (for embedding in Word), or null. */
function brandLogoFile(array $brand): ?string
{
    $rel = trim((string)($brand['logo_path'] ?? ''));
    if ($rel === '' || preg_match('#^https?://#i', $rel)) return null;
    $base = realpath(__DIR__ . '/../uploads/branding');
    $path = realpath(__DIR__ . '/../' . ltrim($rel, '/'));
    if (!$base || !$path || stripos($path, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) return null;
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, ['png', 'jpg', 'jpeg'], true) ? $path : null;
}

/**
 * Logo bytes sized for letters (longest side <= 320px) so documents stay small.
 * The uploaded file is never modified. Falls back to the original when GD is unavailable.
 *
 * @return array{data:string, ext:string, width:int, height:int}|null
 */
function brandLogoForLetters(array $brand): ?array
{
    static $cache = [];
    $file = brandLogoFile($brand);
    if (!$file) return null;
    if (isset($cache[$file])) return $cache[$file];
    $size = @getimagesize($file);
    if (!$size) return null;
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'png' ? 'png' : 'jpeg';
    [$w, $h] = [(int)$size[0], (int)$size[1]];
    $data = (string)file_get_contents($file);
    $max = 320;
    if (function_exists('imagecreatetruecolor') && max($w, $h) > $max) {
        $src = $ext === 'png' ? @imagecreatefrompng($file) : @imagecreatefromjpeg($file);
        if ($src) {
            $scale = $max / max($w, $h);
            $nw = max(1, (int)round($w * $scale));
            $nh = max(1, (int)round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            ob_start();
            $ext === 'png' ? imagepng($dst, null, 9) : imagejpeg($dst, null, 88);
            $resized = (string)ob_get_clean();
            if ($resized !== '') [$data, $w, $h] = [$resized, $nw, $nh];
        }
    }
    return $cache[$file] = ['data' => $data, 'ext' => $ext, 'width' => $w, 'height' => $h];
}

/** Logo as a data URI so printed/PDF letters and the portal never depend on remote hosting. */
function brandLogoDataUri(array $brand): ?string
{
    $logo = brandLogoForLetters($brand);
    if (!$logo || strlen($logo['data']) > 512 * 1024) return null;
    return 'data:image/' . $logo['ext'] . ';base64,' . base64_encode($logo['data']);
}

/** Letter-paper CSS shared by the portal, recruiter preview and print/PDF view. */
function letterPaperCss(): string
{
    return <<<'CSS'
.letter-paper{background:#fff;color:#1f2330;max-width:780px;margin:0 auto;padding:48px 56px;border:1px solid #ececf3;border-radius:10px;font:14px/1.65 Inter,Arial,sans-serif}
.letter-head{display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #6f45ff;padding-bottom:14px;margin-bottom:26px}
.letter-head img{max-height:46px;max-width:180px;object-fit:contain}
.letter-head .org{text-align:right}.letter-head .org strong{display:block;font-size:16px;color:#171820}.letter-head .org small{color:#7b8090;font-size:12px}
.letter-body h2{font-size:18px;margin:20px 0 10px;color:#171820}.letter-body h3{font-size:14px;margin:18px 0 6px;color:#3a2a8c}
.letter-body p{margin:0 0 10px}.letter-body ul,.letter-body ol{margin:0 0 10px 20px;padding:0}
.letter-body table{border-collapse:collapse;width:100%;margin:6px 0 14px}.letter-body th,.letter-body td{border:1px solid #e3e3ee;padding:8px 10px;text-align:left;vertical-align:top}
.letter-body th{background:#f6f4ff;width:34%;font-weight:600}
.letter-foot{margin-top:30px;border-top:1px solid #ececf3;padding-top:10px;color:#7b8090;font-size:11px;text-align:center}
@media print{body{background:#fff!important}.no-print{display:none!important}.letter-paper{border:0;border-radius:0;padding:0;max-width:none}@page{margin:18mm}}
@media (max-width:640px){.letter-paper{padding:24px 18px}}
CSS;
}

/** The letter as a branded block (header with logo, rendered body, footer). */
function letterPaperHtml(string $bodyHtml, array $brand): string
{
    $name = htmlspecialchars((string)($brand['name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $domain = htmlspecialchars((string)($brand['domain'] ?? ''), ENT_QUOTES, 'UTF-8');
    $logo = brandLogoDataUri($brand);
    $logoHtml = $logo ? '<img src="' . $logo . '" alt="' . $name . ' logo">' : '<span></span>';
    return '<article class="letter-paper"><header class="letter-head">' . $logoHtml . '<div class="org"><strong>' . $name . '</strong>' . ($domain ? '<small>' . $domain . '</small>' : '') . '</div></header>'
        . '<div class="letter-body">' . $bodyHtml . '</div>'
        . '<footer class="letter-foot">' . $name . ($domain ? ' · ' . $domain : '') . '</footer></article>';
}

/** Branded email that links to the offer portal (the mail transports do not support attachments). */
function offerEmailHtml(array $offer, array $candidate, array $brand, string $link): string
{
    $e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $date = static fn(?string $d): string => ($d && strtotime($d)) ? date('d F Y', strtotime($d)) : '—';
    $logoUrl = publicBrandLogoUrl($brand, offerPublicBaseUrl());
    $isOffer = ($offer['letter_type'] ?? 'offer_letter') === 'offer_letter';
    $heading = $isOffer ? 'Your offer from ' . $e($brand['name']) : 'Your appointment letter from ' . $e($brand['name']);
    $intro = $isOffer
        ? 'We are delighted to offer you the position of <strong>' . $e($offer['designation']) . '</strong>. Your offer letter and our company policies are ready for you.'
        : 'Please find your appointment letter for the position of <strong>' . $e($offer['designation']) . '</strong>, together with our company policies.';
    $cta = $isOffer ? 'View &amp; respond to your offer' : 'View your appointment letter';
    $respond = $isOffer && !empty($offer['respond_by']) ? '<p style="margin:0 0 14px;color:#4b5060">Please respond by <strong>' . $date($offer['respond_by']) . '</strong>.</p>' : '';
    return '<!doctype html><html><body style="margin:0;background:#f6f7fb;font-family:Inter,Arial,sans-serif;color:#1f2330">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f7fb;padding:28px 12px"><tr><td align="center">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border:1px solid #ececf3;border-radius:12px">'
        . '<tr><td style="padding:22px 28px;border-bottom:2px solid #6f45ff"><img src="' . $e($logoUrl) . '" alt="' . $e($brand['name']) . '" style="max-height:40px;max-width:170px"></td></tr>'
        . '<tr><td style="padding:26px 28px 8px"><h1 style="margin:0 0 12px;font-size:20px;color:#171820">' . $heading . '</h1>'
        . '<p style="margin:0 0 12px">Dear ' . $e($candidate['name'] ?? 'Candidate') . ',</p>'
        . '<p style="margin:0 0 14px;line-height:1.6">' . $intro . '</p>'
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin:0 0 16px;border:1px solid #ececf3;border-radius:8px">'
        . '<tr><td style="padding:9px 12px;color:#7b8090;font-size:13px;width:40%">Designation</td><td style="padding:9px 12px;font-weight:600">' . $e($offer['designation']) . '</td></tr>'
        . '<tr><td style="padding:9px 12px;color:#7b8090;font-size:13px;border-top:1px solid #ececf3">Date of joining</td><td style="padding:9px 12px;font-weight:600;border-top:1px solid #ececf3">' . $date($offer['joining_date'] ?? null) . '</td></tr>'
        . '</table>' . $respond
        . '<p style="margin:0 0 22px"><a href="' . $e($link) . '" style="display:inline-block;background:#6f45ff;color:#ffffff;text-decoration:none;font-weight:600;padding:12px 20px;border-radius:8px">' . $cta . '</a></p>'
        . '<p style="margin:0 0 6px;font-size:13px;color:#4b5060">On that page you can download the letter as PDF or Word and read the company policies.</p>'
        . '<p style="margin:0 0 20px;font-size:12px;color:#7b8090">This link is personal to you. Please do not forward it.</p>'
        . '</td></tr><tr><td style="padding:14px 28px;border-top:1px solid #ececf3;font-size:12px;color:#7b8090">' . $e($brand['name']) . (!empty($brand['domain']) ? ' · ' . $e($brand['domain']) : '') . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

// ---------------------------------------------------------------------------
// Word (.docx) export built with ZipArchive (no extra dependency).
// Handles the tags the sanitiser allows: headings, paragraphs, lists, tables,
// bold/italic/underline, line breaks and horizontal rules. Logo embedded when PNG/JPG.
// ---------------------------------------------------------------------------

function docxEscape(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function docxTextRun(string $text, array $fmt = []): string
{
    $text = preg_replace('/\s+/u', ' ', $text);
    if ($text === '') return '';
    $rPr = '';
    if (!empty($fmt['b'])) $rPr .= '<w:b/>';
    if (!empty($fmt['i'])) $rPr .= '<w:i/>';
    if (!empty($fmt['u'])) $rPr .= '<w:u w:val="single"/>';
    if (!empty($fmt['size'])) $rPr .= '<w:sz w:val="' . (int)$fmt['size'] . '"/>';
    if (!empty($fmt['color'])) $rPr .= '<w:color w:val="' . $fmt['color'] . '"/>';
    return '<w:r>' . ($rPr ? '<w:rPr>' . $rPr . '</w:rPr>' : '') . '<w:t xml:space="preserve">' . docxEscape($text) . '</w:t></w:r>';
}

function docxRuns(DOMNode $node, array $fmt = []): string
{
    $xml = '';
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMText) {
            $xml .= docxTextRun($child->nodeValue, $fmt);
        } elseif ($child instanceof DOMElement) {
            $tag = strtolower($child->tagName);
            if ($tag === 'br') { $xml .= '<w:r><w:br/></w:r>'; continue; }
            $f = $fmt;
            if (in_array($tag, ['strong', 'b', 'th'], true)) $f['b'] = true;
            if (in_array($tag, ['em', 'i'], true)) $f['i'] = true;
            if ($tag === 'u') $f['u'] = true;
            $xml .= docxRuns($child, $f);
        }
    }
    return $xml;
}

function docxParagraph(string $runs, string $pPr = ''): string
{
    return '<w:p>' . ($pPr ? '<w:pPr>' . $pPr . '</w:pPr>' : '') . $runs . '</w:p>';
}

function docxBlocks(DOMNode $node): string
{
    $xml = '';
    $inline = '';
    $flush = function () use (&$inline, &$xml): void {
        if (trim(strip_tags($inline)) !== '' || str_contains($inline, '<w:br/>')) $xml .= docxParagraph($inline, '<w:spacing w:after="120"/>');
        $inline = '';
    };
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMText) {
            $inline .= docxTextRun($child->nodeValue);
            continue;
        }
        if (!$child instanceof DOMElement) continue;
        $tag = strtolower($child->tagName);
        switch ($tag) {
            case 'h2':
            case 'h3':
            case 'h4':
                $flush();
                $size = ['h2' => 30, 'h3' => 25, 'h4' => 23][$tag];
                $xml .= docxParagraph(docxRuns($child, ['b' => true, 'size' => $size, 'color' => $tag === 'h2' ? '171820' : '3A2A8C']), '<w:keepNext/><w:spacing w:before="200" w:after="100"/>');
                break;
            case 'p':
            case 'blockquote':
                $flush();
                $xml .= docxParagraph(docxRuns($child), '<w:spacing w:after="120"/>');
                break;
            case 'ul':
            case 'ol':
                $flush();
                $n = 0;
                foreach ($child->childNodes as $li) {
                    if (!$li instanceof DOMElement || strtolower($li->tagName) !== 'li') continue;
                    $n++;
                    $bullet = $tag === 'ol' ? $n . '. ' : '• ';
                    $xml .= docxParagraph('<w:r><w:t xml:space="preserve">' . $bullet . '</w:t></w:r>' . docxRuns($li), '<w:spacing w:after="60"/><w:ind w:left="360" w:hanging="240"/>');
                }
                break;
            case 'table':
                $flush();
                $rows = '';
                foreach ($child->getElementsByTagName('tr') as $tr) {
                    $cells = '';
                    foreach ($tr->childNodes as $cell) {
                        if (!$cell instanceof DOMElement || !in_array(strtolower($cell->tagName), ['td', 'th'], true)) continue;
                        $isTh = strtolower($cell->tagName) === 'th';
                        $shade = $isTh ? '<w:shd w:val="clear" w:color="auto" w:fill="F6F4FF"/>' : '';
                        $cells .= '<w:tc><w:tcPr><w:tcW w:w="0" w:type="auto"/>' . $shade . '</w:tcPr>' . docxParagraph(docxRuns($cell, $isTh ? ['b' => true] : [])) . '</w:tc>';
                    }
                    if ($cells !== '') $rows .= '<w:tr>' . $cells . '</w:tr>';
                }
                if ($rows !== '') {
                    $border = 'w:val="single" w:sz="4" w:space="0" w:color="E3E3EE"';
                    $xml .= '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/><w:tblBorders><w:top ' . $border . '/><w:left ' . $border . '/><w:bottom ' . $border . '/><w:right ' . $border . '/><w:insideH ' . $border . '/><w:insideV ' . $border . '/></w:tblBorders><w:tblCellMar><w:top w:w="80" w:type="dxa"/><w:left w:w="120" w:type="dxa"/><w:bottom w:w="80" w:type="dxa"/><w:right w:w="120" w:type="dxa"/></w:tblCellMar></w:tblPr>' . $rows . '</w:tbl>';
                    $xml .= docxParagraph('');
                }
                break;
            case 'hr':
                $flush();
                $xml .= docxParagraph('', '<w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="ECECF3"/></w:pBdr>');
                break;
            case 'div':
                $flush();
                $xml .= docxBlocks($child);
                break;
            default:
                $inline .= docxRuns($child, in_array($tag, ['strong', 'b'], true) ? ['b' => true] : (in_array($tag, ['em', 'i'], true) ? ['i' => true] : []));
        }
    }
    $flush();
    return $xml;
}

/** Build a .docx (binary string) for the letter: logo + organisation header, then the body. */
function buildLetterDocx(string $bodyHtml, array $brand, string $title): string
{
    $doc = new DOMDocument('1.0', 'UTF-8');
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="__root">' . $bodyHtml . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $root = $doc->getElementById('__root') ?? $doc;

    $logo = brandLogoForLetters($brand);
    $headerXml = '';
    $rels = '';
    $media = null;
    if ($logo) {
        $ext = $logo['ext'];
        $heightEmu = (int)(0.55 * 914400);
        $widthEmu = (int)min(2.2 * 914400, $heightEmu * ($logo['width'] / max(1, $logo['height'])));
        $media = ['name' => 'logo.' . $ext, 'data' => $logo['data'], 'ext' => $ext];
        $rels = '<Relationship Id="rIdLogo" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/' . $media['name'] . '"/>';
        $headerXml .= '<w:p><w:pPr><w:spacing w:after="80"/></w:pPr><w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="' . $widthEmu . '" cy="' . $heightEmu . '"/><wp:docPr id="1" name="Logo"/>'
            . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:nvPicPr><pic:cNvPr id="0" name="' . $media['name'] . '"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="rIdLogo"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $widthEmu . '" cy="' . $heightEmu . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
    }
    $org = trim((string)($brand['name'] ?? ''));
    $domain = trim((string)($brand['domain'] ?? ''));
    $headerXml .= docxParagraph('<w:r><w:rPr><w:b/><w:sz w:val="28"/></w:rPr><w:t xml:space="preserve">' . docxEscape($org) . '</w:t></w:r>' . ($domain ? '<w:r><w:rPr><w:color w:val="7B8090"/><w:sz w:val="20"/></w:rPr><w:t xml:space="preserve">  ·  ' . docxEscape($domain) . '</w:t></w:r>' : ''),
        '<w:pBdr><w:bottom w:val="single" w:sz="12" w:space="6" w:color="6F45FF"/></w:pBdr><w:spacing w:after="300"/>');

    $body = docxBlocks($root);
    $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
        . '<w:body>' . $headerXml . $body
        . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="567" w:footer="567" w:gutter="0"/></w:sectPr>'
        . '</w:body></w:document>';
    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/><w:sz w:val="22"/><w:color w:val="1F2330"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:line="276" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults></w:styles>';
    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
        . ($media ? '<Default Extension="' . $media['ext'] . '" ContentType="image/' . $media['ext'] . '"/>' : '')
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>';
    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>';
    $docRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' . $rels . '</Relationships>';
    $core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>' . docxEscape($title) . '</dc:title><dc:creator>' . docxEscape($org) . '</dc:creator>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>';

    $files = [
        '[Content_Types].xml' => $contentTypes,
        '_rels/.rels' => $rootRels,
        'word/document.xml' => $documentXml,
        'word/styles.xml' => $stylesXml,
        'word/_rels/document.xml.rels' => $docRels,
        'docProps/core.xml' => $core,
    ];
    if ($media) $files['word/media/' . $media['name']] = $media['data'];
    return buildZipArchive($files);
}

/**
 * Minimal ZIP writer (deflate when zlib is present, otherwise stored).
 * Used instead of ZipArchive so letters can be generated on hosts without ext-zip.
 *
 * @param array<string,string> $files path => contents
 */
function buildZipArchive(array $files): string
{
    $out = '';
    $central = '';
    $count = 0;
    [$dosTime, $dosDate] = (static function (): array {
        $t = getdate();
        return [($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2), (($t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday']];
    })();
    foreach ($files as $name => $data) {
        $crc = crc32($data);
        $size = strlen($data);
        $method = 0;
        $payload = $data;
        if (function_exists('gzdeflate')) {
            $deflated = gzdeflate($data, 6);
            if ($deflated !== false && strlen($deflated) < $size) {
                $method = 8;
                $payload = $deflated;
            }
        }
        $offset = strlen($out);
        $out .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, $method, $dosTime, $dosDate, $crc, strlen($payload), $size, strlen($name), 0) . $name . $payload;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, $method, $dosTime, $dosDate, $crc, strlen($payload), $size, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
        $count++;
    }
    return $out . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($out), 0);
}

/** Safe download filename, e.g. "Offer-Letter-Ankit-Namdeo.docx". */
function letterFileName(array $offer, string $candidateName, string $ext): string
{
    $label = ($offer['letter_type'] ?? '') === 'hiring_letter' ? 'Appointment-Letter' : 'Offer-Letter';
    $name = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $candidateName), '-') ?: 'Candidate';
    return $label . '-' . $name . '.' . $ext;
}
