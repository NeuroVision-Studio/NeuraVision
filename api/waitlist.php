<?php
/**
 * Waitlist signup. POST /api/waitlist.php: platform, email, phone (optional), consent=1.
 * platform=webinar also accepts: source, page, lang, utm_source/medium/campaign, referrer, website (honeypot).
 * JSON: {"ok":true,"duplicate":false} or {"error":"..."} with a 4xx/5xx code.
 */
require_once __DIR__ . '/../functions/waitlist.php';
require_once __DIR__ . '/nv_notify.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function waitlist_reply($code, array $body) {
    http_response_code($code);
    echo json_encode($body);
    exit;
}

function waitlist_client_ip() {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '';
}

function waitlist_cap($s, $len) {
    $s = trim((string)$s);
    return strlen($s) > $len ? substr($s, 0, $len) : $s;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') waitlist_reply(405, ['error' => 'method_not_allowed']);

$platform = strtolower(trim((string)($_POST['platform'] ?? '')));
if (!isset(waitlist_platforms()[$platform])) waitlist_reply(400, ['error' => 'bad_platform']);

$ipHash = waitlist_ip_hash(waitlist_client_ip());

// Honeypot: a hidden field real visitors never fill in. A bot that fills it gets a fake "success"
// (no error to retry against) but nothing is stored and no notification is sent.
if (trim((string)($_POST['website'] ?? '')) !== '') {
    waitlist_reply(200, ['ok' => true, 'platform' => $platform, 'duplicate' => false]);
}

// Rate limit: counts every attempt (not just successful/new ones), so a flood of distinct fake
// emails from one IP is still caught. 8 attempts / 10 minutes per IP across all platforms.
waitlist_record_attempt($ipHash, $platform);
if ($ipHash !== '' && waitlist_recent_attempts($ipHash, 600) > 8) {
    waitlist_reply(429, ['error' => 'rate_limited']);
}

if (($_POST['consent'] ?? '') !== '1') waitlist_reply(400, ['error' => 'no_consent']);

$email = strtolower(trim((string)($_POST['email'] ?? '')));
if ($email === '' || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    waitlist_reply(400, ['error' => 'bad_email']);
}

// Phone is optional: keep only the digits and a leading "+".
$phone = trim((string)($_POST['phone'] ?? ''));
if ($phone !== '') {
    if (!preg_match('/^\+?[0-9 ()-]{6,20}$/', $phone)) waitlist_reply(400, ['error' => 'bad_phone']);
    $phone = preg_replace('/(?!^\+)[^0-9]/', '', $phone);
}

$meta = [
    'source' => waitlist_cap($_POST['source'] ?? '', 40),
    'page' => waitlist_cap($_POST['page'] ?? '', 200),
    'lang' => waitlist_cap($_POST['lang'] ?? '', 10),
    'country' => waitlist_cap($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '', 5),
    // first-touch cookies from lp_common.php when the form did not send them (popup opened on a later page)
    'utm_source' => waitlist_cap(($_POST['utm_source'] ?? '') !== '' ? $_POST['utm_source'] : ($_COOKIE['lx_src'] ?? ''), 100),
    'utm_medium' => waitlist_cap(($_POST['utm_medium'] ?? '') !== '' ? $_POST['utm_medium'] : ($_COOKIE['lx_aud'] ?? ''), 100),
    'utm_campaign' => waitlist_cap(($_POST['utm_campaign'] ?? '') !== '' ? $_POST['utm_campaign'] : ($_COOKIE['lx_camp'] ?? ''), 100),
    // fix3 2026-10-10: landing path (1/2/3, cookie nv_path), device and paid flag -> the mobile webinar Lead row
    'nv_path' => preg_replace('/[^0-3]/', '', substr((string)(($_POST['nv_path'] ?? '') !== '' ? $_POST['nv_path'] : ($_COOKIE['nv_path'] ?? '')), 0, 1)),
    'device' => in_array(($_POST['device'] ?? ''), ['mobile', 'desktop'], true) ? $_POST['device']
              : (preg_match('/iPhone|iPod|iPad|Android|Mobile/i', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')) ? 'mobile' : 'desktop'),
    'paid' => (($_POST['paid'] ?? '') === '1' || !empty($_COOKIE['nv_paid'])) ? 1 : 0,
    'referrer' => waitlist_cap($_POST['referrer'] ?? '', 300),
    'user_agent' => waitlist_cap($_SERVER['HTTP_USER_AGENT'] ?? '', 300),
    'ip_hash' => $ipHash,
    // Same anonymous session id the tracker uses (new/tracker/track.php), read straight from the
    // lx_sid cookie set by lp_common.php / includes/track_actions.php -- lets the admin dashboard
    // join a webinar lead back to its split-test arm. Blank when the cookie is missing/blocked.
    'sid' => preg_replace('/[^A-Za-z0-9-]/', '', substr((string)($_COOKIE['lx_sid'] ?? ''), 0, 36)),
];

try {
    $isNew = waitlist_add($platform, $email, $phone, $meta);
} catch (Throwable $e) {
    error_log('waitlist: ' . $e->getMessage());
    waitlist_reply(500, ['error' => 'store_failed']);
}

if ($platform === 'webinar' && $isNew) {
    nv_api_notify_webinar($email, $meta['country'], $meta['source'] ?: $meta['utm_source'], date('c'), $meta['page']);
}

waitlist_reply(200, ['ok' => true, 'platform' => $platform, 'duplicate' => !$isNew]);
