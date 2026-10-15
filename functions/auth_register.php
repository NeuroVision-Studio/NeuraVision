<?php
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/auth_db.php';
require_once __DIR__ . '/welcome_mail.php';

$action   = isset($_POST['action'])   ? trim($_POST['action'])   : '';
$email    = isset($_POST['email'])    ? trim($_POST['email'])    : '';
$password = isset($_POST['password']) ? (string)$_POST['password'] : '';

if ($action !== 'login') {
    // Sign-up no longer takes a self-chosen password here — it's a two-step
    // email-code flow (see auth_send_code.php / auth_verify_code.php).
    echo json_encode(['ok' => false, 'message' => 'Please verify your email to create an account.']);
    exit;
}

if ($email === '' || $password === '') {
    lx_auth_log_event('login', $email, false, 'Email and password required', 'email');
    echo json_encode(['ok' => false, 'message' => 'Email and password required']);
    exit;
}

$res = lx_auth_login($email, $password);
lx_auth_log_event('login', $email, $res['ok'], $res['ok'] ? '' : ($res['message'] ?? ''), 'email');
if (!$res['ok']) { echo json_encode($res); exit; }

$pdo = lx_auth_db();
$stmt = $pdo->prepare('SELECT token, id FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// A successful password login has to create a browser session too -- without this cookie the
// user landed on the download page and /account sent them back to the home page. 2026-10-10
$token = (string)($user['token'] ?? '');
if ($token === '') {
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('UPDATE users SET token = ? WHERE id = ?')->execute([$token, $user['id']]);
}
lx_auth_set_session_cookies($user['id'] ?? 0, $token);

// (dl=1 -> /lk/dl.php redirect removed 2026-10-10: the owner cancelled the dl=1 -> registration flow)
$__redir = '/account';
echo json_encode(['ok' => true, 'redirect' => $__redir]);
