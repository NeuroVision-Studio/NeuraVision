<?php
/**
 * Reddit Conversions API — server-side copy of the pixel events fired on the Download click.
 * The same conversion_id is sent by the pixel (flow/download.html), so Reddit deduplicates the pair.
 * Config: data/reddit_capi.php → ['pixel_id' => ..., 'token' => ...]. No config = no call.
 */
function reddit_capi_download($os, $cid) {
    if (!preg_match('/^dl-[a-z0-9-]{6,40}$/', (string)$cid)) return;
    if (!empty($_COOKIE['lx_notrack']) || ($_COOKIE['lx_consent'] ?? '') === '0') return;
    if (!empty($_COOKIE['lx_eea']) && ($_COOKIE['lx_consent'] ?? '') !== '1') return;
    $cfgFile = __DIR__ . '/../data/reddit_capi.php';
    if (!is_file($cfgFile)) return;
    $cfg = include $cfgFile;
    if (empty($cfg['pixel_id']) || empty($cfg['token'])) return;

    $ip = trim(explode(',', (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''))[0]);
    $user = ['user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400)];
    if (filter_var($ip, FILTER_VALIDATE_IP)) $user['ip_address'] = $ip;
    if (!empty($_COOKIE['_rdt_uuid'])) $user['uuid'] = substr((string)$_COOKIE['_rdt_uuid'], 0, 100);
    if (!empty($_COOKIE['lx_sid'])) $user['external_id'] = substr((string)$_COOKIE['lx_sid'], 0, 64);

    $base = [
        'event_at' => (int)round(microtime(true) * 1000),
        'action_source' => 'WEBSITE',
        'user' => $user,
        // The pixel sends SHA-256 of the conversionId; send the same hash so both sides match.
        'metadata' => ['conversion_id' => hash('sha256', (string)$cid)],
    ];
    if (!empty($_COOKIE['_rdt_cid'])) $base['click_id'] = substr((string)$_COOKIE['_rdt_cid'], 0, 200);

    $events = [
        $base + ['type' => ['tracking_type' => 'LEAD']],
        $base + ['type' => ['tracking_type' => 'CUSTOM', 'custom_event_name' => 'Download']],
    ];
    $data = ['events' => $events];
    if (!empty($_GET['rdt_test']) && preg_match('/^t2_[a-z0-9_]{1,40}$/i', (string)$_GET['rdt_test'])) $data['test_id'] = (string)$_GET['rdt_test'];

    $ch = curl_init('https://ads-api.reddit.com/api/v3/pixels/' . rawurlencode($cfg['pixel_id']) . '/conversion_events');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['data' => $data]),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cfg['token'], 'Content-Type: application/json', 'User-Agent: neuracreation-capi/1.0'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    @file_put_contents(__DIR__ . '/../data/reddit_capi.log',
        gmdate('c') . ' ' . $os . ' ' . $cid . ' http=' . $code . (isset($data['test_id']) ? ' test' : '') . (isset($base['click_id']) ? ' click' : '') . ' ' . substr(preg_replace('/\s+/', ' ', $code === 200 ? (string)$resp : ($err !== '' ? $err : (string)$resp)), 0, 200) . "\n",
        FILE_APPEND | LOCK_EX);
}
