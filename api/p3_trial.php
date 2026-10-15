<?php
/**
 * /api/p3_trial.php — soft anti-bot gate on the path-3 landing ("download without
 * registration"). Framed to the visitor as "activate your free trial", not as a captcha:
 * enter an email, get a 6-digit code, confirm it. There is no account here and the file
 * download itself (api/desktop_download.php, gated by functions/nv_pow.php) is never
 * blocked by this — it only gates the trial-activation message. A scraper/bot has no real
 * inbox to read the code from, so it never gets the "trial activated" state, while a human
 * visitor's download is not delayed or interrupted by it. Owner 2026-10-14.
 */
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
error_reporting(0);

require_once __DIR__ . '/../functions/auth_db.php';
require_once __DIR__ . '/../functions/email_codes.php';
require_once __DIR__ . '/../functions/welcome_mail.php';
require_once __DIR__ . '/../functions/nv_client_ip.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

$action = isset($_POST['action']) ? (string)$_POST['action'] : 'send';
$email  = isset($_POST['email']) ? strtolower(trim((string)$_POST['email'])) : '';

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'message' => 'Enter a valid email address.']);
    exit;
}

if ($action === 'verify') {
    $code = isset($_POST['code']) ? (string)$_POST['code'] : '';
    $verify = lx_verify_email_code($email, 'p3_trial', $code);
    lx_auth_log_event('p3_trial_verify', $email, $verify['ok'], $verify['ok'] ? '' : ($verify['message'] ?? ''), 'p3');
    echo json_encode($verify);
    exit;
}

// action === 'send' (default): issue + email a fresh 6-digit code.
// IP-level cap on top of the per-email resend cooldown already built into
// lx_issue_email_code() — stops one bot from cycling through many throwaway addresses.
try {
    $pdo = lx_auth_db();
    $ip = nv_client_ip();
    $since = date('c', time() - 1800);
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM auth_events WHERE type = 'p3_trial_send' AND ip = ? AND created_at > ?");
    $cnt->execute([$ip, $since]);
    if ((int)$cnt->fetchColumn() >= 10) {
        echo json_encode(['ok' => false, 'message' => 'Too many code requests from this network. Please try again later.']);
        exit;
    }
} catch (Exception $e) { /* a missing log table must not block sending */ }

$res = lx_issue_email_code($email, 'p3_trial');
if (!$res['ok']) {
    lx_auth_log_event('p3_trial_send', $email, false, $res['message'] ?? '', 'p3');
    echo json_encode($res);
    exit;
}

$mail = lx_send_p3_trial_code_email($email, $res['code']);
lx_auth_log_event('p3_trial_send', $email, true, $mail['ok'] ? '' : 'mail_failed', 'p3');

echo json_encode(['ok' => true, 'mail' => $mail['ok'] ? 'sent' : 'failed']);
