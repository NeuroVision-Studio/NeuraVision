<?php
/**
 * nv_pow.php — self-hosted invisible proof-of-work captcha (ALTCHA-style). 2026-10-10.
 * Used by path 3 of the landing split ("download without registration"): no file is handed out
 * by api/desktop_download.php until the browser has solved one of these challenges.
 *
 *   challenge = sha256(salt . number)        number is secret, 0..maxnumber
 *   signature = hmac_sha256(challenge, key)  key = data/.nv_pow_secret (generated once, 0600)
 *   salt      = <random hex>?expires=<unix>  (10-minute lifetime)
 * The browser brute-forces number (SHA-256, in a Web Worker), sends back
 * {algorithm, challenge, number, salt, signature} as base64(JSON). The server re-checks
 * hash + HMAC + expiry and burns the signature (single use, data/nv_pow_used.sqlite).
 * No external service, no keys in .env.
 */
if (!function_exists('nv_pow_challenge')) {

define('NV_POW_MAX', 1000000);
define('NV_POW_TTL', 600);

function nv_pow_secret() {
    static $k = null;
    if ($k !== null) return $k;
    $f = dirname(__DIR__) . '/data/.nv_pow_secret';
    $v = is_file($f) ? trim((string)@file_get_contents($f)) : '';
    if (strlen($v) < 32) {
        $v = bin2hex(random_bytes(32));
        @file_put_contents($f, $v, LOCK_EX);
        @chmod($f, 0600);
    }
    return $k = $v;
}

function nv_pow_challenge($max = NV_POW_MAX, $ttl = NV_POW_TTL) {
    $salt = bin2hex(random_bytes(12)) . '?expires=' . (time() + (int)$ttl);
    $number = random_int((int)($max * 0.1), (int)$max);
    $challenge = hash('sha256', $salt . $number);
    return [
        'algorithm' => 'SHA-256',
        'challenge' => $challenge,
        'maxnumber' => (int)$max,
        'salt'      => $salt,
        'signature' => hash_hmac('sha256', $challenge, nv_pow_secret()),
    ];
}

function nv_pow_db() {
    $db = new SQLite3(dirname(__DIR__) . '/data/nv_pow_used.sqlite');
    $db->busyTimeout(3000);
    $db->exec('CREATE TABLE IF NOT EXISTS used (sig TEXT PRIMARY KEY, exp INTEGER NOT NULL, at INTEGER NOT NULL)');
    return $db;
}

/**
 * Verify a solved token. $consume=false checks it without burning it (HEAD requests).
 * Returns true/false; $err gets a short reason code.
 */
function nv_pow_verify($token, &$err = '', $consume = true) {
    $err = '';
    $token = (string)$token;
    if ($token === '' || strlen($token) > 2000) { $err = 'missing'; return false; }
    $raw = base64_decode(strtr($token, '-_ ', '+/+'), true);
    $p = $raw !== false ? json_decode($raw, true) : null;
    if (!is_array($p)) { $err = 'malformed'; return false; }
    $alg = (string)($p['algorithm'] ?? ''); $ch = strtolower((string)($p['challenge'] ?? ''));
    $num = $p['number'] ?? null; $salt = (string)($p['salt'] ?? ''); $sig = strtolower((string)($p['signature'] ?? ''));
    if ($alg !== 'SHA-256' || !preg_match('/^[a-f0-9]{64}$/', $ch) || !preg_match('/^[a-f0-9]{64}$/', $sig)
        || !is_numeric($num) || (int)$num < 0 || (int)$num > NV_POW_MAX * 2) { $err = 'malformed'; return false; }
    if (!preg_match('/^[a-f0-9]{24}\?expires=(\d{10})$/', $salt, $m)) { $err = 'salt'; return false; }
    $exp = (int)$m[1];
    if ($exp < time()) { $err = 'expired'; return false; }
    if (!hash_equals(hash_hmac('sha256', $ch, nv_pow_secret()), $sig)) { $err = 'signature'; return false; }
    if (!hash_equals(hash('sha256', $salt . (int)$num), $ch)) { $err = 'solution'; return false; }
    if (!$consume) return true;
    try {
        $db = nv_pow_db();
        $st = $db->prepare('INSERT OR IGNORE INTO used (sig, exp, at) VALUES (:s, :e, :a)');
        $st->bindValue(':s', $sig, SQLITE3_TEXT); $st->bindValue(':e', $exp, SQLITE3_INTEGER); $st->bindValue(':a', time(), SQLITE3_INTEGER);
        $st->execute();
        $fresh = $db->changes() === 1;
        if (random_int(1, 50) === 1) $db->exec('DELETE FROM used WHERE exp < ' . (time() - 60));
        $db->close();
        if (!$fresh) { $err = 'reused'; return false; }
    } catch (Throwable $e) { $err = 'store'; error_log('nv_pow_verify: ' . $e->getMessage()); return false; }
    return true;
}

}
