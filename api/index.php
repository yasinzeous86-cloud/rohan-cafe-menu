<?php
/**
 * ====================================================================
 * کافینکس (Cafinex) — اندپوینت اصلی API منوی کافه
 * ====================================================================
 * انکودینگ: UTF-8 بدون BOM
 * 
 * GET /api/  -> بازگرداندن پی‌لود کامل عمومی منو (تنظیمات، دسته‌ها، آیتم‌ها، تم‌ها)
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$db = get_db();

if ($method === 'GET') {
    // دسته‌بندی‌ها
    $categories = $db->query('SELECT id, name, sort_order FROM categories ORDER BY sort_order, id')->fetchAll();
    foreach ($categories as &$c) {
        $c['id'] = (int) $c['id'];
        $c['sort_order'] = (int) $c['sort_order'];
    }
    unset($c);

    // اقلام منو
    $items = $db->query('SELECT id, category_id, name, description, price, status, sort_order, image_url FROM items ORDER BY sort_order, id')->fetchAll();
    foreach ($items as &$i) {
        $i['id'] = (int) $i['id'];
        $i['category_id'] = (int) $i['category_id'];
        $i['price'] = $i['price'] !== null ? (float) $i['price'] : null;
        $i['sort_order'] = (int) $i['sort_order'];
        $i['available'] = $i['status'] === 'available';
        $i['visible'] = $i['status'] !== 'hidden';
    }
    unset($i);

    // تنظیمات
    $stmt = $db->query('SELECT setting_key, setting_value FROM settings');
    $settings = [];
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    $themes = [
        'espresso' => ['name' => 'اسپرسو کلاسیک', 'brand' => '#dfcaa8', 'accent' => '#855137'],
        'cream'    => ['name' => 'کارامل و خامه', 'brand' => '#f3dfc1', 'accent' => '#c49a6c'],
        'dark'     => ['name' => 'دارک رست شبانه', 'brand' => '#e2d9cc', 'accent' => '#4a3728'],
    ];

    ok([
        'app'          => defined('APP_NAME') ? APP_NAME : 'کافینکس',
        'version'      => defined('APP_VERSION') ? APP_VERSION : '1.0.0',
        'settings'     => $settings,
        'categories'   => $categories,
        'items'        => $items,
        'themes'       => $themes,
        'generated_at' => date('c'),
    ]);
}

fail(405, 'متد ارسالی پشتیبانی نمی‌شود.');
