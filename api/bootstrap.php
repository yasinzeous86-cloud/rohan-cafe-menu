<?php
/**
 * ====================================================================
 * کافینکس (Cafinex) — هسته مشترک پردازش API و اعتبارسنجی
 * ====================================================================
 * انکودینگ: UTF-8 بدون BOM
 * سازگاری با PHP 7.4 تا 8.2+
 */

declare(strict_types=1);

// تنظیم هدر خروجی استاندارد JSON و UTF-8
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store');

// لود فایل پیکربندی مرکزی با مسیر قطعی
if (file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
}

if (!defined('ADMIN_PASSWORD')) {
    define('ADMIN_PASSWORD', 'admin123');
}
if (!defined('ADMIN_KEY')) {
    define('ADMIN_KEY', ADMIN_PASSWORD);
}
if (!defined('DB_FILE')) {
    define('DB_FILE', __DIR__ . '/../menu.sqlite');
}
if (!defined('CORS_ORIGIN')) {
    define('CORS_ORIGIN', '');
}

if (CORS_ORIGIN !== '') {
    header('Access-Control-Allow-Origin: ' . CORS_ORIGIN);
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Admin-Token, X-API-Key');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Vary: Origin');
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/**
 * اتصال پایگاه داده SQLite3
 */
function get_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dbFile = defined('DB_FILE') ? DB_FILE : __DIR__ . '/../menu.sqlite';
    $pdo = new PDO('sqlite:' . $dbFile, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 5,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');

    return $pdo;
}

/**
 * ارسال پاسخ استاندارد با فرمت JSON
 */
function json_response(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ok(array $data = []): void
{
    json_response(200, ['ok' => true] + $data);
}

function fail(int $status, string $message, array $extra = []): void
{
    json_response($status, ['ok' => false, 'error' => $message] + $extra);
}

/**
 * دریافت بدنه درخواست (JSON یا فرم)
 */
function get_request_body(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'multipart/form-data') !== false || !empty($_POST)) {
        return $_POST;
    }
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * اعتبارسنجی توکن ادمین در هدر یا پارامترها
 * مقایسه مستقیم با ADMIN_PASSWORD و ADMIN_KEY در config.php
 */
function require_admin(): void
{
    $authHeader = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';
    $token = '';

    // ۱. بررسی هدر استاندارد Authorization با یا بدون Bearer
    if (preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $m) === 1) {
        $token = trim($m[1]);
    } elseif (trim($authHeader) !== '') {
        $token = trim($authHeader);
    }

    // ۲. بررسی هدرهای اختصاصی X-Admin-Token یا X-API-Key
    if ($token === '') {
        $token = trim($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '');
    }

    // ۳. بررسی پارامتر ارسالی در درخواست (GET / POST)
    if ($token === '') {
        $token = trim((string)($_GET['admin_token'] ?? $_POST['admin_token'] ?? $_GET['admin_key'] ?? $_POST['admin_key'] ?? $_GET['token'] ?? $_POST['token'] ?? ''));
    }

    // مقدار معتبر مستقیماً از config.php خوانده می‌شود
    $expectedPassword = defined('ADMIN_PASSWORD') ? (string)ADMIN_PASSWORD : (defined('ADMIN_KEY') ? (string)ADMIN_KEY : 'admin123');

    if ($token === '' || !hash_equals($expectedPassword, $token)) {
        fail(401, 'خطای احراز هویت: توکن یا رمز عبور مدیر نامعتبر است (Unauthorized: Invalid or missing admin token).', [
            'hint' => 'رمز عبور پیش‌فرض admin123 است که در فایل config.php تعیین شده است.'
        ]);
    }
}
