<?php
// Live promo check for /flow/setup.html. Needs the setup token, so only someone on that step can ask.
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/flow_oauth.php';

$token = isset($_POST['token']) ? trim($_POST['token']) : '';
$promo = isset($_POST['promo']) ? trim($_POST['promo']) : '';

if ($token === '' || !preg_match('/^[A-Za-z0-9._-]{16,512}$/', $token)) {
    echo json_encode(['ok' => false]);
    exit;
}
$stmt = lx_auth_db()->prepare('SELECT id, password_hash, must_change_password, promo_code, credits FROM users WHERE token = ?');
$stmt->execute([$token]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$bonus = $row ? lx_flow_promo_eligible($row, $promo) : 0;

echo json_encode($bonus > 0 ? ['ok' => true, 'credits' => $bonus] : ['ok' => false]);
