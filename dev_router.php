<?php
// Local development router for the PHP built-in server, which ignores .htaccess:
//   php -S 127.0.0.1:8001 dev_router.php
// It mirrors uploads/.htaccess: candidate documents are never served directly
// (use the authenticated resume_file.php route); only branding images are public.
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$uploads = realpath(__DIR__ . '/uploads');
$branding = realpath(__DIR__ . '/uploads/branding');
$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$target = realpath(__DIR__ . '/' . ltrim($path, '/'));

$inside = static fn(string|false $file, string|false $dir): bool => $file !== false && $dir !== false
    && (strcasecmp($file, $dir) === 0 || stripos($file, $dir . DIRECTORY_SEPARATOR) === 0);

// Mirror the root .htaccess deny list for internal folders (sessions, encryption key, scripts, ...).
$segmentOf = static fn(string $p): string => strtolower(explode('/', trim(str_replace('\\', '/', $p), '/'))[0] ?? '');
$root = realpath(__DIR__);
$resolvedSegment = ($target !== false && $root !== false && stripos($target, $root . DIRECTORY_SEPARATOR) === 0)
    ? $segmentOf(substr($target, strlen($root) + 1)) : '';
$denied = ['config', 'database', 'docs', 'scripts', 'test_samples', 'data', 'tmp', 'vendor', 'scratch', 'deploy', 'migrations'];
if (in_array($segmentOf($path), $denied, true) || in_array($resolvedSegment, $denied, true) ||preg_match('/(?:^|\/)\.(?:env|git)|\.(?:sql|log|ini|md|lock)$/i', $path)) {
    http_response_code(403);
    exit('Forbidden');
}

if ($inside($target, $uploads)) {
    $isBrandImage = $inside($target, $branding)
        && is_file($target)
        && preg_match('/^[A-Za-z0-9._-]+\.(?:png|jpe?g|webp)$/i', basename($target));
    if (!$isBrandImage) {
        http_response_code(403);
        exit('Forbidden');
    }
}

return false;
