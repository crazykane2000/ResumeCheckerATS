<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/workspace.php';

$pdo = db();
$stmt = $pdo->prepare("DELETE FROM candidates WHERE name LIKE '%Unknown%' OR email IS NULL OR email = '' OR email = 'No email'");
$stmt->execute();
$deletedDb = $stmt->rowCount();

$items = workspaceData('candidates.json', []);
$clean = array_values(array_filter($items, function($c) {
    $name = strtolower($c['name'] ?? '');
    $email = trim((string)($c['email'] ?? ''));
    if (str_contains($name, 'unknown') || $email === '' || $email === 'no email') {
        return false;
    }
    return true;
}));
saveWorkspaceData('candidates.json', $clean);

echo "Deleted DB records: $deletedDb | Remaining valid candidate records: " . count($clean) . "\n";
