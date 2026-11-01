<?php
/**
 * Cafe Menu API — one file, SQLite, no composer, no terminal.
 *
 * Drop this on cPanel together with the front-end files. On first request it
 * creates menu.sqlite next to itself, builds the schema and seeds demo data.
 *
 * ROUTES (append the route to this file's URL, e.g. /api.php/items)
 *   GET    /                 PUBLIC payload: settings + categories + items
 *   GET    /admin            same payload, admin only (used by the dashboard)
 *   GET    /categories       list          POST /categories       create
 *   PUT    /categories/{id}  update        DELETE /categories/{id} delete
 *   GET    /items            list          POST /items            create
 *   PUT    /items/{id}       update        DELETE /items/{id}      delete
 *   GET    /settings         list          POST /settings         upsert { key: value }
 *   POST   /seed             insert the preset Iranian bestseller items (hidden)
 *
 * AUTH
 *   The single GET / payload is public. Every other route, and every write,
 *   needs the header:
 *       X-Admin-Token: <ADMIN_TOKEN below>
 *   Read it from the request, never from the query string (logs keep URLs).
 *
 * HARDENING — put this in an .htaccess beside the database so Apache refuses
 * to serve it (including the -wal / -shm files SQLite writes):
 *
 *   <FilesMatch "^menu\.sqlite">
 *       Require all denied
 *   </FilesMatch>
 */

declare(strict_types=1);

/* ------------------------------------------------------------------ *
 *  Config — change these two lines, nothing else
 * ------------------------------------------------------------------ */
const DB_FILE     = __DIR__ . '/menu.sqlite';
const ADMIN_TOKEN = 'change-me-to-a-long-random-string';
const CORS_ORIGIN = ''; // '' = same origin only, or 'https://yourdomain.com'

// Upload configuration
const UPLOADS_DIR        = __DIR__ . '/uploads';
const MAX_UPLOAD_BYTES   = 2 * 1024 * 1024; // 2MB
const ALLOWED_MIME_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

/* ------------------------------------------------------------------ *
 *  Hardening
 * ------------------------------------------------------------------ */
error_reporting(E_ALL);
ini_set('display_errors', '0'); // never print paths on a public server
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store');
if (function_exists('header_remove')) {
    @header_remove('X-Powered-By');
}

if (CORS_ORIGIN !== '') {
    header('Access-Control-Allow-Origin: ' . CORS_ORIGIN);
    header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Vary: Origin');
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Last-resort answer if PHP dies fatally after headers went out.
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Server error']);
    }
});

/* ------------------------------------------------------------------ *
 *  Helpers
 * ------------------------------------------------------------------ */

/** Send a JSON response and stop. */
function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ok(array $data = []): void
{
    respond(200, ['ok' => true] + $data);
}

function fail(int $status, string $message): void
{
    respond($status, ['ok' => false, 'error' => $message]);
}

/** Decode the request body as an associative array (JSON or multipart/form-data). */
function body(): array
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
    if (!is_array($data)) {
        fail(400, 'Body must be JSON or form-data');
    }
    return $data;
}

function text_length(string $s): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($s);
    }
    // No mbstring: count UTF-8 code points, not bytes, so Persian text
    // (2 bytes per character) is not rejected as "too long".
    $count = @preg_match_all('/./us', $s);
    return $count === false ? strlen($s) : $count;
}

function str_field(array $in, string $key, string $default, int $max, bool $required = false): string
{
    if (!array_key_exists($key, $in) || $in[$key] === null) {
        if ($required) {
            fail(422, "Field '$key' is required");
        }
        return $default;
    }
    $value = $in[$key];
    if (is_array($value) || is_bool($value)) {
        fail(422, "Field '$key' must be text");
    }
    $value = trim((string) $value);
    if ($required && $value === '') {
        fail(422, "Field '$key' is required");
    }
    if (text_length($value) > $max) {
        fail(422, "Field '$key' is too long (max $max characters)");
    }
    return $value;
}

function int_field(array $in, string $key, int $default, int $min = 0, int $max = 100000): int
{
    if (!array_key_exists($key, $in) || $in[$key] === null || $in[$key] === '') {
        return $default;
    }
    if (!is_numeric($in[$key])) {
        fail(422, "Field '$key' must be a whole number");
    }
    $value = (int) $in[$key];
    if ($value < $min || $value > $max) {
        fail(422, "Field '$key' must be between $min and $max");
    }
    return $value;
}

function price_field(array $in, string $key, ?float $default = 0.0): ?float
{
    if (!array_key_exists($key, $in) || $in[$key] === null || $in[$key] === '') {
        return $default;
    }
    if (!is_numeric($in[$key])) {
        fail(422, "Field '$key' must be a number");
    }
    $value = round((float) $in[$key], 2);
    if ($value < 0) {
        fail(422, "Field '$key' cannot be negative");
    }
    return $value;
}

const STATUSES = ['available', 'sold_out', 'hidden'];

/**
 * One-shot preset for the dashboard's "dronrizi" button: bestsellers of
 * Iranian cafés. Every row is written with status `hidden` on purpose — the
 * owner sets the prices and flips them to `available` before customers see them.
 */
const IRANIAN_PRESET = [
    ['قهوه',         'اسپرسو',    'تک‌شات رست تیره، عطر بالا و کرمای غلیظ.',        150000],
    ['قهوه',         'لاته',      'اسپرسوی دوبل با شیر بخارداده و لایه‌ی کف نازک.', 240000],
    ['قهوه',         'کاپوچینو',  'نسبت کلاسیک اسپرسو، شیر و کف، با پودر کاکائو.',  230000],
    ['قهوه',         'موکا',      'اسپرسو، شکلات تلخ و شیر بخارداده.',              265000],
    ['قهوه',         'وی‌۶۰',     'دم‌آوری دستی با قهوه‌ی تازه‌آسیاب‌شده.',           285000],
    ['نان و شیرینی', 'کیک شکلاتی','کیک شکلاتی مرطوب با گاناش تلخ.',                 250000],
    ['نان و شیرینی', 'چیزکیک',    'چیزکیک کلاسیک با سس توت‌فرنگی.',                  275000],
    ['نوشیدنی سرد',  'شیک نوتلا', 'شیک غلیظ نوتلا با خامه و ترافل شکلات.',           310000],
];

/** Keys the dashboard is allowed to write, and how long they may be. */
const SETTING_RULES = [
    'shop_name'     => 80,
    'tagline'       => 160,
    'currency'      => 8,
    'theme'         => 40,
    'footer_note'   => 200,
    'daily_special' => 160,
    // premium customisation (v1.1)
    'custom_bg_url' => 300,
    'primary_color' => 7,
    'font_family'   => 20,
    // the two card sections at the bottom of the menu (v1.2)
    'reviews_enabled'    => 3,
    'reviews_title'      => 80,
    'reviews'            => 6000,
    'highlights_enabled' => 3,
    'highlights'         => 6000,
    // Telegram bot integration & notifications
    'telegram_bot_token'             => 120,
    'telegram_chat_id'               => 60,
    'telegram_notifications_enabled' => 3,
    'telegram_proxy'                 => 255,
    // Order system toggle: '1' = ordering enabled, '0' = catalog-only mode
    'orders_enabled'                 => 3,
];

/**
 * Shipped values for the two card sections. all_settings() merges these under
 * whatever the database holds, so an install made before these keys existed
 * still renders the sections instead of an empty box.
 */
function default_settings(): array
{
    $reviews = [
        ['stars' => 5, 'tag' => 'کافه لاته آرت',
         'text' => '«بهترین لاته‌ای بود که خوردم؛ کرمای ابریشمی و رایحه‌ی فندقی بی‌نظیر بود.»',
         'author' => 'سارا — دانشجو'],
        ['stars' => 4, 'tag' => 'کلد برو نیتروژن',
         'text' => '«خنک، شیرینی طبیعی و بافت ابریشمی؛ بعدازظهرهای تابستان نجاتم میده.»',
         'author' => 'امیر — برنامه‌نویس'],
        ['stars' => 5, 'tag' => 'فرایز ترافل',
         'text' => '«ترافل واقعی و دیپ قارچش عالی بود؛ برای دفعه بعد هم رزرو کردم.»',
         'author' => 'نگار — عکاس'],
        ['stars' => 5, 'tag' => 'اسموکی برگر',
         'text' => '«گوشت تازه، دود واقعی و پیاز کاراملی؛ بهترین برگر محله.»',
         'author' => 'رضا — مربی ورزش'],
    ];

    // The daily pick sits before these in the menu and is driven by
    // `daily_special`, so it is deliberately not part of this array.
    $highlights = [
        ['badge' => 'رست تازه', 'icon' => '☕', 'variant' => 'dark',
         'title' => 'دانه‌های تک‌خاستگاه', 'text' => 'هر هفته تازه رست می‌شوند تا رایحه‌شان کامل بماند'],
        ['badge' => 'آشپزخانه', 'icon' => '🔥', 'variant' => 'dark',
         'title' => 'منوی گرم تا ۲۳:۳۰', 'text' => 'برگر، پیتزا و پیش‌غذاهای دست‌ساز هر روز تازه'],
        ['badge' => 'سرد و تازه', 'icon' => '🧊', 'variant' => 'dark',
         'title' => 'کلد بروی ۲۴ ساعته', 'text' => 'عصاره‌گیری طولانی با یخ‌های بلوری شفاف'],
    ];

    return [
        'reviews_enabled'                => '1',
        'reviews_title'                  => 'مشتری‌ها درباره ما چی میگن',
        'reviews'                        => json_encode($reviews, JSON_UNESCAPED_UNICODE),
        'highlights_enabled'             => '1',
        'highlights'                     => json_encode($highlights, JSON_UNESCAPED_UNICODE),
        'telegram_bot_token'             => '',
        'telegram_chat_id'               => '',
        'telegram_notifications_enabled' => '1',
        'telegram_proxy'                 => '',
        'orders_enabled'                 => '1',
    ];
}

/** Fonts the dashboard may pick. Anything else is rejected. */
const FONT_CHOICES = ['Vazirmatn', 'Lalezar', 'Dana', 'YekanBakh'];

/**
 * Extra per-key validation for the customisation settings.
 * Called with the trimmed value; fails the request when invalid.
 */
function check_setting(string $key, string $text): void
{
    if (in_array($key, ['reviews_enabled', 'highlights_enabled', 'telegram_notifications_enabled', 'orders_enabled'], true)) {
        if ($text !== '' && !in_array($text, ['0', '1'], true)) {
            fail(422, "Setting '$key' must be 0 (hidden/off) or 1 (shown/on)");
        }
        return;
    }
    if ($key === 'reviews' || $key === 'highlights') {
        if ($text !== '') {
            check_cards($key, $text);
        }
        return;
    }
    if ($text === '') {
        return; // clearing a value is always allowed
    }
    if ($key === 'primary_color' && preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $text) !== 1) {
        fail(422, "Setting 'primary_color' must be a hex colour like #DEC9A4");
    }
    if ($key === 'font_family' && !in_array($text, FONT_CHOICES, true)) {
        fail(422, "Setting 'font_family' must be one of: " . implode(', ', FONT_CHOICES));
    }
    if ($key === 'custom_bg_url') {
        // NOTE: '~' is the delimiter — it must not appear inside the class either.
        $remote = preg_match('~^https?://[A-Za-z0-9._:/?#\[\]@!$&*+,;=%-]+$~i', $text) === 1;
        $local   = preg_match('~^(?:assets/|\./|/)[A-Za-z0-9._/-]+$~', $text) === 1;
        $unsafe  = preg_match('~[\x00-\x1F\x7F\s"\'<>\\]~', $text) === 1
            || strpos($text, '..') !== false;   // no path traversal
        if ($unsafe || !($remote || $local)) {
            fail(422, "Setting 'custom_bg_url' must be an http(s) URL or a path like assets/menu-bg.jpg");
        }
    }
}

/** Read one text field of a card, bounded and free of control bytes. */
function card_field(array $card, string $field, int $max, string $where): string
{
    if (!array_key_exists($field, $card)) {
        fail(422, "$where is missing '$field'");
    }
    $value = $card[$field];
    if (!is_scalar($value)) {
        fail(422, "$where: '$field' must be text");
    }
    $text = trim((string) $value);
    if (text_length($text) > $max) {
        fail(422, "$where: '$field' is too long (max $max characters)");
    }
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) === 1) {
        fail(422, "$where: '$field' contains control characters");
    }
    return $text;
}

/**
 * `reviews` and `highlights` arrive as JSON arrays built by the dashboard.
 * Decode them and check every entry against exactly what the menu renders,
 * so a hand-edited payload can never reach the public page unvalidated.
 */
function check_cards(string $key, string $text): void
{
    // Decode twice: the strict pass tells a JSON array apart from an object
    // like {"0": …} — PHP would coerce that string key to an int and hide it.
    if (!is_array(json_decode($text))) {
        fail(422, "Setting '$key' must be a JSON array");
    }
    $cards = json_decode($text, true);
    if (!is_array($cards)) {
        fail(422, "Setting '$key' must be a JSON array");
    }
    $slot = 0;
    foreach ($cards as $index => $_) {          // keep the entries contiguous
        if ($index !== $slot++) {
            fail(422, "Setting '$key' must be a plain JSON array");
        }
    }
    if (count($cards) > 8) {
        fail(422, "Setting '$key' accepts at most 8 cards");
    }

    if ($key === 'reviews') {
        foreach ($cards as $n => $card) {
            $where = 'Review #' . ($n + 1);
            if (!is_array($card)) {
                fail(422, "$where must be an object");
            }
            foreach ($card as $name => $_) {
                if (!in_array($name, ['stars', 'tag', 'text', 'author'], true)) {
                    fail(422, "$where has an unknown field '$name'");
                }
            }
            if (!array_key_exists('stars', $card)) {
                fail(422, "$where is missing 'stars'");
            }
            $stars = is_scalar($card['stars']) ? (string) $card['stars'] : '';
            if (preg_match('/^[1-5]$/', $stars) !== 1) {
                fail(422, "$where needs 1 to 5 stars");
            }
            card_field($card, 'tag', 40, $where);
            card_field($card, 'text', 240, $where);
            card_field($card, 'author', 40, $where);
        }
        return;
    }

    foreach ($cards as $n => $card) {
        $where = 'Highlight #' . ($n + 1);
        if (!is_array($card)) {
            fail(422, "$where must be an object");
        }
        foreach ($card as $name => $_) {
            if (!in_array($name, ['badge', 'icon', 'title', 'text', 'variant'], true)) {
                fail(422, "$where has an unknown field '$name'");
            }
        }
        card_field($card, 'badge', 30, $where);
        card_field($card, 'icon', 8, $where);
        card_field($card, 'title', 60, $where);
        card_field($card, 'text', 140, $where);
        $variant = card_field($card, 'variant', 10, $where);
        if (!in_array($variant, ['brand', 'dark'], true)) {
            fail(422, "$where: 'variant' must be brand or dark");
        }
    }
}

/**
 * The 20 menu themes. `font` is the display stack written to --font-display,
 * the front-end draws the admin picker and the menu from this list.
 */
const THEMES = [
    ['slug' => 'espresso',     'name' => 'Espresso',     'bg' => '#1B1512', 'surface' => '#241C17', 'text' => '#EFE3D2', 'muted' => '#A38F7C', 'accent' => '#E0A33D', 'font' => 'Fraunces, Georgia, serif'],
    ['slug' => 'crema',        'name' => 'Crema',        'bg' => '#FBF6EE', 'surface' => '#F1E7DA', 'text' => '#2A1F17', 'muted' => '#7A6A5B', 'accent' => '#B4632A', 'font' => 'Fraunces, Georgia, serif'],
    ['slug' => 'saffron',      'name' => 'Saffron',      'bg' => '#FFFDF5', 'surface' => '#FFF3D1', 'text' => '#2E2410', 'muted' => '#8A7A4E', 'accent' => '#E4A11B', 'font' => 'Syne, Archivo, sans-serif'],
    ['slug' => 'pomegranate',  'name' => 'Pomegranate',  'bg' => '#1A0F12', 'surface' => '#26151A', 'text' => '#F6E4E7', 'muted' => '#B08A90', 'accent' => '#C4304B', 'font' => 'Fraunces, Georgia, serif'],
    ['slug' => 'pistachio',    'name' => 'Pistachio',    'bg' => '#F7F9F0', 'surface' => '#E9F0DB', 'text' => '#1F2617', 'muted' => '#6F7A5E', 'accent' => '#6E8B3D', 'font' => '"Bebas Neue", Archivo, sans-serif'],
    ['slug' => 'rosewater',    'name' => 'Rosewater',    'bg' => '#FFF7F6', 'surface' => '#FFE9EA', 'text' => '#2C1B1E', 'muted' => '#94757A', 'accent' => '#D46A7E', 'font' => '"DM Serif Display", Georgia, serif'],
    ['slug' => 'cardamom',     'name' => 'Cardamom',     'bg' => '#151712', 'surface' => '#1E211A', 'text' => '#ECEFE2', 'muted' => '#97A08A', 'accent' => '#A8B84A', 'font' => 'Fraunces, Georgia, serif'],
    ['slug' => 'mint',         'name' => 'Mint',         'bg' => '#F4FBF8', 'surface' => '#E2F4ED', 'text' => '#0F241D', 'muted' => '#5E7B72', 'accent' => '#17A184', 'font' => 'Syne, Archivo, sans-serif'],
    ['slug' => 'honey',        'name' => 'Honey',        'bg' => '#FFFBEF', 'surface' => '#FFF1CE', 'text' => '#2A1E08', 'muted' => '#8B7546', 'accent' => '#E19305', 'font' => '"Bebas Neue", Archivo, sans-serif'],
    ['slug' => 'clay',         'name' => 'Clay',         'bg' => '#FAF3EF', 'surface' => '#EFE1D9', 'text' => '#2B1D16', 'muted' => '#8A6F62', 'accent' => '#C2643C', 'font' => '"DM Serif Display", Georgia, serif'],
    ['slug' => 'slate',        'name' => 'Slate',        'bg' => '#0F1418', 'surface' => '#171E24', 'text' => '#E6EDF3', 'muted' => '#8FA1B0', 'accent' => '#4E93C6', 'font' => 'Syne, Archivo, sans-serif'],
    ['slug' => 'indigo',       'name' => 'Indigo',       'bg' => '#0D0E1C', 'surface' => '#171A33', 'text' => '#E9E9F7', 'muted' => '#8E92B8', 'accent' => '#6C63FF', 'font' => '"Bebas Neue", Archivo, sans-serif'],
    ['slug' => 'plum',         'name' => 'Plum',         'bg' => '#170F1A', 'surface' => '#221628', 'text' => '#F1E6F4', 'muted' => '#A88FAE', 'accent' => '#A24BC0', 'font' => 'Fraunces, Georgia, serif'],
    ['slug' => 'forest',       'name' => 'Forest',       'bg' => '#0C1511', 'surface' => '#12201A', 'text' => '#E4F0E9', 'muted' => '#85A192', 'accent' => '#2FA36B', 'font' => '"DM Serif Display", Georgia, serif'],
    ['slug' => 'ember',        'name' => 'Ember',        'bg' => '#170F0B', 'surface' => '#221710', 'text' => '#F5E7DC', 'muted' => '#AE8E79', 'accent' => '#E4571F', 'font' => '"Bebas Neue", Archivo, sans-serif'],
    ['slug' => 'chalk',        'name' => 'Chalk',        'bg' => '#FFFFFF', 'surface' => '#F4F4F2', 'text' => '#101010', 'muted' => '#6B6B66', 'accent' => '#FF4D00', 'font' => '"Bebas Neue", Archivo, sans-serif'],
    ['slug' => 'noir',         'name' => 'Noir',         'bg' => '#0B0B0C', 'surface' => '#151517', 'text' => '#F5F5F4', 'muted' => '#8A8A87', 'accent' => '#FF3B5C', 'font' => 'Syne, Archivo, sans-serif'],
    ['slug' => 'matcha',       'name' => 'Matcha',       'bg' => '#F2F7EF', 'surface' => '#E4EFDC', 'text' => '#16210F', 'muted' => '#66765A', 'accent' => '#5FA83C', 'font' => 'Syne, Archivo, sans-serif'],
    ['slug' => 'citrus',       'name' => 'Citrus',       'bg' => '#FFFDF2', 'surface' => '#FFF5D6', 'text' => '#232007', 'muted' => '#7E7745', 'accent' => '#FF8A00', 'font' => 'Syne, Archivo, sans-serif'],
    ['slug' => 'midnight',     'name' => 'Midnight',     'bg' => '#060911', 'surface' => '#0D1322', 'text' => '#DFE7F5', 'muted' => '#7E8CA8', 'accent' => '#3D7BFF', 'font' => 'Fraunces, Georgia, serif'],
];

function theme_exists(string $slug): bool
{
    foreach (THEMES as $theme) {
        if ($theme['slug'] === $slug) {
            return true;
        }
    }
    return false;
}

/* ------------------------------------------------------------------ *
 *  Database
 * ------------------------------------------------------------------ */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if (!extension_loaded('pdo_sqlite')) {
        fail(500, 'The pdo_sqlite extension is not enabled on this server');
    }

    $is_new = !file_exists(DB_FILE);
    try {
        $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (Throwable $e) {
        error_log('menu api: cannot open database: ' . $e->getMessage());
        fail(500, 'Cannot create or open the database file — check folder permissions');
    }

    @ $pdo->exec('PRAGMA journal_mode = WAL');    $pdo->exec('PRAGMA foreign_keys = ON');

    if ($is_new) {
        create_schema($pdo);
        seed($pdo);
    } else {
        try {
            $pdo->exec("ALTER TABLE items ADD COLUMN image_url TEXT NOT NULL DEFAULT ''");
        } catch (Throwable $e) {
            // column already exists
        }
        try {
            $cols = $pdo->query('PRAGMA table_info(items)')->fetchAll(PDO::FETCH_ASSOC);
            $priceNotNull = false;
            foreach ($cols as $col) {
                if ($col['name'] === 'price' && (int) $col['notnull'] === 1) {
                    $priceNotNull = true;
                    break;
                }
            }
            if ($priceNotNull) {
                $pdo->exec('PRAGMA foreign_keys = OFF');
                $pdo->exec('BEGIN TRANSACTION');
                $pdo->exec('CREATE TABLE items_temp (
                    id          INTEGER PRIMARY KEY AUTOINCREMENT,
                    category_id INTEGER NOT NULL REFERENCES categories(id) ON DELETE RESTRICT,
                    name        TEXT    NOT NULL,
                    description TEXT    NOT NULL DEFAULT \'\',
                    image_url   TEXT    NOT NULL DEFAULT \'\',
                    price       REAL    DEFAULT NULL,
                    status      TEXT    NOT NULL DEFAULT \'available\',
                    sort_order  INTEGER NOT NULL DEFAULT 0,
                    created_at  TEXT    NOT NULL DEFAULT (datetime(\'now\')),
                    updated_at  TEXT    NOT NULL DEFAULT (datetime(\'now\'))
                )');
                $pdo->exec('INSERT INTO items_temp (id, category_id, name, description, image_url, price, status, sort_order, created_at, updated_at)
                            SELECT id, category_id, name, description, image_url, price, status, sort_order, created_at, updated_at FROM items');
                $pdo->exec('DROP TABLE items');
                $pdo->exec('ALTER TABLE items_temp RENAME TO items');
                $pdo->exec('CREATE INDEX IF NOT EXISTS idx_items_category ON items(category_id)');
                $pdo->exec('CREATE INDEX IF NOT EXISTS idx_items_status    ON items(status)');
                $pdo->exec('COMMIT');
                $pdo->exec('PRAGMA foreign_keys = ON');
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS orders (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                table_number  TEXT    NOT NULL DEFAULT \'میز عمومی\',
                items_json    TEXT    NOT NULL DEFAULT \'[]\',
                total_price   REAL    NOT NULL DEFAULT 0,
                status        TEXT    NOT NULL DEFAULT \'pending\',
                customer_note TEXT    NOT NULL DEFAULT \'\',
                created_at    TEXT    NOT NULL DEFAULT (datetime(\'now\', \'localtime\')),
                updated_at    TEXT    NOT NULL DEFAULT (datetime(\'now\', \'localtime\'))
            )');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_orders_status  ON orders(status)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_orders_created ON orders(created_at)');
        } catch (Throwable $e) {
        }
    }
    return $pdo;
}

function create_schema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS categories (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT    NOT NULL UNIQUE,
        sort_order  INTEGER NOT NULL DEFAULT 0,
        created_at  TEXT    NOT NULL DEFAULT (datetime(\'now\'))
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS items (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        category_id INTEGER NOT NULL REFERENCES categories(id) ON DELETE RESTRICT,
        name        TEXT    NOT NULL,
        description TEXT    NOT NULL DEFAULT \'\',
        image_url   TEXT    NOT NULL DEFAULT \'\',
        price       REAL    DEFAULT NULL,
        status      TEXT    NOT NULL DEFAULT \'available\',
        sort_order  INTEGER NOT NULL DEFAULT 0,
        created_at  TEXT    NOT NULL DEFAULT (datetime(\'now\')),
        updated_at  TEXT    NOT NULL DEFAULT (datetime(\'now\'))
    )');

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_items_category ON items(category_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_items_status    ON items(status)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (
        setting_key   TEXT PRIMARY KEY,
        setting_value TEXT NOT NULL DEFAULT \'\'
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS orders (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        table_number  TEXT    NOT NULL DEFAULT \'میز عمومی\',
        items_json    TEXT    NOT NULL DEFAULT \'[]\',
        total_price   REAL    NOT NULL DEFAULT 0,
        status        TEXT    NOT NULL DEFAULT \'pending\',
        customer_note TEXT    NOT NULL DEFAULT \'\',
        created_at    TEXT    NOT NULL DEFAULT (datetime(\'now\', \'localtime\')),
        updated_at    TEXT    NOT NULL DEFAULT (datetime(\'now\', \'localtime\'))
    )');

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_orders_status  ON orders(status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_orders_created ON orders(created_at)');
}

/** First-run demo menu — delete or edit freely, it only runs once. */
function seed(PDO $pdo): void
{
    $categories = ['قهوه', 'نوشیدنی سرد', 'نان و شیرینی', 'آشپزخانه'];
    $stmt = $pdo->prepare('INSERT INTO categories (name, sort_order) VALUES (?, ?)');
    foreach ($categories as $i => $name) {
        $stmt->execute([$name, ($i + 1) * 10]);
    }

    $items = [
        ['قهوه', 'اسپرسو',          'تک‌خاستگاه، رُست تیره، سرو در فنجان گرم ۶۰ میلی‌لیتری.', 150000, 'available'],
        ['قهوه', 'فلت وایت',        'ریسترتتوی دوبل با کف شیر ابریشمی.',                      240000, 'available'],
        ['قهوه', 'کلد برو',         'دم‌آوری سرد ۱۸ ساعته، روی یک قالب یخ درشت.',              270000, 'sold_out'],
        ['نوشیدنی سرد', 'لیموناد نعنایی', 'نعنای تازه، لیموی تازه‌فشرده و یخ خردشده.',         225000, 'available'],
        ['نوشیدنی سرد', 'سودای گلاب',     'شربت خانگی، آب گازدار و کمی هل.',                   210000, 'available'],
        ['نان و شیرینی', 'کروسان کره‌ای',  'خمیر ورق‌ورق از شب قبل، پخت ساعت ۷ صبح.',           165000, 'available'],
        ['نان و شیرینی', 'کیک خرمایی',     'خرمای چسبناک با کارامل نمکی، گرم یا سرد.',          255000, 'hidden'],
        ['آشپزخانه', 'تست هلومی',    'نان ترش، هلومی کبابی و عسل فلفلی.',                      450000, 'available'],
        ['آشپزخانه', 'شکشوعا',       'دو تخم‌مرغ، فلفل و گوجه، همراه نان.',                     540000, 'available'],
    ];
    $stmt = $pdo->prepare('INSERT INTO items (category_id, name, description, price, status, sort_order)
                           VALUES ((SELECT id FROM categories WHERE name = ?), ?, ?, ?, ?, ?)');
    foreach ($items as $i => $row) {
        $stmt->execute([$row[0], $row[1], $row[2], $row[3], $row[4], ($i + 1) * 10]);
    }

    $settings = [
        'shop_name'   => 'کافه و بار رُهان',
        'tagline'     => 'قهوه‌ی آرام، سرویس سریع — هر روز از ساعت ۷ صبح باز است.',
        'currency'    => 'تومان',
        'theme'       => 'espresso',
        'footer_note' => 'قیمت‌ها شامل مالیات است؛ درباره‌ی شیر جو دو سر، بادام یا سویا از ما بپرسید.',
    ];
    $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)');
    foreach ($settings as $key => $value) {
        $stmt->execute([$key, $value]);
    }
}

function all_settings(PDO $pdo): array
{
    $out = default_settings(); // shipped values first, the database wins
    foreach ($pdo->query('SELECT setting_key, setting_value FROM settings') as $row) {
        $out[$row['setting_key']] = $row['setting_value'];
    }
    return $out;
}

/** The one payload the front-end needs — categories, items and settings together. */
function public_payload(PDO $pdo): array
{
    $categories = $pdo->query('SELECT id, name, sort_order FROM categories ORDER BY sort_order, id')->fetchAll();
    foreach ($categories as &$c) {
        $c['id'] = (int) $c['id'];
        $c['sort_order'] = (int) $c['sort_order'];
    }
    unset($c);

    $items = $pdo->query('SELECT id, category_id, name, description, price, status, sort_order, image_url
                          FROM items ORDER BY sort_order, id')->fetchAll();
    foreach ($items as &$i) {
        $i['id'] = (int) $i['id'];
        $i['category_id'] = (int) $i['category_id'];
        $i['price'] = $i['price'] !== null ? (float) $i['price'] : null;
        $i['sort_order'] = (int) $i['sort_order'];
        $i['available'] = $i['status'] === 'available';
        $i['visible'] = $i['status'] !== 'hidden';
    }
    unset($i);

    return [
        'settings'     => all_settings($pdo),
        'categories'   => $categories,
        'items'        => $items,
        'themes'       => THEMES,
        'generated_at' => gmdate('c'),
    ];
}

/* ------------------------------------------------------------------ *
 *  Auth
 * ------------------------------------------------------------------ */

function require_admin(): void
{
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';
    $token = '';
    if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m) === 1) {
        $token = $m[1];
    }
    if ($token === '') {
        $token = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';
    }
    if ($token === '' || !hash_equals(ADMIN_TOKEN, $token)) {
        fail(401, 'Invalid or missing admin token');
    }
}

/* ------------------------------------------------------------------ *
 *  Route
 * ------------------------------------------------------------------ */

$method   = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'POST') {
    $override = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? $_POST['_method'] ?? '';
    if (in_array(strtoupper((string) $override), ['PUT', 'DELETE', 'PATCH'], true)) {
        $method = strtoupper((string) $override);
    }
}
$script   = $_SERVER['SCRIPT_NAME'] ?? '/api.php';
$uri      = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$base     = dirname($script);                      // '' or '/' or '/sub'
$path     = $uri;
if ($base !== '' && $base !== '/' && strpos($path, $base) === 0) {
    $path = substr($path, strlen($base));
}
if (isset($_GET['path'])) {                        // fallback: ?path=/items
    $path = (string) $_GET['path'];
}

$segments = [];
foreach (explode('/', $path) as $seg) {
    if ($seg === '') {
        continue;
    }
    if ($seg === basename($script)) {              // '/api.php' with no rewrite
        continue;
    }
    $segments[] = $seg;
}

$resource = $segments[0] ?? '';
$id       = isset($segments[1]) ? (int) $segments[1] : null;

$is_read  = ($method === 'GET' || $method === 'HEAD');
$is_public_order = ($method === 'POST' && $resource === 'orders');
// Root payload (GET /) and customer checkout (POST /orders) are public.
$needs_auth = !(($is_read && $resource === '') || $is_public_order);
if ($needs_auth) {
    require_admin();
}

try {
    $db = db();

    /* ---- public payload ---- */
    if ($resource === '' || $resource === 'admin') {
        if ($is_read) {
            ok(public_payload($db));
        }
        fail(404, 'Not found');
    }

    /* ---- categories ---- */
    if ($resource === 'categories') {
        if ($is_read) {
            ok(['categories' => $db->query('SELECT id, name, sort_order FROM categories ORDER BY sort_order, id')->fetchAll()]);
        }
        if ($method === 'POST') {
            $in    = body();
            $name  = str_field($in, 'name', '', 60, true);
            $order = int_field($in, 'sort_order', 0);
            $stmt  = $db->prepare('INSERT INTO categories (name, sort_order) VALUES (?, ?)');
            try {
                    $stmt->execute([$name, $order]);
            } catch (PDOException $e) {
                if (stripos($e->getMessage(), 'unique') !== false) {
                    fail(409, 'A category with that name already exists');
                }
                throw $e;
            }
            ok(['category' => category_row($db, (int) $db->lastInsertId())]);
        }
        if ($method === 'PUT' && $id !== null) {
            $current = find_row($db, 'categories', $id);
            if ($current === null) {
                fail(404, 'Category not found');
            }
            $in    = body();
            $name  = str_field($in, 'name', (string) $current['name'], 60, true);
            $order = int_field($in, 'sort_order', (int) $current['sort_order']);
            $db->prepare('UPDATE categories SET name = ?, sort_order = ? WHERE id = ?')
               ->execute([$name, $order, $id]);
            ok(['category' => category_row($db, $id)]);
        }
        if ($method === 'DELETE' && $id !== null) {
            if (find_row($db, 'categories', $id) === null) {
                fail(404, 'Category not found');
            }
            $count = $db->prepare('SELECT COUNT(*) FROM items WHERE category_id = ?');
            $count->execute([$id]);
            $used = (int) $count->fetchColumn();
            if ($used > 0) {
                fail(409, "Category still holds $used item(s) — move or delete them first");
            }
            $db->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
            ok(['deleted' => $id]);
        }
        fail(404, 'Not found');
    }

    /* ---- items ---- */
    if ($resource === 'items') {
        if ($is_read) {
            $items = $db->query('SELECT id, category_id, name, description, price, status, sort_order, image_url
                                       FROM items ORDER BY sort_order, id')->fetchAll();
            foreach ($items as &$it) {
                $it['id'] = (int) $it['id'];
                $it['category_id'] = (int) $it['category_id'];
                $it['price'] = $it['price'] !== null ? (float) $it['price'] : null;
                $it['sort_order'] = (int) $it['sort_order'];
            }
            unset($it);
            ok(['items' => $items]);
        }
        if ($method === 'POST' && $id === null) {
            $data = item_values($db, body(), null);
            $db->prepare('INSERT INTO items (category_id, name, description, price, status, sort_order, image_url)
                          VALUES (?, ?, ?, ?, ?, ?, ?)')
               ->execute($data);
            $new_id = (int) $db->lastInsertId();
            ok(['item' => item_row($db, $new_id)]);
        }
        if (($method === 'PUT' || ($method === 'POST' && $id !== null)) && $id !== null) {
            $current = find_row($db, 'items', $id);
            if ($current === null) {
                fail(404, 'Item not found');
            }
            $data        = item_values($db, body(), (array) $current);
            $data[]      = $id;
            $db->prepare('UPDATE items SET category_id = ?, name = ?, description = ?, price = ?,
                          status = ?, sort_order = ?, image_url = ?, updated_at = datetime(\'now\') WHERE id = ?')
               ->execute($data);
            ok(['item' => item_row($db, $id)]);
        }
        if ($method === 'DELETE' && $id !== null) {
            $current = find_row($db, 'items', $id);
            if ($current === null) {
                fail(404, 'Item not found');
            }
            if (!empty($current['image_url']) && strpos((string) $current['image_url'], 'uploads/') === 0 && file_exists(__DIR__ . '/' . $current['image_url'])) {
                @unlink(__DIR__ . '/' . $current['image_url']);
            }
            $db->prepare('DELETE FROM items WHERE id = ?')->execute([$id]);
            ok(['deleted' => $id]);
        }
        fail(404, 'Not found');
    }

    /* ---- preset: Iranian café bestsellers, all inserted as `hidden` ---- */
    if ($resource === 'seed') {
        if ($method === 'POST') {
            $inserted = 0;
            $skipped  = 0;
            foreach (IRANIAN_PRESET as $row) {
                [$catName, $name, $description, $price] = $row;

                $stmt = $db->prepare('SELECT id FROM categories WHERE name = ?');
                $stmt->execute([$catName]);
                $categoryId = $stmt->fetchColumn();
                if ($categoryId === false) {
                    $db->prepare('INSERT INTO categories (name, sort_order) VALUES (?, 0)')
                       ->execute([$catName]);
                    $categoryId = (int) $db->lastInsertId();
                }
                $categoryId = (int) $categoryId;

                $check = $db->prepare('SELECT COUNT(*) FROM items WHERE category_id = ? AND name = ?');
                $check->execute([$categoryId, $name]);
                if ((int) $check->fetchColumn() > 0) {
                    $skipped++;    // never duplicate a preset item
                    continue;
                }

                $next = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM items WHERE category_id = ?');
                $next->execute([$categoryId]);
                $order = (int) $next->fetchColumn();

                $db->prepare('INSERT INTO items (category_id, name, description, price, status, sort_order)
                              VALUES (?, ?, ?, ?, ?, ?)')
                   ->execute([$categoryId, $name, $description, $price, 'hidden', $order]);
                $inserted++;
            }
            ok(['inserted' => $inserted, 'skipped' => $skipped, 'status' => 'hidden']);
        }
        fail(404, 'Not found');
    }

    /* ---- settings ---- */
    if ($resource === 'settings') {
        if ($is_read) {
            ok(['settings' => all_settings($db)]);
        }
        if ($method === 'POST') {
            $in = body();
            if ($in === []) {
                fail(422, 'Send settings as { "key": "value" }');
            }
            foreach ($in as $key => $value) {
                if (!isset(SETTING_RULES[$key])) {
                    fail(422, "Unknown setting '$key'");
                }
                $max  = SETTING_RULES[$key];
                $text = is_scalar($value) ? trim((string) $value) : '';
                if (text_length($text) > $max) {
                    fail(422, "Setting '$key' is too long (max $max characters)");
                }
                if ($key === 'theme' && $text !== '' && !theme_exists($text)) {
                    fail(422, 'Unknown theme');
                }
                check_setting($key, $text);
                $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                              ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value')
                   ->execute([$key, $text]);
            }
            ok(['settings' => all_settings($db)]);
        }
        fail(404, 'Not found');
    }

    /* ---- orders ---- */
    if ($resource === 'orders') {
        if ($method === 'POST' && $id === null) {
            $settings = all_settings($db);
            if (($settings['orders_enabled'] ?? '1') === '0') {
                fail(403, 'امکان ثبت سفارش آنلاین در حال حاضر غیرفعال است (حالت کاتالوگ).');
            }

            $in          = body();
            $tableNumber = str_field($in, 'table_number', 'میز عمومی', 50);
            $note        = str_field($in, 'customer_note', '', 300);
            $totalPrice  = price_field($in, 'total_price', 0.0);
            $items       = $in['items'] ?? [];

            if (!is_array($items) || empty($items)) {
                fail(422, 'سبد سفارش نمی‌تواند خالی باشد.');
            }

            $cleanItems = [];
            foreach ($items as $it) {
                if (!is_array($it)) continue;
                $cleanItems[] = [
                    'name'  => trim((string) ($it['name'] ?? 'آیتم')),
                    'qty'   => max(1, (int) ($it['qty'] ?? $it['count'] ?? 1)),
                    'price' => max(0.0, (float) ($it['price'] ?? 0)),
                ];
            }
            if (empty($cleanItems)) {
                fail(422, 'آیتم‌های معتبری در سفارش یافت نشد.');
            }

            if ($totalPrice <= 0.0) {
                foreach ($cleanItems as $ci) {
                    $totalPrice += (float) ($ci['qty'] * $ci['price']);
                }
            }

            $itemsJson = json_encode($cleanItems, JSON_UNESCAPED_UNICODE);
            $stmt = $db->prepare('INSERT INTO orders (table_number, items_json, total_price, customer_note, status) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$tableNumber, $itemsJson, $totalPrice, $note, 'pending']);
            $orderId = (int) $db->lastInsertId();

            $telegramSent = notify_telegram_new_order($db, $orderId, $tableNumber, $cleanItems, $totalPrice, $note);

            ok([
                'order_id'      => $orderId,
                'table_number'  => $tableNumber,
                'total_price'   => $totalPrice,
                'telegram_sent' => $telegramSent,
                'message'       => 'سفارش شما با موفقیت ثبت شد و به باریستا ارسال گردید.',
            ]);
        }

        if ($method === 'GET') {
            $stmt = $db->query('SELECT id, table_number, items_json, total_price, status, customer_note, created_at FROM orders ORDER BY id DESC LIMIT 50');
            $rows = $stmt->fetchAll();
            foreach ($rows as &$r) {
                $r['id'] = (int) $r['id'];
                $r['total_price'] = (float) $r['total_price'];
                $r['items'] = json_decode((string) $r['items_json'], true) ?: [];
            }
            unset($r);
            ok(['orders' => $rows]);
        }

        if (($method === 'PUT' || ($method === 'POST' && $id !== null)) && $id !== null) {
            $in     = body();
            $status = str_field($in, 'status', 'pending', 20);
            if (!in_array($status, ['pending', 'completed', 'cancelled'], true)) {
                fail(422, 'وضعیت سفارش نامعتبر است.');
            }
            $db->prepare('UPDATE orders SET status = ?, updated_at = datetime(\'now\', \'localtime\') WHERE id = ?')
               ->execute([$status, $id]);
            ok(['updated' => $id, 'status' => $status]);
        }

        fail(404, 'Not found');
    }

    /* ---- telegram actions ---- */
    if ($resource === 'telegram') {
        $sub = $segments[1] ?? '';
        if ($sub === 'test' && $method === 'POST') {
            $in       = body();
            $settings = all_settings($db);
            $token    = trim((string) ($in['token'] ?? $settings['telegram_bot_token'] ?? ''));
            $chatId   = trim((string) ($in['chat_id'] ?? $settings['telegram_chat_id'] ?? ''));
            $proxy    = trim((string) ($in['proxy'] ?? $settings['telegram_proxy'] ?? ''));

            if ($token === '' || $chatId === '') {
                fail(422, 'توکن ربات یا شناسه چت تنظیم نشده است.');
            }

            $testMsg = "☕️ <b>تست اتصال ربات منوی کافه رُهان</b>\n\n" .
                       "اتصال با موفقیت برقرار شد! سیستم ارسال نوتیفیکیشن سفارشات آنلاین آماده به کار است.\n\n" .
                       ($proxy !== '' ? "🌐 پروکسی فعال: <code>" . htmlspecialchars($proxy, ENT_QUOTES, 'UTF-8') . "</code>\n\n" : '') .
                       "🕒 زمان تست: " . date('Y/m/d H:i:s');

            $res = send_telegram_request($token, 'sendMessage', [
                'chat_id'    => $chatId,
                'text'       => $testMsg,
                'parse_mode' => 'HTML',
            ], $proxy);

            if (empty($res['ok'])) {
                fail(400, 'خطا در ارتباط با تلگرام: ' . ($res['description'] ?? $res['error'] ?? 'خطای نامشخص'));
            }

            ok(['telegram_response' => $res, 'message' => 'پیام آزمایشی به تلگرام ارسال شد!']);
        }

        if ($sub === 'set-webhook' && $method === 'POST') {
            $in       = body();
            $settings = all_settings($db);
            $token    = trim((string) ($in['token'] ?? $settings['telegram_bot_token'] ?? ''));
            $url      = trim((string) ($in['webhook_url'] ?? ''));
            $proxy    = trim((string) ($in['proxy'] ?? $settings['telegram_proxy'] ?? ''));

            if ($token === '') {
                fail(422, 'توکن ربات تنظیم نشده است.');
            }
            if ($url === '') {
                fail(422, 'آدرس وب‌هوک ارسال نشده است.');
            }

            $res = send_telegram_request($token, 'setWebhook', [
                'url' => $url,
                'allowed_updates' => ['message', 'callback_query'],
            ], $proxy);

            if (empty($res['ok'])) {
                fail(400, 'خطا در ثبت وب‌هوک: ' . ($res['description'] ?? $res['error'] ?? 'خطای نامشخص'));
            }

            ok(['telegram_response' => $res, 'message' => 'وب‌هوک ربات تلگرام با موفقیت ثبت شد!']);
        }

        fail(404, 'Not found');
    }

    fail(404, 'Unknown route');
} catch (PDOException $e) {
    error_log('menu api: database error: ' . $e->getMessage());
    fail(500, 'Database error');
} catch (Throwable $e) {
    error_log('menu api: ' . $e->getMessage());
    fail(500, 'Server error');
}

/* ------------------------------------------------------------------ *
 *  Small record helpers
 * ------------------------------------------------------------------ */

function find_row(PDO $db, string $table, int $id): ?array
{
    if ($table !== 'items' && $table !== 'categories') {
        return null;
    }
    $stmt = $db->prepare("SELECT * FROM $table WHERE id = ?");
    $stmt->execute([$id]);
    $found = $stmt->fetch();
    return $found === false ? null : $found;
}

function category_row(PDO $db, int $id): array
{
    $stmt = $db->prepare('SELECT id, name, sort_order FROM categories WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row === false ? [] : $row;
}

function item_row(PDO $db, int $id): array
{
    $stmt = $db->prepare('SELECT id, category_id, name, description, price, status, sort_order, image_url
                          FROM items WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row !== false) {
        $row['id'] = (int) $row['id'];
        $row['category_id'] = (int) $row['category_id'];
        $row['price'] = $row['price'] !== null ? (float) $row['price'] : null;
        $row['sort_order'] = (int) $row['sort_order'];
    }
    return $row === false ? [] : $row;
}

/**
 * Handle secure local image uploads.
 * Validates file size (max 2MB), MIME type, extension, and content.
 * Generates unique safe filename and ensures uploads/.htaccess blocks script execution.
 */
function handle_image_upload(?string $currentUrl = null): ?string
{
    $file = $_FILES['item_image_file'] ?? $_FILES['image_file'] ?? null;
    if ($file === null || !is_array($file) || !isset($file['error'])) {
        return null;
    }

    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return null; // No file uploaded
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            fail(422, 'حجم تصویر نباید بیشتر از ۲ مگابایت باشد.');
        }
        fail(422, 'خطا در بارگذاری فایل تصویر (کد: ' . (int) $file['error'] . ')');
    }

    // 1. File size limit (2MB)
    if ($file['size'] > MAX_UPLOAD_BYTES) {
        fail(422, 'حجم تصویر نباید بیشتر از ۲ مگابایت باشد.');
    }

    // 2. MIME type detection
    $detectedMime = '';
    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detectedMime = (string) @finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
        }
    }
    if ($detectedMime === '' && function_exists('mime_content_type')) {
        $detectedMime = (string) @mime_content_type($file['tmp_name']);
    }
    if ($detectedMime === '') {
        $info = @getimagesize($file['tmp_name']);
        if ($info && !empty($info['mime'])) {
            $detectedMime = (string) $info['mime'];
        }
    }

    if (!isset(ALLOWED_MIME_TYPES[$detectedMime])) {
        fail(422, 'فرمت تصویر مجاز نیست. فقط تصاویر JPG، PNG و WEBP مجاز هستند.');
    }

    // 3. Extension check
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $validExts = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($ext, $validExts, true)) {
        fail(422, 'پسوند تصویر مجاز نیست. فقط jpg، png و webp مجاز هستند.');
    }
    $safeExt = ALLOWED_MIME_TYPES[$detectedMime];

    // 4. Verify real image
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        fail(422, 'محتوای فایل یک تصویر معتبر نیست.');
    }

    // 5. Ensure uploads/ directory exists
    if (!is_dir(UPLOADS_DIR)) {
        if (!mkdir(UPLOADS_DIR, 0755, true) && !is_dir(UPLOADS_DIR)) {
            fail(500, 'امکان ساخت پوشه uploads وجود ندارد. دسترسی پوشه را بررسی کنید.');
        }
    }

    // 6. Ensure uploads/.htaccess blocks script execution
    $htaccessPath = UPLOADS_DIR . '/.htaccess';
    if (!file_exists($htaccessPath)) {
        $htaccessContent = "# Disable script execution in uploads directory\n" .
            "<FilesMatch \"\.(php|php5|php7|php8|phtml|phar|cgi|pl|py|sh|asp|aspx)$\">\n" .
            "    <IfModule mod_authz_core.c>\n" .
            "        Require all denied\n" .
            "    </IfModule>\n" .
            "    <IfModule !mod_authz_core.c>\n" .
            "        Order deny,allow\n" .
            "        Deny from all\n" .
            "    </IfModule>\n" .
            "</FilesMatch>\n" .
            "<IfModule mod_php7.c>\n" .
            "    php_flag engine off\n" .
            "</IfModule>\n" .
            "<IfModule mod_php.c>\n" .
            "    php_flag engine off\n" .
            "</IfModule>\n" .
            "RemoveHandler .php\n" .
            "RemoveType .php\n" .
            "Options -ExecCGI -Indexes\n";
        @file_put_contents($htaccessPath, $htaccessContent);
    }

    // 7. Generate unique safe filename
    $uniqueName = 'item_' . bin2hex(random_bytes(8)) . '_' . time() . '.' . $safeExt;
    $targetPath = UPLOADS_DIR . '/' . $uniqueName;

    // 8. Move uploaded file
    $moved = false;
    if (is_uploaded_file($file['tmp_name'])) {
        $moved = @move_uploaded_file($file['tmp_name'], $targetPath);
    } else {
        $moved = @copy($file['tmp_name'], $targetPath);
        if ($moved) {
            @unlink($file['tmp_name']);
        }
    }
    if (!$moved) {
        fail(500, 'خطا در ذخیره‌سازی فایل تصویر.');
    }
    @chmod($targetPath, 0644);

    // 9. Remove previous local uploaded file if updating
    if ($currentUrl && strpos($currentUrl, 'uploads/') === 0 && file_exists(__DIR__ . '/' . $currentUrl)) {
        @unlink(__DIR__ . '/' . $currentUrl);
    }

    return 'uploads/' . $uniqueName;
}

/**
 * Validate an item payload. With $current it becomes a partial update;
 * without it, it is a create and every required field must arrive.
 * Returns the bound values in column order.
 */
function item_values(PDO $db, array $in, ?array $current): array
{
    $has = static function (string $key) use ($in): bool {
        return array_key_exists($key, $in) && $in[$key] !== null;
    };

    $name = $has('name')
        ? str_field($in, 'name', '', 120, true)
        : (string) ($current['name'] ?? '');
    if ($name === '') {
        fail(422, "Field 'name' is required");
    }

    $description = $has('description')
        ? str_field($in, 'description', '', 500)
        : (string) ($current['description'] ?? '');

    $isInquiry = !empty($in['is_inquiry']) || (array_key_exists('price', $in) && ($in['price'] === null || $in['price'] === ''));
    if ($isInquiry) {
        $price = null;
    } elseif (array_key_exists('price', $in)) {
        $price = price_field($in, 'price', null);
    } else {
        $price = isset($current['price']) && $current['price'] !== null ? (float) $current['price'] : null;
    }

    $status = $has('status')
        ? str_field($in, 'status', '', 20)
        : (string) ($current['status'] ?? 'available');
    if (!in_array($status, STATUSES, true)) {
        fail(422, "Field 'status' must be one of: " . implode(', ', STATUSES));
    }

    $category_id = $has('category_id')
        ? int_field($in, 'category_id', 0, 1)
        : (int) ($current['category_id'] ?? 0);
    if ($category_id === 0) {
        fail(422, "Field 'category_id' is required");
    }

    $sort_order = $has('sort_order')
        ? int_field($in, 'sort_order', 0, 0, 100000)
        : (int) ($current['sort_order'] ?? 0);

    $image_url = $has('image_url')
        ? str_field($in, 'image_url', '', 500)
        : (string) ($current['image_url'] ?? '');

    // Handle local image file upload if provided
    $uploadedUrl = handle_image_upload($current['image_url'] ?? null);
    if ($uploadedUrl !== null) {
        $image_url = $uploadedUrl;
    } elseif ($has('image_url') && $image_url === '' && !empty($current['image_url'])) {
        // Image was cleared by user
        if (strpos((string) $current['image_url'], 'uploads/') === 0 && file_exists(__DIR__ . '/' . $current['image_url'])) {
            @unlink(__DIR__ . '/' . $current['image_url']);
        }
    }

    $check = $db->prepare('SELECT COUNT(*) FROM categories WHERE id = ?');
    $check->execute([$category_id]);
    if ((int) $check->fetchColumn() === 0) {
        fail(422, 'That category does not exist');
    }

    return [$category_id, $name, $description, $price, $status, $sort_order, $image_url];
}

/* ------------------------------------------------------------------ *
 *  Telegram Bot API Integration
 * ------------------------------------------------------------------ */

/**
 * Send an HTTP request to the Telegram Bot API with optional proxy and resilient SSL.
 */
function send_telegram_request(string $botToken, string $method, array $params = [], string $proxy = ''): array
{
    $botToken = trim($botToken);
    if ($botToken === '') {
        return ['ok' => false, 'error' => 'Bot token is empty'];
    }

    $url = "https://api.telegram.org/bot{$botToken}/{$method}";

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $curlOpts = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ];

        $proxy = trim($proxy);
        if ($proxy !== '') {
            $curlOpts[CURLOPT_PROXY] = $proxy;
            if (stripos($proxy, 'socks5://') === 0 || stripos($proxy, 'socks5h://') === 0) {
                $curlOpts[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5_HOSTNAME;
            } elseif (stripos($proxy, 'http://') === 0 || stripos($proxy, 'https://') === 0) {
                $curlOpts[CURLOPT_PROXYTYPE] = CURLPROXY_HTTP;
            }
        }

        curl_setopt_array($ch, $curlOpts);
        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err !== '' || $response === false) {
            return ['ok' => false, 'error' => $err ?: 'Network error connecting to Telegram API'];
        }
        $data = json_decode((string) $response, true);
        return is_array($data) ? $data : ['ok' => false, 'error' => 'Invalid JSON from Telegram'];
    }

    $httpOpts = [
        'method'        => 'POST',
        'header'        => "Content-Type: application/json; charset=utf-8\r\n",
        'content'       => json_encode($params, JSON_UNESCAPED_UNICODE),
        'timeout'       => 12,
        'ignore_errors' => true,
    ];
    $proxy = trim($proxy);
    if ($proxy !== '') {
        $httpOpts['proxy'] = $proxy;
        $httpOpts['request_fulluri'] = true;
    }
    $ctx = stream_context_create([
        'http' => $httpOpts,
        'ssl'  => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ],
    ]);
    $response = @file_get_contents($url, false, $ctx);
    if ($response === false) {
        $lastErr = error_get_last();
        return ['ok' => false, 'error' => 'Connection to Telegram API failed: ' . ($lastErr['message'] ?? 'Network unreachable')];
    }
    $data = json_decode($response, true);
    return is_array($data) ? $data : ['ok' => false, 'error' => 'Invalid JSON from Telegram'];
}

/**
 * Format and dispatch real-time Telegram order notification to admin.
 */
function notify_telegram_new_order(PDO $db, int $orderId, string $tableNumber, array $items, float $totalPrice, string $note = ''): bool
{
    $settings = all_settings($db);
    $enabled  = ($settings['telegram_notifications_enabled'] ?? '1') !== '0';
    $token    = trim((string) ($settings['telegram_bot_token'] ?? ''));
    $chatId   = trim((string) ($settings['telegram_chat_id'] ?? ''));
    $proxy    = trim((string) ($settings['telegram_proxy'] ?? ''));

    if (!$enabled || $token === '' || $chatId === '') {
        return false;
    }

    $currency = $settings['currency'] ?? 'تومان';
    $time     = date('H:i');
    $date     = date('Y/m/d');

    $msg  = "🔔 <b>سفارش جدید ثبت شد! (#{$orderId})</b>\n\n";
    $msg .= "📍 <b>شماره میز:</b> <code>{$tableNumber}</code>\n";
    $msg .= "🕒 <b>زمان ثبت:</b> {$time} — {$date}\n\n";
    $msg .= "📋 <b>اقلام سفارش:</b>\n";

    foreach ($items as $item) {
        $name  = htmlspecialchars((string) ($item['name'] ?? 'آیتم'), ENT_QUOTES, 'UTF-8');
        $qty   = max(1, (int) ($item['qty'] ?? 1));
        $price = (float) ($item['price'] ?? 0);
        $itemTotal = number_format($price * $qty) . ' ' . $currency;
        $msg .= "▫️ <b>{$qty}×</b> {$name} (<code>{$itemTotal}</code>)\n";
    }

    $formattedTotal = number_format($totalPrice) . ' ' . $currency;
    $msg .= "\n💰 <b>مبلغ کل:</b> <code>{$formattedTotal}</code>\n";

    if ($note !== '') {
        $safeNote = htmlspecialchars($note, ENT_QUOTES, 'UTF-8');
        $msg .= "📝 <b>یادداشت مشتری:</b> <i>{$safeNote}</i>\n";
    }

    $msg .= "\n⚡️ <i>وضعیت: در انتظار آماده‌سازی</i>";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '✅ تأیید و آماده‌سازی', 'callback_data' => "order_complete_{$orderId}"],
                ['text' => '❌ لغو سفارش',         'callback_data' => "order_cancel_{$orderId}"],
            ],
        ],
    ];

    $res = send_telegram_request($token, 'sendMessage', [
        'chat_id'      => $chatId,
        'text'         => $msg,
        'parse_mode'   => 'HTML',
        'reply_markup' => $keyboard,
    ], $proxy);

    return !empty($res['ok']);
}
