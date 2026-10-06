<?php
require_once __DIR__ . '/lib/auth.php';
requireAuth();
require_once __DIR__ . '/lib/workspace.php';
require_once __DIR__ . '/lib/branding.php';

$pdo = db();
$user = currentUser();
$brand = organizationBrand();

// Fetch open jobs for Job Selector dropdown
$openJobs = [];
try {
    $stmt = $pdo->query("SELECT id, title, required_skills_json, description FROM jobs WHERE status IN ('open','draft') ORDER BY title ASC");
    $openJobs = $stmt->fetchAll();
} catch (Throwable $e) {}

/**
 * Clean and normalize text
 */
function cleanText(string $text): string {
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_scrub($text, 'UTF-8');
    }
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim((string)$text);
}

function commandExists(string $cmd): bool {
    if (stripos(PHP_OS, 'WIN') === 0) {
        $out = shell_exec("where " . escapeshellarg($cmd) . " 2>NUL");
    } else {
        $out = shell_exec("command -v " . escapeshellarg($cmd) . " 2>/dev/null");
    }
    return !empty(trim((string)$out));
}

function extractPdfText(string $file): array {
    $text = '';
    $requiresOcr = false;
    $error = null;

    $autoloadPath = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
    }

    if (class_exists('Smalot\\PdfParser\\Parser')) {
        try {
            $parser = new Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($file);
            $text = cleanText($pdf->getText());
        } catch (Throwable $e) {
            $error = 'PdfParser notice: ' . $e->getMessage();
        }
    }

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

    if (empty($text) || mb_strlen($text) < 40) {
        $requiresOcr = true;
    }

    return [
        'text' => $text,
        'requires_ocr' => $requiresOcr,
        'error' => $error
    ];
}

function extractDocxText(string $file): array {
    if (!class_exists('ZipArchive')) {
        return ['text' => '', 'requires_ocr' => false, 'error' => 'ZipArchive PHP extension is missing. Cannot parse DOCX files.'];
    }
    $zip = new ZipArchive();
    if ($zip->open($file) === true) {
        $content = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($content !== false) {
            $text = strip_tags($content);
            return ['text' => cleanText($text), 'requires_ocr' => false, 'error' => null];
        }
    }
    return ['text' => '', 'requires_ocr' => false, 'error' => 'Failed to read word/document.xml inside DOCX file.'];
}

function extractDocText(string $file): array {
    return ['text' => '', 'requires_ocr' => false, 'error' => 'Legacy binary .DOC parser not available. Please save as .DOCX or .PDF.'];
}

function extractResumeText(string $file, string $ext): array {
    return match ($ext) {
        'pdf' => extractPdfText($file),
        'docx' => extractDocxText($file),
        'doc' => extractDocText($file),
        default => ['text' => '', 'requires_ocr' => false, 'error' => 'Unsupported file format. Only PDF, DOCX, and DOC are accepted.']
    };
}

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
        'Blockchain' => ['blockchain', 'distributed ledger'],
        'Web3' => ['web3', 'web 3'],
        'Solidity' => ['solidity'],
        'Ethereum' => ['ethereum', 'evm'],
        'Smart Contracts' => ['smart contract', 'smart contracts'],
    ];
}

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

function extractEmail(string $text): ?string {
    if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $text, $m)) {
        return $m[0];
    }
    return null;
}

function extractPhone(string $text): ?string {
    $patterns = [
        '/(?<!\d)(?:\+?91[\s\.-]?)?[6-9]\d{9}(?!\d)/',
        '/(?<!\d)(?:\+?\d{1,3}[\s\.-]?)?\(?\d{3}\)?[\s\.-]?\d{3}[\s\.-]?\d{4}(?!\d)/',
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

function basicMatch(string $jd, string $resumeText): array {
    if (trim($jd) === '' || trim($resumeText) === '') {
        return [0, [], [], []];
    }
    $jdTokens = normalizeTokens($jd);
    if (empty($jdTokens)) {
        return [0, [], [], []];
    }
    $resumeLower = mb_strtolower($resumeText, 'UTF-8');
    $matched = [];
    $missing = [];

    foreach ($jdTokens as $token) {
        $pattern = '/(?<=^|[\s,.\/;:()\[\]{}!?-])' . preg_quote($token, '/') . '(?=$|[\s,.\/;:()\[\]{}!?-])/i';
        if (preg_match($pattern, $resumeLower)) {
            $matched[] = $token;
        } else {
            $missing[] = $token;
        }
    }
    $score = (int)round((count($matched) / count($jdTokens)) * 100);
    return [$score, $matched, $missing, $jdTokens];
}

function formatBytes(int $bytes): string {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }
    return number_format($bytes / 1024, 1) . ' KB';
}

$result = null;
$error = null;
$processedCount = 0;
$batchSummary = [];

// HANDLE MULTI-FILE RESUME INGESTION POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $selectedJobId = (int)($_POST['job_id'] ?? 0);
        $jd = trim($_POST['jd'] ?? '');

        // Gather uploaded files array
        $uploadFiles = [];
        if (!empty($_FILES['resumes']['name'][0])) {
            foreach ($_FILES['resumes']['name'] as $idx => $name) {
                if ($_FILES['resumes']['error'][$idx] === UPLOAD_ERR_OK) {
                    $uploadFiles[] = [
                        'name'     => $name,
                        'tmp_name' => $_FILES['resumes']['tmp_name'][$idx],
                        'size'     => $_FILES['resumes']['size'][$idx],
                    ];
                }
            }
        } elseif (!empty($_FILES['resume']['name'])) {
            $uploadFiles[] = [
                'name'     => $_FILES['resume']['name'],
                'tmp_name' => $_FILES['resume']['tmp_name'],
                'size'     => $_FILES['resume']['size'],
            ];
        }

        if (empty($uploadFiles)) {
            throw new Exception('Please select or drag & drop at least one candidate resume file (.pdf, .docx, .doc).');
        }

        $uploadDir = __DIR__ . '/uploads';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        foreach ($uploadFiles as $fileInfo) {
            $originalName = $fileInfo['name'];
            $fileSize = $fileInfo['size'];
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

            if (!in_array($ext, ['pdf', 'docx', 'doc'], true)) {
                $batchSummary[] = ['file' => $originalName, 'status' => 'error', 'msg' => 'Unsupported extension'];
                continue;
            }

            if ($fileSize > 8 * 1024 * 1024) {
                $batchSummary[] = ['file' => $originalName, 'status' => 'error', 'msg' => 'Exceeds 8 MB limit'];
                continue;
            }

            $storedName = uniqid('resume_', true) . '.' . $ext;
            $targetFile = $uploadDir . '/' . $storedName;

            if (!move_uploaded_file($fileInfo['tmp_name'], $targetFile)) {
                $batchSummary[] = ['file' => $originalName, 'status' => 'error', 'msg' => 'Failed to save on server'];
                continue;
            }

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

            [$score, $matched, $missing, $jdTokens] = basicMatch($jd, $extractedText);
            $detectedSkills = extractDetectedSkills($extractedText);
            $email = extractEmail($extractedText);
            $phone = extractPhone($extractedText);
            $experience = analyzeExperienceTimeline($extractedText, $detectedSkills);

            $resObj = [
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

            saveCandidateRecord($resObj, $selectedJobId > 0 ? $selectedJobId : null);
            $result = $resObj;
            $processedCount++;
            $batchSummary[] = [
                'file'   => $originalName,
                'status' => 'success',
                'name'   => candidatePresentation($resObj)['name'],
                'score'  => $score,
                'email'  => $email
            ];
        }

        if ($processedCount === 0 && empty($batchSummary)) {
            throw new Exception('No valid resumes were processed.');
        }

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$activePage = 'scan';
$pageTitle = 'Resume Parser & Ingestion Hub · NonceBlox ATS';
$pageStyles = '<style>
.ingestion-hero { padding: 22px 24px; background: linear-gradient(135deg, #f8f7ff 0%, #f1ecff 100%); border-radius: 16px; border: 1px solid #e0e7ff; margin-bottom: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.02); }
.ingestion-hero h1 { margin: 4px 0 0; font-size: 26px; font-weight: 900; color: #111827; }

.ingestion-card { background: #ffffff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 24px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); margin-bottom: 24px; }
.ingestion-card h2 { margin: 0 0 6px; font-size: 18px; font-weight: 800; color: #111827; }

.dropzone-box { border: 2px dashed #6366f1; background: #faf9ff; border-radius: 14px; padding: 36px 20px; text-align: center; cursor: pointer; transition: all 0.2s ease; position: relative; margin-top: 14px; }
.dropzone-box:hover { background: #f3f0ff; border-color: #4338ca; transform: translateY(-1px); }
.dropzone-icon { font-size: 40px; color: #6366f1; margin-bottom: 12px; }
.dropzone-box input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; }

.job-select-toolbar { background: #fafafa; border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px; margin-bottom: 16px; }

.batch-results-card { background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 12px; padding: 16px 20px; margin-bottom: 20px; color: #065f46; font-size: 13px; }
.batch-results-list { margin-top: 10px; display: flex; flex-direction: column; gap: 6px; }
.batch-item { display: flex; justify-content: space-between; background: #fff; padding: 8px 12px; border-radius: 8px; border: 1px solid #d1fae5; }

.notice { padding: 14px 18px; border-radius: 10px; margin-bottom: 16px; font-weight: 600; font-size: 13px; }
.ok { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
.err { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }

.result-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 24px; margin-top: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); }
.pill-container { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
.pill { font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 12px; }
.pill.skill { background: #e0e7ff; color: #4338ca; border: 1px solid #c7d2fe; }
.pill.matched { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
.pill.missing { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
.raw-text-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; font-family: monospace; font-size: 12px; color: #334155; max-height: 300px; overflow-y: auto; white-space: pre-wrap; }
</style>';

require __DIR__ . '/views/partials/header.php';
?>

<section class="ingestion-hero">
  <div class="kicker" style="color:#6366f1;font-weight:800;font-size:12px;letter-spacing:0.05em;text-transform:uppercase">
    <i class="fa-solid fa-cloud-arrow-up"></i> Bulk Resume Ingestion Hub
  </div>
  <h1>Resume Parser & Ingestion</h1>
  <p style="margin:4px 0 0;font-size:13px;color:#6b7280">Upload single or multiple candidate resumes (.pdf, .docx, .doc). Select an active job profile or ingest into the open candidate pool.</p>
</section>

<?php if ($error): ?>
  <div class="notice err"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($processedCount > 0): ?>
  <div class="batch-results-card">
    <strong><i class="fa-solid fa-circle-check"></i> Successfully Ingested <?= $processedCount ?> Candidate Resume(s) [Source: <code>direct_upload</code>]</strong>
    <div class="batch-results-list">
      <?php foreach ($batchSummary as $b): ?>
        <div class="batch-item">
          <span><strong><?= htmlspecialchars($b['name'] ?? $b['file']) ?></strong> (<?= htmlspecialchars($b['email'] ?? 'No email') ?>)</span>
          <span class="badge" style="background:#eeefee;color:#4338ca;font-weight:800"><?= isset($b['score']) && $b['score'] > 0 ? $b['score'].'%' : 'Indexed' ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<div class="ingestion-card">
  <h2>📄 Upload Candidate Resumes</h2>
  <p style="font-size:13px;color:#6b7280;margin:0 0 16px">Select an active job profile to match requirements, or pick "Open Pool" for general ingestion without typing a JD.</p>

  <form method="post" enctype="multipart/form-data">
    <div class="job-select-toolbar">
      <label style="font-size:12px;font-weight:800;color:#374151;text-transform:uppercase;display:block;margin-bottom:6px">Target Job Profile:</label>
      <select class="control" name="job_id" id="jobSelect" onchange="onJobSelect(this)" style="font-size:13px;border-radius:10px;padding:10px 14px;width:100%;border:1px solid #d1d5db;background:#fff">
        <option value="0" data-skills="" data-desc="">✨ General Ingestion / Open Pool (All Roles)</option>
        <?php foreach ($openJobs as $job): 
          $jdSkills = json_decode($job['required_skills_json'] ?? '[]', true) ?: [];
          $jdText = trim((string)$job['description']);
          if (!empty($jdSkills)) {
              $jdText = "Required Skills:\n- " . implode("\n- ", $jdSkills) . "\n\n" . $jdText;
          }
        ?>
        <option value="<?= (int)$job['id'] ?>" data-jd="<?= htmlspecialchars($jdText) ?>">
          🎯 <?= htmlspecialchars($job['title']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- DRAG AND DROP MULTI-FILE ZONE -->
    <div class="dropzone-box">
      <div class="dropzone-icon"><i class="fa-solid fa-file-circle-plus"></i></div>
      <strong style="font-size:15px;color:#111827;display:block" id="fileLabel">Click or Drag & Drop Resumes Here</strong>
      <span style="font-size:12px;color:#6b7280;display:block;margin-top:4px">Select single or multiple files: <strong>PDF, DOCX, DOC</strong> (Max 8 MB per file)</span>
      <input type="file" name="resumes[]" id="resumeInput" accept=".pdf,.docx,.doc" multiple required onchange="updateFileNames(this)">
    </div>

    <!-- OPTIONAL JD OVERRIDE / READONLY VIEW -->
    <div style="margin-top:16px">
      <label style="font-size:11px;font-weight:800;color:#6b7280;text-transform:uppercase;display:block;margin-bottom:4px">Selected Job Description / Keywords (Auto-filled):</label>
      <textarea class="control" name="jd" id="jdInput" placeholder="Auto-filled when a Job Profile is selected above, or paste custom job requirements…" style="width:100%;min-height:90px;font-size:12px;border-radius:10px;padding:10px"><?= htmlspecialchars($_POST['jd'] ?? '') ?></textarea>
    </div>

    <div style="margin-top:20px;display:flex;justify-content:flex-end">
      <button class="btn btn-primary" type="submit" style="height:44px;border-radius:10px;padding:0 24px;font-weight:700;font-size:13px">
        <i class="fa-solid fa-cloud-arrow-up"></i> Ingest & Parse Resumes
      </button>
    </div>
  </form>
</div>

<?php if ($result): ?>
  <!-- EXTRACTION SUMMARY CARD FOR LAST PARSED RESUME -->
  <div class="result-card">
    <h3 style="margin:0 0 14px;font-size:18px;font-weight:800;color:#111827"><i class="fa-solid fa-magnifying-glass-chart" style="color:#6366f1"></i> Parse & Match Result</h3>
    
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px,1fr));gap:14px;margin-bottom:18px">
      <div style="background:#fafafa;padding:12px 16px;border-radius:10px;border:1px solid #e5e7eb">
        <span style="font-size:11px;color:#6b7280;display:block">Candidate Name</span>
        <strong style="font-size:14px;color:#111827"><?= htmlspecialchars(candidatePresentation($result)['name']) ?></strong>
      </div>
      <div style="background:#fafafa;padding:12px 16px;border-radius:10px;border:1px solid #e5e7eb">
        <span style="font-size:11px;color:#6b7280;display:block">Contact Details</span>
        <strong style="font-size:13px;color:#111827"><?= htmlspecialchars($result['email'] ?? 'No email') ?></strong>
      </div>
      <div style="background:#fafafa;padding:12px 16px;border-radius:10px;border:1px solid #e5e7eb">
        <span style="font-size:11px;color:#6b7280;display:block">Provenance / Source</span>
        <span class="badge" style="background:#e0e7ff;color:#4338ca;font-weight:800">direct_upload</span>
      </div>
      <div style="background:#fafafa;padding:12px 16px;border-radius:10px;border:1px solid #e5e7eb">
        <span style="font-size:11px;color:#6b7280;display:block">Match Score</span>
        <strong style="font-size:16px;color:#16a34a"><?= $result['match_score'] > 0 ? $result['match_score'].'%' : 'Indexed' ?></strong>
      </div>
    </div>

    <!-- DETECTED SKILLS -->
    <div style="margin-bottom:16px">
      <strong style="font-size:12px;color:#374151">Detected Skills:</strong>
      <div class="pill-container">
        <?php foreach ($result['detected_skills'] as $sk): ?>
          <span class="pill skill">✓ <?= htmlspecialchars($sk) ?></span>
        <?php endforeach; ?>
        <?php if (empty($result['detected_skills'])): ?>
          <span style="font-size:12px;color:#9ca3af">No standard dictionary skills detected.</span>
        <?php endif; ?>
      </div>
    </div>

    <!-- RAW TEXT BOX -->
    <div>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <strong style="font-size:12px;color:#374151">Raw Extracted Resume Text:</strong>
        <button type="button" class="btn" onclick="copyRawText()" style="font-size:11px;padding:4px 10px;border-radius:6px"><i class="fa-regular fa-copy"></i> Copy</button>
      </div>
      <div class="raw-text-box" id="rawTextContent"><?= htmlspecialchars($result['extracted_text']) ?></div>
    </div>
  </div>
<?php endif; ?>

<script>
function onJobSelect(selectEl) {
  const selectedOpt = selectEl.options[selectEl.selectedIndex];
  const jdText = selectedOpt.getAttribute('data-jd') || '';
  document.getElementById('jdInput').value = jdText;
}

function updateFileNames(input) {
  const label = document.getElementById('fileLabel');
  if (input.files && input.files.length > 0) {
    if (input.files.length === 1) {
      label.innerHTML = 'Selected: <strong>' + input.files[0].name + '</strong>';
    } else {
      label.innerHTML = 'Selected: <strong>' + input.files.length + ' Resume Files</strong>';
    }
  } else {
    label.innerText = 'Click or Drag & Drop Resumes Here';
  }
}

function copyRawText() {
  const text = document.getElementById('rawTextContent').innerText;
  navigator.clipboard.writeText(text).then(() => {
    alert('Extracted text copied to clipboard!');
  });
}
</script>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
