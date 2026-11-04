<?php
/**
 * ====================================================================
 * کافینکس (Cafinex) — تست و اتصال ربات تلگرام (Telegram API Endpoint)
 * ====================================================================
 * انکودینگ: UTF-8 بدون BOM
 * 
 * متدها:
 * POST /api/telegram.php?action=test        -> ارسال پیام تست به مدیر
 * POST /api/telegram.php?action=set-webhook -> ثبت وب‌هوک ربات تلگرام
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// تست و تنظیمات وب‌هوک تلگرام مستلزم دسترسی ادمین است
require_admin();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'POST') {
    fail(405, 'فقط متد POST مجاز است.');
}

$action = trim((string) ($_GET['action'] ?? 'test'));
$in = get_request_body();
$db = get_db();

// استخراج توکن، چت‌آیدی و پراکسی از ورودی یا دیتابیس
$stmt = $db->query('SELECT setting_key, setting_value FROM settings WHERE setting_key IN ("telegram_bot_token", "telegram_chat_id", "telegram_proxy")');
$dbSet = [];
while ($row = $stmt->fetch()) {
    $dbSet[$row['setting_key']] = $row['setting_value'];
}

$token  = trim((string) ($in['token'] ?? $dbSet['telegram_bot_token'] ?? defined('TELEGRAM_BOT_TOKEN') ? TELEGRAM_BOT_TOKEN : ''));
$chatId = trim((string) ($in['chat_id'] ?? $dbSet['telegram_chat_id'] ?? defined('TELEGRAM_CHAT_ID') ? TELEGRAM_CHAT_ID : ''));
$proxy  = trim((string) ($in['proxy'] ?? $dbSet['telegram_proxy'] ?? defined('TELEGRAM_PROXY') ? TELEGRAM_PROXY : ''));

if ($token === '') {
    fail(422, 'توکن ربات تلگرام وارد نشده است.');
}

if ($action === 'test') {
    if ($chatId === '') {
        fail(422, 'شناسه چت (Chat ID) مدیر وارد نشده است.');
    }

    $time = date('H:i');
    $date = date('Y/m/d');
    $testText = "✅ <b>اتصال با موفقیت برقرار شد!</b>\n\nاین یک پیام آزمایشی از پنل مدیریت منوی دیجیتال کافینکس (Cafinex) است.\n🕒 زمان تست: {$time} — {$date}";

    $payload = [
        'chat_id'    => $chatId,
        'text'       => $testText,
        'parse_mode' => 'HTML',
    ];

    $res = call_telegram_api($token, 'sendMessage', $payload, $proxy);
    if ($res['ok'] ?? false) {
        ok(['message' => 'پیام تست با موفقیت به تلگرام مدیر ارسال شد!']);
    } else {
        $desc = $res['description'] ?? $res['error'] ?? 'خطای نامشخص در ارتباط با تلگرام';
        fail(502, 'ارسال پیام ناموفق بود: ' . $desc);
    }
}

if ($action === 'set-webhook') {
    $webhookUrl = trim((string) ($in['webhook_url'] ?? ''));
    if ($webhookUrl === '') {
        // تشخیص خودکار آدرس webhook.php
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir = dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/api/telegram.php'));
        $dir = rtrim($dir, '/\\');
        $webhookUrl = "{$proto}://{$host}{$dir}/webhook.php";
    }

    $res = call_telegram_api($token, 'setWebhook', ['url' => $webhookUrl], $proxy);
    if ($res['ok'] ?? false) {
        ok(['message' => 'وب‌هوک با موفقیت فعال شد!', 'webhook_url' => $webhookUrl]);
    } else {
        $desc = $res['description'] ?? $res['error'] ?? 'خطای فعال‌سازی وب‌هوک';
        fail(502, 'تنظیم وب‌هوک ناموفق بود: ' . $desc);
    }
}

fail(400, 'عملیات نامعتبر است.');

/**
 * تابع کمکی ارسال درخواست به API تلگرام با پشتیبانی از cURL و Proxy
 */
function call_telegram_api(string $token, string $method, array $params = [], string $proxy = ''): array
{
    $url = "https://api.telegram.org/bot{$token}/{$method}";

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json; charset=utf-8']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        if ($proxy !== '') {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
        }
        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['ok' => false, 'error' => $err ?: 'Network error connecting to Telegram API'];
        }
        $data = json_decode((string) $response, true);
        return is_array($data) ? $data : ['ok' => false, 'error' => 'Invalid JSON from Telegram'];
    }

    return ['ok' => false, 'error' => 'cURL extension is required on PHP server'];
}
