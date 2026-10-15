<?php
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://neuracreation.com');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['status'=>'error','message'=>'Method not allowed']); exit; }

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) $input = $_POST;
$authToken = trim($input['auth_token'] ?? '');
$hwid = trim($input['hwid'] ?? '');

if (empty($authToken)) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'Auth token required']); exit; }

$dbDir = __DIR__ . '/app_data';
if (!is_dir($dbDir)) mkdir($dbDir, 0755, true);
$db = new SQLite3($dbDir . '/keys.db');
$db->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id TEXT UNIQUE NOT NULL, provider TEXT NOT NULL, provider_id TEXT NOT NULL, username TEXT DEFAULT "", display_name TEXT DEFAULT "", email TEXT DEFAULT "", credits INTEGER DEFAULT 1000, plan TEXT DEFAULT "starter", access_token TEXT DEFAULT "", auth_token TEXT UNIQUE NOT NULL, hwid TEXT DEFAULT "", created_at TEXT DEFAULT CURRENT_TIMESTAMP, last_login TEXT DEFAULT CURRENT_TIMESTAMP)');

$stmt = $db->prepare('SELECT * FROM users WHERE auth_token = :token');
$stmt->bindValue(':token', $authToken, SQLITE3_TEXT);
$row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

if (!$row) { http_response_code(401); echo json_encode(['status'=>'error','message'=>'Invalid auth token']); exit; }

if ($hwid && empty($row['hwid'])) {
    $s = $db->prepare('UPDATE users SET hwid = :hwid WHERE id = :id');
    $s->bindValue(':hwid', $hwid, SQLITE3_TEXT);
    $s->bindValue(':id', $row['id'], SQLITE3_INTEGER);
    $s->execute();
} elseif ($hwid && !empty($row['hwid']) && $row['hwid'] !== $hwid) {
    http_response_code(403);
    echo json_encode(['status'=>'error','message'=>'Account is bound to another device']);
    exit;
}

echo json_encode(['status'=>'ok','user'=>['id'=>$row['user_id'],'username'=>$row['username'],'display_name'=>$row['display_name'],'email'=>$row['email'],'credits'=>(int)$row['credits'],'plan'=>$row['plan'],'provider'=>$row['provider'],'hwid_bound'=>!empty($row['hwid'])],'update_available'=>true,'update_url'=>'https://neuracreation.com/api/download_update.php','update_version'=>'1.0.0']);
