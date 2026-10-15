<?php
/**
 * Logs a "request to purchase" lead (plan + email) and pings Telegram.
 * No payment provider is wired up yet — this replaces a fake card-collection
 * form that could never actually charge anyone, so demand data isn't lost.
 */
header('Content-Type: application/json');
error_reporting(0);

$raw = file_get_contents('php://input');
$in = json_decode($raw, true);
if (!is_array($in)) $in = $_POST;

$email = trim((string)($in['email'] ?? ''));
$plan = trim((string)($in['plan'] ?? ''));
$userId = trim((string)($in['user_id'] ?? ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'valid email required']);
    exit;
}
$plan = mb_substr($plan, 0, 80);

$dbPath = __DIR__ . '/../data/users.sqlite';
try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE IF NOT EXISTS billing_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at TEXT NOT NULL,
        user_id TEXT,
        email TEXT NOT NULL,
        plan TEXT,
        ip TEXT
    )");
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $st = $db->prepare('INSERT INTO billing_requests (created_at, user_id, email, plan, ip) VALUES (?,?,?,?,?)');
    $st->execute([gmdate('c'), $userId, $email, $plan, $ip]);
    $id = $db->lastInsertId();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'could not queue']);
    exit;
}

try {
    require_once __DIR__ . '/nv_notify.php';
    $token = nv_api_env('TG_BOT_TOKEN');
    $chat = nv_api_env('TG_CHAT_ID');
    if ($token && $chat) {
        $msg = "\xF0\x9F\x92\xB3 New purchase request\n"
             . "Plan: " . ($plan ?: '—') . "\n"
             . "Email: {$email}\n"
             . "User: " . ($userId ?: '—');
        $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 5,
            CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $chat, 'text' => $msg]),
        ]);
        curl_exec($ch); curl_close($ch);
    }
} catch (Exception $e) {}

echo json_encode(['status' => 'ok', 'id' => $id]);
