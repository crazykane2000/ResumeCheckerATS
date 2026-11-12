<?php
require_once __DIR__ . '/lib/auth.php';
requireAuth();
require_once __DIR__ . '/lib/workspace.php';
require_once __DIR__ . '/lib/branding.php';
require_once __DIR__ . '/lib/resume_storage.php';
require_once __DIR__ . '/lib/ctc.php';

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
    $text = preg_replace('/\r\n|\r/', "\n", $text);
    $text = preg_replace('/[ \t]+/u', ' ', $text);
    $text = preg_replace('/\n{3,}/u', "\n\n", $text);
    return trim((string)$text);
}

function extractCandidateNameInfo(string $text): array {
    $lines = preg_split('/\R/', trim($text));
    $name = '';
    $role = 'Candidate';

    foreach ($lines as $idx => $line) {
        $line = trim($line);
        if (mb_strlen($line) < 2) continue;

        if (preg_match('/@|http|www\.|\+?\d{8,}|summary|experience|education|skills|projects|curriculum|resume|profile/i', $line)) {
            continue;
        }

        $cleanLine = trim(preg_replace('/[^\p{L}\s\.-]/u', ' ', $line));
        $cleanLine = trim(preg_replace('/\s+/', ' ', $cleanLine));
        if (mb_strlen($cleanLine) < 3 || !preg_match('/[\p{L}]{2,}/u', $cleanLine)) {
            continue;
        }

        $words = array_values(array_filter(explode(' ', $cleanLine)));

        if (count($words) >= 1 && count($words) <= 5 && mb_strlen($cleanLine) <= 50) {
            $isNameCandidate = true;
            foreach ($words as $w) {
                if (mb_strlen($w) < 2 || preg_match('/\d/', $w)) {
                    $isNameCandidate = false;
                    break;
                }
            }
            if ($isNameCandidate) {
                $name = ucwords(mb_strtolower($cleanLine));
                if (isset($lines[$idx + 1])) {
                    $next = trim($lines[$idx + 1]);
                    if (!preg_match('/@|http|\+?\d{8,}/', $next) && mb_strlen($next) <= 60 && mb_strlen($next) >= 3) {
                        $cleanRole = trim(preg_replace('/[^\p{L}\s\.-]/u', ' ', $next));
                        if (mb_strlen($cleanRole) >= 3) {
                            $role = ucwords(mb_strtolower($cleanRole));
                        }
                    }
                }
                break;
            }
        }
    }

    return ['name' => $name ?: 'Unknown candidate', 'role' => $role];
}

function extractCountryInfo(string $text): array {
    $countryMap = [
        'india' => ['India', 'IN'],
        'pune' => ['India', 'IN'],
        'mumbai' => ['India', 'IN'],
        'delhi' => ['India', 'IN'],
        'bengaluru' => ['India', 'IN'],
        'bangalore' => ['India', 'IN'],
        'hyderabad' => ['India', 'IN'],
        'noida' => ['India', 'IN'],
        'gurgaon' => ['India', 'IN'],
        'gurugram' => ['India', 'IN'],
        'chennai' => ['India', 'IN'],
        'kolkata' => ['India', 'IN'],
        'maharashtra' => ['India', 'IN'],

        'united states' => ['United States', 'US'],
        'usa' => ['United States', 'US'],
        'tx' => ['United States', 'US'],
        'ca' => ['United States', 'US'],
        'ny' => ['United States', 'US'],
        'fl' => ['United States', 'US'],
        'wa' => ['United States', 'US'],
        'il' => ['United States', 'US'],
        'ma' => ['United States', 'US'],
        'pa' => ['United States', 'US'],
        'ga' => ['United States', 'US'],
        'nc' => ['United States', 'US'],
        'nj' => ['United States', 'US'],
        'va' => ['United States', 'US'],
        'los angeles' => ['United States', 'US'],
        'new york' => ['United States', 'US'],
        'chicago' => ['United States', 'US'],
        'houston' => ['United States', 'US'],
        'san francisco' => ['United States', 'US'],
        'seattle' => ['United States', 'US'],
        'austin' => ['United States', 'US'],
        'dallas' => ['United States', 'US'],

        'canada' => ['Canada', 'CA'],
        'toronto' => ['Canada', 'CA'],
        'vancouver' => ['Canada', 'CA'],
        'montreal' => ['Canada', 'CA'],
        'ontario' => ['Canada', 'CA'],
        'on' => ['Canada', 'CA'],
        'bc' => ['Canada', 'CA'],

        'united kingdom' => ['United Kingdom', 'GB'],
        'uk' => ['United Kingdom', 'GB'],
        'london' => ['United Kingdom', 'GB'],

        'united arab emirates' => ['United Arab Emirates', 'AE'],
        'uae' => ['United Arab Emirates', 'AE'],
        'dubai' => ['United Arab Emirates', 'AE'],

        'kenya' => ['Kenya', 'KE'],
        'australia' => ['Australia', 'AU'],
        'germany' => ['Germany', 'DE'],
        'france' => ['France', 'FR'],
        'philippines' => ['Philippines', 'PH'],
        'singapore' => ['Singapore', 'SG'],
        'nigeria' => ['Nigeria', 'NG'],
        'ireland' => ['Ireland', 'IE'],
        'spain' => ['Spain', 'ES'],
        'egypt' => ['Egypt', 'EG'],
        'indonesia' => ['Indonesia', 'ID'],
        'honduras' => ['Honduras', 'HN'],
        'finland' => ['Finland', 'FI'],
    ];

    $textLower = mb_strtolower($text, 'UTF-8');
    foreach ($countryMap as $key => [$cName, $cCode]) {
        if (preg_match('/(?<=^|[\s,.\/;:()\[\]{}!?-])' . preg_quote($key, '/') . '(?=$|[\s,.\/;:()\[\]{}!?-])/i', $textLower)) {
            return ['country_name' => $cName, 'country_code' => $cCode];
        }
    }
    return ['country_name' => '', 'country_code' => ''];
}

function extractSalaryInfo(string $text): array {
    $currentCtc = null;
    $expectedCtc = null;

    // Expected CTC regex
    // A salary keyword is required: "Exp 3 years" or "expected graduation 2027" must not become a CTC.
    if (preg_match('/(?:\b(?:expected|exp\.?|target)\s*(?:ctc|salary|package|remuneration)|\be\.?\s*ctc)\b\s*[:\-]?\s*(?:inr|rs\.?|₹)?\s*(\d+(?:\.\d+)?)\s*(lpa|lacs|lakhs|k|k\b|p\.a\.|\/year|\/yr)?/i', $text, $m)) {
        $val = (float)$m[1];
        $unit = strtolower($m[2] ?? '');
        if (in_array($unit, ['lpa', 'lacs', 'lakhs', 'p.a.'], true)) {
            $expectedCtc = $val;
        } elseif ($unit === 'k') {
            $expectedCtc = ($val >= 100) ? round($val / 100, 2) : round(($val * 1000) / 100000, 2);
        } elseif ($val > 10000) {
            $expectedCtc = round($val / 100000, 2);
        } else {
            $expectedCtc = $val;
        }
    }

    // Current CTC regex
    // Previously "c" with no word boundary and an optional keyword matched any "c" before a number
    // (e.g. "Sec 2024"), storing years as CTC. Now a whole-word keyword is required.
    if (preg_match('/(?:\b(?:current|present)\s*(?:ctc|salary|package|remuneration)|\bc\.?\s*ctc)\b\s*[:\-]?\s*(?:inr|rs\.?|₹)?\s*(\d+(?:\.\d+)?)\s*(lpa|lacs|lakhs|k|k\b|p\.a\.|\/year|\/yr)?/i', $text, $m)) {
        $val = (float)$m[1];
        $unit = strtolower($m[2] ?? '');
        if (in_array($unit, ['lpa', 'lacs', 'lakhs', 'p.a.'], true)) {
            $currentCtc = $val;
        } elseif ($unit === 'k') {
            $currentCtc = ($val >= 100) ? round($val / 100, 2) : round(($val * 1000) / 100000, 2);
        } elseif ($val > 10000) {
            $currentCtc = round($val / 100000, 2);
        } else {
            $currentCtc = $val;
        }
    }

    if ($currentCtc === null && $expectedCtc === null) {
        if (preg_match('/\b(?:ctc|salary|package)\s*[:\-]?\s*(?:inr|rs\.?|₹|\$|€|£)?\s*(\d+(?:\.\d+)?)\s*(lpa|lacs|lakhs)\b/i', $text, $m)) {
            $currentCtc = (float)$m[1];
        }
    }

    // Drop physically implausible values (years, phone fragments) instead of storing them as CTC.
    return [
        'current_ctc'  => ctcIsPlausible($currentCtc) ? $currentCtc : null,
        'expected_ctc' => ctcIsPlausible($expectedCtc) ? $expectedCtc : null,
    ];
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

        if (function_exists('curl_init') && file_exists($file)) {
            $apiKey = getenv('OCR_SPACE_API_KEY') ?: 'K83173654988957';
            $endpoint = 'https://api.ocr.space/parse/image';
            try {
                $post = [
                    'file' => new CURLFile($file, 'application/pdf', basename($file)),
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
                    CURLOPT_CONNECTTIMEOUT => 15,
                    CURLOPT_TIMEOUT => 60,
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);
                $body = curl_exec($ch);
                curl_close($ch);
                if ($body !== false) {
                    $json = json_decode($body, true);
                    if (is_array($json) && empty($json['IsErroredOnProcessing'])) {
                        $pages = [];
                        foreach (($json['ParsedResults'] ?? []) as $page) {
                            if (!empty($page['ParsedText'])) {
                                $pages[] = $page['ParsedText'];
                            }
                        }
                        $ocrText = cleanText(implode("\n\n", $pages));
                        if (mb_strlen($ocrText) >= 30) {
                            $text = $ocrText;
                            $requiresOcr = false;
                            $error = null;
                        }
                    }
                }
            } catch (Throwable $ocrError) {
                // Soft fallback: keep requiresOcr = true if API is unreachable
            }
        }

        if ($requiresOcr && !$error) {
            $error = 'Scanned or image-only PDF detected. Contains very little/no selectable text and requires OCR.';
        }
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

if (defined('RESUMEIQ_FUNCTIONS_ONLY') && RESUMEIQ_FUNCTIONS_ONLY) {
    return;
}

$result = null;
$error = null;
$processedCount = 0;
$batchSummary = [];
$selectedCount = 0;
$receivedCount = 0;
$failedCount = 0;
$managementMessage = '';
$managementError = '';
$managementResults = [];
$serverMaxFiles = max(1, (int)ini_get('max_file_uploads'));
$ownerId = (int)$pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
$isWorkspaceOwner = (int)($user['id'] ?? 0) === $ownerId;

function uploadErrorMessage(int $code): string {
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds the server upload size limit',
        UPLOAD_ERR_PARTIAL => 'File upload was incomplete',
        UPLOAD_ERR_NO_FILE => 'No file was received',
        UPLOAD_ERR_NO_TMP_DIR => 'Server temporary upload directory is unavailable',
        UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded file',
        UPLOAD_ERR_EXTENSION => 'A server extension stopped the upload',
        default => 'Unknown upload error',
    };
}

// HANDLE MULTI-FILE RESUME INGESTION POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? 'upload_resumes') === 'upload_resumes') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? '')) {
            http_response_code(403);
            throw new Exception('Invalid request token. Refresh the page and try again.');
        }
        $selectedJobId = (int)($_POST['job_id'] ?? 0);
        $selectedCount = max(0, (int)($_POST['selected_count'] ?? 0));
        if ($selectedJobId <= 0) {
            throw new Exception('Please select a job profile. Resumes cannot be uploaded without a target job.');
        }
        $selectedJob = null;
        foreach ($openJobs as $openJob) {
            if ((int)$openJob['id'] === $selectedJobId) {
                $selectedJob = $openJob;
                break;
            }
        }
        if (!$selectedJob) {
            throw new Exception('The selected job profile is unavailable. Choose an active job and try again.');
        }
        $jobSkills = json_decode($selectedJob['required_skills_json'] ?? '[]', true) ?: [];
        $jd = implode(', ', $jobSkills).' '.trim((string)$selectedJob['description']);

        // Gather uploaded files array
        $uploadFiles = [];
        if (isset($_FILES['resumes']['name']) && is_array($_FILES['resumes']['name'])) {
            foreach ($_FILES['resumes']['name'] as $idx => $name) {
                if ((string)$name !== '' || (int)($_FILES['resumes']['error'][$idx] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
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

        $receivedCount = count($uploadFiles);
        if ($selectedCount > $receivedCount) {
            $batchSummary[] = [
                'file' => 'Upload batch',
                'status' => 'error',
                'msg' => ($selectedCount - $receivedCount).' selected file(s) did not reach PHP. Server max_file_uploads is '.$serverMaxFiles.'.',
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
            if ((int)$fileInfo['error'] !== UPLOAD_ERR_OK) {
                $batchSummary[] = [
                    'file' => $originalName ?: 'Unknown file',
                    'status' => 'error',
                    'msg' => uploadErrorMessage((int)$fileInfo['error']),
                ];
                continue;
            }

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

            $detectedSkills = extractDetectedSkills($extractedText);
            $email = extractEmail($extractedText);
            $phone = extractPhone($extractedText);
            $experience = analyzeExperienceTimeline($extractedText, $detectedSkills);

            $nameInfo = extractCandidateNameInfo($extractedText);
            $countryInfo = extractCountryInfo($extractedText);
            $salaryInfo = extractSalaryInfo($extractedText);
            $candName = $nameInfo['name'];
            $candRole = $nameInfo['role'];

            // STRICT VALIDATION FOR UNFIT / EMPTY DOCUMENTS
            if (mb_strlen($extractedText) < 30 || ($candName === 'Unknown candidate' && empty($email) && empty($phone))) {
                @unlink($targetFile); // Remove unparseable upload file
                $batchSummary[] = [
                    'file'   => $originalName,
                    'status' => 'unfit',
                    'msg'    => 'Unfit / Empty Document (No readable candidate text or contact info found. Skipped from DB)'
                ];
                continue;
            }

            $processingStatus = 'processed';
            if ($requiresOcr) {
                $processingStatus = 'requires_ocr';
            } elseif ($extractionError && empty($extractedText)) {
                $processingStatus = 'failed';
            }

            [$score, $matched, $missing, $jdTokens] = basicMatch($jd, $extractedText);

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
                'name'              => $candName,
                'role'              => $candRole,
                'email'             => $email,
                'phone'             => $phone,
                'country_name'      => $countryInfo['country_name'],
                'country_code'      => $countryInfo['country_code'],
                'current_ctc'       => $salaryInfo['current_ctc'],
                'expected_ctc'      => $salaryInfo['expected_ctc'],
                'detected_skills'   => $detectedSkills,
                'match_score'       => $score,
                'jd_keywords'       => $jdTokens,
                'matched_keywords'  => $matched,
                'missing_keywords'  => $missing,
                'experience'        => $experience,
            ];

            $candidateId = saveCandidateRecord($resObj, $selectedJobId);
            $result = $resObj;
            $processedCount++;
            $batchSummary[] = [
                'file'   => $originalName,
                'status' => 'success',
                'candidate_id' => $candidateId,
                'name'   => $candName,
                'role'   => $candRole,
                'score'  => $score,
                'email'  => $email,
                'job'    => $selectedJob['title'],
            ];
        }

        $failedCount = count(array_filter($batchSummary, fn($item) => ($item['status'] ?? '') !== 'success'));
        $batchId = 'UPLOAD-'.date('YmdHis').'-'.bin2hex(random_bytes(4));
        $auditFiles = array_map(fn($item) => [
            'filename' => $item['file'] ?? '',
            'status' => $item['status'] ?? 'error',
            'candidate_id' => $item['candidate_id'] ?? null,
            'reason' => $item['msg'] ?? null,
        ], $batchSummary);
        $batchMetadata = [
            'job_id' => $selectedJobId,
            'job_title' => $selectedJob['title'],
            'selected' => $selectedCount,
            'received' => $receivedCount,
            'successful' => $processedCount,
            'failed' => $failedCount,
            'files' => $auditFiles,
        ];
        $pdo->prepare('INSERT INTO audit_events(user_id,action,entity_type,entity_id,metadata_json) VALUES(?,?,?,?,?)')->execute([
            $user['id'] ?? null,
            'resume_upload_batch_completed',
            'upload_batch',
            $batchId,
            json_encode($batchMetadata, JSON_UNESCAPED_SLASHES),
        ]);

        if ($processedCount === 0 && empty($batchSummary)) {
            throw new Exception('No valid resumes were processed.');
        }

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reassign_candidates') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? '')) throw new Exception('Invalid request token.');
        $candidateIds = $_POST['candidate_ids'] ?? [];
        if (!is_array($candidateIds)) $candidateIds = [];
        $candidateIds = array_slice(array_values(array_unique(array_filter(array_map(
            fn($value) => preg_replace('/[^A-Za-z0-9_-]/', '', (string)$value),
            $candidateIds
        )))), 0, 200);
        $assignmentJobId = (int)($_POST['assignment_job_id'] ?? 0);
        if (!$candidateIds) throw new Exception('Select at least one previous upload.');
        if ($assignmentJobId <= 0) throw new Exception('Select the job profile to assign.');
        $assignmentJob = null;
        foreach ($openJobs as $openJob) {
            if ((int)$openJob['id'] === $assignmentJobId) {$assignmentJob = $openJob;break;}
        }
        if (!$assignmentJob) throw new Exception('The selected job profile is unavailable.');
        $assignmentSkills = json_decode($assignmentJob['required_skills_json'] ?? '[]', true) ?: [];
        $assignmentJd = implode(', ', $assignmentSkills).' '.trim((string)$assignmentJob['description']);
        $marks = implode(',', array_fill(0, count($candidateIds), '?'));
        $candidateStmt = $pdo->prepare("SELECT id,name,stored_file,source_url,source_name FROM candidates WHERE id IN ($marks)");
        $candidateStmt->execute($candidateIds);
        $selectedCandidates = $candidateStmt->fetchAll();
        if (!$selectedCandidates) throw new Exception('No eligible candidates were found.');
        $updateCandidate = $pdo->prepare('UPDATE candidates SET job_id=?,score=?,skills_json=?,experience_json=?,analysis_json=? WHERE id=?');
        $reassigned = 0;
        foreach ($selectedCandidates as $selectedCandidate) {
            $candidatePath = resolveCandidateResumePath($selectedCandidate, (int)($user['id'] ?? 0));
            if (!$candidatePath) {
                $managementResults[] = ['name'=>$selectedCandidate['name'],'status'=>'error','message'=>'Protected resume is missing or could not be securely retrieved.'];
                continue;
            }
            $extension = strtolower(pathinfo($candidatePath, PATHINFO_EXTENSION));
            if (!in_array($extension, ['pdf','docx','doc'], true)) {
                $managementResults[] = ['name'=>$selectedCandidate['name'],'status'=>'error','message'=>'Unsupported stored resume format.'];
                continue;
            }
            $reExtraction = extractResumeText($candidatePath, $extension);
            $resumeText = trim((string)($reExtraction['text'] ?? ''));
            if ($resumeText === '') {
                $managementResults[] = ['name'=>$selectedCandidate['name'],'status'=>'error','message'=>$reExtraction['error'] ?: 'Resume text could not be extracted.'];
                continue;
            }
            $reSkills = extractDetectedSkills($resumeText);
            $reExperience = analyzeExperienceTimeline($resumeText, $reSkills);
            [$reScore, $reMatched, $reMissing, $reTokens] = basicMatch($assignmentJd, $resumeText);
            $reAnalysis = [
                'matched_keywords'=>$reMatched,
                'missing_keywords'=>$reMissing,
                'jd_keywords'=>$reTokens,
                'reassigned_at'=>date(DATE_ATOM),
                'reassigned_job_id'=>$assignmentJobId,
            ];
            $updateCandidate->execute([
                $assignmentJobId,
                $reScore,
                json_encode($reSkills),
                json_encode($reExperience),
                json_encode($reAnalysis),
                $selectedCandidate['id'],
            ]);
            $reassigned++;
            $managementResults[] = ['name'=>$selectedCandidate['name'],'status'=>'success','message'=>'Assigned to '.$assignmentJob['title'].' and recalculated: '.$reScore.'%.'];
        }
        $managementMessage = $reassigned.' candidate(s) assigned to '.$assignmentJob['title'].' and recalculated.';
        $pdo->prepare('INSERT INTO audit_events(user_id,action,entity_type,entity_id,metadata_json) VALUES(?,?,?,?,?)')->execute([
            $user['id'] ?? null,
            'candidate_bulk_job_reassigned',
            'job',
            (string)$assignmentJobId,
            json_encode(['requested'=>count($candidateIds),'reassigned'=>$reassigned,'failed'=>count($selectedCandidates)-$reassigned,'candidate_ids'=>array_column($selectedCandidates,'id')]),
        ]);
    } catch (Throwable $e) {
        $managementError = $e->getMessage();
    }
}

$previousUploads = $pdo->query("SELECT c.id,c.name,c.email,c.role_title,c.score,c.stage,c.source_name,c.stored_file,c.source_url,c.created_at,c.updated_at,c.job_id,j.title job_title FROM candidates c LEFT JOIN jobs j ON j.id=c.job_id ORDER BY c.created_at DESC,c.id DESC")->fetchAll();
$candidateSources = array_values(array_unique(array_filter(array_map(fn($candidate) => trim((string)($candidate['source_name'] ?? '')), $previousUploads))));
$candidateStages = array_values(array_unique(array_filter(array_map(fn($candidate) => trim((string)($candidate['stage'] ?? '')), $previousUploads))));
$candidateJobs = [];
foreach ($previousUploads as $candidate) {
    if ($candidate['job_id'] !== null && trim((string)$candidate['job_title']) !== '') {
        $candidateJobs[(int)$candidate['job_id']] = (string)$candidate['job_title'];
    }
}
sort($candidateSources, SORT_NATURAL | SORT_FLAG_CASE);
sort($candidateStages, SORT_NATURAL | SORT_FLAG_CASE);
asort($candidateJobs, SORT_NATURAL | SORT_FLAG_CASE);

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
.batch-results-list { margin-top: 10px; display: flex; flex-direction: column; gap: 8px; }
.batch-item { display: flex; justify-content: space-between; align-items: center; background: #fff; padding: 10px 14px; border-radius: 8px; border: 1px solid #d1fae5; }
.batch-item.unfit-item { background: #fff5f5; border-color: #fecaca; color: #991b1b; }
.batch-counts { display:grid; grid-template-columns:repeat(4,minmax(90px,1fr)); gap:8px; margin:12px 0; }
.batch-count { background:#fff; border:1px solid #d1fae5; border-radius:8px; padding:10px; text-align:center; }
.batch-count strong { display:block; font-size:20px; }
.batch-count span { font-size:10px; color:#64748b; }
.batch-person { display:flex; align-items:center; gap:10px; min-width:0; }
.batch-person small { display:none; }
.batch-clean-details { display:block; color:#64748b; font-size:10px; }
.selection-warning { margin-top:10px; padding:10px 12px; border-radius:8px; background:#fff7ed; border:1px solid #fdba74; color:#9a3412; font-size:12px; }
.selection-warning.ok { background:#ecfdf5; border-color:#86efac; color:#166534; }
.bulk-actions { display:flex; justify-content:space-between; align-items:center; gap:10px; margin-top:12px; }
.upload-manager { margin-top:20px; padding:0; overflow:hidden; }
.upload-manager summary { cursor:pointer; padding:16px 18px; font-weight:800; list-style:none; }
.upload-manager summary::-webkit-details-marker { display:none; }
.manager-toolbar { display:flex; gap:10px; align-items:end; padding:14px 18px; border-top:1px solid #e5e7eb; border-bottom:1px solid #e5e7eb; background:#fafafa; }
.manager-job { flex:1; }
.manager-filters { display:grid; grid-template-columns:minmax(220px,2fr) repeat(4,minmax(130px,1fr)); gap:10px; padding:14px 18px; background:#fff; border-bottom:1px solid #e5e7eb; }
.manager-filter label { display:block; font-size:10px; font-weight:800; color:#64748b; margin-bottom:4px; text-transform:uppercase; }
.manager-filter .control { width:100%; }
.manager-count { padding:8px 18px; color:#475569; font-size:11px; background:#f8fafc; border-bottom:1px solid #e5e7eb; }
.manager-table-wrap { overflow:auto; max-height:520px; }
.manager-table { width:100%; border-collapse:collapse; font-size:12px; }
.manager-table th,.manager-table td { padding:10px 12px; border-bottom:1px solid #eef2f7; text-align:left; white-space:nowrap; }
.manager-table th { position:sticky; top:0; background:#f8fafc; z-index:1; }

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
@media(max-width:900px){.manager-filters{grid-template-columns:1fr 1fr}}
@media(max-width:700px){.batch-counts{grid-template-columns:repeat(2,1fr)}.batch-item{align-items:flex-start;gap:8px}.bulk-actions,.manager-toolbar{align-items:stretch;flex-direction:column}.manager-filters{grid-template-columns:1fr}}
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
<?php if ($managementError): ?>
  <div class="notice err"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($managementError) ?></div>
<?php endif; ?>
<?php if ($managementMessage): ?>
  <div class="notice ok"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($managementMessage) ?></div>
  <?php foreach ($managementResults as $managementResult): ?>
    <div class="notice <?= $managementResult['status']==='success'?'ok':'err' ?>"><?= htmlspecialchars($managementResult['name'].' - '.$managementResult['message']) ?></div>
  <?php endforeach; ?>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
  <div class="notice ok"><i class="fa-solid fa-circle-check"></i> <?= (int)$_GET['deleted'] ?> selected candidate(s) deleted. Audit tombstones were preserved.</div>
<?php endif; ?>

<?php if (!empty($batchSummary)): ?>
  <div class="batch-results-card" style="<?= $processedCount === 0 ? 'background:#fff5f5;border-color:#fecaca;color:#991b1b' : '' ?>">
    <strong><i class="fa-solid fa-circle-check"></i> Ingestion Batch Report [Processed: <?= $processedCount ?> Valid Resume(s) | Source: <code>direct_upload</code>]</strong>
    <div class="batch-counts">
      <div class="batch-count"><strong><?= $selectedCount ?></strong><span>Selected</span></div>
      <div class="batch-count"><strong><?= $receivedCount ?></strong><span>Received</span></div>
      <div class="batch-count"><strong><?= $processedCount ?></strong><span>Successful</span></div>
      <div class="batch-count"><strong><?= $failedCount ?></strong><span>Failed</span></div>
    </div>
    <?php if ($isWorkspaceOwner && $processedCount > 0): ?>
    <form method="post" action="candidate_delete.php" id="batchDeleteForm" onsubmit="return confirmBatchDelete()">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES) ?>">
      <input type="hidden" name="return_to" value="scan">
    <?php endif; ?>
    <div class="batch-results-list">
      <?php foreach ($batchSummary as $b): ?>
        <?php if (($b['status'] ?? '') === 'unfit'): ?>
          <div class="batch-item unfit-item">
            <span><i class="fa-solid fa-triangle-exclamation"></i> <strong><?= htmlspecialchars($b['file']) ?></strong> — <?= htmlspecialchars($b['msg']) ?></span>
            <span class="badge" style="background:#fee2e2;color:#dc2626;font-weight:800">UNFIT / SKIPPED</span>
          </div>
        <?php elseif (($b['status'] ?? '') === 'success'): ?>
          <div class="batch-item">
            <span class="batch-person">
              <?php if ($isWorkspaceOwner): ?><input class="uploaded-candidate-check" type="checkbox" name="candidate_ids[]" value="<?= htmlspecialchars($b['candidate_id'], ENT_QUOTES) ?>"><?php endif; ?>
                <small><?= htmlspecialchars($b['email'] ?: 'No email') ?> | <?= htmlspecialchars($b['role']) ?> | <?= htmlspecialchars($b['file']) ?></small>
                <small><?= htmlspecialchars($b['email'] ?: 'No email') ?> � <?= htmlspecialchars($b['role']) ?> � <?= htmlspecialchars($b['file']) ?></small>
              </span>
            </span>
            <span class="batch-clean-details"><?= htmlspecialchars($b['email'] ?: 'No email') ?> | <?= htmlspecialchars($b['role']) ?> | <?= htmlspecialchars($b['file']) ?> | <?= htmlspecialchars($b['job']) ?></span>
            <span class="badge" style="background:#dcfce7;color:#15803d;font-weight:800"><?= isset($b['score']) && $b['score'] > 0 ? $b['score'].'%' : 'Indexed' ?></span>
          </div>
        <?php else: ?>
          <div class="batch-item unfit-item">
            <span><strong><?= htmlspecialchars($b['file']) ?></strong> — <?= htmlspecialchars($b['msg'] ?? 'Processing error') ?></span>
            <span class="badge" style="background:#fee2e2;color:#dc2626;font-weight:800">ERROR</span>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <?php if ($isWorkspaceOwner && $processedCount > 0): ?>
      <div class="bulk-actions">
        <label><input type="checkbox" id="selectAllUploaded"> Select all successful uploads</label>
        <button class="btn" type="submit" style="color:#b91c1c;border-color:#fecaca"><i class="fa-solid fa-trash"></i> Delete selected</button>
      </div>
    </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="ingestion-card">
  <h2><i class="fa-solid fa-file-arrow-up" style="color:#6366f1;margin-right:6px"></i> Upload Candidate Resumes</h2>
  <p style="font-size:13px;color:#6b7280;margin:0 0 16px">Select the target job first. Every resume in the batch will be assigned and scored against that job's saved requirements.</p>

  <form method="post" enctype="multipart/form-data" id="ingestionForm">
    <input type="hidden" name="action" value="upload_resumes">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES) ?>">
    <input type="hidden" name="selected_count" id="selectedCountInput" value="0">
    <div class="job-select-toolbar">
        <option value="" data-jd="" selected>Select a job profile - required</option>
      <select class="control" name="job_id" id="jobSelect" required onchange="onJobSelect(this)" style="font-size:13px;border-radius:10px;padding:10px 14px;width:100%;border:1px solid #d1d5db;background:#fff">
        <option value="" data-jd="" selected>Select a job profile - required</option>
        <?php foreach ($openJobs as $job): 
          $jdSkills = json_decode($job['required_skills_json'] ?? '[]', true) ?: [];
          $jdText = trim((string)$job['description']);
          if (!empty($jdSkills)) {
              $jdText = "Required Skills:\n- " . implode("\n- ", $jdSkills) . "\n\n" . $jdText;
          }
        ?>
        <option value="<?= (int)$job['id'] ?>" data-jd="<?= htmlspecialchars($jdText) ?>">
          <?= htmlspecialchars($job['title']) ?>
        </option>
        <?php endforeach; ?>
      </select>
      <div class="selection-warning" id="jobWarning"><i class="fa-solid fa-triangle-exclamation"></i> Select a job profile first. Resumes will only be assigned and scored for the job you choose.</div>
    </div>

    <!-- DRAG AND DROP MULTI-FILE ZONE -->
    <div class="dropzone-box">
      <div class="dropzone-icon"><i class="fa-solid fa-file-circle-plus"></i></div>
      <strong style="font-size:15px;color:#111827;display:block" id="fileLabel">Click or Drag & Drop Resumes Here</strong>
      <span style="font-size:12px;color:#6b7280;display:block;margin-top:4px">Select single or multiple files: <strong>PDF, DOCX, DOC</strong> (Max 8 MB per file)</span>
      <input type="file" name="resumes[]" id="resumeInput" accept=".pdf,.docx,.doc" multiple required onchange="updateFileNames(this)">
    </div>
    <div class="selection-warning" id="fileWarning"><i class="fa-solid fa-triangle-exclamation"></i> Select at least one resume. Current server batch limit: <?= $serverMaxFiles ?> files.</div>

    <!-- OPTIONAL JD OVERRIDE / READONLY VIEW -->
    <div style="margin-top:16px">
      <label style="font-size:11px;font-weight:800;color:#6b7280;text-transform:uppercase;display:block;margin-bottom:4px">Selected Job Description / Keywords (Auto-filled):</label>
      <textarea class="control" name="jd" id="jdInput" placeholder="Auto-filled when a Job Profile is selected above, or paste custom job requirements…" style="width:100%;min-height:90px;font-size:12px;border-radius:10px;padding:10px"><?= htmlspecialchars($_POST['jd'] ?? '') ?></textarea>
    </div>

    <div style="margin-top:20px;display:flex;justify-content:flex-end">
      <button class="btn btn-primary" id="submitBtn" type="submit" disabled style="height:44px;border-radius:10px;padding:0 24px;font-weight:700;font-size:13px">
        <i class="fa-solid fa-cloud-arrow-up"></i> Ingest & Parse Resumes
      </button>
    </div>
  </form>
</div>

<details class="panel upload-manager" id="previousUploads">
  <summary><i class="fa-solid fa-users-gear" style="color:#6366f1"></i> Manage All Candidates (<?= count($previousUploads) ?>) <a href="candidate_duplicates.php" onclick="event.stopPropagation()" style="float:right;color:#6f45ff;font-size:12px"><i class="fa-solid fa-clone"></i> Find duplicates</a></summary>
  <form method="post" action="index.php#previousUploads" id="previousUploadForm" onsubmit="return validatePreviousUploadAction(event)">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES) ?>">
    <input type="hidden" name="action" value="reassign_candidates">
    <input type="hidden" name="return_to" value="scan">
    <div class="manager-toolbar">
      <label><input type="checkbox" id="selectAllPrevious"> Select all shown</label>
      <div class="manager-job">
        <label style="display:block;font-size:11px;font-weight:800;margin-bottom:5px">Assign selected candidates to job</label>
        <select class="control" name="assignment_job_id" id="assignmentJob">
          <option value="">Select job profile</option>
          <?php foreach ($openJobs as $job): ?><option value="<?= (int)$job['id'] ?>"><?= htmlspecialchars($job['title']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-primary" type="submit"><i class="fa-solid fa-arrows-rotate"></i> Assign & Recalculate</button>
      <?php if ($isWorkspaceOwner): ?>
        <button class="btn" type="submit" formaction="candidate_delete.php" onclick="return confirmPreviousDelete()" style="color:#b91c1c;border-color:#fecaca"><i class="fa-solid fa-trash"></i> Delete selected</button>
      <?php endif; ?>
    </div>
    <div style="padding:9px 18px;background:#fff7ed;color:#9a3412;font-size:11px;border-bottom:1px solid #fed7aa">
      Delete removes the candidate from ResumeIQ only. Original NonceBlox/source-system applications remain unchanged and may return on a future source sync.
    </div>
    <div class="manager-filters">
      <div class="manager-filter">
        <label for="candidateSearch">Search candidate</label>
        <input class="control" type="search" id="candidateSearch" placeholder="Name, email, role or ID">
      </div>
      <div class="manager-filter">
        <label for="candidateSourceFilter">Source</label>
        <select class="control" id="candidateSourceFilter"><option value="">All sources</option><?php foreach ($candidateSources as $source): ?><option value="<?= htmlspecialchars(strtolower($source), ENT_QUOTES) ?>"><?= htmlspecialchars($source) ?></option><?php endforeach; ?></select>
      </div>
      <div class="manager-filter">
        <label for="candidateJobFilter">Job</label>
        <select class="control" id="candidateJobFilter"><option value="">All jobs</option><option value="__unassigned__">Unassigned</option><?php foreach ($candidateJobs as $jobId => $jobTitle): ?><option value="<?= $jobId ?>"><?= htmlspecialchars($jobTitle) ?></option><?php endforeach; ?></select>
      </div>
      <div class="manager-filter">
        <label for="candidateStageFilter">Stage</label>
        <select class="control" id="candidateStageFilter"><option value="">All stages</option><?php foreach ($candidateStages as $stage): ?><option value="<?= htmlspecialchars(strtolower($stage), ENT_QUOTES) ?>"><?= htmlspecialchars($stage) ?></option><?php endforeach; ?></select>
      </div>
      <div class="manager-filter">
        <label for="candidateDateFilter">Added date</label>
        <input class="control" type="date" id="candidateDateFilter">
      </div>
    </div>
    <div class="manager-count"><strong id="visibleCandidateCount"><?= count($previousUploads) ?></strong> of <?= count($previousUploads) ?> candidates shown · <strong id="selectedCandidateCount">0</strong> selected</div>
    <div class="manager-table-wrap">
      <table class="manager-table">
        <thead><tr><th></th><th>Candidate</th><th>Source</th><th>Current job</th><th>Score</th><th>Stage</th><th>Added</th></tr></thead>
        <tbody>
<?php if (!$previousUploads): ?><tr><td colspan="7">No candidates found.</td></tr><?php endif; ?>
        <?php foreach ($previousUploads as $previous): ?>
          <tr class="candidate-manager-row" data-search="<?= htmlspecialchars(strtolower(implode(' ', [$previous['id'], $previous['name'], $previous['email'], $previous['role_title'], $previous['job_title']])), ENT_QUOTES) ?>" data-source="<?= htmlspecialchars(strtolower(trim((string)$previous['source_name'])), ENT_QUOTES) ?>" data-job="<?= $previous['job_id'] === null ? '__unassigned__' : (int)$previous['job_id'] ?>" data-stage="<?= htmlspecialchars(strtolower(trim((string)$previous['stage'])), ENT_QUOTES) ?>" data-date="<?= htmlspecialchars(date('Y-m-d', strtotime($previous['created_at'])), ENT_QUOTES) ?>">
            <td><input class="previous-candidate-check" type="checkbox" name="candidate_ids[]" value="<?= htmlspecialchars($previous['id'], ENT_QUOTES) ?>"></td>
            <td><strong><?= htmlspecialchars($previous['name']) ?></strong><br><small><?= htmlspecialchars($previous['email'] ?: 'No email') ?></small></td>
            <td><?= htmlspecialchars($previous['source_name'] ?: 'Unknown') ?></td>
            <td><?= htmlspecialchars($previous['job_title'] ?: 'Unassigned') ?></td>
            <td><?= (int)$previous['score'] ?>%</td>
            <td><?= htmlspecialchars($previous['stage']) ?></td>
            <td><?= htmlspecialchars(date('d M Y, h:i A', strtotime($previous['created_at']))) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </form>
</details>

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
          <span class="pill skill"><i class="fa-solid fa-check" style="font-size:10px;margin-right:2px"></i> <?= htmlspecialchars($sk) ?></span>
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
  updateUploadState();
}

function updateFileNames(input) {
  const label = document.getElementById('fileLabel');
  const selectedCount = input.files ? input.files.length : 0;
  document.getElementById('selectedCountInput').value = String(selectedCount);
  if (input.files && input.files.length > 0) {
    if (input.files.length === 1) {
      label.innerHTML = 'Selected: <strong>' + input.files[0].name + '</strong>';
    } else {
      label.innerHTML = 'Selected: <strong>' + input.files.length + ' Resume Files</strong>';
    }
  } else {
    label.innerText = 'Click or Drag & Drop Resumes Here';
  }
  updateUploadState();
}

function updateUploadState() {
  const jobSelected = document.getElementById('jobSelect').value !== '';
  const fileCount = document.getElementById('resumeInput').files.length;
  const jobWarning = document.getElementById('jobWarning');
  const fileWarning = document.getElementById('fileWarning');
  jobWarning.classList.toggle('ok', jobSelected);
  jobWarning.innerHTML = jobSelected
    ? '<i class="fa-solid fa-circle-check"></i> Job selected. Every resume in this batch will be assigned and scored for this job.'
    : '<i class="fa-solid fa-triangle-exclamation"></i> Select a job profile first. Upload will not start without it.';
  fileWarning.classList.toggle('ok', fileCount > 0 && fileCount <= <?= $serverMaxFiles ?>);
  fileWarning.innerHTML = fileCount > 0
    ? '<i class="fa-solid fa-file-circle-check"></i> Selected: <strong>' + fileCount + '</strong> resume(s). Server batch limit: <?= $serverMaxFiles ?>.'
    : '<i class="fa-solid fa-triangle-exclamation"></i> Select at least one resume. Current server batch limit: <?= $serverMaxFiles ?> files.';
  document.getElementById('submitBtn').disabled = !jobSelected || fileCount < 1 || fileCount > <?= $serverMaxFiles ?>;
}

document.getElementById('ingestionForm').addEventListener('submit', function(e) {
  const jobSelected = document.getElementById('jobSelect').value !== '';
  const fileCount = document.getElementById('resumeInput').files.length;
  if (!jobSelected || fileCount < 1 || fileCount > <?= $serverMaxFiles ?>) {
    e.preventDefault();
    updateUploadState();
    alert(!jobSelected ? 'Please select a job profile before uploading.' : 'Please select between 1 and <?= $serverMaxFiles ?> resume files.');
    return;
  }
  const btn = document.getElementById('submitBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing & Parsing Resumes... Please wait...';
  btn.style.opacity = '0.75';
  btn.style.cursor = 'wait';
});

document.getElementById('jdInput').readOnly = true;
document.getElementById('jobSelect').options[0].textContent = 'Select a job profile - required';
updateUploadState();

const previousUploadsPanel = document.getElementById('previousUploads');
const ingestionCard = document.querySelector('.ingestion-card');
if (previousUploadsPanel && ingestionCard) {
  ingestionCard.parentNode.insertBefore(previousUploadsPanel, ingestionCard);
}

const selectAllUploaded = document.getElementById('selectAllUploaded');
if (selectAllUploaded) {
  selectAllUploaded.addEventListener('change', function () {
    document.querySelectorAll('.uploaded-candidate-check').forEach(box => box.checked = this.checked);
  });
}

function confirmBatchDelete() {
  const selected = document.querySelectorAll('.uploaded-candidate-check:checked').length;
  if (!selected) {
    alert('Select at least one uploaded candidate to delete.');
    return false;
  }
  return confirm('Permanently delete ' + selected + ' selected candidate(s) and their protected local resume files? This cannot be undone.');
}

const selectAllPrevious = document.getElementById('selectAllPrevious');
if (selectAllPrevious) {
  selectAllPrevious.addEventListener('change', function () {
    document.querySelectorAll('.candidate-manager-row').forEach(row => {
      if (!row.hidden) row.querySelector('.previous-candidate-check').checked = this.checked;
    });
    updateCandidateManagerCounts();
  });
}

const candidateManagerRows = Array.from(document.querySelectorAll('.candidate-manager-row'));
const candidateFilterIds = ['candidateSearch', 'candidateSourceFilter', 'candidateJobFilter', 'candidateStageFilter', 'candidateDateFilter'];

function updateCandidateManagerCounts() {
  const visible = candidateManagerRows.filter(row => !row.hidden).length;
  const selected = document.querySelectorAll('.previous-candidate-check:checked').length;
  const visibleCount = document.getElementById('visibleCandidateCount');
  const selectedCount = document.getElementById('selectedCandidateCount');
  if (visibleCount) visibleCount.textContent = visible;
  if (selectedCount) selectedCount.textContent = selected;
}

function filterCandidateManager() {
  const search = document.getElementById('candidateSearch').value.trim().toLowerCase();
  const source = document.getElementById('candidateSourceFilter').value;
  const job = document.getElementById('candidateJobFilter').value;
  const stage = document.getElementById('candidateStageFilter').value;
  const date = document.getElementById('candidateDateFilter').value;
  candidateManagerRows.forEach(row => {
    const matches = (
      (!search || row.dataset.search.includes(search)) &&
      (!source || row.dataset.source === source) &&
      (!job || row.dataset.job === job) &&
      (!stage || row.dataset.stage === stage) &&
      (!date || row.dataset.date === date)
    );
    row.hidden = !matches;
    if (!matches) row.querySelector('.previous-candidate-check').checked = false;
  });
  if (selectAllPrevious) selectAllPrevious.checked = false;
  updateCandidateManagerCounts();
}

candidateFilterIds.forEach(id => {
  const field = document.getElementById(id);
  if (field) field.addEventListener(id === 'candidateSearch' ? 'input' : 'change', filterCandidateManager);
});
document.querySelectorAll('.previous-candidate-check').forEach(box => box.addEventListener('change', updateCandidateManagerCounts));
updateCandidateManagerCounts();

function previousSelectionCount() {
  return document.querySelectorAll('.previous-candidate-check:checked').length;
}

function validatePreviousUploadAction(event) {
  if (event.submitter && (event.submitter.getAttribute('formaction') || '').includes('candidate_delete.php')) {
    return true;
  }
  if (!previousSelectionCount()) {
    event.preventDefault();
    alert('Select at least one previous upload.');
    return false;
  }
  if (!document.getElementById('assignmentJob').value) {
    event.preventDefault();
    alert('Select the job profile for assignment and recalculation.');
    return false;
  }
  return confirm('Assign the selected candidate(s) to this job and recalculate their score from the protected resumes?');
}

function confirmPreviousDelete() {
  const selected = previousSelectionCount();
  if (!selected) {
    alert('Select at least one candidate to delete.');
    return false;
  }
  return confirm('Permanently delete ' + selected + ' selected candidate(s) from ResumeIQ? Original source-system records will not be deleted and may return on a future sync.');
}

function copyRawText() {
  const text = document.getElementById('rawTextContent').innerText;
  navigator.clipboard.writeText(text).then(() => {
    alert('Extracted text copied to clipboard!');
  });
}
</script>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
