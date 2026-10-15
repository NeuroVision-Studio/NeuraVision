<?php
/**
 * /api/lk_resend_login.php — "Lost your password?" button on the cabinet (/lk/c.php so far).
 *
 * Auth: the same httponly `lx_auth_token` cookie the rest of the dashboard uses (same pattern
 * as api/promo_redeem.php) — the account to email is never taken from the request body, only
 * from the session, so this can't be used to spam an arbitrary address.
 *
 * A real plaintext password is never stored (only its bcrypt hash), so "resend" means mint a
 * fresh temporary password via lx_auth_issue_credentials() (functions/auth_db.php) and email
 * it with lx_send_credentials_email() (functions/welcome_mail.php) — the exact same helper the
 * Google/email sign-up flow already uses. The account's own real password (if the user set
 * one) is overwritten by the new temp password; must_change_password is set, matching what
 * already happens on a fresh registration. Owner 2026-10-10.
 */
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
error_reporting(0);

require_once __DIR__ . '/../functions/auth_db.php';
require_once __DIR__ . '/../functions/welcome_mail.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

$token = isset($_COOKIE['lx_auth_token']) ? trim((string)$_COOKIE['lx_auth_token']) : '';
if ($token === '' || !preg_match('/^[A-Za-z0-9._-]{16,512}$/', $token)) {
    echo json_encode(['ok' => false, 'message' => 'Session expired. Please sign in again.']);
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
$email = strtolower((string)$row['email']);

// Light throttle: 3 resends per account per 30 minutes — this mints a new temp password
// every time, so it must not be hammered.
try {
    $since = date('c', time() - 1800);
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM auth_events WHERE type = 'lk_resend_login' AND email = ? AND created_at > ?");
    $cnt->execute([$email, $since]);
    if ((int)$cnt->fetchColumn() >= 3) {
        echo json_encode(['ok' => false, 'message' => 'You already asked for this a moment ago — check your inbox (and spam folder). Please try again in a bit.']);
        exit;
    }
} catch (Exception $e) { /* a missing log table must not block the resend */ }

$creds = lx_auth_issue_credentials($email, '');

$ustmt = $pdo->prepare('SELECT credits FROM users WHERE id = ?');
$ustmt->execute([$creds['user_id']]);
$balance = (int)($ustmt->fetchColumn() ?: 0);

$mailRes = lx_send_credentials_email($email, $creds['temp_password'], $creds['bonus'], $balance);

lx_auth_log_event('lk_resend_login', $email, (bool)$mailRes['ok'], $mailRes['ok'] ? 'sent' : ('mail_failed:' . ($mailRes['error'] ?? '')), 'lk_c');

if (!$mailRes['ok']) {
    echo json_encode(['ok' => false, 'message' => 'Could not send the email right now. Please try again in a minute.']);
    exit;
}

echo json_encode(['ok' => true, 'message' => 'A new login and password were sent to ' . $email . '.']);
