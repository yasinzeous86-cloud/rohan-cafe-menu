<?php
/**
 * Hyper-Fast Local Telegram Polling Worker — Rohan Cafe Menu
 *
 * Runs in-memory without HTTP loopbacks, reuses TLS connections,
 * and responds to Telegram user taps in ~200-300ms.
 */

declare(strict_types=1);

require_once __DIR__ . '/webhook.php';

const DB_PATH = __DIR__ . '/menu.sqlite';

echo "[FastPoller] Starting Ultra-Responsive Telegram Poller...\n";

if (!file_exists(DB_PATH)) {
    fwrite(STDERR, "[FastPoller Error] Database not found\n");
    exit(1);
}

$db = new PDO('sqlite:' . DB_PATH, null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
@$db->exec('PRAGMA journal_mode = WAL');

$lastOffset = 0;
$pollCh = curl_init();

// Fetch settings once before loop
$stmt = $db->query('SELECT setting_key, setting_value FROM settings');
$settings = [];
while ($r = $stmt->fetch()) {
    $settings[$r['setting_key']] = $r['setting_value'];
}

$token = trim((string) ($settings['telegram_bot_token'] ?? ''));
$proxy = trim((string) ($settings['telegram_proxy'] ?? ''));

echo "[FastPoller] Bot connected: " . substr($token, 0, 10) . "...\n";
echo "[FastPoller] Listening for user taps & messages with sub-second latency...\n";

$loopCount = 0;

while (true) {
    // Refresh settings every 10 iterations in case user changes anything in admin panel
    if (++$loopCount % 10 === 0) {
        $stmt = $db->query('SELECT setting_key, setting_value FROM settings');
        $settings = [];
        while ($r = $stmt->fetch()) {
            $settings[$r['setting_key']] = $r['setting_value'];
        }
        $token = trim((string) ($settings['telegram_bot_token'] ?? ''));
        $proxy = trim((string) ($settings['telegram_proxy'] ?? ''));
    }

    if ($token === '') {
        sleep(2);
        continue;
    }

    $url = "https://api.telegram.org/bot{$token}/getUpdates?offset={$lastOffset}&timeout=2";

    $curlOpts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TCP_KEEPALIVE  => 1,
    ];

    if ($proxy !== '') {
        $curlOpts[CURLOPT_PROXY] = $proxy;
        if (stripos($proxy, 'socks5') === 0) {
            $curlOpts[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5_HOSTNAME;
        } elseif (stripos($proxy, 'http') === 0) {
            $curlOpts[CURLOPT_PROXYTYPE] = CURLPROXY_HTTP;
        }
    } else {
        $curlOpts[CURLOPT_PROXY] = '';
    }

    curl_setopt_array($pollCh, $curlOpts);
    $resp = curl_exec($pollCh);
    $err  = curl_error($pollCh);

    if ($err !== '' || $resp === false) {
        // Reconnect if socket closed
        curl_close($pollCh);
        $pollCh = curl_init();
        usleep(200000); // 200ms
        continue;
    }

    $data = json_decode((string) $resp, true);
    if (!is_array($data) || empty($data['ok'])) {
        usleep(200000);
        continue;
    }

    $updates = $data['result'] ?? [];
    if (!empty($updates)) {
        foreach ($updates as $up) {
            $upId = (int) ($up['update_id'] ?? 0);
            if ($upId >= $lastOffset) {
                $lastOffset = $upId + 1;
            }

            // INSTANT in-memory processing (no HTTP loopbacks!)
            try {
                handle_telegram_update($up, $db, $settings);
            } catch (Throwable $e) {
                fwrite(STDERR, "[Update Error] " . $e->getMessage() . "\n");
            }
        }
    }
}
