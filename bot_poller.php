<?php
/**
 * Local Telegram Polling Worker — Rohan Cafe Menu
 *
 * For local testing on localhost without public domain/SSL.
 * Continuously polls Telegram getUpdates and dispatches to webhook.php.
 */

declare(strict_types=1);

const DB_FILE = __DIR__ . '/menu.sqlite';
const WEBHOOK_LOCAL_URL = 'http://localhost:8085/webhook.php';

echo "[Poller] Starting Rohan Cafe Telegram Polling Worker...\n";

if (!file_exists(DB_FILE)) {
    fwrite(STDERR, "[Poller Error] Database not found at " . DB_FILE . "\n");
    exit(1);
}

$db = new PDO('sqlite:' . DB_FILE, null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$lastOffset = 0;

while (true) {
    try {
        // Read current token and proxy dynamically from DB
        $stmt = $db->query('SELECT setting_key, setting_value FROM settings WHERE setting_key IN ("telegram_bot_token", "telegram_proxy")');
        $conf = [];
        while ($r = $stmt->fetch()) {
            $conf[$r['setting_key']] = $r['setting_value'];
        }

        $token = trim((string) ($conf['telegram_bot_token'] ?? ''));
        $proxy = trim((string) ($conf['telegram_proxy'] ?? ''));

        if ($token === '') {
            echo "[Poller] Bot token not configured yet. Retrying in 5s...\n";
            sleep(5);
            continue;
        }

        $url = "https://api.telegram.org/bot{$token}/getUpdates?offset={$lastOffset}&timeout=15";
        $ch = curl_init($url);
        $curlOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ];

        if ($proxy !== '') {
            $curlOpts[CURLOPT_PROXY] = $proxy;
            if (stripos($proxy, 'socks5') === 0) {
                $curlOpts[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5_HOSTNAME;
            } elseif (stripos($proxy, 'http') === 0) {
                $curlOpts[CURLOPT_PROXYTYPE] = CURLPROXY_HTTP;
            }
        }

        curl_setopt_array($ch, $curlOpts);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err !== '' || $resp === false) {
            // Network glitch or proxy blip, wait briefly and retry
            sleep(3);
            continue;
        }

        $data = json_decode((string) $resp, true);
        if (!is_array($data) || empty($data['ok'])) {
            sleep(3);
            continue;
        }

        $updates = $data['result'] ?? [];
        foreach ($updates as $up) {
            $upId = (int) ($up['update_id'] ?? 0);
            if ($upId >= $lastOffset) {
                $lastOffset = $upId + 1;
            }

            // Forward update to local webhook.php
            $wh = curl_init(WEBHOOK_LOCAL_URL);
            curl_setopt_array($wh, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($up, JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
            ]);
            curl_exec($wh);
            curl_close($wh);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "[Poller Exception] " . $e->getMessage() . "\n");
        sleep(3);
    }
}
