<?php
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/auth_db.php';

$raw = file_get_contents('php://input');
$in = json_decode($raw, true);
if (!is_array($in)) $in = $_POST;

// The auth token lives in an httponly cookie — read it server-side rather than
// round-tripping it through JS/the request body, which would defeat httponly.
$token    = isset($_COOKIE['lx_auth_token']) ? trim((string)$_COOKIE['lx_auth_token']) : '';
$current  = isset($in['current_password']) ? (string)$in['current_password'] : '';
$new      = isset($in['new_password'])      ? (string)$in['new_password']      : '';

if ($token === '' || !preg_match('/^[A-Za-z0-9._-]{16,512}$/', $token)) {
    echo json_encode(['ok' => false, 'message' => 'Session expired. Please sign in again.']);
    exit;
}
if (strlen($new) < 8 || strlen($new) > 128) {
    echo json_encode(['ok' => false, 'message' => 'New password must be at least 8 characters']);
    exit;
}
if (!preg_match('/[A-Za-z]/', $new) || !preg_match('/[0-9]/', $new)) {
    echo json_encode(['ok' => false, 'message' => 'New password must include letters and a number']);
    exit;
}

$pdo = lx_auth_db();
$stmt = $pdo->prepare('SELECT id, email, password_hash FROM users WHERE token = ?');
$stmt->execute([$token]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo json_encode(['ok' => false, 'message' => 'Session expired. Please sign in again.']);
    exit;
}

// A Google/X-only account has no password yet (password_hash is empty) — let it set
// one for the first time without asking for a "current" password that never existed.
$hadPassword = $row['password_hash'] !== '' && $row['password_hash'] !== null;
if ($hadPassword) {
    if ($current === '' || !password_verify($current, $row['password_hash'])) {
        lx_auth_log_event('password_change', $row['email'], false, 'wrong_current_password', 'email');
        echo json_encode(['ok' => false, 'message' => 'Current password is incorrect']);
        exit;
    }
    if (password_verify($new, $row['password_hash'])) {
        echo json_encode(['ok' => false, 'message' => 'New password must be different from the current one']);
        exit;
    }
}

$hash = password_hash($new, PASSWORD_BCRYPT);
$pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 0, temp_password_expires_at = NULL WHERE id = ?')
    ->execute([$hash, $row['id']]);

lx_auth_log_event('password_change', $row['email'], true, $hadPassword ? 'changed' : 'first_set', 'email');

echo json_encode(['ok' => true, 'message' => $hadPassword ? 'Password updated' : 'Password set']);
