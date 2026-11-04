<?php
/**
 * ====================================================================
 * کافینکس (Cafinex) — مدیریت تنظیمات منو (Settings API Endpoint)
 * ====================================================================
 * انکودینگ: UTF-8 بدون BOM
 * 
 * متدها:
 * GET  /api/settings.php -> دریافت تنظیمات سیستم (عمومی / ادمین)
 * POST /api/settings.php -> ذخیره و ویرایش تنظیمات (نیازمند احراز هویت ادمین)
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$db = get_db();

// ۱. دریافت تنظیمات
if ($method === 'GET') {
    $stmt = $db->query('SELECT setting_key, setting_value FROM settings');
    $settings = [];
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    // پیش‌فرض‌های سیستم در صورت خالی بودن دیتابیس
    $defaults = [
        'shop_name'      => 'کافینکس | منوی کافه',
        'tagline'        => 'قهوه‌ی آرام، سرویس سریع — هر روز از ساعت ۷ صبح باز است.',
        'currency'       => 'تومان',
        'theme'          => 'espresso',
        'font_family'    => 'Vazirmatn',
        'orders_enabled' => '1',
        'footer_note'    => 'قیمت‌ها شامل مالیات است؛ درباره‌ی شیر جو دو سر، بادام یا سویا از ما بپرسید.',
    ];

    $merged = array_merge($defaults, $settings);
    ok(['settings' => $merged]);
}

// برای ذخیره تنظیمات، احراز هویت ادمین الزامی است
require_admin();

// ۲. ذخیره تنظیمات
if ($method === 'POST') {
    $in = get_request_body();
    if (empty($in)) {
        fail(422, 'داده‌ای برای ذخیره ارسال نشده است.');
    }

    $stmt = $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value');

    foreach ($in as $key => $val) {
        $safeKey = trim((string) $key);
        $safeVal = is_array($val) ? json_encode($val, JSON_UNESCAPED_UNICODE) : trim((string) $val);
        if ($safeKey === '') continue;
        $stmt->execute([$safeKey, $safeVal]);
    }

    // بازگرداندن تنظیمات کامل پس از ذخیره
    $stmt = $db->query('SELECT setting_key, setting_value FROM settings');
    $all = [];
    while ($row = $stmt->fetch()) {
        $all[$row['setting_key']] = $row['setting_value'];
    }

    ok(['settings' => $all, 'message' => 'تنظیمات با موفقیت ذخیره شدند.']);
}

fail(405, 'متد ارسالی پشتیبانی نمی‌شود.');
