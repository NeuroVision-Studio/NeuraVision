<?php
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/auth_db.php';
require_once __DIR__ . '/email_codes.php';
require_once __DIR__ . '/welcome_mail.php';

$email = isset($_POST['email']) ? strtolower(trim($_POST['email'])) : '';

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'message' => 'Please enter a valid email address']);
    exit;
}

// Optional promo code: reject an unknown one before any email goes out.
$promo = isset($_POST['promo']) ? trim($_POST['promo']) : '';
if ($promo !== '' && lx_promo_credits($promo) <= 0) {
    echo json_encode(['ok' => false, 'message' => 'This promo code is not valid.', 'field' => 'promo']);
    exit;
}

$pdo = lx_auth_db();

// Test accounts (.env LX_TEST_EMAILS) always restart the flow as a brand-new signup.
if (lx_is_test_email($email)) {
    $pdo->prepare('DELETE FROM users WHERE email = ?')->execute([$email]);
}

$stmt = $pdo->prepare('SELECT id, password_hash, must_change_password FROM users WHERE email = ?');
$stmt->execute([$email]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

// A real, already-chosen password exists — this is a returning user, send them to sign in instead.
if ($existing && $existing['password_hash'] !== '' && empty($existing['must_change_password'])) {
    echo json_encode(['ok' => false, 'message' => 'This email is already registered. Please sign in instead.', 'already_registered' => true]);
    exit;
}

$res = lx_issue_email_code($email, 'register');
if (!$res['ok']) {
    lx_auth_log_event('register_code_request', $email, false, $res['message'], 'email');
    echo json_encode($res);
    exit;
}

$mail = lx_send_verification_code_email($email, $res['code']);
lx_auth_log_event('register_code_request', $email, true, $mail['ok'] ? '' : 'mail_failed', 'email');

echo json_encode(['ok' => true, 'mail' => $mail['ok'] ? 'sent' : 'failed']);
