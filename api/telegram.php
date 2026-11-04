<?php
/**
 * ====================================================================
 * کافینکس (Cafinex) — تست و اتصال ربات تلگرام و بله (Messenger API Endpoint)
 * ====================================================================
 * انکودینگ: UTF-8 بدون BOM
 * 
 * متدها:
 * POST /api/telegram.php?action=test        -> ارسال پیام تست به مدیر (تلگرام / بله)
 * POST /api/telegram.php?action=set-webhook -> ثبت وب‌هوک ربات (تلگرام / بله)
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// تست و تنظیمات وب‌هوک مستلزم دسترسی ادمین است
require_admin();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'POST') {
    fail(405, 'فقط متد POST مجاز است.');
}

$action = trim((string) ($_GET['action'] ?? 'test'));
$in = get_request_body();
$db = get_db();

// استخراج تنظیمات از دیتابیس
$stmt = $db->query('SELECT setting_key, setting_value FROM settings WHERE setting_key IN ("notification_service", "telegram_bot_token", "telegram_chat_id", "telegram_proxy", "bale_bot_token", "bale_chat_id")');
$dbSet = [];
while ($row = $stmt->fetch()) {
    $dbSet[$row['setting_key']] = $row['setting_value'];
}

$service = trim((string) ($in['service'] ?? $dbSet['notification_service'] ?? (defined('NOTIFICATION_SERVICE') ? NOTIFICATION_SERVICE : 'telegram')));
if (!in_array($service, ['telegram', 'bale'], true)) {
    $service = 'telegram';
}

if ($service === 'bale') {
    $token  = trim((string) ($in['token'] ?? $dbSet['bale_bot_token'] ?? $dbSet['telegram_bot_token'] ?? (defined('BALE_BOT_TOKEN') ? BALE_BOT_TOKEN : '')));
    $chatId = trim((string) ($in['chat_id'] ?? $dbSet['bale_chat_id'] ?? $dbSet['telegram_chat_id'] ?? (defined('BALE_CHAT_ID') ? BALE_CHAT_ID : '')));
    $proxy  = '';
} else {
    $token  = trim((string) ($in['token'] ?? $dbSet['telegram_bot_token'] ?? (defined('TELEGRAM_BOT_TOKEN') ? TELEGRAM_BOT_TOKEN : '')));
    $chatId = trim((string) ($in['chat_id'] ?? $dbSet['telegram_chat_id'] ?? (defined('TELEGRAM_CHAT_ID') ? TELEGRAM_CHAT_ID : '')));
    $proxy  = trim((string) ($in['proxy'] ?? $dbSet['telegram_proxy'] ?? (defined('TELEGRAM_PROXY') ? TELEGRAM_PROXY : '')));
}

$serviceTitle = ($service === 'bale') ? 'پیام‌رسان بله' : 'ربات تلگرام';

if ($token === '') {
    fail(422, "توکن ربات {$serviceTitle} وارد نشده است.");
}

if ($action === 'test') {
    if ($chatId === '') {
        fail(422, "شناسه چت (Chat ID) مدیر در {$serviceTitle} وارد نشده است.");
    }

    $time = date('H:i');
    $date = date('Y/m/d');
    $testText = "✅ <b>اتصال با موفقیت برقرار شد!</b>\n\nاین یک پیام آزمایشی از پنل مدیریت منوی دیجیتال کافینکس (Cafinex) در <b>{$serviceTitle}</b> است.\n🕒 زمان تست: {$time} — {$date}";

    $payload = [
        'chat_id'    => $chatId,
        'text'       => $testText,
        'parse_mode' => 'HTML',
    ];

    $res = call_messenger_api($service, $token, 'sendMessage', $payload, $proxy);
    if ($res['ok'] ?? false) {
        ok(['message' => "پیام تست با موفقیت به {$serviceTitle} مدیر ارسال شد!"]);
    } else {
        $desc = $res['error'] ?? 'خطای نامشخص در ارتباط با سرور';
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

    $res = call_messenger_api($service, $token, 'setWebhook', ['url' => $webhookUrl], $proxy);
    if ($res['ok'] ?? false) {
        ok(['message' => "وب‌هوک با موفقیت در سرورهای {$serviceTitle} فعال شد!", 'webhook_url' => $webhookUrl]);
    } else {
        $desc = $res['error'] ?? 'خطای فعال‌سازی وب‌هوک';
        fail(502, "تنظیم وب‌هوک {$serviceTitle} ناموفق بود: " . $desc);
    }
}

fail(400, 'عملیات نامعتبر است.');

/**
 * تابع کمکی ارسال درخواست به API تلگرام یا بله با پشتیبانی از cURL و Proxy
 */
function call_messenger_api(string $service, string $token, string $method, array $params = [], string $proxy = ''): array
{
    $service = strtolower(trim($service));
    if ($service === 'bale') {
        $url = "https://tapi.bale.ai/bot{$token}/{$method}";
        $proxy = '';
    } else {
        $url = "https://api.telegram.org/bot{$token}/{$method}";
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json; charset=utf-8']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        if ($service === 'telegram' && $proxy !== '') {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
            if (stripos($proxy, 'socks5') === 0) {
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
            } elseif (stripos($proxy, 'http') === 0) {
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
            }
        }
        $response = curl_exec($ch);
        $err = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0 || $response === false) {
            $msg = format_curl_error($errno, $err, $service);
            return ['ok' => false, 'error' => $msg, 'raw_curl_error' => $err];
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'پاسخ دریافتی در قالب JSON معتبر نیست.'];
        }

        if (empty($data['ok'])) {
            $desc = (string) ($data['description'] ?? $data['error'] ?? 'خطای نامشخص');
            $persianDesc = format_messenger_error($desc, $service);
            return ['ok' => false, 'error' => $persianDesc, 'raw_description' => $desc];
        }

        return $data;
    }

    return ['ok' => false, 'error' => 'ماژول cURL در سرور PHP فعال نیست.'];
}

function format_curl_error(int $errno, string $rawErr, string $service): string
{
    $serviceName = ($service === 'bale') ? 'پیام‌رسان بله' : 'تلگرام';

    switch ($errno) {
        case 28:
            if ($service === 'telegram') {
                return 'خطای مهلت زمانی (Timeout): ارتباط با تلگرام برقرار نشد. پیشنهاد می‌شود از پروکسی استفاده نمایید یا سرویس را روی «پیام‌رسان بله» قرار دهید.';
            }
            return 'خطای مهلت زمانی (Timeout): ارتباط با سرور بله برقرار نشد.';
        case 6:
            return "خطای DNS: دامنه سرور {$serviceName} شناسایی نشد.";
        case 7:
            if ($service === 'telegram') {
                return 'ارتباط با پورت تلگرام برقرار نشد. پورت مسدود است؛ از پیام‌رسان بله یا پروکسی استفاده فرمایید.';
            }
            return 'ارتباط با سرور بله برقرار نشد.';
        case 35:
            return "خطای امنیتی SSL در ارتباط با {$serviceName}.";
        default:
            return "خطای شبکه ({$errno}) در ارتباط با {$serviceName}: {$rawErr}";
    }
}

function format_messenger_error(string $desc, string $service): string
{
    $serviceName = ($service === 'bale') ? 'بله' : 'تلگرام';

    if (stripos($desc, 'Not Found') !== false || stripos($desc, 'Unauthorized') !== false) {
        return "توکن ربات {$serviceName} نامعتبر است. لطفاً توکن BotFather را بررسی فرمایید.";
    }
    if (stripos($desc, 'chat not found') !== false) {
        return "شناسه چت یافت نشد. مدیر محترم ابتدا باید دکمه /start را در ربات بزند تا ربات مجاز به ارسال پیام باشد.";
    }
    if (stripos($desc, 'bot was blocked by the user') !== false) {
        return "ربات توسط کاربر در چت بلاک شده است.";
    }
    return "خطای سرور {$serviceName}: {$desc}";
}
