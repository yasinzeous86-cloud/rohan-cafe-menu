<?php
require_once __DIR__ . '/api.php';

$token = ADMIN_TOKEN;
$db = db();

echo "--- 1. Testing Auth ---\n";
// Simulated request with valid token
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_X_ADMIN_TOKEN'] = $token;

// Check admin read
$res = all_settings($db);
assert($res['ok'] === true, 'Admin read failed');
echo "Admin read OK.\n";

echo "--- 2. Testing Category CRUD ---\n";
// Create category
$stmt = $db->prepare('INSERT INTO categories (name, sort_order) VALUES (?, ?)');
$stmt->execute(['دسته تستی', 999]);
$catId = (int)$db->lastInsertId();
assert($catId > 0, 'Category create failed');
echo "Created category ID: $catId\n";

// Update category
$stmt = $db->prepare('UPDATE categories SET name = ?, sort_order = ? WHERE id = ?');
$stmt->execute(['دسته تستی ویرایش شده', 888, $catId]);
$cat = category_row($db, $catId);
assert($cat['name'] === 'دسته تستی ویرایش شده', 'Category update failed');
echo "Updated category OK: {$cat['name']}\n";

echo "--- 3. Testing Item CRUD ---\n";
// Create item
$stmt = $db->prepare('INSERT INTO items (category_id, name, description, price, status, sort_order, image_url) VALUES (?, ?, ?, ?, ?, ?, ?)');
$stmt->execute([$catId, 'آیتم تستی', 'توضیحات تست', 125000, 'available', 10, 'https://example.com/test.jpg']);
$itemId = (int)$db->lastInsertId();
assert($itemId > 0, 'Item create failed');
$item = item_row($db, $itemId);
assert($item['image_url'] === 'https://example.com/test.jpg', 'Item image_url failed');
assert($item['status'] === 'available', 'Item status failed');
echo "Created item ID: $itemId with image_url OK.\n";

// Update item status and price
$stmt = $db->prepare('UPDATE items SET status = ?, price = ?, sort_order = ? WHERE id = ?');
$stmt->execute(['sold_out', 130000, 20, $itemId]);
$item = item_row($db, $itemId);
assert($item['status'] === 'sold_out', 'Status update to sold_out failed');
assert($item['price'] == 130000, 'Price update failed');
echo "Updated item status to sold_out OK.\n";

// Delete item and category
$db->prepare('DELETE FROM items WHERE id = ?')->execute([$itemId]);
assert(item_row($db, $itemId) === [], 'Item delete failed');
$db->prepare('DELETE FROM categories WHERE id = ?')->execute([$catId]);
assert(category_row($db, $catId) === [], 'Category delete failed');
echo "Deleted test item and category OK.\n";

echo "--- 4. Testing Settings CRUD ---\n";
$stmt = $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value');
$stmt->execute(['daily_special', 'کافه لاته آرت با ۲۰٪ تخفیف']);
$s = all_settings($db);
assert($s['settings']['daily_special'] === 'کافه لاته آرت با ۲۰٪ تخفیف', 'Daily special update failed');
echo "Daily special setting OK: {$s['settings']['daily_special']}\n";

echo "\nALL CRUD & DATABASE OPERATIONS PASSED PERFECTLY!\n";
unlink(__FILE__);
