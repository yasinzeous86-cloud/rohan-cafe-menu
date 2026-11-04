<?php
/**
 * ====================================================================
 * کافینکس (Cafinex) — مدیریت دسته‌بندی‌های منو (Categories API Endpoint)
 * ====================================================================
 * انکودینگ: UTF-8 بدون BOM
 * 
 * متدها:
 * GET    /api/categories.php         -> دریافت لیست دسته‌بندی‌ها (عمومی)
 * POST   /api/categories.php         -> افزودن دسته‌بندی جدید (نیازمند احراز هویت ادمین)
 * PUT    /api/categories.php?id={id} -> ویرایش دسته‌بندی (نیازمند احراز هویت ادمین)
 * DELETE /api/categories.php?id={id} -> حذف دسته‌بندی (نیازمند احراز هویت ادمین)
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

// ۱. دریافت دسته‌بندی‌ها (عمومی)
if ($method === 'GET') {
    $categories = $db->query('SELECT id, name, sort_order FROM categories ORDER BY sort_order, id')->fetchAll();
    foreach ($categories as &$c) {
        $c['id'] = (int) $c['id'];
        $c['sort_order'] = (int) $c['sort_order'];
    }
    unset($c);
    ok(['categories' => $categories]);
}

// برای کلیه عملیات‌های زیر، احراز هویت ادمین الزامی است
require_admin();

$in = get_request_body();

// ۲. افزودن دسته‌بندی جدید
if ($method === 'POST') {
    $name  = trim((string) ($in['name'] ?? ''));
    $order = (int) ($in['sort_order'] ?? 0);

    if ($name === '') {
        fail(422, 'نام دسته‌بندی الزامی است.');
    }

    try {
        $stmt = $db->prepare('INSERT INTO categories (name, sort_order) VALUES (?, ?)');
        $stmt->execute([$name, $order]);
        $newId = (int) $db->lastInsertId();
        ok(['id' => $newId, 'name' => $name, 'sort_order' => $order, 'message' => 'دسته‌بندی با موفقیت ایجاد شد.']);
    } catch (PDOException $e) {
        fail(409, 'دسته‌بندی با این نام از قبل وجود دارد.');
    }
}

// ۳. ویرایش دسته‌بندی
if ($method === 'PUT') {
    if ($id === null || $id <= 0) {
        fail(422, 'شناسه دسته‌بندی الزامی است.');
    }
    $name  = trim((string) ($in['name'] ?? ''));
    $order = isset($in['sort_order']) ? (int) $in['sort_order'] : null;

    if ($name === '') {
        fail(422, 'نام دسته‌بندی الزامی است.');
    }

    try {
        if ($order !== null) {
            $stmt = $db->prepare('UPDATE categories SET name = ?, sort_order = ? WHERE id = ?');
            $stmt->execute([$name, $order, $id]);
        } else {
            $stmt = $db->prepare('UPDATE categories SET name = ? WHERE id = ?');
            $stmt->execute([$name, $id]);
        }
        ok(['id' => $id, 'name' => $name, 'message' => 'دسته‌بندی با موفقیت ویرایش شد.']);
    } catch (PDOException $e) {
        fail(409, 'نام دسته‌بندی تکراری است.');
    }
}

// ۴. حذف دسته‌بندی
if ($method === 'DELETE') {
    if ($id === null || $id <= 0) {
        fail(422, 'شناسه دسته‌بندی الزامی است.');
    }

    // بررسی اینکه آیا آیتمی در این دسته‌بندی وجود دارد یا خیر
    $stmt = $db->prepare('SELECT COUNT(*) FROM items WHERE category_id = ?');
    $stmt->execute([$id]);
    if ((int) $stmt->fetchColumn() > 0) {
        fail(409, 'ابتدا باید آیتم‌های موجود در این دسته‌بندی را حذف یا منتقل نمایید.');
    }

    $stmt = $db->prepare('DELETE FROM categories WHERE id = ?');
    $stmt->execute([$id]);
    ok(['deleted_id' => $id, 'message' => 'دسته‌بندی با موفقیت حذف شد.']);
}

fail(405, 'متد ارسالی پشتیبانی نمی‌شود.');
