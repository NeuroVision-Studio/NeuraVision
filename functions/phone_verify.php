<?php

// TG notify on phone verified
if (isset($_GET['action']) && $_GET['action'] === 'tg_verified') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $uid = $input['user_id'] ?? '';
    $provider = $input['provider'] ?? 'unknown';
    $phone = $input['phone'] ?? 'unknown';
    // Send TG notification
    $msg = "✅ Phone Verified
" . "Provider: {$provider}
" . "User: {$uid}
" . "Phone: {$phone}
" . "Status: FULLY REGISTERED";
    $bot = getenv('TG_BOT_TOKEN') ?: '';
    $chat = getenv('TG_CHAT_ID') ?: '';
    $url = "https://api.telegram.org/bot{$bot}/sendMessage";
    $payload = json_encode(['chat_id' => $chat, 'text' => $msg]);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_exec($ch);
    curl_close($ch);
    echo json_encode(['ok' => true]);
    exit;
}

/**
 * Phone Verification via Prelude API v2
 * Handles sending SMS codes and verifying them
 * Supports BOTH databases:
 *   - keys.db (api.leronx.org) for Google/X OAuth users
 *   - users.sqlite (leronx.org) for email-registered users
 * 
 * Endpoints:
 *   POST /functions/phone_verify.php?action=send   - Send verification code
 *   POST /functions/phone_verify.php?action=check  - Check verification code
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://leronx.org');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}
require_once __DIR__ . "/../includes/x_ads_config.php"; // loads .env

// Prelude API configuration
define('PRELUDE_API_KEY', lx_env_value('PRELUDE_API_KEY'));
define('PRELUDE_API_URL', 'https://api.prelude.dev/v2');

// Database: keys.db (OAuth users - Google/X)
function getKeysDb() {
    static $db = null;
    if ($db !== null) return $db;
    $dbPath = '/www/wwwroot/neuracreation.com/api/app_data/keys.db';
    if (!file_exists($dbPath)) return null;
    $db = new SQLite3($dbPath);
    $db->busyTimeout(5000);
    return $db;
}

// Database: users.sqlite (email-registered users)
function getUsersDb() {
    static $db = null;
    if ($db !== null) return $db;
    $dbPath = __DIR__ . '/../data/users.sqlite';
    if (!file_exists($dbPath)) return null;
    $db = new SQLite3($dbPath);
    $db->busyTimeout(5000);
    return $db;
}

/**
 * Find user across both databases
 * Returns: ['source' => 'keys'|'users', 'id' => int, 'row' => array] or null
 */
function findUser($userId, $token) {
    // 1. Try keys.db first (OAuth users: user_id = LX-XXXXX, auth_token)
    $keysDb = getKeysDb();
    if ($keysDb) {
        $stmt = $keysDb->prepare('SELECT id, user_id, email FROM users WHERE user_id = :uid AND auth_token = :token');
        $stmt->bindValue(':uid', $userId, SQLITE3_TEXT);
        $stmt->bindValue(':token', $token, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        if ($row) {
            return ['source' => 'keys', 'id' => (int)$row['id'], 'row' => $row];
        }
    }
    
    // 2. Try users.sqlite (email users: id = numeric, token = hex)
    $usersDb = getUsersDb();
    if ($usersDb) {
        $stmt = $usersDb->prepare('SELECT id, email FROM users WHERE id = :uid AND token = :token');
        $stmt->bindValue(':uid', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':token', $token, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        if ($row) {
            return ['source' => 'users', 'id' => (int)$row['id'], 'row' => $row];
        }
    }
    
    return null;
}

// Route
$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?? [];

switch ($action) {
    case 'send':
        handleSend($input);
        break;
    case 'check':
        handleCheck($input);
        break;
    case 'send_email':
        handleSendEmail($input);
        break;
    case 'check_email':
        handleCheckEmail($input);
        break;
    case 'fbdiag':
        lx_pv_audit('fb_diag','-',false,substr((string)($input['err'] ?? ''),0,200));
        echo json_encode(['ok'=>true]);
        break;
    case 'firebase_verify':
        handleFirebaseVerify($input);
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
}

/** Best-effort client IP. */
function lx_pv_client_ip() {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_REAL_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return 'unknown';
}

/** Simple file-based sliding-window rate limiter. Returns false when over limit. */
function lx_pv_rate_limit($key, $max, $windowSec) {
    $file = sys_get_temp_dir() . '/lx_pv_' . md5($key) . '.json';
    $now  = time();
    $hits = is_file($file) ? (json_decode(@file_get_contents($file), true) ?: []) : [];
    $hits = array_values(array_filter($hits, function ($t) use ($now, $windowSec) { return ($now - $t) < $windowSec; }));
    if (count($hits) >= $max) return false;
    $hits[] = $now;
    @file_put_contents($file, json_encode($hits), LOCK_EX);
    return true;
}

/**
 * Audit logger for phone-verification (writes to shared auth_events in users.sqlite).
 * Provider="phone". No raw phone numbers stored — masked to last 4 digits.
 * Added 2026-06-21.
 */
function lx_pv_audit($type, $email, $ok, $reason) {
    try {
        $db = getUsersDb();
        if (!$db) return;
        $db->exec("CREATE TABLE IF NOT EXISTS auth_events (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT NOT NULL, type TEXT NOT NULL, provider TEXT, email TEXT, ok INTEGER NOT NULL DEFAULT 0, reason TEXT, ip TEXT, user_agent TEXT)");
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : '';
        $st = $db->prepare('INSERT INTO auth_events (created_at,type,provider,email,ok,reason,ip,user_agent) VALUES (:c,:t,:p,:e,:o,:r,:i,:u)');
        $st->bindValue(':c', date('c'), SQLITE3_TEXT);
        $st->bindValue(':t', $type, SQLITE3_TEXT);
        $st->bindValue(':p', 'phone', SQLITE3_TEXT);
        $st->bindValue(':e', strtolower(trim((string)$email)), SQLITE3_TEXT);
        $st->bindValue(':o', $ok ? 1 : 0, SQLITE3_INTEGER);
        $st->bindValue(':r', (string)$reason, SQLITE3_TEXT);
        $st->bindValue(':i', lx_pv_client_ip(), SQLITE3_TEXT);
        $st->bindValue(':u', $ua, SQLITE3_TEXT);
        $st->execute();
    } catch (Exception $e) { /* never break verify flow */ }
}

function lx_pv_mask_phone($p) {
    $p = preg_replace('/\D+/', '', (string)$p);
    return $p === '' ? '' : ('***' . substr($p, -4));
}

/**
 * Send verification SMS to phone number
 */
function handleSend($input) {
    $phone = $input['phone'] ?? '';
    $userId = $input['user_id'] ?? '';
    $token = $input['token'] ?? '';

    if (empty($phone)) {
        http_response_code(400);
        lx_pv_audit('verify_send', 'uid:' . $userId, false, 'phone missing');
        echo json_encode(['error' => 'Phone number is required']);
        return;
    }

    // Region restriction: India blocked — bot/abuse mitigation. Flag the user so the
    // email fallback can't be used to bypass it. (2026-06-22)
    if (lx_pv_phone_is_india($phone)) {
        @file_put_contents(lx_pv_india_flag($userId), '1');
        http_response_code(403);
        lx_pv_audit('verify_send', 'uid:' . $userId, false, 'country blocked: IN');
        echo json_encode(['error' => 'Sorry, your country is not supported at this time.']);
        return;
    }
    $lxBlk = lx_pv_block_ip(lx_pv_client_ip());
    if ($lxBlk !== '') {
        @file_put_contents(lx_pv_india_flag($userId), '1');
        http_response_code(403);
        lx_pv_audit('verify_send', 'uid:' . $userId, false, 'blocked: ' . $lxBlk);
        echo json_encode(['error' => 'Sorry, your country is not supported at this time.']);
        return;
    }

    if (empty($userId) || empty($token)) {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required']);
        return;
    }

    // Find user in either database
    $user = findUser($userId, $token);
    if (!$user) {
        http_response_code(401);
        lx_pv_audit('verify_send', 'uid:' . $userId, false, 'invalid credentials');
        echo json_encode(['error' => 'Invalid credentials']);
        return;
    }

    // Rate limiting: protect against SMS pumping / Prelude cost abuse (2026-06-21).
    $ip = lx_pv_client_ip();
    if (!lx_pv_rate_limit('u_' . $userId, 5, 600) || !lx_pv_rate_limit('ip_' . $ip, 8, 600)) {
        http_response_code(429);
        lx_pv_audit('verify_send', isset($user['row']['email']) ? $user['row']['email'] : ('uid:' . $userId), false, 'rate limited');
        echo json_encode(['error' => 'Too many attempts. Please wait a few minutes and try again.']);
        return;
    }

    // Active SMS provider (Plivo/Vonage/Twilio) — used when SMS_PROVIDER is configured. Prelude = legacy fallback. (2026-06-23)
    require_once __DIR__ . '/sms_provider.php';
    if (lx_sms_provider() !== '') {
        if ($user['source'] === 'keys') { $db=getKeysDb(); $u=$db->prepare('UPDATE users SET phone_number=:p WHERE id=:i'); $u->bindValue(':p',$phone,SQLITE3_TEXT); $u->bindValue(':i',$user['id'],SQLITE3_INTEGER); $u->execute(); }
        else { $db=getUsersDb(); $u=$db->prepare('UPDATE users SET phone=:p WHERE id=:i'); $u->bindValue(':p',$phone,SQLITE3_TEXT); $u->bindValue(':i',$user['id'],SQLITE3_INTEGER); $u->execute(); }
        $res = lx_sms_send_code($userId, $phone);
        lx_pv_audit('verify_send', isset($user['row']['email'])?$user['row']['email']:('uid:'.$userId), !empty($res['ok']), 'sms('.lx_sms_provider().') '.(!empty($res['ok'])?('sent '.lx_pv_mask_phone($phone)):($res['error']??'fail')));
        if (!empty($res['ok'])) echo json_encode(['ok'=>true,'status'=>'success','channel'=>'sms']);
        else { http_response_code(502); echo json_encode(['ok'=>false,'error'=>'Could not send the SMS right now. Please try again.']); }
        return;
    }

    // Call Prelude API to create verification
    $response = preludeRequest('/verification', [
        'target' => [
            'type' => 'phone_number',
            'value' => $phone
        ]
    ]);

    if ($response['http_code'] === 200 || $response['http_code'] === 201) {
        $data = json_decode($response['body'], true);

        // Prelude returns HTTP 200 even when it does NOT actually send the SMS
        // (status: blocked / retry). Only 'success' means a code went out. (2026-06-22)
        $pstatus = $data['status'] ?? '';
        if ($pstatus !== 'success') {
            $preason = $data['reason'] ?? $pstatus;
            $pmsg = ($pstatus === 'blocked')
                ? 'Too many attempts to this number. Please wait ~15 minutes or use a different number.'
                : 'Could not send the code right now. Please try again shortly or use another number.';
            http_response_code(429);
            lx_pv_audit('verify_send', isset($user['row']['email']) ? $user['row']['email'] : ('uid:' . $userId), false, 'prelude status=' . $pstatus . ' (' . $preason . ')');
            echo json_encode(['ok' => false, 'error' => $pmsg, 'status' => $pstatus]);
            return;
        }

        // Store phone number in the correct database
        if ($user['source'] === 'keys') {
            $db = getKeysDb();
            $upd = $db->prepare('UPDATE users SET phone_number = :phone WHERE id = :id');
            $upd->bindValue(':phone', $phone, SQLITE3_TEXT);
            $upd->bindValue(':id', $user['id'], SQLITE3_INTEGER);
            $upd->execute();
        } else {
            $db = getUsersDb();
            $upd = $db->prepare('UPDATE users SET phone = :phone WHERE id = :id');
            $upd->bindValue(':phone', $phone, SQLITE3_TEXT);
            $upd->bindValue(':id', $user['id'], SQLITE3_INTEGER);
            $upd->execute();
        }

        lx_pv_audit('verify_send', isset($user['row']['email']) ? $user['row']['email'] : ('uid:' . $userId), true, 'code sent ' . lx_pv_mask_phone($phone));
        echo json_encode([
            'ok' => true,
            'status' => $data['status'] ?? 'success',
            'verification_id' => $data['id'] ?? null
        ]);
    } else {
        $data = json_decode($response['body'], true);
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => $data['message'] ?? 'Failed to send verification',
            'code' => $data['code'] ?? 'unknown'
        ]);
        lx_pv_audit('verify_send', isset($user['row']['email']) ? $user['row']['email'] : ('uid:' . $userId), false, 'prelude: ' . ($data['message'] ?? 'failed'));
    }
}

/**
 * Check verification code
 */
function handleCheck($input) {
    $phone = $input['phone'] ?? '';
    $code = $input['code'] ?? '';
    $userId = $input['user_id'] ?? '';
    $token = $input['token'] ?? '';

    if (empty($phone) || empty($code)) {
        http_response_code(400);
        lx_pv_audit('verify_check', 'uid:' . $userId, false, 'phone/code missing');
        echo json_encode(['error' => 'Phone and code are required']);
        return;
    }

    if (empty($userId) || empty($token)) {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required']);
        return;
    }

    // Find user in either database
    $user = findUser($userId, $token);
    if (!$user) {
        http_response_code(401);
        lx_pv_audit('verify_check', 'uid:' . $userId, false, 'invalid credentials');
        echo json_encode(['error' => 'Invalid credentials']);
        return;
    }

    // Active SMS provider verify (2026-06-23)
    require_once __DIR__ . '/sms_provider.php';
    if (lx_sms_provider() !== '') {
        $res = lx_sms_check_code($userId, $code);
        if (!empty($res['verified'])) {
            if ($user['source'] === 'keys') { $db=getKeysDb(); $u=$db->prepare('UPDATE users SET phone_number=:p, phone_verified=1 WHERE id=:i'); $u->bindValue(':p',$phone,SQLITE3_TEXT); $u->bindValue(':i',$user['id'],SQLITE3_INTEGER); $u->execute(); }
            else { $db=getUsersDb(); $u=$db->prepare('UPDATE users SET phone=:p, phone_verified=1 WHERE id=:i'); $u->bindValue(':p',$phone,SQLITE3_TEXT); $u->bindValue(':i',$user['id'],SQLITE3_INTEGER); $u->execute(); }
            lx_pv_audit('verify_check', isset($user['row']['email'])?$user['row']['email']:('uid:'.$userId), true, 'sms verified '.lx_pv_mask_phone($phone));
            echo json_encode(['ok'=>true,'verified'=>true]);
        } else { echo json_encode(['ok'=>true,'verified'=>false,'error'=>$res['error']??'Incorrect code.']); }
        return;
    }

    // Call Prelude API to check code
    $response = preludeRequest('/verification/check', [
        'target' => [
            'type' => 'phone_number',
            'value' => $phone
        ],
        'code' => $code
    ]);

    if ($response['http_code'] === 200) {
        $data = json_decode($response['body'], true);
        $status = $data['status'] ?? '';

        if ($status === 'success') {
            // Mark phone as verified in the correct database
            if ($user['source'] === 'keys') {
                $db = getKeysDb();
                $upd = $db->prepare('UPDATE users SET phone_number = :phone, phone_verified = 1 WHERE id = :id');
                $upd->bindValue(':phone', $phone, SQLITE3_TEXT);
                $upd->bindValue(':id', $user['id'], SQLITE3_INTEGER);
                $upd->execute();
            } else {
                $db = getUsersDb();
                $upd = $db->prepare('UPDATE users SET phone = :phone, phone_verified = 1 WHERE id = :id');
                $upd->bindValue(':phone', $phone, SQLITE3_TEXT);
                $upd->bindValue(':id', $user['id'], SQLITE3_INTEGER);
                $upd->execute();
            }

            lx_pv_audit('verify_check', isset($user['row']['email']) ? $user['row']['email'] : ('uid:' . $userId), true, 'verified ' . lx_pv_mask_phone($phone));
            echo json_encode([
                'ok' => true,
                'verified' => true
            ]);
        } else {
            lx_pv_audit('verify_check', isset($user['row']['email']) ? $user['row']['email'] : ('uid:' . $userId), false, 'wrong/expired code (status=' . $status . ')');
            echo json_encode([
                'ok' => true,
                'verified' => false,
                'status' => $status
            ]);
        }
    } else {
        $data = json_decode($response['body'], true);
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => $data['message'] ?? 'Verification failed',
            'code' => $data['code'] ?? 'unknown'
        ]);
        lx_pv_audit('verify_check', isset($user['row']['email']) ? $user['row']['email'] : ('uid:' . $userId), false, 'prelude: ' . ($data['message'] ?? 'failed'));
    }
}

/**
 * Make request to Prelude API
 */
function preludeRequest($endpoint, $body) {
    $ch = curl_init(PRELUDE_API_URL . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . PRELUDE_API_KEY
        ],
        CURLOPT_TIMEOUT => 30
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [
        'http_code' => $httpCode,
        'body' => $response
    ];
}


/* ---------- India region guard helpers (2026-06-22) ---------- */
function lx_pv_phone_is_india($phone) {
    $n = preg_replace('/[^0-9+]/', '', (string)$phone);
    return (strpos($n, '+91') === 0 || strpos($n, '0091') === 0 || preg_match('/^91[6-9][0-9]{9}$/', $n)) ? true : false;
}
function lx_pv_india_flag($userId) { return sys_get_temp_dir() . '/lx_india_' . md5((string)$userId) . '.flag'; }
// Returns block reason for an IP: 'IN' (India), 'datacenter' (hosting/bot IP), or '' (allow). Fail-open.
function lx_pv_block_ip($ip) {
    if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) return '';
    $cf = sys_get_temp_dir() . '/lx_geo_' . md5($ip) . '.json';
    $d = null;
    if (is_file($cf) && (time() - filemtime($cf) < 86400)) { $d = json_decode(@file_get_contents($cf), true); }
    if (!is_array($d)) {
        $ch = curl_init('http://ip-api.com/json/' . urlencode($ip) . '?fields=status,countryCode,hosting');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
        $r = curl_exec($ch); curl_close($ch);
        $d = $r ? json_decode($r, true) : null;
        if (is_array($d) && ($d['status'] ?? '') === 'success') @file_put_contents($cf, json_encode($d));
    }
    if (!is_array($d)) return '';
    if (($d['countryCode'] ?? '') === 'IN') return 'IN';
    if (!empty($d['hosting'])) return 'datacenter';
    return '';
}
function lx_pv_user_is_india($userId, $user) {
    if (is_file(lx_pv_india_flag($userId))) return true;
    $stored = $user['row']['phone'] ?? ($user['row']['phone_number'] ?? '');
    if (lx_pv_phone_is_india($stored)) return true;
    if (lx_pv_block_ip(lx_pv_client_ip()) !== '') { @file_put_contents(lx_pv_india_flag($userId), '1'); return true; }
    return false;
}

/* ---------- Firebase Phone Auth verify (client did SMS+code; we validate the ID token). 2026-06-23 ---------- */
function handleFirebaseVerify($input) {
    $userId = $input['user_id'] ?? '';
    $token  = $input['token'] ?? '';
    $idToken= $input['id_token'] ?? '';
    if (empty($userId) || empty($token)) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Authentication required']); return; }
    $user = findUser($userId, $token);
    if (!$user) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Invalid credentials']); return; }
    if (empty($idToken)) { http_response_code(400); echo json_encode(['ok'=>false,'verified'=>false,'error'=>'Missing token']); return; }
    $apiKey = (function_exists('lx_env_value') && lx_env_value('FIREBASE_API_KEY')) ? lx_env_value('FIREBASE_API_KEY') : 'AIzaSyDZjCrvsa74rPXdd1i7dwAgJ0ew8ubfUIs';
    $ch = curl_init('https://identitytoolkit.googleapis.com/v1/accounts:lookup?key='.urlencode($apiKey));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10, CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_POSTFIELDS=>json_encode(['idToken'=>$idToken])]);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $data = $resp ? json_decode($resp, true) : null;
    $phone = $data['users'][0]['phoneNumber'] ?? '';
    if ($code !== 200 || $phone === '') {
        http_response_code(400);
        lx_pv_audit('verify_check', isset($user['row']['email'])?$user['row']['email']:('uid:'.$userId), false, 'firebase token invalid');
        echo json_encode(['ok'=>true,'verified'=>false,'error'=>'Could not verify. Please try again.']); return;
    }
    if (lx_pv_phone_is_india($phone)) {
        @file_put_contents(lx_pv_india_flag($userId), '1');
        http_response_code(403);
        lx_pv_audit('verify_check', $phone, false, 'country blocked: IN (firebase)');
        echo json_encode(['ok'=>false,'verified'=>false,'error'=>'Sorry, your country is not supported at this time.']); return;
    }
    if ($user['source'] === 'keys') { $db=getKeysDb(); $u=$db->prepare('UPDATE users SET phone_number=:p, phone_verified=1 WHERE id=:i'); $u->bindValue(':p',$phone,SQLITE3_TEXT); $u->bindValue(':i',$user['id'],SQLITE3_INTEGER); $u->execute(); }
    else { $db=getUsersDb(); $u=$db->prepare('UPDATE users SET phone=:p, phone_verified=1 WHERE id=:i'); $u->bindValue(':p',$phone,SQLITE3_TEXT); $u->bindValue(':i',$user['id'],SQLITE3_INTEGER); $u->execute(); }
    lx_pv_audit('verify_check', isset($user['row']['email'])?$user['row']['email']:('uid:'.$userId), true, 'firebase verified '.lx_pv_mask_phone($phone));
    echo json_encode(['ok'=>true,'verified'=>true]);
}

/* ---------- Email fallback (used when Prelude SMS is blocked/undeliverable). 2026-06-22 ---------- */
function lx_ecode_file($userId) { return sys_get_temp_dir() . '/lx_ecode_' . md5((string)$userId) . '.json'; }

function handleSendEmail($input) {
    $userId = $input['user_id'] ?? '';
    $token  = $input['token'] ?? '';
    if (empty($userId) || empty($token)) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Authentication required']); return; }
    $user = findUser($userId, $token);
    if (!$user) { http_response_code(401); lx_pv_audit('verify_send','uid:'.$userId,false,'email: invalid credentials'); echo json_encode(['ok'=>false,'error'=>'Invalid credentials']); return; }
    $email = $user['row']['email'] ?? '';
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'No valid email on file']); return; }
    if (lx_pv_user_is_india($userId, $user)) { http_response_code(403); lx_pv_audit('verify_send', $email, false, 'country blocked: IN (email)'); echo json_encode(['ok'=>false,'error'=>'Sorry, your country is not supported at this time.']); return; }
    $ip = lx_pv_client_ip();
    if (!lx_pv_rate_limit('em_'.$userId, 5, 600) || !lx_pv_rate_limit('emip_'.$ip, 10, 600)) {
        http_response_code(429); lx_pv_audit('verify_send',$email,false,'email rate limited');
        echo json_encode(['ok'=>false,'error'=>'Too many attempts. Please wait a few minutes.']); return;
    }
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    @file_put_contents(lx_ecode_file($userId), json_encode(['hash'=>password_hash($code, PASSWORD_DEFAULT),'exp'=>time()+600,'tries'=>0]), LOCK_EX);
    require_once __DIR__ . '/welcome_mail.php';
    $subject = 'Your Neuravision verification code: ' . $code;
    $html = '<div style="background:#0a0a0f;padding:32px;font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:#fff">'
          . '<h2 style="margin:0 0 8px">Verify your Neuravision account</h2>'
          . '<p style="color:#a3a3b8;margin:0 0 20px">Enter this code to finish setting up your account:</p>'
          . '<div style="font-size:34px;font-weight:800;letter-spacing:8px;background:#15151f;border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:18px;text-align:center;color:#fff">' . $code . '</div>'
          . '<p style="color:#6b7280;font-size:12px;margin-top:18px">This code expires in 10 minutes. If you did not request it, ignore this email.</p></div>';
    $from = defined('FROM_NOREPLY') ? FROM_NOREPLY : null;
    $sent = function_exists('resend_send_email') ? resend_send_email($email, $subject, $html, $from) : false;
    lx_pv_audit('verify_send', $email, (bool)$sent, $sent ? 'email code sent' : 'email send failed');
    if ($sent) echo json_encode(['ok'=>true,'channel'=>'email']);
    else { http_response_code(502); echo json_encode(['ok'=>false,'error'=>'Could not send email right now. Please try again.']); }
}

function handleCheckEmail($input) {
    $userId = $input['user_id'] ?? '';
    $token  = $input['token'] ?? '';
    $code   = preg_replace('/\D/','', (string)($input['code'] ?? ''));
    if (empty($userId) || empty($token)) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Authentication required']); return; }
    $user = findUser($userId, $token);
    if (!$user) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Invalid credentials']); return; }
    if (lx_pv_user_is_india($userId, $user)) { http_response_code(403); echo json_encode(['ok'=>false,'verified'=>false,'error'=>'Sorry, your country is not supported at this time.']); return; }
    $file = lx_ecode_file($userId);
    $rec = is_file($file) ? json_decode(@file_get_contents($file), true) : null;
    if (!$rec || ($rec['exp'] ?? 0) < time()) { echo json_encode(['ok'=>true,'verified'=>false,'error'=>'Code expired. Request a new one.']); return; }
    if (($rec['tries'] ?? 0) >= 5) { @unlink($file); echo json_encode(['ok'=>true,'verified'=>false,'error'=>'Too many tries. Request a new code.']); return; }
    if (!password_verify($code, $rec['hash'] ?? '')) {
        $rec['tries'] = ($rec['tries'] ?? 0) + 1; @file_put_contents($file, json_encode($rec), LOCK_EX);
        lx_pv_audit('verify_check', $user['row']['email'] ?? ('uid:'.$userId), false, 'wrong email code');
        echo json_encode(['ok'=>true,'verified'=>false,'error'=>'Incorrect code.']); return;
    }
    @unlink($file);
    $db = ($user['source'] === 'keys') ? getKeysDb() : getUsersDb();
    $upd = $db->prepare('UPDATE users SET phone_verified = 1 WHERE id = :id');
    $upd->bindValue(':id', $user['id'], SQLITE3_INTEGER); $upd->execute();
    lx_pv_audit('verify_check', $user['row']['email'] ?? ('uid:'.$userId), true, 'verified via email');
    echo json_encode(['ok'=>true,'verified'=>true]);
}
