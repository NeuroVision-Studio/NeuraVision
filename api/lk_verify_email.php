<?php
/**
 * /api/lk_verify_email.php — extra "confirm it's you" email-code gate on /lk/c.php before
 * the Download button unlocks. Owner 2026-10-10: C's users arrive already signed in via
 * Google/email, so this re-confirms the inbox is real before handing over the installer, and
 * (same mechanics as "Lost your password?") re-sends login+password to that confirmed inbox.
 *
 * Auth: lx_auth_token cookie only (same pattern as api/lk_resend_login.php) — the email to
 * send/verify is never taken from the request body, so this can't be used to probe/spam an
 * arbitrary address.
 */
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
error_reporting(0);

require_once __DIR__ . '/../functions/auth_db.php';
require_once __DIR__ . '/../functions/email_codes.php';
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
$userId = (int)$row['id'];
$email  = strtolower((string)$row['email']);

$action = isset($_POST['action']) ? (string)$_POST['action'] : 'send';

if ($action === 'verify') {
    $code = isset($_POST['code']) ? (string)$_POST['code'] : '';
    $verify = lx_verify_email_code($email, 'lk_verify', $code);
    lx_auth_log_event('lk_verify_email', $email, $verify['ok'], $verify['ok'] ? '' : ($verify['message'] ?? ''), 'lk_c');
    if (!$verify['ok']) {
        echo json_encode($verify);
        exit;
    }

    $pdo->prepare('UPDATE users SET lk_c_email_verified_at = ? WHERE id = ?')->execute([date('c'), $userId]);

    // Same helper the "Lost your password?" button uses: mint a fresh temp password and
    // email it, now that the inbox has just been freshly confirmed to belong to this person.
    // Unlike that button, this isn't a user-initiated password reset -- it's a side effect of
    // a simple email check -- so the must_change_password flag it sets is cleared right back:
    // without this, the very next /lk/ page view (a refresh, a re-opened tab) would bounce the
    // still-mid-download visitor to /set-password.php instead of the Download button, which is
    // exactly the conversion hit the owner asked this gate not to cause. 2026-10-10
    $creds = lx_auth_issue_credentials($email, '');
    $pdo->prepare('UPDATE users SET must_change_password = 0 WHERE id = ?')->execute([$creds['user_id']]);
    $ustmt = $pdo->prepare('SELECT credits FROM users WHERE id = ?');
    $ustmt->execute([$creds['user_id']]);
    $balance = (int)($ustmt->fetchColumn() ?: 0);
    $mailRes = lx_send_credentials_email($email, $creds['temp_password'], $creds['bonus'], $balance);
    lx_auth_log_event('lk_verify_email_creds', $email, (bool)$mailRes['ok'], $mailRes['ok'] ? 'sent' : ('mail_failed:' . ($mailRes['error'] ?? '')), 'lk_c');

    echo json_encode(['ok' => true, 'mail' => $mailRes['ok'] ? 'sent' : 'failed']);
    exit;
}

// action === 'send' (default): issue + email a fresh 6-digit code.
try {
    $since = date('c', time() - 1800);
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM auth_events WHERE type = 'lk_verify_email_send' AND email = ? AND created_at > ?");
    $cnt->execute([$email, $since]);
    if ((int)$cnt->fetchColumn() >= 5) {
        echo json_encode(['ok' => false, 'message' => 'Too many code requests. Please try again in a bit.']);
        exit;
    }
} catch (Exception $e) { /* a missing log table must not block sending */ }

$res = lx_issue_email_code($email, 'lk_verify');
if (!$res['ok']) {
    lx_auth_log_event('lk_verify_email_send', $email, false, $res['message'] ?? '', 'lk_c');
    echo json_encode($res);
    exit;
}

$mail = lx_send_lk_unlock_code_email($email, $res['code']);
lx_auth_log_event('lk_verify_email_send', $email, true, $mail['ok'] ? '' : 'mail_failed', 'lk_c');

echo json_encode(['ok' => true, 'mail' => $mail['ok'] ? 'sent' : 'failed', 'email' => $email]);
