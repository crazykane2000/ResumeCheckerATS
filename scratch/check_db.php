<?php
require __DIR__ . '/../config/database.php';
$pdo = db();
$stmt = $pdo->query("SELECT * FROM candidates ORDER BY id DESC LIMIT 5");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Total candidates in DB: " . count($rows) . "\n";
print_r($rows);
