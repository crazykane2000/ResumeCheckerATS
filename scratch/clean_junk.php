<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/workspace.php';

$pdo = db();
$pdo->exec("DELETE FROM candidates WHERE id='CAN-53ec4adc'");

$items = workspaceData('candidates.json', []);
$items = array_values(array_filter($items, function($item) {
    return ($item['id'] ?? '') !== 'CAN-53ec4adc';
}));
saveWorkspaceData('candidates.json', $items);

echo "Duplicate candidate CAN-53ec4adc deleted successfully.\n";
