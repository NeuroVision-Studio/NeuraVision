<?php
// Finishes a Google sign-in WITHOUT the emailed code: Google already verified the address
// (auth/google/callback.php stores it in the session). Same result as auth_verify_code.php.
// Added 2026-10-10 after paid Android users got stuck on the code step.
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/auth_db.php';
require_once __DIR__ . '/welcome_mail.php';
require_once __DIR__ . '/flow_oauth.php';

$row = lx_flow_pending_user();
if (!$row || ($_SESSION['lx_flow_provider'] ?? '') !== 'google' || !lx_flow_google_verified($row)) {
    echo json_encode(['ok' => false, 'message' => 'Please confirm your email with the code.']);
    exit;
}
$email = strtolower((string)$row['email']);
$creds = lx_auth_issue_credentials($email, '');
lx_auth_log_event('register', $email, true, ($creds['is_new'] ? 'new' : 'temp_password_reissued') . ' | google_verified', 'google');
if ($creds['is_new']) {
    lx_notify_new_registration($email, 'google');
}
setcookie('lx_gads_reg', '1', ['expires' => time() + 1800, 'path' => '/', 'samesite' => 'Lax']);

$pdo = lx_auth_db();
$stmt = $pdo->prepare('SELECT token, credits FROM users WHERE id = ?');
$stmt->execute([$creds['user_id']]);
$urow = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['token' => '', 'credits' => null];
$token = $urow['token'];
$mailRes = lx_send_credentials_email($email, $creds['temp_password'], $creds['bonus'], $urow['credits']);
if (!$token) {
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('UPDATE users SET token = ? WHERE id = ?')->execute([$token, $creds['user_id']]);
}
lx_auth_set_session_cookies($creds['user_id'], $token);
unset($_SESSION['lx_google_verified']);

echo json_encode([
    'ok'       => true,
    'redirect' => '/account?token=' . urlencode($token),
    'mail'     => $mailRes['ok'] ? 'sent' : 'failed',
    'bonus'    => $creds['bonus'],
]);
