<?php
/**
 * Promo code redemption for the signed-in account dashboard (/account).
 *
 * Auth: the same httponly `lx_auth_token` cookie the rest of the dashboard uses
 * (see functions/auth_change_password.php) — never trust a user id from the body.
 * Credits are added ON TOP of the current balance, once per account, by
 * lx_promo_redeem() in functions/auth_db.php (atomic compare-and-set on `promo_code`).
 * Every success is written to `promo_redemptions` and is visible in /admin/promo/.
 *
 * Added 2026-10-09 together with the free-signup-bonus change (1000 -> 250) and HOT1000.
 */
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
error_reporting(0);

require_once __DIR__ . '/../functions/auth_db.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$in  = json_decode($raw, true);
if (!is_array($in)) $in = $_POST;

$token = isset($_COOKIE['lx_auth_token']) ? trim((string)$_COOKIE['lx_auth_token']) : '';
// the cabinet JS historically posted `promo`; accept both names. 2026-10-10
$code  = isset($in['code']) ? trim((string)$in['code']) : (isset($in['promo']) ? trim((string)$in['promo']) : '');

if ($token === '' || !preg_match('/^[A-Za-z0-9._-]{16,512}$/', $token)) {
    echo json_encode(['ok' => false, 'message' => 'Session expired. Please sign in again.']);
    exit;
}
if ($code === '') {
    echo json_encode(['ok' => false, 'message' => 'Enter a promo code.']);
    exit;
}
// Codes are short and alphanumeric; reject anything else before touching the DB.
if (!preg_match('/^[A-Za-z0-9_-]{2,32}$/', $code)) {
    echo json_encode(['ok' => false, 'message' => 'This promo code is not valid.']);
    exit;
}

$pdo  = lx_auth_db();
$stmt = $pdo->prepare('SELECT id, email FROM users WHERE token = ?');
$stmt->execute([$token]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo json_encode(['ok' => false, 'message' => 'Session expired. Please sign in again.']);
    exit;
}

// Light throttle: 10 attempts per account per 10 minutes. The code space is tiny and
// public, so this is only here to stop someone hammering the endpoint.
try {
    $since = date('c', time() - 600);
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM auth_events WHERE type = 'promo_redeem' AND email = ? AND created_at > ?");
    $cnt->execute([strtolower($row['email']), $since]);
    if ((int)$cnt->fetchColumn() >= 10) {
        echo json_encode(['ok' => false, 'message' => 'Too many attempts. Please try again in a few minutes.']);
        exit;
    }
} catch (Exception $e) { /* a missing log table must not block redemption */ }

$res = lx_promo_redeem((int)$row['id'], $code);

if (!empty($res['ok'])) {
    lx_auth_log_event('promo_redeem', $row['email'], true, strtoupper($code) . '_+' . (int)$res['credited'], 'email');
    echo json_encode([
        'ok'       => true,
        'credited' => (int)$res['credited'],
        'balance'  => (int)$res['balance'],
        'message'  => '+' . (int)$res['credited'] . ' credits added. Your balance is now ' . (int)$res['balance'] . '.',
    ]);
    exit;
}

$already = !empty($res['already_used']);
$message = $already
    ? 'This promo code has already been used on your account.'
    : (($res['message'] ?? '') === 'This promo code is not valid.' ? 'This promo code is not valid.' : (string)$res['message']);

lx_auth_log_event('promo_redeem', $row['email'], false, strtoupper($code) . '_' . ($already ? 'already_used' : 'invalid'), 'email');
echo json_encode(['ok' => false, 'already_used' => $already, 'message' => $message]);
