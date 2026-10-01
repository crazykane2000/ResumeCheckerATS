<?php
define('RESUMEIQ_FUNCTIONS_ONLY', true);

$jd = "Senior PHP Developer with 3+ years experience in Laravel Framework, MySQL, REST API, JavaScript, Git, AWS";
$file = __DIR__ . '/test_samples/sample_resume.docx';
$ext = 'docx';
if (!class_exists('ZipArchive')) {
    $file = __DIR__ . '/test_samples/sample_resume.pdf';
    $ext = 'pdf';
}

require_once __DIR__ . '/index.php';

$extraction = extractResumeText($file, $ext);
$extractedText = $extraction['text'];
$requiresOcr = $extraction['requires_ocr'];
$extractionError = $extraction['error'];

[$score, $matched, $missing, $jdTokens] = basicMatch($jd, $extractedText);
$detectedSkills = extractDetectedSkills($extractedText);
$email = extractEmail($extractedText);
$phone = extractPhone($extractedText);

echo "=== MOCK POST SIMULATION RESULT ===\n";
echo "Extracted Chars   : " . mb_strlen($extractedText) . "\n";
echo "Email             : " . ($email ?? 'N/A') . "\n";
echo "Phone             : " . ($phone ?? 'N/A') . "\n";
echo "Detected Skills   : " . implode(', ', $detectedSkills) . "\n";
echo "Match Score       : " . $score . "%\n";
echo "Matched Keywords  : " . implode(', ', $matched) . "\n";
echo "Missing Keywords  : " . implode(', ', $missing) . "\n";
