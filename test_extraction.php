<?php
// Prevent HTML output when running CLI tests
define('CLI_MODE', true);

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

    $autoload = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
        if (class_exists('Smalot\\PdfParser\\Parser')) {
            try {
                $parser = new Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($file);
                $text = cleanText($pdf->getText());
            } catch (Throwable $e) {
                $error = 'PdfParser notice: ' . $e->getMessage();
            }
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
        if (!$error) {
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
        return [
            'text' => '',
            'requires_ocr' => false,
            'error' => 'PHP Zip extension (ZipArchive) is not enabled.'
        ];
    }
    $zip = new ZipArchive();
    $res = $zip->open($file);
    if ($res !== true) {
        return [
            'text' => '',
            'requires_ocr' => false,
            'error' => 'Could not open DOCX archive.'
        ];
    }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) {
        return [
            'text' => '',
            'requires_ocr' => false,
            'error' => 'Invalid DOCX file (word/document.xml missing).'
        ];
    }
    $xml = str_replace(['</w:p>', '</w:tr>', '<w:tab/>', '</w:tc>'], ["\n", "\n", "\t", " | "], $xml);
    $text = cleanText(strip_tags($xml));

    return [
        'text' => $text,
        'requires_ocr' => false,
        'error' => empty($text) ? 'No text extracted from DOCX.' : null
    ];
}

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

function extractResumeText(string $file, string $ext): array {
    return match ($ext) {
        'pdf' => extractPdfText($file),
        'docx' => extractDocxText($file),
        'doc' => extractDocText($file),
        default => ['text' => '', 'requires_ocr' => false, 'error' => 'Unsupported file format.']
    };
}

$files = [
    'test_samples/sample_resume.docx' => 'docx',
    'test_samples/sample_resume.pdf' => 'pdf',
    'test_samples/scanned_image_resume.pdf' => 'pdf',
    'test_samples/sample_legacy.doc' => 'doc',
];

echo "=== SCANNING POC VERIFICATION TEST ===\n\n";

foreach ($files as $filePath => $ext) {
    echo "Testing File: $filePath ($ext)\n";
    $fullPath = __DIR__ . '/' . $filePath;
    $res = extractResumeText($fullPath, $ext);

    echo "Status        : " . ($res['requires_ocr'] ? 'REQUIRES_OCR' : ($res['error'] ? 'ERROR / FAILED' : 'SUCCESS')) . "\n";
    echo "Requires OCR  : " . ($res['requires_ocr'] ? 'true' : 'false') . "\n";
    echo "Text Length   : " . mb_strlen($res['text']) . " chars\n";
    if ($res['error']) {
        echo "Message/Error : " . $res['error'] . "\n";
    }
    if (!empty($res['text'])) {
        echo "Text Preview  : " . mb_substr($res['text'], 0, 120) . "...\n";
    }
    echo "--------------------------------------------------------\n";
}
