<?php
/**
 * Built-in Web Server Router for Rohan Cafe Menu
 * Provides security, MIME types, and clean routing for Render, Railway, and local development.
 *
 * Usage: php -S 0.0.0.0:$PORT router.php
 */

$rawUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($rawUri, PHP_URL_PATH);
$path = urldecode($path);

// 1. SECURITY: Block direct access to database, hidden files, and configs
if (
    preg_match('/\.(sqlite|sqlite-shm|sqlite-wal|db|sql|log|yml|yaml|md|lock)$/i', $path) ||
    strpos($path, '/.') !== false ||
    preg_match('/(^|\/)(Dockerfile|\.git|\.htaccess|composer\.json|package\.json)/i', $path)
) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo '403 Forbidden: Direct access to this resource is prohibited.';
    exit;
}

$target = __DIR__ . $path;

// 2. ROOT or DIRECTORY: Serve index.html by default
if ($path === '/' || is_dir($target)) {
    $indexHtml = rtrim($target, '/') . '/index.html';
    if (file_exists($indexHtml)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($indexHtml);
        exit;
    }
}

// 3. CLEAN ALIASES (e.g. /admin -> admin.html)
if ($path === '/admin' || $path === '/admin/') {
    if (file_exists(__DIR__ . '/admin.html')) {
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . '/admin.html');
        exit;
    }
}

// 4. EXISTING STATIC FILES OR PHP SCRIPTS
if (file_exists($target) && !is_dir($target)) {
    $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));

    // If it's a PHP script, let PHP execute it
    if ($ext === 'php') {
        return false;
    }

    // Set correct MIME types for modern web assets
    $mimes = [
        'html'  => 'text/html; charset=utf-8',
        'css'   => 'text/css; charset=utf-8',
        'js'    => 'application/javascript; charset=utf-8',
        'json'  => 'application/json; charset=utf-8',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'webp'  => 'image/webp',
        'svg'   => 'image/svg+xml',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'ico'   => 'image/x-icon',
    ];

    if (isset($mimes[$ext])) {
        header('Content-Type: ' . $mimes[$ext]);
    }

    // Let the built-in server deliver the static file with proper headers/ETags
    return false;
}

// 5. EXTENSIONLESS PHP SCRIPTS (e.g. /api?path=/categories -> /api.php)
if (file_exists($target . '.php')) {
    include $target . '.php';
    exit;
}

// 6. 404 Not Found
http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html dir="rtl" lang="fa"><head><meta charset="utf-8"><title>۴۰۴ یافت نشد</title><style>body{background:#120b07;color:#dfcaa8;font-family:system-ui;text-align:center;padding:60px;}</style></head><body><h1>صفحه مورد نظر یافت نشد (404)</h1><p><a href="/" style="color:#f59e0b;text-decoration:none;">بازگشت به منوی اصلی</a></p></body></html>';
exit;
