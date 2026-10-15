<?php
/**
 * Pinterest Conversions API — server-side copy of the Pinterest Tag 'lead' event fired on the Download click.
 * The same event_id (cid) is sent by the tag (flow/download.html), so Pinterest deduplicates the pair.
 * Config: data/pinterest_capi.php → ['ad_account_id' => ..., 'token' => ...]. No config = no call.
 * Test: &pin_test=1 on the download endpoint → test_mode=true in the request body.
 */
function pinterest_capi_download($os, $cid) {
    if (!preg_match('/^dl-[a-z0-9-]{6,40}$/', (string)$cid)) return;
    if (!empty($_COOKIE['lx_notrack']) || ($_COOKIE['lx_consent'] ?? '') === '0') return;
    if (!empty($_COOKIE['lx_eea']) && ($_COOKIE['lx_consent'] ?? '') !== '1') return;
    $cfgFile = __DIR__ . '/../data/pinterest_capi.php';
    if (!is_file($cfgFile)) return;
    $cfg = include $cfgFile;
    if (empty($cfg['ad_account_id']) || empty($cfg['token'])) return;

    $ip = trim(explode(',', (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''))[0]);
    $user = ['client_user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400)];
    if (filter_var($ip, FILTER_VALIDATE_IP)) $user['client_ip_address'] = $ip;
    if (!empty($_COOKIE['_epik'])) $user['click_id'] = substr((string)$_COOKIE['_epik'], 0, 200);
    if (!empty($_COOKIE['lx_sid'])) $user['external_id'] = hash('sha256', strtolower((string)$_COOKIE['lx_sid']));

    $event = [
        'event_name' => 'lead',
        'action_source' => 'web',
        'event_time' => time(),
        'event_id' => (string)$cid,
        'event_source_url' => 'https://neuracreation.com/flow/download.html',
        'user_data' => $user,
        'custom_data' => ['lead_type' => 'Download'],
    ];
    $data = ['data' => [$event]];
    $test = !empty($_GET['pin_test']);
    if ($test) $data['test_mode'] = true;

    $ch = curl_init('https://api.pinterest.com/v5/ad_accounts/' . rawurlencode((string)$cfg['ad_account_id']) . '/events');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cfg['token'], 'Content-Type: application/json', 'User-Agent: neuracreation-capi/1.0'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    @file_put_contents(__DIR__ . '/../data/pinterest_capi.log',
        gmdate('c') . ' ' . $os . ' ' . $cid . ' http=' . $code . ($test ? ' test' : '') . (isset($user['click_id']) ? ' click' : '') . ' ' . substr(preg_replace('/\s+/', ' ', $code === 200 ? (string)$resp : ($err !== '' ? $err : (string)$resp)), 0, 200) . "\n",
        FILE_APPEND | LOCK_EX);
}
