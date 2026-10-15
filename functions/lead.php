<?php
/**
 * Lead capture endpoint for pricing checkout intents and enterprise inquiries.
 * Stores the lead in the SQLite `leads` table via lx_save_lead().
 * Always returns JSON.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/auth_db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

$type = isset($_POST['type']) ? trim($_POST['type']) : 'checkout';
$type = in_array($type, ['checkout', 'enterprise'], true) ? $type : 'checkout';

$email = isset($_POST['email']) ? strtolower(trim($_POST['email'])) : '';
if ($type === 'enterprise' && (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
    echo json_encode(['ok' => false, 'message' => 'Please enter a valid work email.']);
    exit;
}

$data = [
    'type'      => $type,
    'plan'      => isset($_POST['plan'])      ? $_POST['plan']      : '',
    'billing'   => isset($_POST['billing'])   ? $_POST['billing']   : '',
    'amount'    => isset($_POST['amount'])    ? $_POST['amount']    : '',
    'email'     => $email,
    'name'      => isset($_POST['name'])      ? $_POST['name']      : '',
    'company'   => isset($_POST['company'])   ? $_POST['company']   : '',
    'team_size' => isset($_POST['team_size']) ? $_POST['team_size'] : '',
    'volume'    => isset($_POST['volume'])    ? $_POST['volume']    : '',
    'needs_api' => isset($_POST['needs_api']) ? $_POST['needs_api'] : '',
    'message'   => isset($_POST['message'])   ? $_POST['message']   : '',
];

$res = lx_save_lead($data);

if (!empty($res['ok'])) {
    if ($type === 'enterprise') {
        echo json_encode(['ok' => true, 'message' => 'Thanks — our team will reach out shortly.']);
    } else {
        // Checkout flow: we intentionally simulate a bank-side decline (see pricing UI).
        echo json_encode(['ok' => true, 'lead_id' => $res['id']]);
    }
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Could not save right now. Please try again.']);
}
