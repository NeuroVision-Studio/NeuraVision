<?php
// Tells /flow/verify.html who just came back from Google/X and whether an email is still needed.
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/flow_oauth.php';

$row = lx_flow_pending_user();
if (!$row) {
    echo json_encode(['ok' => true, 'pending' => false, 'signed_in' => !empty($_SESSION['user_id'])]);
    exit;
}
$synthetic = lx_flow_is_synthetic_email($row['email']);
$name = trim((string)($row['google_name'] ?: $row['twitter_name']));
echo json_encode([
    'ok'         => true,
    'pending'    => true,
    'provider'   => isset($_SESSION['lx_flow_provider']) ? $_SESSION['lx_flow_provider'] : '',
    'need_email' => $synthetic,
    'google_ok'  => (($_SESSION['lx_flow_provider'] ?? '') === 'google') && lx_flow_google_verified($row),
    'email'      => $synthetic ? '' : $row['email'],
    'name'       => $name,
]);
