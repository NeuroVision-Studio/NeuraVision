<?php
/**
 * Neuravision Desktop App - Key Validation API
 * Endpoint: POST /api/validate_key.php
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['status' => 'error', 'message' => 'Method not allowed']); exit; }

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) $input = $_POST;

$key = trim($input['key'] ?? '');
$hwid = trim($input['hwid'] ?? '');

if (empty($key) || empty($hwid)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Key and hardware ID are required']);
    exit;
}

$configFile = __DIR__ . '/app_config.json';
$packageUpdateUrl = '';
$packageUpdateVersion = '1.0.0';
if (file_exists($configFile)) {
    $configData = json_decode(file_get_contents($configFile), true);
    $packageUpdateUrl = $configData['app_update_package_url'] ?? '';
    $packageUpdateVersion = $configData['app_update_version'] ?? '1.0.0';
}

$dbDir = __DIR__ . '/app_data';
if (!is_dir($dbDir)) mkdir($dbDir, 0755, true);
$dbFile = $dbDir . '/keys.db';

try {
    $db = new SQLite3($dbFile);
    $db->exec('CREATE TABLE IF NOT EXISTS license_keys (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        key_value TEXT UNIQUE NOT NULL,
        user_id TEXT NOT NULL,
        username TEXT DEFAULT "",
        credits INTEGER DEFAULT 1000,
        plan TEXT DEFAULT "starter",
        hwid TEXT DEFAULT "",
        hwid_bound_at TEXT DEFAULT "",
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        last_used TEXT DEFAULT CURRENT_TIMESTAMP,
        active INTEGER DEFAULT 1
    )');

    $stmt = $db->prepare('SELECT * FROM license_keys WHERE key_value = :key');
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    if (!$row) {
        $userId = 'NV-' . strtoupper(substr(md5($key . time()), 0, 8));
        $stmt = $db->prepare('INSERT INTO license_keys (key_value, user_id, hwid, hwid_bound_at, credits) VALUES (:key, :uid, :hwid, :bound_at, 1000)');
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $stmt->bindValue(':uid', $userId, SQLITE3_TEXT);
        $stmt->bindValue(':hwid', $hwid, SQLITE3_TEXT);
        $stmt->bindValue(':bound_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);
        $stmt->execute();

        echo json_encode([
            'status' => 'ok', 'message' => 'Key activated successfully',
            'user' => ['id' => $userId, 'username' => '', 'credits' => 1000, 'plan' => 'starter', 'hwid_bound' => true, 'bound_at' => date('Y-m-d H:i:s')],
            'update_available' => !empty($packageUpdateUrl), 'update_url' => $packageUpdateUrl,
            'update_version' => $packageUpdateVersion, 'package_update_url' => $packageUpdateUrl,
        ]);
        exit;
    }

    if (!empty($row['hwid']) && $row['hwid'] !== $hwid) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'This key is already bound to another device', 'bound_hwid' => substr($row['hwid'], 0, 8) . '...']);
        exit;
    }

    if (!$row['active']) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'This key has been deactivated']);
        exit;
    }

    if (empty($row['hwid'])) {
        $stmt = $db->prepare('UPDATE license_keys SET hwid = :hwid, hwid_bound_at = :bound_at WHERE key_value = :key');
        $stmt->bindValue(':hwid', $hwid, SQLITE3_TEXT);
        $stmt->bindValue(':bound_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $stmt->execute();
    }

    $stmt = $db->prepare('UPDATE license_keys SET last_used = :now WHERE key_value = :key');
    $stmt->bindValue(':now', date('Y-m-d H:i:s'), SQLITE3_TEXT);
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $stmt->execute();

    echo json_encode([
        'status' => 'ok', 'message' => 'Account verified',
        'user' => ['id' => $row['user_id'], 'username' => $row['username'], 'credits' => 1000, 'plan' => $row['plan'], 'hwid_bound' => true, 'bound_at' => $row['hwid_bound_at'] ?: date('Y-m-d H:i:s')],
        'update_available' => !empty($packageUpdateUrl), 'update_url' => $packageUpdateUrl,
        'update_version' => $packageUpdateVersion, 'package_update_url' => $packageUpdateUrl,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
}
