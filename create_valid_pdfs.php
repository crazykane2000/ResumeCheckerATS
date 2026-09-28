<?php
// Let's create a minimal 100% compliant PDF 1.3 structure that Smalot PdfParser can parse
$dir = __DIR__ . '/test_samples';
if (!file_exists($dir)) mkdir($dir, 0777, true);

function buildPdf($text) {
    $stream = "BT /F1 12 Tf 50 700 Td (" . addcslashes($text, "()\\") . ") Tj ET";
    $streamLen = strlen($stream);

    $pdf = "%PDF-1.3\n";
    $pdf .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
    $pdf .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
    $pdf .= "3 0 obj\n<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 4 0 R >> >> /MediaBox [0 0 612 792] /Contents 5 0 R >>\nendobj\n";
    $pdf .= "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";
    $pdf .= "5 0 obj\n<< /Length $streamLen >>\nstream\n$stream\nendstream\nendobj\n";

    // xref table
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 6\n";
    $pdf .= "0000000000 65535 f \n";
    $pdf .= sprintf("%010d 00000 n \n", 9);
    $pdf .= sprintf("%010d 00000 n \n", 58);
    $pdf .= sprintf("%010d 00000 n \n", 115);
    $pdf .= sprintf("%010d 00000 n \n", 239);
    $pdf .= sprintf("%010d 00000 n \n", 311);
    $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\n";
    $pdf .= "startxref\n$xrefOffset\n%%EOF";

    return $pdf;
}

$samplePdfText = "Jane Smith - Full Stack Developer\nEmail: jane.smith@example.com | Phone: +91 9876543210\nSummary: 6 years experience with PHP 8, Laravel, MySQL, REST API, React, Docker and AWS.";
file_put_contents($dir . '/sample_resume.pdf', buildPdf($samplePdfText));

// Empty stream PDF for scanned test
$emptyPdf = "%PDF-1.3\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R >>\nendobj\n4 0 obj\n<< /Length 0 >>\nstream\nendstream\nendobj\nxref\n0 5\n0000000000 65535 f \n0000000009 00000 n \n0000000058 00000 n \n0000000115 00000 n \n0000000204 00000 n \ntrailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n260\n%%EOF";
file_put_contents($dir . '/scanned_image_resume.pdf', $emptyPdf);

echo "PDF test files updated successfully.\n";
