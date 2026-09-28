<?php
$sessionPath = __DIR__ . '/tmp/sessions';
if (!is_dir($sessionPath)) {
    mkdir($sessionPath, 0770, true);
}
session_save_path($sessionPath);
session_start();

// Ensure vendor autoloader is included if present
$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}
require_once __DIR__ . '/lib/workspace.php';

/**
 * Clean and normalize text
 */
function cleanText(string $text): string {
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text);
}

/**
 * Check if a CLI command exists on the OS
 */
function commandExists(string $cmd): bool {
    if (stripos(PHP_OS, 'WIN') === 0) {
        $out = shell_exec("where " . escapeshellarg($cmd) . " 2>NUL");
    } else {
        $out = shell_exec("command -v " . escapeshellarg($cmd) . " 2>/dev/null");
    }
    return !empty(trim((string)$out));
}

/**
 * Extract text from PDF files
 */
function extractPdfText(string $file): array {
    $text = '';
    $requiresOcr = false;
    $error = null;

    if (class_exists('Smalot\\PdfParser\\Parser')) {
        try {
            $parser = new Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($file);
            $text = cleanText($pdf->getText());
        } catch (Throwable $e) {
            $error = 'PdfParser notice: ' . $e->getMessage();
        }
    }

    // Fallback to pdftotext CLI if installed
    if (empty($text) && function_exists('shell_exec') && commandExists('pdftotext')) {
        $tmp = tempnam(sys_get_temp_dir(), 'pdftext_');
        $cmd = 'pdftotext -layout ' . escapeshellarg($file) . ' ' . escapeshellarg($tmp) . ' 2>&1';
        shell_exec($cmd);
        if (file_exists($tmp)) {
            $cliText = file_get_contents($tmp);
            @unlink($tmp);
            if ($cliText !== false && trim($cliText) !== '') {
                $text = cleanText($cliText);
                $error = null;
            }
        }
    }

    // Check for scanned / image-only PDF
    if (empty($text) || mb_strlen($text) < 40) {
        $requiresOcr = true;
        if (!$error) {
            $error = 'Scanned / image-only PDF detected. It contains very little or no selectable text and requires OCR processing.';
        }
    }

    return [
        'text' => $text,
        'requires_ocr' => $requiresOcr,
        'error' => $error
    ];
}

/**
 * Extract text from DOCX files
 */
function extractDocxText(string $file): array {
    if (!class_exists('ZipArchive')) {
        return [
            'text' => '',
            'requires_ocr' => false,
            'error' => 'PHP Zip extension (ZipArchive) is not enabled on this server.'
        ];
    }
    $zip = new ZipArchive();
    $res = $zip->open($file);
    if ($res !== true) {
        return [
            'text' => '',
            'requires_ocr' => false,
            'error' => 'Could not open DOCX document archive.'
        ];
    }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) {
        return [
            'text' => '',
            'requires_ocr' => false,
            'error' => 'Invalid DOCX structure: word/document.xml missing.'
        ];
    }
    $xml = str_replace(['</w:p>', '</w:tr>', '<w:tab/>', '</w:tc>'], ["\n", "\n", "\t", " | "], $xml);
    $text = cleanText(strip_tags($xml));

    return [
        'text' => $text,
        'requires_ocr' => false,
        'error' => empty($text) ? 'No text content could be extracted from DOCX.' : null
    ];
}

/**
 * Extract text from legacy binary DOC files
 */
function extractDocText(string $file): array {
    if (function_exists('shell_exec')) {
        if (commandExists('antiword')) {
            $out = shell_exec('antiword ' . escapeshellarg($file) . ' 2>&1');
            if (!empty(trim((string)$out))) return ['text' => cleanText($out), 'requires_ocr' => false, 'error' => null];
        }
        if (commandExists('catdoc')) {
            $out = shell_exec('catdoc ' . escapeshellarg($file) . ' 2>&1');
            if (!empty(trim((string)$out))) return ['text' => cleanText($out), 'requires_ocr' => false, 'error' => null];
        }
        if (commandExists('libreoffice') || commandExists('soffice')) {
            $bin = commandExists('libreoffice') ? 'libreoffice' : 'soffice';
            $dir = sys_get_temp_dir() . '/resume_doc_' . uniqid();
            @mkdir($dir, 0777, true);
            shell_exec($bin . ' --headless --convert-to txt:Text --outdir ' . escapeshellarg($dir) . ' ' . escapeshellarg($file) . ' 2>&1');
            $txt = $dir . '/' . pathinfo($file, PATHINFO_FILENAME) . '.txt';
            if (file_exists($txt)) {
                $text = file_get_contents($txt);
                @unlink($txt); @rmdir($dir);
                if ($text !== false && trim($text) !== '') return ['text' => cleanText($text), 'requires_ocr' => false, 'error' => null];
            }
            @rmdir($dir);
        }
    }
    return [
        'text' => '',
        'requires_ocr' => false,
        'error' => 'Legacy binary .DOC parser not available on server (antiword/catdoc/LibreOffice required). Please save as .DOCX or .PDF.'
    ];
}

/**
 * Main Resume Text Extraction Router
 */
function extractResumeText(string $file, string $ext): array {
    return match ($ext) {
        'pdf' => extractPdfText($file),
        'docx' => extractDocxText($file),
        'doc' => extractDocText($file),
        default => ['text' => '', 'requires_ocr' => false, 'error' => 'Unsupported file format. Only PDF, DOCX, and DOC are accepted.']
    };
}

/**
 * Standard Skill Dictionary with Normalization Rules
 */
function getSkillDictionary(): array {
    return [
        'JavaScript' => ['javascript', 'java script', 'js', 'ecmascript'],
        'TypeScript' => ['typescript', 'ts'],
        'PHP' => ['php', 'php8', 'php7', 'php 8', 'php 7'],
        'Laravel' => ['laravel', 'laravel framework', 'laravel php'],
        'Symfony' => ['symfony', 'symfony framework'],
        'CodeIgniter' => ['codeigniter', 'ci framework'],
        'MySQL' => ['mysql', 'my sql', 'mariadb'],
        'PostgreSQL' => ['postgresql', 'postgres', 'pgsql'],
        'MongoDB' => ['mongodb', 'mongo'],
        'Redis' => ['redis'],
        'SQLite' => ['sqlite', 'sqlite3'],
        'React' => ['react', 'reactjs', 'react.js'],
        'Vue.js' => ['vue', 'vuejs', 'vue.js'],
        'Angular' => ['angular', 'angularjs', 'angular.js'],
        'Node.js' => ['node', 'nodejs', 'node.js'],
        'Express.js' => ['express', 'expressjs', 'express.js'],
        'HTML5' => ['html', 'html5'],
        'CSS3' => ['css', 'css3'],
        'Tailwind CSS' => ['tailwind', 'tailwindcss', 'tailwind css'],
        'Bootstrap' => ['bootstrap', 'bootstrap4', 'bootstrap5'],
        'Sass / SCSS' => ['sass', 'scss'],
        'Python' => ['python', 'python3', 'py'],
        'Django' => ['django'],
        'Flask' => ['flask'],
        'Java' => ['java'],
        'Spring Boot' => ['spring boot', 'springboot', 'spring framework'],
        'C++' => ['c++', 'cpp'],
        'C#' => ['c#', 'csharp', '.net', 'dotnet'],
        'Git' => ['git', 'github', 'gitlab', 'bitbucket'],
        'Docker' => ['docker', 'containerization'],
        'Kubernetes' => ['kubernetes', 'k8s'],
        'AWS' => ['aws', 'amazon web services', 'ec2', 's3'],
        'Azure' => ['azure', 'microsoft azure'],
        'GCP' => ['gcp', 'google cloud', 'google cloud platform'],
        'REST API' => ['rest api', 'restful api', 'restful', 'rest apis'],
        'GraphQL' => ['graphql'],
        'Linux' => ['linux', 'ubuntu', 'debian', 'centos'],
        'Nginx' => ['nginx'],
        'Apache' => ['apache'],
        'CI/CD' => ['ci/cd', 'ci cd', 'jenkins', 'github actions', 'gitlab ci'],
        'Agile / Scrum' => ['agile', 'scrum', 'kanban'],
        'Jira' => ['jira'],
        'Unit Testing' => ['unit testing', 'phpunit', 'jest', 'testing'],
        'Microservices' => ['microservices', 'micro-services'],
    ];
}

/**
 * Extract Normalized Detected Skills from Resume Text
 */
function extractDetectedSkills(string $text): array {
    $textLower = ' ' . mb_strtolower($text, 'UTF-8') . ' ';
    $dictionary = getSkillDictionary();
    $detected = [];

    foreach ($dictionary as $normalizedSkill => $synonyms) {
        foreach ($synonyms as $synonym) {
            $pattern = '/(?<=^|[\s,.\/;:()\[\]{}!?-])' . preg_quote($synonym, '/') . '(?=$|[\s,.\/;:()\[\]{}!?-])/i';
            if (preg_match($pattern, $textLower)) {
                $detected[] = $normalizedSkill;
                break;
            }
        }
    }
    return array_values(array_unique($detected));
}

/**
 * Extract Candidate Email
 */
function extractEmail(string $text): ?string {
    if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $text, $m)) {
        return $m[0];
    }
    return null;
}

/**
 * Extract Candidate Phone Number
 */
function extractPhone(string $text): ?string {
    $patterns = [
        '/(?:\+?91[\s\.-]?)?[6-9]\d{9}\b/',
        '/(?:\+?\d{1,3}[\s\.-]?)?\(?\d{3}\)?[\s\.-]?\d{3}[\s\.-]?\d{4}\b/',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            $cleaned = trim($m[0]);
            if (strlen(preg_replace('/\D/', '', $cleaned)) >= 10) {
                return $cleaned;
            }
        }
    }
    return null;
}

/**
 * Normalize tokens for JD keyword matching
 */
function normalizeTokens(string $text): array {
    $textLower = mb_strtolower($text, 'UTF-8');
    $textCleaned = preg_replace('/[^a-z0-9+#.\- ]+/u', ' ', $textLower);
    $parts = preg_split('/\s+/', $textCleaned, -1, PREG_SPLIT_NO_EMPTY);
    
    $stopWords = array_flip([
        'and','or','the','a','an','to','of','in','for','with','on','at','is','are','be','as','by','from',
        'required','preferred','experience','years','year','job','role','candidate','knowledge','good',
        'strong','skills','skill','minimum','plus','ability','must','have','working','responsibilities',
        'requirements','description','development','engineer','developer','team','work'
    ]);
    
    $tokens = [];
    foreach ($parts as $p) {
        if (mb_strlen($p) < 2) continue;
        if (isset($stopWords[$p])) continue;
        $tokens[$p] = true;
    }
    return array_keys($tokens);
}

/**
 * Compare JD with Extracted Resume Text
 */
function basicMatch(string $jd, string $resumeText): array {
    $resumeLower = mb_strtolower($resumeText, 'UTF-8');
    $jdTokens = normalizeTokens($jd);

    $matched = [];
    $missing = [];
    foreach ($jdTokens as $token) {
        if (mb_strpos($resumeLower, $token) !== false) {
            $matched[] = $token;
        } else {
            $missing[] = $token;
        }
    }
    $total = count($jdTokens);
    $score = $total > 0 ? round((count($matched) / $total) * 100) : 0;
    return [$score, $matched, $missing, $jdTokens];
}

// Format size helper
function formatBytes(int $bytes, int $precision = 2): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}

$result = null;
$error = null;
$batchResults = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $incoming = $_FILES['resume'] ?? [];
    $uploadSet = [];
    if (is_array($incoming['name'] ?? null)) {
        foreach ($incoming['name'] as $i => $name) $uploadSet[] = ['name'=>$name,'type'=>$incoming['type'][$i]??'','tmp_name'=>$incoming['tmp_name'][$i]??'','error'=>$incoming['error'][$i]??UPLOAD_ERR_NO_FILE,'size'=>$incoming['size'][$i]??0];
    } else $uploadSet[] = $incoming;
    foreach ($uploadSet as $uploadFile) {
    $_FILES['resume'] = $uploadFile;
    try {
        if (empty($_FILES['resume']['tmp_name']) || $_FILES['resume']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Please select a valid resume file to upload.');
        }
        $jd = trim($_POST['jd'] ?? '');
        if ($jd === '') {
            throw new Exception('Please paste or enter the Job Description.');
        }

        $originalName = $_FILES['resume']['name'];
        $fileSize = $_FILES['resume']['size'];
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($ext, ['pdf', 'docx', 'doc'], true)) {
            throw new Exception('Invalid file extension. Only PDF, DOCX, and DOC files are accepted.');
        }

        if ($fileSize > 8 * 1024 * 1024) {
            throw new Exception('File size exceeds 8 MB maximum limit.');
        }

        // Store file safely in uploads directory
        $uploadDir = __DIR__ . '/uploads';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $storedName = uniqid('resume_', true) . '.' . $ext;
        $targetFile = $uploadDir . '/' . $storedName;

        if (!move_uploaded_file($_FILES['resume']['tmp_name'], $targetFile)) {
            throw new Exception('Failed to save uploaded file on server.');
        }

        // Perform text extraction
        $extraction = extractResumeText($targetFile, $ext);
        $extractedText = $extraction['text'];
        $requiresOcr = $extraction['requires_ocr'];
        $extractionError = $extraction['error'];

        $processingStatus = 'processed';
        if ($requiresOcr) {
            $processingStatus = 'requires_ocr';
        } elseif ($extractionError && empty($extractedText)) {
            $processingStatus = 'failed';
        }

        // Perform basic JD matching and skill extraction if text is available
        [$score, $matched, $missing, $jdTokens] = basicMatch($jd, $extractedText);
        $detectedSkills = extractDetectedSkills($extractedText);
        $email = extractEmail($extractedText);
        $phone = extractPhone($extractedText);
        $experience = analyzeExperienceTimeline($extractedText, $detectedSkills);

        $result = [
            'original_filename' => $originalName,
            'stored_filename'   => $storedName,
            'file_type'         => strtoupper($ext),
            'file_size_bytes'   => $fileSize,
            'file_size_fmt'     => formatBytes($fileSize),
            'created_at'        => date('Y-m-d H:i:s'),
            'processing_status' => $processingStatus,
            'requires_ocr'      => $requiresOcr,
            'processing_error'  => $extractionError,
            'extracted_text'    => $extractedText,
            'text_length'       => mb_strlen($extractedText),
            'email'             => $email,
            'phone'             => $phone,
            'detected_skills'   => $detectedSkills,
            'match_score'       => $score,
            'jd_keywords'       => $jdTokens,
            'matched_keywords'  => $matched,
            'missing_keywords'  => $missing,
            'experience'        => $experience,
        ];
        saveCandidateRecord($result);
        $batchResults[] = ['file'=>$originalName,'ok'=>true,'score'=>$score];
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $batchResults[] = ['file'=>$uploadFile['name']??'Unknown file','ok'=>false,'error'=>$error];
    }
    }
}
require __DIR__ . '/views/dashboard.php';
exit;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ATS Resume Screening — Proof of Concept Scanner</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root {
  --bg: #0f172a;
  --panel: #1e293b;
  --panel-border: #334155;
  --text-main: #f8fafc;
  --text-muted: #94a3b8;
  --primary: #38bdf8;
  --primary-hover: #0284c7;
  --accent-green: #10b981;
  --accent-amber: #f59e0b;
  --accent-red: #ef4444;
  --badge-bg: #0f172a;
}

* { box-sizing: border-box; }
body {
  margin: 0;
  padding: 0;
  background-color: var(--bg);
  color: var(--text-main);
  font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
  line-height: 1.5;
}

.header-bar {
  background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
  border-bottom: 1px solid var(--panel-border);
  padding: 20px 0;
  margin-bottom: 30px;
}
.header-bar .wrap {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.logo {
  font-size: 22px;
  font-weight: 800;
  letter-spacing: -0.5px;
  color: #fff;
  display: flex;
  align-items: center;
  gap: 10px;
}
.logo span {
  color: var(--primary);
}
.tag {
  background: rgba(56, 189, 248, 0.1);
  color: var(--primary);
  border: 1px solid rgba(56, 189, 248, 0.25);
  font-size: 12px;
  font-weight: 600;
  padding: 4px 10px;
  border-radius: 20px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.wrap {
  max-width: 1200px;
  margin: 0 auto;
  padding: 0 20px;
}

.card {
  background: var(--panel);
  border: 1px solid var(--panel-border);
  border-radius: 16px;
  padding: 24px;
  margin-bottom: 24px;
  box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);
}

h2 {
  font-size: 18px;
  font-weight: 700;
  margin: 0 0 16px 0;
  color: #fff;
  display: flex;
  align-items: center;
  gap: 8px;
}

p.desc {
  color: var(--text-muted);
  font-size: 14px;
  margin-top: -8px;
  margin-bottom: 20px;
}

.grid-2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
}

label {
  display: block;
  font-weight: 600;
  font-size: 14px;
  margin-bottom: 8px;
  color: #cbd5e1;
}

.file-dropzone {
  border: 2px dashed #475569;
  border-radius: 12px;
  padding: 24px;
  text-align: center;
  background: rgba(15, 23, 42, 0.5);
  transition: all 0.2s ease;
  cursor: pointer;
  position: relative;
}
.file-dropzone:hover {
  border-color: var(--primary);
  background: rgba(56, 189, 248, 0.03);
}
.file-dropzone input[type=file] {
  position: absolute;
  top: 0; left: 0; width: 100%; height: 100%;
  opacity: 0;
  cursor: pointer;
}
.dropzone-icon {
  font-size: 32px;
  margin-bottom: 8px;
  color: var(--primary);
}
.dropzone-text {
  font-size: 14px;
  font-weight: 600;
  color: #e2e8f0;
}
.dropzone-subtext {
  font-size: 12px;
  color: var(--text-muted);
  margin-top: 4px;
}

textarea {
  width: 100%;
  height: 180px;
  background: rgba(15, 23, 42, 0.7);
  border: 1px solid var(--panel-border);
  border-radius: 12px;
  color: #fff;
  padding: 14px;
  font-family: inherit;
  font-size: 14px;
  resize: vertical;
  outline: none;
}
textarea:focus {
  border-color: var(--primary);
  box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.15);
}

.btn-row {
  display: flex;
  gap: 12px;
  margin-top: 18px;
  align-items: center;
}

button.btn-primary {
  background: var(--primary);
  color: #0f172a;
  border: none;
  border-radius: 10px;
  padding: 12px 24px;
  font-size: 15px;
  font-weight: 700;
  cursor: pointer;
  transition: background 0.2s;
}
button.btn-primary:hover {
  background: var(--primary-hover);
}

button.btn-secondary {
  background: transparent;
  color: var(--primary);
  border: 1px solid rgba(56, 189, 248, 0.3);
  border-radius: 10px;
  padding: 12px 18px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
}
button.btn-secondary:hover {
  background: rgba(56, 189, 248, 0.1);
}

.alert-error {
  background: rgba(239, 68, 68, 0.15);
  border: 1px solid rgba(239, 68, 68, 0.4);
  color: #fca5a5;
  padding: 14px 18px;
  border-radius: 12px;
  margin-bottom: 24px;
  font-weight: 500;
  font-size: 14px;
}

.alert-warning {
  background: rgba(245, 158, 11, 0.15);
  border: 1px solid rgba(245, 158, 11, 0.4);
  color: #fde68a;
  padding: 14px 18px;
  border-radius: 12px;
  margin-bottom: 18px;
  font-size: 14px;
}

/* Score display */
.score-box {
  display: flex;
  align-items: center;
  gap: 20px;
  background: rgba(15, 23, 42, 0.6);
  padding: 20px;
  border-radius: 12px;
  border: 1px solid var(--panel-border);
}
.score-circle {
  width: 90px;
  height: 90px;
  border-radius: 50%;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  font-size: 30px;
  font-weight: 800;
  border: 4px solid var(--panel-border);
  flex-shrink: 0;
}
.score-circle.good { border-color: var(--accent-green); color: var(--accent-green); }
.score-circle.mid  { border-color: var(--accent-amber); color: var(--accent-amber); }
.score-circle.bad  { border-color: var(--accent-red); color: var(--accent-red); }

.score-meta h3 {
  margin: 0 0 4px 0;
  font-size: 16px;
  color: #fff;
}
.score-meta p {
  margin: 0;
  font-size: 13px;
  color: var(--text-muted);
}

/* Metadata pills & status */
.badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 12px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 700;
  text-transform: uppercase;
}
.badge.processed { background: rgba(16, 185, 129, 0.2); color: var(--accent-green); border: 1px solid rgba(16, 185, 129, 0.4); }
.badge.requires_ocr { background: rgba(245, 158, 11, 0.2); color: var(--accent-amber); border: 1px solid rgba(245, 158, 11, 0.4); }
.badge.failed { background: rgba(239, 68, 68, 0.2); color: var(--accent-red); border: 1px solid rgba(239, 68, 68, 0.4); }

.meta-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 16px;
  margin-top: 14px;
}
.meta-item {
  background: rgba(15, 23, 42, 0.4);
  padding: 12px;
  border-radius: 10px;
  border: 1px solid rgba(255, 255, 255, 0.05);
}
.meta-item .lbl {
  font-size: 12px;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  font-weight: 600;
}
.meta-item .val {
  font-size: 14px;
  font-weight: 600;
  color: #fff;
  margin-top: 4px;
  word-break: break-all;
}

/* Skills & Keywords Badges */
.pill-container {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 10px;
}
.pill {
  font-size: 13px;
  font-weight: 600;
  padding: 6px 12px;
  border-radius: 8px;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.pill.skill { background: rgba(56, 189, 248, 0.15); color: #7dd3fc; border: 1px solid rgba(56, 189, 248, 0.3); }
.pill.matched { background: rgba(16, 185, 129, 0.15); color: #6ee7b7; border: 1px solid rgba(16, 185, 129, 0.3); }
.pill.missing { background: rgba(239, 68, 68, 0.15); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.3); }

.raw-text-box {
  background: #090d16;
  border: 1px solid var(--panel-border);
  border-radius: 12px;
  padding: 16px;
  font-family: 'JetBrains Mono', Consolas, monospace;
  font-size: 13px;
  line-height: 1.6;
  color: #cbd5e1;
  max-height: 350px;
  overflow-y: auto;
  white-space: pre-wrap;
  word-break: break-word;
}

@media (max-width: 768px) {
  .grid-2 { grid-template-columns: 1fr; }
  .score-box { flex-direction: column; text-align: center; }
}

/* Minimal light dashboard */
:root { --bg:#f7f9fc; --panel:#fff; --panel-border:#e7ebf2; --text-main:#101828; --text-muted:#667085; --primary:#5b5cf0; --primary-hover:#4748dc; --accent-green:#12b76a; --accent-amber:#f79009; --accent-red:#f04438; }
body { background:radial-gradient(circle at 12% 0%,rgba(91,92,240,.07),transparent 28rem),radial-gradient(circle at 90% 12%,rgba(14,165,233,.06),transparent 24rem),var(--bg); color:var(--text-main); min-height:100vh; }
.header-bar { position:sticky; top:0; z-index:20; padding:15px 0; margin-bottom:42px; background:rgba(255,255,255,.82); border-bottom:1px solid rgba(231,235,242,.9); backdrop-filter:blur(18px); }
.logo { color:#111827; font-size:19px; letter-spacing:-.4px; }
.logo-mark { width:34px; height:34px; display:grid; place-items:center; border-radius:10px; color:#fff!important; background:linear-gradient(145deg,#7071ff,#4b4cdd); box-shadow:0 8px 20px rgba(91,92,240,.24); }
.logo span { color:var(--primary); }
.tag { background:#f0f0ff; color:#4f46e5; border-color:#ddddff; }
.wrap { max-width:1180px; }
.hero { margin:0 0 24px; }
.eyebrow,.section-kicker { color:var(--primary); font-size:11px; font-weight:800; letter-spacing:.12em; text-transform:uppercase; }
.hero h1 { margin:8px 0 9px; font-size:clamp(30px,5vw,48px); line-height:1.08; letter-spacing:-.045em; }
.hero p { max-width:650px; margin:0; color:var(--text-muted); font-size:15px; }
.card { background:rgba(255,255,255,.94); border-color:var(--panel-border); border-radius:20px; padding:clamp(20px,3vw,30px); box-shadow:0 16px 48px rgba(16,24,40,.06); }
h2 { color:var(--text-main); font-size:17px; letter-spacing:-.015em; }
p.desc { color:var(--text-muted); }
label { color:#344054; }
.file-dropzone { min-height:180px; display:flex; flex-direction:column; justify-content:center; border:1.5px dashed #cfd4dc; background:#fafbff; }
.file-dropzone:hover { border-color:var(--primary); background:#f7f7ff; transform:translateY(-1px); }
.dropzone-icon { width:44px; height:44px; margin:0 auto 12px; display:grid; place-items:center; border-radius:13px; background:#ededff; color:var(--primary); font-size:20px; }
.dropzone-text { color:#1d2939; }
textarea { color:var(--text-main); background:#fafbff; border-color:#dfe3ea; }
textarea:focus { background:#fff; border-color:var(--primary); box-shadow:0 0 0 4px rgba(91,92,240,.1); }
button.btn-primary { min-height:46px; color:#fff; background:linear-gradient(135deg,#6567f4,#4f50da); border-radius:12px; box-shadow:0 10px 22px rgba(91,92,240,.2); }
button.btn-primary:hover { background:linear-gradient(135deg,#5658e7,#4243cb); transform:translateY(-1px); }
button.btn-secondary { color:#4f46e5; border-color:#d9d9ff; background:#f8f8ff; }
button.btn-secondary:hover { background:#efefff; }
.alert-error { background:#fff3f2; border-color:#fecdca; color:#b42318; }
.alert-warning { background:#fffaeb; border-color:#fedf89; color:#93370d; }
.score-box { min-height:210px; padding:24px; border-color:#ebeef4; background:linear-gradient(135deg,#fbfbff,#f8faff); }
.score-circle { --score:0; --ring:var(--primary); position:relative; isolation:isolate; width:132px; height:132px; border:0; color:#101828; font-size:26px; background:conic-gradient(var(--ring) calc(var(--score) * 1%),#e9ecf2 0); box-shadow:0 12px 28px rgba(16,24,40,.1); }
.score-circle::before { content:''; position:absolute; inset:12px; z-index:-1; border-radius:50%; background:#fff; }
.score-circle::after { content:'MATCH'; display:block; font-size:9px; letter-spacing:.12em; color:var(--text-muted); }
.score-circle.good { --ring:var(--accent-green); color:#087a4b; }
.score-circle.mid { --ring:var(--accent-amber); color:#b54708; }
.score-circle.bad { --ring:var(--accent-red); color:#b42318; }
.score-meta h3 { color:var(--text-main); font-size:18px; }
.score-meta p { color:var(--text-muted); }
.chart-panel { padding:22px; border:1px solid #ebeef4; border-radius:14px; background:#fff; }
.chart-title { margin:0 0 18px; font-size:13px; font-weight:700; color:#344054; }
.bar-row { display:grid; grid-template-columns:62px 1fr 30px; align-items:center; gap:10px; margin:13px 0; font-size:12px; color:var(--text-muted); }
.bar-track { height:9px; overflow:hidden; border-radius:99px; background:#edf0f5; }
.bar-fill { height:100%; border-radius:inherit; background:linear-gradient(90deg,#7778f7,#5153df); }
.bar-fill.missing { background:#dfe3ea; }
.bar-value { color:#344054; font-weight:700; text-align:right; }
.summary-side { display:grid; align-content:center; gap:18px; }
.contact-box { padding:16px; border:1px solid #ebeef4; background:#fafbfc; border-radius:13px; color:#344054; }
.badge.processed { background:#ecfdf3; color:#027a48; border-color:#abefc6; }
.badge.requires_ocr { background:#fffaeb; color:#b54708; border-color:#fedf89; }
.badge.failed { background:#fef3f2; color:#b42318; border-color:#fecdca; }
.meta-item { background:#fafbfc; border-color:#eceff3; }
.meta-item .lbl { color:var(--text-muted); }
.meta-item .val { color:#1d2939; }
.pill.skill { background:#f0f0ff; color:#4f46e5; border-color:#ddddff; }
.pill.matched { background:#ecfdf3; color:#027a48; border-color:#abefc6; }
.pill.missing { background:#fef3f2; color:#b42318; border-color:#fecdca; }
.raw-text-box { background:#f8fafc; border-color:#e4e7ec; color:#344054; }
@media (max-width:768px) { .header-bar{margin-bottom:28px}.tag{display:none}.hero h1{font-size:34px}.card{border-radius:16px}.score-box{flex-direction:row;text-align:left}.score-circle{width:110px;height:110px;font-size:23px}.btn-row{align-items:stretch;flex-direction:column}button.btn-primary,button.btn-secondary{width:100%} }
@media (max-width:480px) { .wrap{padding:0 14px}.score-box{flex-direction:column;text-align:center}.meta-grid{grid-template-columns:1fr} }

/* Product UI rule: controls and surfaces never exceed a 3px corner radius. */
.card,
.tag,
.logo-mark,
.dropzone-icon,
.file-dropzone,
textarea,
button.btn-primary,
button.btn-secondary,
.alert-error,
.alert-warning,
.score-box,
.chart-panel,
.bar-track,
.bar-fill,
.contact-box,
.badge,
.meta-item,
.pill,
.raw-text-box { border-radius:3px; }
.score-circle {
  border-radius:0;
  clip-path:circle(50% at 50% 50%);
  --ring:#2bc9a5;
}
.score-circle::before {
  border-radius:0;
  clip-path:circle(50% at 50% 50%);
}
.score-circle.good { --ring:#2bc9a5; }
.score-box { background:#f0f3fb; border-left:3px solid #7869e6; }
.chart-panel { background:#fff; }
.bar-fill { background:linear-gradient(90deg,#25c7a0,#46d6b4); }
.bar-fill.missing { background:#dfe3ea; }
.file-dropzone:hover { border-color:#2bc9a5; background:#f5fffc; }
.eyebrow,.section-kicker { color:#16a987; }
.dashboard-shell { display:grid; grid-template-columns:minmax(190px,.34fr) minmax(0,1fr); gap:10px; }
.dashboard-shell > .score-box { min-height:100%; flex-direction:column; justify-content:center; text-align:center; }
.dashboard-shell > .summary-side { padding:18px; background:#eef1fa; border:1px solid #e1e5ef; }
@media (max-width:768px) { .dashboard-shell{grid-template-columns:1fr}.dashboard-shell>.score-box{min-height:220px} }
.result-card{padding:0;overflow:hidden}.result-card>h2:first-child,.result-heading{padding:22px 24px;margin:0;border-bottom:1px solid #e8ebf0;background:#fff}.result-heading small{display:block;margin-top:4px;color:var(--text-muted);font-size:12px;font-weight:500}.result-card .dashboard-shell{padding:10px;background:#f4f6fa}.dashboard-shell{grid-template-columns:270px minmax(0,1fr)}.dashboard-shell>.score-box{padding:26px 20px;background:#fff;border:1px solid #e1e5eb;border-left:3px solid #20b995;align-items:center}.score-label{margin-top:-6px;color:#667085;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase}.score-rating{padding:4px 10px;background:#e9fbf5;border:1px solid #b7eadb;border-radius:3px;color:#087b61;font-size:11px;font-weight:800}.audit-list{width:100%;margin-top:20px;padding-top:16px;border-top:1px solid #edf0f3}.audit-title{margin-bottom:9px;color:#344054;font-size:12px;font-weight:800}.audit-item{display:flex;align-items:center;justify-content:space-between;padding:9px 10px;margin-top:5px;background:#f8fafb;border:1px solid #eef0f3;border-radius:3px;color:#475467;font-size:12px}.audit-state{min-width:22px;padding:2px 5px;border-radius:3px;background:#e9fbf5;color:#07805f;text-align:center;font-weight:800}.audit-state.issue{background:#fff1f0;color:#d92d20}.dashboard-shell>.summary-side{padding:28px;background:#eef1f9;border:1px solid #dfe4ef}.analysis-title{margin:0;color:#101828;font-size:clamp(21px,3vw,29px);line-height:1.2;letter-spacing:-.035em}.analysis-subtitle{margin:7px 0 22px;color:#667085;font-size:13px}.spectrum-card{padding:22px;background:#fff;border:1px solid #dfe4ea;border-radius:3px}.spectrum-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:34px}.spectrum-head strong{color:#344054;font-size:13px}.spectrum-head span{color:#667085;font-size:11px}.spectrum-wrap{position:relative;padding-top:17px}.spectrum-track{height:10px;background:linear-gradient(90deg,#ef5b5b 0%,#f2a93b 35%,#e7ca44 55%,#7bcf7c 75%,#20b995 100%);border-radius:3px}.spectrum-marker{position:absolute;top:-16px;transform:translateX(-50%);display:flex;flex-direction:column;align-items:center;color:#101828;font-size:11px;font-weight:800}.spectrum-marker::after{content:'';width:2px;height:17px;margin-top:2px;background:#101828}.spectrum-marker b{padding:3px 6px;color:#fff;background:#101828;border-radius:3px}.spectrum-scale{display:flex;justify-content:space-between;margin-top:7px;color:#98a2b3;font-size:10px}.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px}.detail-panel{padding:16px;background:#fff;border:1px solid #dfe4ea;border-radius:3px}.detail-panel label{margin-bottom:10px;font-size:11px;letter-spacing:.06em;text-transform:uppercase}@media(max-width:768px){.dashboard-shell{grid-template-columns:1fr}.dashboard-shell>.summary-side{padding:20px}.detail-grid{grid-template-columns:1fr}.spectrum-card{padding:18px}}
</style>
</head>
<body>

<div class="header-bar">
  <div class="wrap">
    <div class="logo"><span class="logo-mark">A</span> Resume<span>IQ</span></div>
    <div class="tag">Smart candidate analysis</div>
  </div>
</div>

<div class="wrap">

  <section class="hero">
    <div class="eyebrow">AI-ready screening workspace</div>
    <h1>Match talent with clarity.</h1>
    <p>Upload a resume, add the role requirements, and get an explainable keyword match overview in seconds.</p>
  </section>

  <?php if ($error): ?>
    <div class="alert-error">
      ⚠️ <strong>Upload Error:</strong> <?= htmlspecialchars($error) ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2>📄 Resume Document Scanner & Match Verification</h2>
    <p class="desc">Upload a candidate resume (.pdf, .docx, .doc) and paste a Job Description to verify text extraction, candidate details parsing, and basic keyword matching.</p>

    <form method="post" enctype="multipart/form-data">
      <div class="grid-2">
        <div>
          <label>Candidate Resume File</label>
          <div class="file-dropzone">
            <div class="dropzone-icon">📁</div>
            <div class="dropzone-text" id="fileLabel">Click or Drag & Drop Resume Here</div>
            <div class="dropzone-subtext">Accepted formats: <strong>PDF, DOCX, DOC</strong> (Max 8 MB)</div>
            <input type="file" name="resume" id="resumeInput" accept=".pdf,.docx,.doc" required onchange="updateFileName(this)">
          </div>
        </div>

        <div>
          <label>Job Description (JD)</label>
          <textarea name="jd" id="jdInput" required placeholder="Paste Job Description here... E.g. Senior PHP Developer with 3+ years experience in Laravel, MySQL, REST API, JavaScript, Git, AWS..."><?= htmlspecialchars($_POST['jd'] ?? '') ?></textarea>
          <div class="btn-row" style="margin-top:8px;">
            <button type="button" class="btn-secondary" onclick="loadSampleJD()">⚡ Load Sample PHP Job Description</button>
          </div>
        </div>
      </div>

      <div class="btn-row" style="margin-top: 24px;">
        <button type="submit" class="btn-primary">🔍 Scan Resume & Match JD</button>
      </div>
    </form>
  </div>

  <?php if ($result): ?>
    <!-- SCORE & OVERVIEW CARD -->
    <div class="card result-card">
      <h2>📊 Extraction & Match Summary</h2>
      
      <?php if ($result['requires_ocr']): ?>
        <div class="alert-warning">
          ⚠️ <strong>Scanned PDF / Image-Only Detected:</strong> The system detected zero or minimal selectable text in this PDF. It has been flagged as <code>requires_ocr = true</code>.
        </div>
      <?php endif; ?>

      <?php if ($result['processing_error'] && !$result['requires_ocr']): ?>
        <div class="alert-warning">
          ℹ️ <strong>Processing Note:</strong> <?= htmlspecialchars($result['processing_error']) ?>
        </div>
      <?php endif; ?>

      <div class="dashboard-shell">
        <div class="score-box">
          <?php 
            $sc = $result['match_score'];
            $scClass = $sc >= 70 ? 'good' : ($sc >= 45 ? 'mid' : 'bad');
          ?>
          <div class="score-circle <?= $scClass ?>" style="--score: <?= $sc ?>">
            <?= $sc ?>%
          </div>
          <div class="score-label">Resume score</div>
          <div class="score-rating"><?= $sc >= 70 ? 'STRONG MATCH' : ($sc >= 45 ? 'NEEDS REVIEW' : 'LOW MATCH') ?></div>
          <div class="score-meta">
            <h3>Basic Keyword Match Score</h3>
            <p><?= count($result['matched_keywords']) ?> of <?= count($result['jd_keywords']) ?> JD keywords present in candidate resume text.</p>
          </div>
          <div class="audit-list">
            <div class="audit-title">Evaluation overview</div>
            <div class="audit-item"><span>ATS parsability</span><span class="audit-state <?= $result['processing_status'] === 'processed' ? '' : 'issue' ?>"><?= $result['processing_status'] === 'processed' ? 'OK' : '!' ?></span></div>
            <div class="audit-item"><span>Detected skills</span><span class="audit-state"><?= count($result['detected_skills']) ?></span></div>
            <div class="audit-item"><span>Contact details</span><span class="audit-state <?= ($result['email'] || $result['phone']) ? '' : 'issue' ?>"><?= ($result['email'] || $result['phone']) ? 'OK' : '!' ?></span></div>
            <div class="audit-item"><span>Missing keywords</span><span class="audit-state <?= count($result['missing_keywords']) ? 'issue' : '' ?>"><?= count($result['missing_keywords']) ?></span></div>
          </div>
        </div>

        <div class="summary-side">
          <div>
            <h3 class="analysis-title">Your resume scored <?= $sc ?> out of 100</h3>
            <p class="analysis-subtitle">Keyword coverage against the job description you provided.</p>
          </div>
          <div class="spectrum-card">
            <div class="spectrum-head"><strong>Job description match spectrum</strong><span><?= count($result['matched_keywords']) ?> matched / <?= count($result['jd_keywords']) ?> total</span></div>
            <div class="spectrum-wrap">
              <div class="spectrum-marker" style="left:<?= max(2, min(98, $sc)) ?>%"><b><?= $sc ?></b></div>
              <div class="spectrum-track"></div>
              <div class="spectrum-scale"><span>0</span><span>25</span><span>50</span><span>75</span><span>100</span></div>
            </div>
          </div>
          <div class="detail-grid">
            <div class="detail-panel">
              <label>Processing status</label>
              <span class="badge <?= $result['processing_status'] ?>"><?= strtoupper($result['processing_status']) ?></span>
              <?php if ($result['requires_ocr']): ?><span class="badge requires_ocr">REQUIRES OCR</span><?php endif; ?>
            </div>
            <div class="detail-panel">
              <label>Detected contact info</label>
              <div style="font-size:12px;line-height:1.7;color:#475467">
                <strong>Email:</strong> <?= htmlspecialchars($result['email'] ?? 'Not found') ?><br>
                <strong>Phone:</strong> <?= htmlspecialchars($result['phone'] ?? 'Not found') ?>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- FILE METADATA CARD -->
    <div class="card">
      <h2>📁 Document File Metadata</h2>
      <div class="meta-grid">
        <div class="meta-item">
          <div class="lbl">Original Filename</div>
          <div class="val"><?= htmlspecialchars($result['original_filename']) ?></div>
        </div>
        <div class="meta-item">
          <div class="lbl">Stored Filename</div>
          <div class="val"><?= htmlspecialchars($result['stored_filename']) ?></div>
        </div>
        <div class="meta-item">
          <div class="lbl">Format / Extension</div>
          <div class="val"><?= $result['file_type'] ?></div>
        </div>
        <div class="meta-item">
          <div class="lbl">File Size</div>
          <div class="val"><?= $result['file_size_fmt'] ?></div>
        </div>
        <div class="meta-item">
          <div class="lbl">Extracted Characters</div>
          <div class="val"><?= number_format($result['text_length']) ?> chars</div>
        </div>
        <div class="meta-item">
          <div class="lbl">Scan Timestamp</div>
          <div class="val"><?= $result['created_at'] ?></div>
        </div>
      </div>
    </div>

    <!-- DETECTED SKILLS CARD -->
    <div class="card">
      <h2>🛠️ Detected & Normalized Skills</h2>
      <p class="desc">Rule-based dictionary parsing matches skill synonyms (e.g., <em>"Laravel PHP" &rarr; Laravel</em>, <em>"JS" &rarr; JavaScript</em>).</p>
      
      <div class="pill-container">
        <?php foreach ($result['detected_skills'] as $skill): ?>
          <span class="pill skill">✓ <?= htmlspecialchars($skill) ?></span>
        <?php endforeach; ?>
        <?php if (empty($result['detected_skills'])): ?>
          <span style="font-size:13px; color:var(--text-muted);">No standard dictionary skills detected in resume text.</span>
        <?php endif; ?>
      </div>
    </div>

    <!-- KEYWORDS BREAKDOWN CARD -->
    <div class="card">
      <div class="grid-2">
        <div>
          <h2>✅ Matched JD Keywords (<?= count($result['matched_keywords']) ?>)</h2>
          <div class="pill-container">
            <?php foreach ($result['matched_keywords'] as $kw): ?>
              <span class="pill matched">✓ <?= htmlspecialchars($kw) ?></span>
            <?php endforeach; ?>
            <?php if (empty($result['matched_keywords'])): ?>
              <span style="font-size:13px; color:var(--text-muted);">None matched.</span>
            <?php endif; ?>
          </div>
        </div>

        <div>
          <h2>❌ Missing JD Keywords (<?= count($result['missing_keywords']) ?>)</h2>
          <div class="pill-container">
            <?php foreach ($result['missing_keywords'] as $kw): ?>
              <span class="pill missing">✕ <?= htmlspecialchars($kw) ?></span>
            <?php endforeach; ?>
            <?php if (empty($result['missing_keywords'])): ?>
              <span style="font-size:13px; color:var(--text-muted);">None missing. All keywords matched!</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- RAW EXTRACTED RESUME TEXT CARD -->
    <div class="card">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
        <h2 style="margin:0;">📝 Raw Extracted Resume Text</h2>
        <button type="button" class="btn-secondary" onclick="copyRawText()">📋 Copy Text</button>
      </div>
      <p class="desc">This is the exact plain text extracted from the document stream. Verify its completeness and readability.</p>
      <div class="raw-text-box" id="rawTextContent"><?= htmlspecialchars($result['extracted_text']) ?></div>
    </div>
  <?php endif; ?>

</div>

<script>
function updateFileName(input) {
  const label = document.getElementById('fileLabel');
  if (input.files && input.files[0]) {
    label.innerHTML = 'Selected: <strong>' + input.files[0].name + '</strong>';
  } else {
    label.innerHTML = 'Click or Drag & Drop Resume Here';
  }
}

function loadSampleJD() {
  const sample = `Senior PHP Developer

Job Summary:
We are seeking an experienced Senior PHP Developer to join our core backend engineering team.

Key Requirements:
- 3+ years of commercial development experience with modern PHP (PHP 8+)
- Strong expertise in Laravel Framework and MySQL database design
- Hands-on experience building REST API endpoints and web services
- Proficient with Frontend technologies including JavaScript, HTML5, CSS3, and React
- Version control experience with Git and GitHub
- Experience with Docker, Linux server environment, and AWS cloud deployment is preferred.`;
  
  document.getElementById('jdInput').value = sample;
}

function copyRawText() {
  const text = document.getElementById('rawTextContent').innerText;
  navigator.clipboard.writeText(text).then(() => {
    alert('Extracted resume text copied to clipboard!');
  }).catch(err => {
    console.error('Failed to copy text: ', err);
  });
}
</script>
</body>
</html>
