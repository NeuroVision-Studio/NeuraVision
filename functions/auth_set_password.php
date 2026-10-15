<?php
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/flow_oauth.php';

$token = isset($_POST['token'])    ? trim($_POST['token'])       : '';
$new   = isset($_POST['password']) ? (string)$_POST['password'] : '';
$promo = isset($_POST['promo'])    ? trim($_POST['promo'])       : '';

if ($token === '' || !preg_match('/^[A-Za-z0-9._-]{16,512}$/', $token)) {
    echo json_encode(['ok' => false, 'message' => 'Invalid or expired link. Please sign in again.']);
    exit;
}
if (strlen($new) < 8 || strlen($new) > 128) {
    echo json_encode(['ok' => false, 'message' => 'Password must be at least 8 characters']);
    exit;
}
if (!preg_match('/[A-Za-z]/', $new) || !preg_match('/[0-9]/', $new)) {
    echo json_encode(['ok' => false, 'message' => 'Password must include letters and a number']);
    exit;
}

$pdo = lx_auth_db();
$stmt = $pdo->prepare('SELECT id, email, password_hash, must_change_password, promo_code, credits FROM users WHERE token = ?');
$stmt->execute([$token]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo json_encode(['ok' => false, 'message' => 'Invalid or expired link. Please sign in again.']);
    exit;
}

// Optional promo code: checked before anything is saved, added once per account.
$bonus = 0;
if ($promo !== '') {
    $bonus = lx_flow_promo_eligible($row, $promo);
    if ($bonus <= 0) {
        echo json_encode(['ok' => false, 'message' => 'This promo code is not valid.', 'field' => 'promo']);
        exit;
    }
}

$hash = password_hash($new, PASSWORD_BCRYPT);
$pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 0, temp_password_expires_at = NULL WHERE id = ?')
    ->execute([$hash, $row['id']]);

$applied = 0;
if ($bonus > 0) {
    $upd = $pdo->prepare("UPDATE users SET credits = credits + ?, promo_code = ? WHERE id = ? AND COALESCE(promo_code,'') = ''");
    $upd->execute([$bonus, strtoupper($promo), $row['id']]);
    $applied = $upd->rowCount() > 0 ? $bonus : 0;
    if ($applied > 0) {
        lx_send_promo_credits_email($row['email'], $applied);
        // Log it like every other redemption, otherwise a code applied on THIS step (the
        // set-password screen) never shows up in /admin/promo/ — only the ones applied from
        // the account dashboard did. 2026-10-09
        if (function_exists('lx_promo_log_redemption')) {
            lx_promo_log_redemption($row['id'], $row['email'], $promo, $applied, 'setup');
        }
    }
}

lx_auth_log_event('password_set', $row['email'], true, $applied > 0 ? 'promo_' . strtoupper($promo) : '', 'email');

// The visitor is authenticated at this point: give them the session cookie so /account and
// the /lk/ A|B cabinet open straight away instead of redirecting home. 2026-10-10
lx_auth_set_session_cookies($row['id'], $token);

echo json_encode(['ok' => true, 'redirect' => '/account?token=' . urlencode($token), 'bonus' => $applied]);
