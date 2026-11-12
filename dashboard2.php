<?php
// Old route kept for bookmarks: the job hiring workspace now lives at dashboard.php.
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: dashboard.php' . ($qs !== '' ? '?' . $qs : ''), true, 301);
exit;
