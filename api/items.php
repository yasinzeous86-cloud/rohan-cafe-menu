<?php
/**
 * ====================================================================
 * کافینکس (Cafinex) — مدیریت اقلام منو (Items API Endpoint)
 * ====================================================================
 * انکودینگ: UTF-8 بدون BOM
 * 
 * متدها:
 * GET    /api/items.php         -> دریافت لیست اقلام (عمومی)
 * POST   /api/items.php         -> افزودن آیتم جدید (نیازمند احراز هویت ادمین)
 * PUT    /api/items.php?id={id} -> ویرایش آیتم (نیازمند احراز هویت ادمین)
 * DELETE /api/items.php?id={id} -> حذف آیتم (نیازمند احراز هویت ادمین)
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

// ۱. دریافت اقلام (عمومی)
if ($method === 'GET') {
    if ($id !== null) {
        $stmt = $db->prepare('SELECT * FROM items WHERE id = ?');
        $stmt->execute([$id]);
        $item = $stmt->fetch();
        if (!$item) {
            fail(404, 'آیتم مورد نظر یافت نشد.');
        }
        $item['id'] = (int) $item['id'];
        $item['category_id'] = (int) $item['category_id'];
        $item['price'] = $item['price'] !== null ? (float) $item['price'] : null;
        $item['sort_order'] = (int) $item['sort_order'];
        $item['available'] = $item['status'] === 'available';
        $item['visible'] = $item['status'] !== 'hidden';
        ok(['item' => $item]);
    }

    $categoryId = isset($_GET['category_id']) ? (int) $_GET['category_id'] : null;
    if ($categoryId !== null) {
        $stmt = $db->prepare('SELECT * FROM items WHERE category_id = ? ORDER BY sort_order, id');
        $stmt->execute([$categoryId]);
        $items = $stmt->fetchAll();
    } else {
        $items = $db->query('SELECT * FROM items ORDER BY sort_order, id')->fetchAll();
    }

    foreach ($items as &$i) {
        $i['id'] = (int) $i['id'];
        $i['category_id'] = (int) $i['category_id'];
        $i['price'] = $i['price'] !== null ? (float) $i['price'] : null;
        $i['sort_order'] = (int) $i['sort_order'];
        $i['available'] = $i['status'] === 'available';
        $i['visible'] = $i['status'] !== 'hidden';
    }
    unset($i);

    ok(['items' => $items, 'total' => count($items)]);
}

// برای کلیه عملیات‌های زیر، احراز هویت ادمین الزامی است
require_admin();

$in = get_request_body();

// ۲. افزودن آیتم جدید
if ($method === 'POST') {
    $catId   = (int) ($in['category_id'] ?? 0);
    $name    = trim((string) ($in['name'] ?? ''));
    $desc    = trim((string) ($in['description'] ?? ''));
    $priceRaw= $in['price'] ?? null;
    $price   = ($priceRaw !== null && $priceRaw !== '') ? (float) $priceRaw : null;
    $status  = trim((string) ($in['status'] ?? 'available'));
    $order   = (int) ($in['sort_order'] ?? 0);
    $img     = trim((string) ($in['image_url'] ?? ''));

    if ($name === '') {
        fail(422, 'نام آیتم الزامی است.');
    }
    if ($catId <= 0) {
        fail(422, 'دسته‌بندی نامعتبر است.');
    }
    if (!in_array($status, ['available', 'sold_out', 'hidden'], true)) {
        $status = 'available';
    }

    $stmt = $db->prepare('INSERT INTO items (category_id, name, description, image_url, price, status, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$catId, $name, $desc, $img, $price, $status, $order]);
    $newId = (int) $db->lastInsertId();

    $stmt = $db->prepare('SELECT * FROM items WHERE id = ?');
    $stmt->execute([$newId]);
    $created = $stmt->fetch();
    ok(['item' => $created, 'message' => 'آیتم با موفقیت ایجاد شد.']);
}

// ۳. ویرایش آیتم
if ($method === 'PUT') {
    if ($id === null || $id <= 0) {
        fail(422, 'شناسه آیتم الزامی است.');
    }

    $stmt = $db->prepare('SELECT * FROM items WHERE id = ?');
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if (!$existing) {
        fail(404, 'آیتم مورد نظر یافت نشد.');
    }

    $catId   = isset($in['category_id']) ? (int) $in['category_id'] : (int) $existing['category_id'];
    $name    = isset($in['name']) ? trim((string) $in['name']) : $existing['name'];
    $desc    = isset($in['description']) ? trim((string) $in['description']) : $existing['description'];
    $status  = isset($in['status']) ? trim((string) $in['status']) : $existing['status'];
    $order   = isset($in['sort_order']) ? (int) $in['sort_order'] : (int) $existing['sort_order'];
    $img     = isset($in['image_url']) ? trim((string) $in['image_url']) : $existing['image_url'];

    $price = $existing['price'];
    if (array_key_exists('price', $in)) {
        $priceRaw = $in['price'];
        $price = ($priceRaw !== null && $priceRaw !== '') ? (float) $priceRaw : null;
    }

    if (!in_array($status, ['available', 'sold_out', 'hidden'], true)) {
        $status = $existing['status'];
    }

    $stmt = $db->prepare('UPDATE items SET category_id = ?, name = ?, description = ?, image_url = ?, price = ?, status = ?, sort_order = ?, updated_at = datetime(\'now\') WHERE id = ?');
    $stmt->execute([$catId, $name, $desc, $img, $price, $status, $order, $id]);

    $stmt = $db->prepare('SELECT * FROM items WHERE id = ?');
    $stmt->execute([$id]);
    $updated = $stmt->fetch();
    ok(['item' => $updated, 'message' => 'آیتم با موفقیت ویرایش شد.']);
}

// ۴. حذف آیتم
if ($method === 'DELETE') {
    if ($id === null || $id <= 0) {
        fail(422, 'شناسه آیتم الزامی است.');
    }
    $stmt = $db->prepare('DELETE FROM items WHERE id = ?');
    $stmt->execute([$id]);
    ok(['deleted_id' => $id, 'message' => 'آیتم با موفقیت حذف شد.']);
}

fail(405, 'متد ارسالی پشتیبانی نمی‌شود.');
