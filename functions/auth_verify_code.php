<?php
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/auth_db.php';
require_once __DIR__ . '/email_codes.php';
require_once __DIR__ . '/welcome_mail.php';
require_once __DIR__ . '/flow_oauth.php';

$email = isset($_POST['email']) ? strtolower(trim($_POST['email'])) : '';
$rawCode = isset($_POST['code']) ? (string)$_POST['code'] : '';
$code  = preg_replace('/\D+/', '', $rawCode);   // digits only (Android paste/autofill), 2026-10-10
$__ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$__shape = 'raw_len=' . strlen($rawCode) . ' digits=' . strlen($code) . ' nondigit=' . (strlen($rawCode) - strlen($code))
    . ' dev=' . (stripos($__ua, 'android') !== false ? 'android' : (preg_match('/iphone|ipad/i', $__ua) ? 'ios' : 'desktop'));

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $code === '') {
    echo json_encode(['ok' => false, 'message' => 'Email and code are required']);
    exit;
}

$verify = lx_verify_email_code($email, 'register', $code);
lx_auth_log_event('register_code_verify', $email, $verify['ok'], ($verify['ok'] ? '' : ($verify['message'] ?? '') . ' | ') . $__shape, 'email');
if (!$verify['ok']) {
    echo json_encode($verify);
    exit;
}

// X sign-in: the confirmed address replaces the placeholder one on the same account.
$adoptErr = lx_flow_adopt_email($email);
if ($adoptErr !== '') {
    echo json_encode(['ok' => false, 'message' => $adoptErr]);
    exit;
}

$promo = isset($_POST['promo']) ? trim($_POST['promo']) : '';
$creds = lx_auth_issue_credentials($email, $promo);

lx_auth_log_event('register', $email, true, $creds['is_new'] ? 'new' : 'temp_password_reissued', 'email');
if ($creds['is_new']) {
    lx_notify_new_registration($email, 'email');
    setcookie('lx_gads_reg', '1', ['expires' => time() + 1800, 'path' => '/', 'samesite' => 'Lax']);
}

$pdo = lx_auth_db();
$stmt = $pdo->prepare('SELECT token, credits FROM users WHERE id = ?');
$stmt->execute([$creds['user_id']]);
$urow = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['token' => '', 'credits' => null];
$token = $urow['token'];

// The real balance goes into the email so it never quotes a credit figure the account
// does not have (free signup bonus 250 + any promo code). 2026-10-09
$mailRes = lx_send_credentials_email($email, $creds['temp_password'], $creds['bonus'], $urow['credits']);
if (!$token) {
    // Google/X accounts are created without a token; setup.html needs one.
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('UPDATE users SET token = ? WHERE id = ?')->execute([$token, $creds['user_id']]);
}

// hand the browser the dashboard session cookie, not just a token in a URL
lx_auth_set_session_cookies($creds['user_id'], $token);

echo json_encode([
    'ok'       => true,
    'redirect' => '/account?token=' . urlencode($token ?: ''),
    'mail'     => $mailRes['ok'] ? 'sent' : 'failed',
    'bonus'    => $creds['bonus'],
]);
