<?php
/**
 * ====================================================================
 * کافینکس (Cafinex) — مدیریت سفارشات (Orders API Endpoint)
 * ====================================================================
 * انکودینگ: UTF-8 بدون BOM
 * 
 * متدها:
 * POST   /api/orders.php         -> ثبت سفارش جدید توسط مشتری (عمومی)
 * GET    /api/orders.php         -> لیست سفارشات (نیازمند احراز هویت ادمین)
 * PUT    /api/orders.php?id={id} -> تغییر وضعیت سفارش (نیازمند احراز هویت ادمین)
 * DELETE /api/orders.php?id={id} -> حذف سفارش (نیازمند احراز هویت ادمین)
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'POST') {
    $override = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? $_POST['_method'] ?? '';
    if (in_array(strtoupper((string) $override), ['PUT', 'DELETE', 'PATCH'], true)) {
        $method = strtoupper((string) $override);
    }
}

$db = get_db();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;

// ۱. ثبت سفارش توسط مشتری (عمومی)
if ($method === 'POST' && $id === null) {
    // بررسی وضعیت فعال بودن سفارش‌گیری
    $stmt = $db->prepare('SELECT setting_value FROM settings WHERE setting_key = "orders_enabled"');
    $stmt->execute();
    $ordersEnabled = $stmt->fetchColumn();
    if ($ordersEnabled === '0') {
        fail(403, 'امکان ثبت سفارش آنلاین در حال حاضر غیرفعال است (حالت کاتالوگ).');
    }

    $in = get_request_body();
    $tableNumber = trim((string) ($in['table_number'] ?? 'میز عمومی'));
    $items = $in['items'] ?? [];
    $note = trim((string) ($in['customer_note'] ?? ''));

    if (!is_array($items) || empty($items)) {
        fail(422, 'سبد سفارش خالی است. لطفاً حداقل یک آیتم انتخاب نمایید.');
    }

    $sanitizedItems = [];
    $totalPrice = 0.0;

    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $name = trim((string) ($item['name'] ?? ''));
        $qty  = max(1, (int) ($item['qty'] ?? 1));
        $price= max(0.0, (float) ($item['price'] ?? 0));
        if ($name === '') continue;

        $sanitizedItems[] = [
            'name'  => $name,
            'qty'   => $qty,
            'price' => $price,
        ];
        $totalPrice += ($price * $qty);
    }

    if (empty($sanitizedItems)) {
        fail(422, 'اقلام ارسالی نامعتبر است.');
    }

    $itemsJson = json_encode($sanitizedItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $db->prepare('INSERT INTO orders (table_number, items_json, total_price, status, customer_note) VALUES (?, ?, ?, "pending", ?)');
    $stmt->execute([$tableNumber, $itemsJson, $totalPrice, $note]);
    $orderId = (int) $db->lastInsertId();

    // ارسال نوتیفیکیشن تلگرام در صورت فعال بودن
    try {
        send_telegram_order_notice($db, $orderId, $tableNumber, $sanitizedItems, $totalPrice, $note);
    } catch (Throwable $e) {
        // عدم توقف سفارش در صورت بروز خطای شبکه تلگرام
    }

    ok([
        'order_id'    => $orderId,
        'message'     => 'سفارش شما با موفقیت ثبت شد و به باریستا ارسال گردید.',
        'total_price' => $totalPrice,
    ]);
}

// برای کلیه عملیات‌های زیر، احراز هویت ادمین الزامی است
require_admin();

// ۲. دریافت لیست سفارشات (مدیر)
if ($method === 'GET') {
    $statusFilter = trim((string) ($_GET['status'] ?? ''));
    if ($statusFilter !== '' && in_array($statusFilter, ['pending', 'completed', 'cancelled'], true)) {
        $stmt = $db->prepare('SELECT * FROM orders WHERE status = ? ORDER BY id DESC LIMIT 100');
        $stmt->execute([$statusFilter]);
        $orders = $stmt->fetchAll();
    } else {
        $orders = $db->query('SELECT * FROM orders ORDER BY id DESC LIMIT 100')->fetchAll();
    }

    foreach ($orders as &$o) {
        $o['id'] = (int) $o['id'];
        $o['total_price'] = (float) $o['total_price'];
        $o['items'] = json_decode((string) $o['items_json'], true) ?: [];
    }
    unset($o);

    ok(['orders' => $orders, 'total' => count($orders)]);
}

// ۳. تغییر وضعیت سفارش (مدیر)
if ($method === 'PUT') {
    if ($id === null || $id <= 0) {
        fail(422, 'شناسه سفارش الزامی است.');
    }
    $in = get_request_body();
    $newStatus = trim((string) ($in['status'] ?? ''));

    if (!in_array($newStatus, ['pending', 'completed', 'cancelled'], true)) {
        fail(422, 'وضعیت نامعتبر است (مقادیر مجاز: pending, completed, cancelled).');
    }

    $stmt = $db->prepare('UPDATE orders SET status = ?, updated_at = datetime(\'now\', \'localtime\') WHERE id = ?');
    $stmt->execute([$newStatus, $id]);
    ok(['order_id' => $id, 'status' => $newStatus, 'message' => 'وضعیت سفارش با موفقیت بروزرسانی شد.']);
}

// ۴. حذف سفارش (مدیر)
if ($method === 'DELETE') {
    if ($id === null || $id <= 0) {
        fail(422, 'شناسه سفارش الزامی است.');
    }
    $stmt = $db->prepare('DELETE FROM orders WHERE id = ?');
    $stmt->execute([$id]);
    ok(['deleted_id' => $id, 'message' => 'سفارش با موفقیت حذف شد.']);
}

fail(405, 'متد ارسالی پشتیبانی نمی‌شود.');

/**
 * ارسال اعلان تلگرام برای سفارش جدید
 */
function send_order_notification(PDO $db, int $orderId, string $tableNumber, array $items, float $totalPrice, string $note = ''): void
{
    $stmt = $db->query('SELECT setting_key, setting_value FROM settings WHERE setting_key IN ("notification_service", "telegram_bot_token", "telegram_chat_id", "telegram_proxy", "bale_bot_token", "bale_chat_id", "telegram_notifications_enabled", "currency")');
    $dbSettings = [];
    while ($row = $stmt->fetch()) {
        $dbSettings[$row['setting_key']] = $row['setting_value'];
    }

    if (($dbSettings['telegram_notifications_enabled'] ?? '1') === '0') {
        return;
    }

    $service = $dbSettings['notification_service'] ?? (defined('NOTIFICATION_SERVICE') ? NOTIFICATION_SERVICE : 'telegram');
    if (!in_array($service, ['telegram', 'bale'], true)) {
        $service = 'telegram';
    }

    if ($service === 'bale') {
        $token  = trim((string) ($dbSettings['bale_bot_token'] ?? $dbSettings['telegram_bot_token'] ?? (defined('BALE_BOT_TOKEN') ? BALE_BOT_TOKEN : '')));
        $chatId = trim((string) ($dbSettings['bale_chat_id'] ?? $dbSettings['telegram_chat_id'] ?? (defined('BALE_CHAT_ID') ? BALE_CHAT_ID : '')));
        $proxy  = '';
        $url    = "https://tapi.bale.ai/bot{$token}/sendMessage";
    } else {
        $token  = trim((string) ($dbSettings['telegram_bot_token'] ?? (defined('TELEGRAM_BOT_TOKEN') ? TELEGRAM_BOT_TOKEN : '')));
        $chatId = trim((string) ($dbSettings['telegram_chat_id'] ?? (defined('TELEGRAM_CHAT_ID') ? TELEGRAM_CHAT_ID : '')));
        $proxy  = trim((string) ($dbSettings['telegram_proxy'] ?? (defined('TELEGRAM_PROXY') ? TELEGRAM_PROXY : '')));
        $url    = "https://api.telegram.org/bot{$token}/sendMessage";
    }

    $currency = $dbSettings['currency'] ?? 'تومان';

    if ($token === '' || $chatId === '') {
        return;
    }

    $time = date('H:i');
    $date = date('Y/m/d');

    $msg  = "🔔 <b>سفارش جدید ثبت شد! (#{$orderId})</b>\n\n";
    $msg .= "📍 <b>شماره میز:</b> <code>{$tableNumber}</code>\n";
    $msg .= "🕒 <b>زمان:</b> {$time} — {$date}\n\n";
    $msg .= "📋 <b>اقلام سفارش:</b>\n";

    foreach ($items as $item) {
        $name = htmlspecialchars((string) ($item['name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $qty  = (int) ($item['qty'] ?? 1);
        $cost = number_format(((float) ($item['price'] ?? 0)) * $qty) . ' ' . $currency;
        $msg .= "▫️ <b>{$qty}×</b> {$name} (<code>{$cost}</code>)\n";
    }

    $msg .= "\n💰 <b>جمع کل:</b> <code>" . number_format($totalPrice) . " {$currency}</code>\n";
    if ($note !== '') {
        $msg .= "📝 <b>توضیحات مشتری:</b> <i>" . htmlspecialchars($note, ENT_QUOTES, 'UTF-8') . "</i>\n";
    }

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '✅ تکمیل شد', 'callback_data' => "order_status:{$orderId}:completed"],
                ['text' => '❌ لغو سفارش', 'callback_data' => "order_status:{$orderId}:cancelled"],
            ],
            [
                ['text' => '📦 مشاهده در پنل', 'url' => (isset($_SERVER['HTTP_HOST']) ? 'http://' . $_SERVER['HTTP_HOST'] : '') . '/admin.html'],
            ]
        ]
    ];

    $payload = [
        'chat_id'      => $chatId,
        'text'         => $msg,
        'parse_mode'   => 'HTML',
        'reply_markup' => $keyboard,
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json; charset=utf-8']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        if ($service === 'telegram' && $proxy !== '') {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
            if (stripos($proxy, 'socks5') === 0) {
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
            } elseif (stripos($proxy, 'http') === 0) {
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
            }
        }
        curl_exec($ch);
        curl_close($ch);
    }
}

/**
 * نام مستعار جهت حفظ سازگاری کامل
 */
function send_telegram_order_notice(PDO $db, int $orderId, string $tableNumber, array $items, float $totalPrice, string $note = ''): void
{
    send_order_notification($db, $orderId, $tableNumber, $items, $totalPrice, $note);
}
