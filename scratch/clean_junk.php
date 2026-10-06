<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/workspace.php';
require_once __DIR__ . '/../vendor/autoload.php';

$pdo = db();
$stmt = $pdo->query("SELECT id, stored_file FROM candidates WHERE email LIKE '%ashishd751%'");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $r) {
    $file = __DIR__ . '/../uploads/' . basename($r['stored_file']);
    if (file_exists($file)) {
        $parser = new Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($file);
        $rawText = $pdf->getText();
        
        $skills = extractDetectedSkills($rawText);
        $experience = analyzeExperienceTimeline($rawText, $skills);

        $pdo->prepare("UPDATE candidates SET experience_json=? WHERE id=?")
            ->execute([json_encode($experience), $r['id']]);
        
        echo "UPDATED {$r['id']} => Experience Roles: " . count($experience['jobs']) . " | Total Months: {$experience['total_months']} (" . round($experience['total_months']/12, 1) . " yrs)\n";
    }
}
