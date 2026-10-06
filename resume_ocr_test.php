<?php
/*
 * Resume OCR Test - Single File
 * --------------------------------------------
 * Shared-hosting friendly (requires PHP cURL).
 * Uses OCR.space Free API. No AI.
 *
 * SECURITY:
 * Put your OCR.space API key below for testing.
 * Do not commit this file with a real key to a public repository.
 */

$OCR_API_KEY = 'K83173654988957';
$OCR_ENDPOINT = 'https://api.ocr.space/parse/image';

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cleanText($text)
{
    $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
}

function normalizePhone($phone)
{
    return trim(preg_replace('/\s+/', ' ', (string) $phone));
}

function firstMeaningfulLine($text)
{
    $lines = preg_split('/\R/u', $text);

    $blocked = [
        'resume',
        'curriculum vitae',
        'cv',
        'profile',
        'contact',
        'education',
        'experience',
        'skills',
        'technical skills',
        'projects',
        'summary',
        'objective'
    ];

    foreach ($lines as $line) {
        $line = trim(preg_replace('/\s+/', ' ', $line));

        if ($line === '' || mb_strlen($line) < 3 || mb_strlen($line) > 70) {
            continue;
        }

        $lower = mb_strtolower($line);

        if (in_array($lower, $blocked, true)) {
            continue;
        }

        if (strpos($line, '@') !== false) {
            continue;
        }

        if (preg_match('/https?:|www\.|linkedin|github/i', $line)) {
            continue;
        }

        // Avoid lines dominated by numbers/symbols.
        $letters = preg_match_all('/[A-Za-z]/', $line);
        $digits = preg_match_all('/\d/', $line);

        if ($letters >= 3 && $digits <= 2) {
            return $line;
        }
    }

    return null;
}

function findSection($text, array $headings, array $allHeadings)
{
    $lines = preg_split('/\R/u', $text);
    $start = null;
    $result = [];

    foreach ($lines as $line) {
        $trim = trim($line);
        $normalized = mb_strtolower(rtrim($trim, ':'));

        if ($start === null) {
            foreach ($headings as $heading) {
                if ($normalized === mb_strtolower($heading)) {
                    $start = true;
                    break;
                }
            }
            continue;
        }

        foreach ($allHeadings as $heading) {
            if ($normalized === mb_strtolower($heading)) {
                return trim(implode("\n", $result));
            }
        }

        if ($trim !== '') {
            $result[] = $trim;
        }
    }

    return trim(implode("\n", $result));
}

function parseResumeText($text)
{
    $text = cleanText($text);

    $data = [
        'name' => firstMeaningfulLine($text),
        'email' => null,
        'phone' => null,
        'linkedin' => null,
        'github' => null,
        'education' => null,
        'experience' => null,
        'skills' => null,
        'projects' => null,
    ];

    if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $m)) {
        $data['email'] = $m[0];
    }

    // Flexible international/Indian phone extraction.
    if (preg_match('/(?<!\d)(?:\+?\d{1,3}[\s.\-]?)?(?:\(?\d{2,5}\)?[\s.\-]?)?\d{3,5}[\s.\-]\d{3,5}(?!\d)/', $text, $m)) {
        $candidate = normalizePhone($m[0]);
        $digitCount = strlen(preg_replace('/\D/', '', $candidate));
        if ($digitCount >= 10 && $digitCount <= 13) {
            $data['phone'] = $candidate;
        }
    }

    // Fallback for common 10-digit Indian mobile numbers.
    if (!$data['phone'] && preg_match('/(?<!\d)(?:\+91[\s\-]?)?[6-9]\d{9}(?!\d)/', preg_replace('/[().]/', '', $text), $m)) {
        $data['phone'] = normalizePhone($m[0]);
    }

    if (preg_match('~(?:https?://)?(?:www\.)?linkedin\.com/[^\s<>"\']+~i', $text, $m)) {
        $data['linkedin'] = rtrim($m[0], '.,;');
    }

    if (preg_match('~(?:https?://)?(?:www\.)?github\.com/[^\s<>"\']+~i', $text, $m)) {
        $data['github'] = rtrim($m[0], '.,;');
    }

    $allHeadings = [
        'education',
        'academic qualifications',
        'academics',
        'experience',
        'work experience',
        'professional experience',
        'employment',
        'skills',
        'technical skills',
        'core skills',
        'key skills',
        'projects',
        'personal projects',
        'academic projects',
        'certifications',
        'certificates',
        'achievements',
        'awards',
        'summary',
        'profile',
        'objective',
        'professional summary',
        'languages',
        'interests',
        'hobbies',
        'declaration'
    ];

    $data['education'] = findSection(
        $text,
        ['education', 'academic qualifications', 'academics'],
        $allHeadings
    ) ?: null;

    $data['experience'] = findSection(
        $text,
        ['experience', 'work experience', 'professional experience', 'employment'],
        $allHeadings
    ) ?: null;

    $data['skills'] = findSection(
        $text,
        ['skills', 'technical skills', 'core skills', 'key skills'],
        $allHeadings
    ) ?: null;

    $data['projects'] = findSection(
        $text,
        ['projects', 'personal projects', 'academic projects'],
        $allHeadings
    ) ?: null;

    return $data;
}

function runOcr($tmpFile, $originalName, $mimeType, $apiKey, $endpoint)
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'PHP cURL extension is not enabled on this hosting.'];
    }

    $post = [
        'file' => new CURLFile($tmpFile, $mimeType ?: 'application/octet-stream', $originalName),
        'language' => 'eng',
        'isOverlayRequired' => 'false',
        'detectOrientation' => 'true',
        'scale' => 'true',
        'OCREngine' => '2',
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_HTTPHEADER => ['apikey: ' . $apiKey],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false || $curlError) {
        return ['ok' => false, 'error' => 'cURL error: ' . $curlError];
    }

    $json = json_decode($body, true);

    if (!is_array($json)) {
        return [
            'ok' => false,
            'error' => 'OCR API returned invalid JSON. HTTP ' . $httpCode,
            'raw_response' => $body
        ];
    }

    if (!empty($json['IsErroredOnProcessing'])) {
        $errors = $json['ErrorMessage'] ?? $json['ErrorDetails'] ?? 'OCR processing failed.';
        if (is_array($errors)) {
            $errors = implode(' | ', $errors);
        }

        return [
            'ok' => false,
            'error' => (string) $errors,
            'api_response' => $json
        ];
    }

    $pages = [];
    foreach (($json['ParsedResults'] ?? []) as $page) {
        if (!empty($page['ParsedText'])) {
            $pages[] = $page['ParsedText'];
        }
    }

    $text = cleanText(implode("\n\n", $pages));

    if ($text === '') {
        return [
            'ok' => false,
            'error' => 'OCR completed but no text was detected.',
            'api_response' => $json
        ];
    }

    return [
        'ok' => true,
        'text' => $text,
        'api_response' => $json
    ];
}

$result = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($OCR_API_KEY === 'YOUR_OCR_SPACE_API_KEY' || trim($OCR_API_KEY) === '') {
        $error = 'First paste your OCR.space API key in $OCR_API_KEY at the top of this PHP file.';
    } elseif (!isset($_FILES['resume'])) {
        $error = 'Please select a resume file.';
    } elseif ($_FILES['resume']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Upload failed. PHP upload error code: ' . (int) $_FILES['resume']['error'];
    } else {
        $file = $_FILES['resume'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'pdf'];

        if (!in_array($extension, $allowed, true)) {
            $error = 'Only JPG, JPEG, PNG and PDF files are allowed.';
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            // Local safety check; your OCR.space plan may have a lower upload limit.
            $error = 'File is larger than 5 MB. Try a smaller/compressed resume.';
        } else {
            $mime = function_exists('mime_content_type')
                ? mime_content_type($file['tmp_name'])
                : ($file['type'] ?? 'application/octet-stream');

            $ocr = runOcr(
                $file['tmp_name'],
                $file['name'],
                $mime,
                $OCR_API_KEY,
                $OCR_ENDPOINT
            );

            if (!$ocr['ok']) {
                $error = $ocr['error'];
                $result = $ocr;
            } else {
                $parsed = parseResumeText($ocr['text']);
                $result = [
                    'ocr_text' => $ocr['text'],
                    'parsed' => $parsed
                ];
            }
        }
    }
}

if (isset($_GET['json']) && $result && isset($result['parsed'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'data' => $result['parsed'],
        'raw_text' => $result['ocr_text']
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Resume OCR Test</title>
    <style>
        * {
            box-sizing: border-box
        }

        body {
            margin: 0;
            background: #f5f6f8;
            color: #171717;
            font-family: Arial, Helvetica, sans-serif
        }

        .wrap {
            max-width: 1100px;
            margin: 40px auto;
            padding: 0 18px
        }

        .card {
            background: #fff;
            border: 1px solid #e4e6ea;
            border-radius: 14px;
            padding: 24px;
            margin-bottom: 18px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, .04)
        }

        h1 {
            margin: 0 0 8px;
            font-size: 28px
        }

        h2 {
            margin: 0 0 16px;
            font-size: 20px
        }

        .sub {
            color: #666;
            margin: 0 0 24px
        }

        .upload {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap
        }

        input[type=file] {
            flex: 1;
            min-width: 260px;
            padding: 13px;
            border: 1px solid #d7d9de;
            border-radius: 9px;
            background: #fff
        }

        button {
            border: 0;
            background: #111;
            color: #fff;
            padding: 13px 20px;
            border-radius: 9px;
            font-weight: 700;
            cursor: pointer
        }

        .error {
            background: #fff0f0;
            border: 1px solid #ffc9c9;
            color: #a40000;
            padding: 14px;
            border-radius: 9px;
            margin-top: 18px
        }

        .ok {
            background: #effaf2;
            border: 1px solid #bfe8c8;
            padding: 14px;
            border-radius: 9px;
            margin-bottom: 18px
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px
        }

        .field {
            border: 1px solid #e5e5e5;
            border-radius: 9px;
            padding: 13px
        }

        .label {
            font-size: 12px;
            text-transform: uppercase;
            color: #777;
            margin-bottom: 6px;
            font-weight: 700
        }

        .value {
            white-space: pre-wrap;
            word-break: break-word
        }

        .full {
            grid-column: 1/-1
        }

        pre {
            white-space: pre-wrap;
            word-break: break-word;
            background: #111;
            color: #eee;
            padding: 18px;
            border-radius: 10px;
            max-height: 520px;
            overflow: auto;
            line-height: 1.45
        }

        .note {
            font-size: 13px;
            color: #777;
            margin-top: 14px
        }

        @media(max-width:700px) {
            .grid {
                grid-template-columns: 1fr
            }

            .full {
                grid-column: auto
            }
        }
    </style>
</head>

<body>
    <div class="wrap">

        <div class="card">
            <h1>Resume OCR Test</h1>
            <p class="sub">Flattened JPG/PNG/PDF → OCR.space → PHP rule-based parser. No AI.</p>

            <form method="post" enctype="multipart/form-data">
                <div class="upload">
                    <input type="file" name="resume" accept=".jpg,.jpeg,.png,.pdf" required>
                    <button type="submit">Parse Resume</button>
                </div>
            </form>

            <div class="note">
                For testing, paste your API key into <strong>$OCR_API_KEY</strong> at the top of this file.
                Keep the key server-side only.
            </div>

            <?php if ($error): ?>
                <div class="error"><?= e($error) ?></div>
            <?php endif; ?>
        </div>

        <?php if ($result && isset($result['parsed'])): ?>
            <?php $p = $result['parsed']; ?>

            <div class="card">
                <div class="ok">OCR completed successfully.</div>
                <h2>Parsed Resume Data</h2>

                <div class="grid">
                    <div class="field">
                        <div class="label">Name</div>
                        <div class="value"><?= e($p['name'] ?: 'Not detected') ?></div>
                    </div>

                    <div class="field">
                        <div class="label">Email</div>
                        <div class="value"><?= e($p['email'] ?: 'Not detected') ?></div>
                    </div>

                    <div class="field">
                        <div class="label">Phone</div>
                        <div class="value"><?= e($p['phone'] ?: 'Not detected') ?></div>
                    </div>

                    <div class="field">
                        <div class="label">LinkedIn</div>
                        <div class="value"><?= e($p['linkedin'] ?: 'Not detected') ?></div>
                    </div>

                    <div class="field">
                        <div class="label">GitHub</div>
                        <div class="value"><?= e($p['github'] ?: 'Not detected') ?></div>
                    </div>

                    <div class="field full">
                        <div class="label">Education</div>
                        <div class="value"><?= e($p['education'] ?: 'Not detected') ?></div>
                    </div>

                    <div class="field full">
                        <div class="label">Experience</div>
                        <div class="value"><?= e($p['experience'] ?: 'Not detected') ?></div>
                    </div>

                    <div class="field full">
                        <div class="label">Skills</div>
                        <div class="value"><?= e($p['skills'] ?: 'Not detected') ?></div>
                    </div>

                    <div class="field full">
                        <div class="label">Projects</div>
                        <div class="value"><?= e($p['projects'] ?: 'Not detected') ?></div>
                    </div>
                </div>
            </div>

            <div class="card">
                <h2>Raw OCR Text</h2>
                <pre><?= e($result['ocr_text']) ?></pre>
            </div>

            <div class="card">
                <h2>Parsed JSON</h2>
                <pre><?= e(json_encode($p, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
            </div>
        <?php endif; ?>

    </div>
</body>

</html>