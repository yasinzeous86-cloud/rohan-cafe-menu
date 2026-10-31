<?php
/**
 * Telegram Bot Webhook Receiver — Rohan Cafe Menu
 *
 * Receives incoming updates from Telegram Bot API, handles admin commands,
 * interactive menus, order status changes and menu stock management.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');

const DB_FILE = __DIR__ . '/menu.sqlite';

if (!file_exists(DB_FILE)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database not found']);
    exit;
}

try {
    $db = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    @$db->exec('PRAGMA journal_mode = WAL');
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database connection failed']);
    exit;
}

// Fetch all settings
$settings = [];
foreach ($db->query('SELECT setting_key, setting_value FROM settings') as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$botToken = trim((string) ($settings['telegram_bot_token'] ?? ''));
$adminChatId = trim((string) ($settings['telegram_chat_id'] ?? ''));
$currency = $settings['currency'] ?? 'تومان';

// If bot token is not configured yet
if ($botToken === '') {
    echo json_encode(['ok' => false, 'error' => 'Telegram Bot Token not configured in settings']);
    exit;
}

// Read raw incoming update from Telegram
$rawInput = file_get_contents('php://input');
if ($rawInput === false || trim($rawInput) === '') {
    // Healthcheck ping
    echo json_encode(['ok' => true, 'service' => 'Rohan Cafe Telegram Webhook Active']);
    exit;
}

$update = json_decode($rawInput, true);
if (!is_array($update)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

/**
 * Send request to Telegram Bot API
 */
function tg_call(string $botToken, string $method, array $params = []): array
{
    $url = "https://api.telegram.org/bot{$botToken}/{$method}";

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        return is_string($res) ? (json_decode($res, true) ?: []) : [];
    }

    $opts = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json; charset=utf-8\r\n",
            'content' => json_encode($params, JSON_UNESCAPED_UNICODE),
            'timeout' => 6,
        ],
    ];
    $res = @file_get_contents($url, false, stream_context_create($opts));
    return is_string($res) ? (json_decode($res, true) ?: []) : [];
}

/**
 * Main persistent ReplyKeyboardMarkup
 */
function get_main_keyboard(): array
{
    return [
        'keyboard' => [
            [['text' => '📦 سفارشات فعال'], ['text' => '📊 گزارش فروش امروز']],
            [['text' => '🍔 مدیریت آیتم‌ها'], ['text' => '💬 بررسی نظرات']],
            [['text' => '🔔 تنظیمات نوتیفیکیشن']],
        ],
        'resize_keyboard' => true,
        'is_persistent'   => true,
    ];
}

// ─────────────────────────────────────────────────────────────────
// Handle Callback Queries (Inline button clicks)
// ─────────────────────────────────────────────────────────────────
if (isset($update['callback_query'])) {
    $cb       = $update['callback_query'];
    $cbId     = (string) ($cb['id'] ?? '');
    $cbData   = (string) ($cb['data'] ?? '');
    $chatId   = (string) ($cb['message']['chat']['id'] ?? '');
    $msgId    = (int) ($cb['message']['message_id'] ?? 0);
    $origText = (string) ($cb['message']['text'] ?? '');

    // 1. Order Completion
    if (strpos($cbData, 'order_complete_') === 0) {
        $orderId = (int) substr($cbData, strlen('order_complete_'));
        $db->prepare('UPDATE orders SET status = "completed", updated_at = datetime("now", "localtime") WHERE id = ?')
           ->execute([$orderId]);

        tg_call($botToken, 'answerCallbackQuery', [
            'callback_query_id' => $cbId,
            'text'              => "سفارش #{$orderId} تکمیل و تحویل شد ✅",
            'show_alert'        => false,
        ]);

        $updatedText = $origText . "\n\n━━━━━━━━━━━━━━━━\n✅ <b>این سفارش توسط مدیر تکمیل و تحویل گردید.</b>";
        tg_call($botToken, 'editMessageText', [
            'chat_id'    => $chatId,
            'message_id' => $msgId,
            'text'       => $updatedText,
            'parse_mode' => 'HTML',
        ]);
        exit;
    }

    // 2. Order Cancellation
    if (strpos($cbData, 'order_cancel_') === 0) {
        $orderId = (int) substr($cbData, strlen('order_cancel_'));
        $db->prepare('UPDATE orders SET status = "cancelled", updated_at = datetime("now", "localtime") WHERE id = ?')
           ->execute([$orderId]);

        tg_call($botToken, 'answerCallbackQuery', [
            'callback_query_id' => $cbId,
            'text'              => "سفارش #{$orderId} لغو شد ❌",
            'show_alert'        => false,
        ]);

        $updatedText = $origText . "\n\n━━━━━━━━━━━━━━━━\n❌ <b>این سفارش لغو گردید.</b>";
        tg_call($botToken, 'editMessageText', [
            'chat_id'    => $chatId,
            'message_id' => $msgId,
            'text'       => $updatedText,
            'parse_mode' => 'HTML',
        ]);
        exit;
    }

    // 3. Item Stock Toggle (available <-> sold_out)
    if (strpos($cbData, 'item_toggle_') === 0) {
        $itemId = (int) substr($cbData, strlen('item_toggle_'));
        $stmt = $db->prepare('SELECT id, name, status FROM items WHERE id = ?');
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();

        if ($item) {
            $newStatus = ($item['status'] === 'available') ? 'sold_out' : 'available';
            $db->prepare('UPDATE items SET status = ?, updated_at = datetime("now", "localtime") WHERE id = ?')
               ->execute([$newStatus, $itemId]);

            $statusFa = ($newStatus === 'available') ? 'موجود شد ✅' : 'ناموجود شد ❌';
            tg_call($botToken, 'answerCallbackQuery', [
                'callback_query_id' => $cbId,
                'text'              => "«{$item['name']}» {$statusFa}",
                'show_alert'        => false,
            ]);

            // Rebuild item keyboard
            $items = $db->query('SELECT id, name, price, status FROM items WHERE status != "hidden" ORDER BY id LIMIT 10')->fetchAll();
            $kb = [];
            foreach ($items as $it) {
                $icon = ($it['status'] === 'available') ? '✅ موجود' : '❌ تمام شد';
                $kb[] = [[
                    'text'          => "{$it['name']} — {$icon}",
                    'callback_data' => "item_toggle_{$it['id']}",
                ]];
            }
            tg_call($botToken, 'editMessageReplyMarkup', [
                'chat_id'      => $chatId,
                'message_id'   => $msgId,
                'reply_markup' => ['inline_keyboard' => $kb],
            ]);
        }
        exit;
    }
}

// ─────────────────────────────────────────────────────────────────
// Handle Text Messages & Commands
// ─────────────────────────────────────────────────────────────────
if (isset($update['message'])) {
    $msg    = $update['message'];
    $chatId = (string) ($msg['chat']['id'] ?? '');
    $text   = trim((string) ($msg['text'] ?? ''));

    // Security Gate: verify admin chat ID if configured
    if ($adminChatId !== '' && $chatId !== $adminChatId) {
        tg_call($botToken, 'sendMessage', [
            'chat_id'    => $chatId,
            'text'       => "⛔️ <b>دسترسی غیرمجاز!</b>\nاین ربات اختصاصی کافه رُهان است.\nشناسه چت شما: <code>{$chatId}</code>",
            'parse_mode' => 'HTML',
        ]);
        exit;
    }

    // Auto-bind chat ID if first time /start from admin
    if ($adminChatId === '' && $text === '/start') {
        $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES ("telegram_chat_id", ?) ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value')
           ->execute([$chatId]);
        $adminChatId = $chatId;
    }

    // 1. /start or Help
    if ($text === '/start' || $text === 'منو') {
        $shopName = $settings['shop_name'] ?? 'کافه و بار رُهان';
        $welcome = "☕️ <b>سلام به پنل هوشمند مدیریت {$shopName} خوش آمدید!</b>\n\n" .
                   "از طریق این ربات می‌توانید به سادگی سفارشات آنلاین مشتریان را دریافت و پیگیری کنید، گزارش فروش لحظه‌ای بگیرید و موجودی آیتم‌های منو را مدیریت نمایید.\n\n" .
                   "👇 <b>یکی از گزینه‌های زیر را انتخاب نمایید:</b>";

        tg_call($botToken, 'sendMessage', [
            'chat_id'      => $chatId,
            'text'         => $welcome,
            'parse_mode'   => 'HTML',
            'reply_markup' => get_main_keyboard(),
        ]);
        exit;
    }

    // 2. 📦 سفارشات فعال
    if ($text === '📦 سفارشات فعال') {
        $stmt = $db->query('SELECT id, table_number, items_json, total_price, customer_note, created_at 
                            FROM orders WHERE status = "pending" ORDER BY id DESC LIMIT 10');
        $orders = $stmt->fetchAll();

        if (empty($orders)) {
            tg_call($botToken, 'sendMessage', [
                'chat_id'      => $chatId,
                'text'         => "✅ <b>هیچ سفارش فعالی در صف انتظار نیست!</b>\nتمامی سفارش‌های مشتریان آماده و تحویل گردیده است.",
                'parse_mode'   => 'HTML',
                'reply_markup' => get_main_keyboard(),
            ]);
            exit;
        }

        tg_call($botToken, 'sendMessage', [
            'chat_id'    => $chatId,
            'text'       => "📦 <b>تعداد " . count($orders) . " سفارش فعال در صف انتظار است:</b>",
            'parse_mode' => 'HTML',
        ]);

        foreach ($orders as $ord) {
            $items = json_decode((string) $ord['items_json'], true) ?: [];
            $itemsText = '';
            foreach ($items as $it) {
                $qty = (int) ($it['qty'] ?? 1);
                $name = htmlspecialchars((string) ($it['name'] ?? ''), ENT_QUOTES, 'UTF-8');
                $itemsText .= "▫️ <b>{$qty}×</b> {$name}\n";
            }

            $formattedTotal = number_format((float) $ord['total_price']) . ' ' . $currency;
            $table = htmlspecialchars((string) $ord['table_number'], ENT_QUOTES, 'UTF-8');
            $time = htmlspecialchars((string) $ord['created_at'], ENT_QUOTES, 'UTF-8');

            $ordMsg  = "🔖 <b>سفارش #{$ord['id']} — {$table}</b>\n";
            $ordMsg .= "🕒 زمان ثبت: <code>{$time}</code>\n";
            $ordMsg .= "💰 مبلغ فاکتور: <b>{$formattedTotal}</b>\n\n";
            $ordMsg .= "📋 <b>اقلام سفارش:</b>\n{$itemsText}";

            if (!empty($ord['customer_note'])) {
                $note = htmlspecialchars((string) $ord['customer_note'], ENT_QUOTES, 'UTF-8');
                $ordMsg .= "\n📝 <i>یادداشت: {$note}</i>";
            }

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '✅ تکمیل و تحویل', 'callback_data' => "order_complete_{$ord['id']}"],
                        ['text' => '❌ لغو سفارش',     'callback_data' => "order_cancel_{$ord['id']}"],
                    ],
                ],
            ];

            tg_call($botToken, 'sendMessage', [
                'chat_id'      => $chatId,
                'text'         => $ordMsg,
                'parse_mode'   => 'HTML',
                'reply_markup' => $keyboard,
            ]);
        }
        exit;
    }

    // 3. 📊 گزارش فروش امروز
    if ($text === '📊 گزارش فروش امروز') {
        $today = date('Y-m-d');
        
        // Completed orders stats
        $stmt = $db->prepare('SELECT COUNT(*) as cnt, COALESCE(SUM(total_price), 0) as total FROM orders WHERE date(created_at) = ? AND status = "completed"');
        $stmt->execute([$today]);
        $comp = $stmt->fetch();
        $compCount = (int) ($comp['cnt'] ?? 0);
        $compTotal = (float) ($comp['total'] ?? 0);

        // Pending orders stats
        $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM orders WHERE date(created_at) = ? AND status = "pending"');
        $stmt->execute([$today]);
        $pendCount = (int) ($stmt->fetch()['cnt'] ?? 0);

        // Cancelled orders stats
        $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM orders WHERE date(created_at) = ? AND status = "cancelled"');
        $stmt->execute([$today]);
        $cancCount = (int) ($stmt->fetch()['cnt'] ?? 0);

        $dateFa = date('Y/m/d');
        $reportMsg  = "📊 <b>گزارش فروش و وضعیت سفارشات امروز:</b>\n";
        $reportMsg .= "📅 تاریخ: <code>{$dateFa}</code>\n\n";
        $reportMsg .= "💰 <b>مجموع فروش قطعی:</b> <code>" . number_format($compTotal) . " {$currency}</code>\n";
        $reportMsg .= "✅ <b>سفارشات تکمیل و تحویل‌شده:</b> <b>{$compCount}</b> سفارش\n";
        $reportMsg .= "⏳ <b>سفارشات فعال در انتظار:</b> <b>{$pendCount}</b> سفارش\n";
        $reportMsg .= "❌ <b>سفارشات لغو شده:</b> <b>{$cancCount}</b> سفارش\n";
        $reportMsg .= "📈 <b>کل فاکتورهای ثبت‌شده:</b> " . ($compCount + $pendCount + $cancCount) . " مورد\n\n";
        $reportMsg .= "☕️ <i>کافه و بار رُهان</i>";

        tg_call($botToken, 'sendMessage', [
            'chat_id'      => $chatId,
            'text'         => $reportMsg,
            'parse_mode'   => 'HTML',
            'reply_markup' => get_main_keyboard(),
        ]);
        exit;
    }

    // 4. 🍔 مدیریت آیتم‌ها
    if ($text === '🍔 مدیریت آیتم‌ها' || $text === '🍔 مدیریت آیتمها') {
        $items = $db->query('SELECT id, name, price, status FROM items WHERE status != "hidden" ORDER BY id LIMIT 12')->fetchAll();

        if (empty($items)) {
            tg_call($botToken, 'sendMessage', [
                'chat_id'      => $chatId,
                'text'         => "آیتمی برای مدیریت یافت نشد.",
                'reply_markup' => get_main_keyboard(),
            ]);
            exit;
        }

        $kb = [];
        foreach ($items as $it) {
            $icon = ($it['status'] === 'available') ? '✅ موجود' : '❌ تمام شد';
            $kb[] = [[
                'text'          => "{$it['name']} — {$icon}",
                'callback_data' => "item_toggle_{$it['id']}",
            ]];
        }

        $msgText = "🍔 <b>مدیریت موجودی آیتم‌های منو:</b>\n" .
                   "برای تغییر وضعیت موجودی (موجود / ناموجود) بر روی هر آیتم کلیک کنید. تغییرات بلافاصله در منوی زنده مشتریان اعمال می‌شود:";

        tg_call($botToken, 'sendMessage', [
            'chat_id'      => $chatId,
            'text'         => $msgText,
            'parse_mode'   => 'HTML',
            'reply_markup' => ['inline_keyboard' => $kb],
        ]);
        exit;
    }

    // 5. 💬 بررسی نظرات
    if ($text === '💬 بررسی نظرات') {
        $rawReviews = $settings['reviews'] ?? '[]';
        $reviews = json_decode($rawReviews, true) ?: [];

        if (empty($reviews)) {
            tg_call($botToken, 'sendMessage', [
                'chat_id'      => $chatId,
                'text'         => "💬 هیچ نظری در سیستم ثبت نشده است.",
                'reply_markup' => get_main_keyboard(),
            ]);
            exit;
        }

        $revMsg = "💬 <b>نظرات ثبت‌شده‌ی مشتریان:</b>\n\n";
        foreach ($reviews as $idx => $r) {
            $stars = str_repeat('⭐', (int) ($r['stars'] ?? 5));
            $author = htmlspecialchars((string) ($r['author'] ?? ''), ENT_QUOTES, 'UTF-8');
            $tag = htmlspecialchars((string) ($r['tag'] ?? ''), ENT_QUOTES, 'UTF-8');
            $txt = htmlspecialchars((string) ($r['text'] ?? ''), ENT_QUOTES, 'UTF-8');

            $revMsg .= "{$stars} <b>{$author}</b> ({$tag})\n{$txt}\n\n";
        }

        tg_call($botToken, 'sendMessage', [
            'chat_id'      => $chatId,
            'text'         => $revMsg,
            'parse_mode'   => 'HTML',
            'reply_markup' => get_main_keyboard(),
        ]);
        exit;
    }

    // 6. 🔔 تنظیمات نوتیفیکیشن
    if ($text === '🔔 تنظیمات نوتیفیکیشن') {
        $current = ($settings['telegram_notifications_enabled'] ?? '1') !== '0';
        $newVal = $current ? '0' : '1';

        $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES ("telegram_notifications_enabled", ?) ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value')
           ->execute([$newVal]);

        $statusText = ($newVal === '1')
            ? "🔔 <b>ارسال نوتیفیکیشن سفارشات جدید فعال شد ✅</b>\nاز این پس سفارش‌های ثبت‌شده بلافاصله در تلگرام به شما پیام داده خواهند شد."
            : "🔕 <b>ارسال نوتیفیکیشن سفارشات جدید غیرفعال شد ❌</b>\nسفارش‌ها ثبت می‌شوند اما هشداری به تلگرام ارسال نخواهد شد.";

        tg_call($botToken, 'sendMessage', [
            'chat_id'      => $chatId,
            'text'         => $statusText,
            'parse_mode'   => 'HTML',
            'reply_markup' => get_main_keyboard(),
        ]);
        exit;
    }

    // Default reply
    tg_call($botToken, 'sendMessage', [
        'chat_id'      => $chatId,
        'text'         => "دستور متوجه نشدم. لطفاً از گزینه‌های منوی زیر استفاده فرمایید:",
        'reply_markup' => get_main_keyboard(),
    ]);
}

echo json_encode(['ok' => true]);
