<?php
$dir = __DIR__ . '/test_samples';
if (!file_exists($dir)) {
    mkdir($dir, 0777, true);
}

// 1. Create a valid sample DOCX
$zip = new ZipArchive();
$docxPath = $dir . '/sample_resume.docx';
if ($zip->open($docxPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
    $xmlContent = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">' .
        '<w:body>' .
        '<w:p><w:r><w:t>John Doe - Senior Software Engineer</w:t></w:r></w:p>' .
        '<w:p><w:r><w:t>Email: john.doe@example.com | Phone: +91 9876543210 | Location: Bengaluru, India</w:t></w:r></w:p>' .
        '<w:p><w:r><w:t>Summary: 5+ years of experience building scalable web applications. Proficient in PHP 8, Laravel PHP, MySQL, JavaScript, React.js, REST API, Git, Docker and AWS.</w:t></w:r></w:p>' .
        '<w:p><w:r><w:t>Experience: Tech Lead at Acme Corp (2021 - Present). Senior PHP Developer at TechSolutions (2018 - 2021).</w:t></w:r></w:p>' .
        '<w:p><w:r><w:t>Education: B.Tech in Computer Science from VTU (2018).</w:t></w:r></w:p>' .
        '</w:body></w:document>';
    $zip->addFromString('word/document.xml', $xmlContent);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $zip->close();
    echo "Created: $docxPath\n";
}

// 2. Create a simple PDF using TCPDF or basic PDF structure or FPDF if available, or write raw PDF object
// Let's create a minimal valid PDF 1.4 with readable text stream
$pdfContent = "%PDF-1.4\n" .
"1 0 obj <</Type /Catalog /Pages 2 0 R>> endobj\n" .
"2 0 obj <</Type /Pages /Kids [3 0 R] /Count 1>> endobj\n" .
"3 0 obj <</Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources <</Font <</F1 5 0 R>>>> >> endobj\n" .
"4 0 obj <</Length 280>> stream\n" .
"BT\n" .
"/F1 12 Tf\n" .
"50 750 Td (Jane Smith - Lead Full Stack Engineer) Tj\n" .
"0 -20 Td (Email: jane.smith@techcorp.io | Phone: +1 555-019-2834) Tj\n" .
"0 -20 Td (Skills: PHP, Laravel, MySQL, JavaScript, HTML5, CSS3, REST API, Git, Docker) Tj\n" .
"0 -20 Td (Experience: 6 years of experience in full stack software development.) Tj\n" .
"ET\n" .
"endstream\n" .
"endobj\n" .
"5 0 obj <</Type /Font /Subtype /Type1 /BaseFont /Helvetica>> endobj\n" .
"xref\n" .
"0 6\n" .
"0000000000 65535 f \n" .
"0000000009 00000 n \n" .
"0000000056 00000 n \n" .
"0000000111 00000 n \n" .
"0000000224 00000 n \n" .
"0000000553 00000 n \n" .
"trailer <</Size 6 /Root 1 0 R>>\n" .
"startxref\n" .
"625\n" .
"%%EOF\n";

$pdfPath = $dir . '/sample_resume.pdf';
file_put_contents($pdfPath, $pdfContent);
echo "Created: $pdfPath\n";

// 3. Create a scanned (image-only / empty text) PDF to test OCR flag detection
$scannedPdfContent = "%PDF-1.4\n" .
"1 0 obj <</Type /Catalog /Pages 2 0 R>> endobj\n" .
"2 0 obj <</Type /Pages /Kids [3 0 R] /Count 1>> endobj\n" .
"3 0 obj <</Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R>> endobj\n" .
"4 0 obj <</Length 0>> stream\n" .
"endstream\n" .
"endobj\n" .
"xref\n" .
"0 5\n" .
"0000000000 65535 f \n" .
"0000000009 00000 n \n" .
"0000000056 00000 n \n" .
"0000000111 00000 n \n" .
"0000000204 00000 n \n" .
"trailer <</Size 5 /Root 1 0 R>>\n" .
"startxref\n" .
"255\n" .
"%%EOF\n";

$scannedPdfPath = $dir . '/scanned_image_resume.pdf';
file_put_contents($scannedPdfPath, $scannedPdfContent);
echo "Created: $scannedPdfPath\n";

// 4. Create a dummy legacy DOC file for testing DOC fallback handling
$docPath = $dir . '/sample_legacy.doc';
file_put_contents($docPath, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1 binary legacy doc format placeholder");
echo "Created: $docPath\n";
