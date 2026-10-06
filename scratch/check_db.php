<?php
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../vendor/autoload.php';

$pdo = db();
$stmt = $pdo->query("SELECT stored_file FROM candidates WHERE email LIKE '%ashishd751%' LIMIT 1");
$r = $stmt->fetch(PDO::FETCH_ASSOC);
$file = __DIR__ . '/../uploads/' . basename($r['stored_file']);

$parser = new Smalot\PdfParser\Parser();
$pdf = $parser->parseFile($file);
$rawText = $pdf->getText();

echo "=== RAW TEXT ===\n";
echo $rawText;
