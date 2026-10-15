<?php
/**
 * Neuravision download-click resolver + tracker (local replacement for api.leronx.xyz/data.php).
 * Same OS -> download-URL mapping as before, but served locally, own sqlite, no leronx.xyz call.
 * NOTE: the download targets themselves are still LeronX-built app binaries (separate issue —
 * true independence there needs new app builds, not just a backend swap).
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://neuracreation.com');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$dbDir = __DIR__ . '/app_data';
if (!is_dir($dbDir)) mkdir($dbDir, 0755, true);
$db = new SQLite3($dbDir . '/downloads.db');
$db->exec('CREATE TABLE IF NOT EXISTS links (id INTEGER PRIMARY KEY, os TEXT UNIQUE, url TEXT, click_count INTEGER DEFAULT 0)');
$db->exec('CREATE TABLE IF NOT EXISTS clicks (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT, os TEXT, useragent TEXT, user_id TEXT, user_email TEXT, date TEXT DEFAULT CURRENT_TIMESTAMP)');

// Seed defaults once (same targets currently live — desktop/mobile apps are still
// LeronX-built binaries; only the redirect/tracking layer is now local).
$seed = [
    'windows' => 'https://www.mediafire.com/file_premium/1jy1ltgch5ozbp5/NeuraVision.exe/file',
    'macos'   => 'https://www.mediafire.com/file_premium/pyf92ot5v0cd8rd/Leron_X.dmg/file',
    'apk'     => 'https://play.google.com/store/apps/details?id=com.leron.pro&hl=gsw',
    'ios'     => 'https://apps.apple.com/us/app/leron-pro/id6758914033',
];
foreach ($seed as $os => $url) {
    $stmt = $db->prepare('INSERT OR IGNORE INTO links (os, url) VALUES (:os, :url)');
    $stmt->bindValue(':os', $os, SQLITE3_TEXT);
    $stmt->bindValue(':url', $url, SQLITE3_TEXT);
    $stmt->execute();
}

function get_client_ip() {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_REAL_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return 'Unknown';
}

$link_id = isset($_POST['link_id']) ? (int)$_POST['link_id'] : 1;
$osMap = [1 => 'windows', 2 => 'macos', 3 => 'apk', 4 => 'ios'];
$os = $osMap[$link_id] ?? 'windows';

$useragent = $_SERVER['HTTP_USER_AGENT'] ?? '';
if (preg_match('/android/i', $useragent)) $os = 'apk';
elseif (preg_match('/iphone|ipad/i', $useragent)) $os = 'ios';
elseif (preg_match('/macintosh|mac os x/i', $useragent)) $os = 'macos';
elseif (preg_match('/windows|win32/i', $useragent)) $os = 'windows';

$stmt = $db->prepare('SELECT url FROM links WHERE os = :os');
$stmt->bindValue(':os', $os, SQLITE3_TEXT);
$row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
$link_url = $row['url'] ?? $seed['windows'];

$ip = get_client_ip();
$userId = trim((string)($_POST['user_id'] ?? ''));
$userEmail = trim((string)($_POST['user_email'] ?? ''));

$stmt = $db->prepare('INSERT INTO clicks (ip, os, useragent, user_id, user_email) VALUES (:ip, :os, :ua, :uid, :uem)');
$stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
$stmt->bindValue(':os', $os, SQLITE3_TEXT);
$stmt->bindValue(':ua', $useragent, SQLITE3_TEXT);
$stmt->bindValue(':uid', $userId, SQLITE3_TEXT);
$stmt->bindValue(':uem', $userEmail, SQLITE3_TEXT);
$stmt->execute();

$db->exec("UPDATE links SET click_count = click_count + 1 WHERE os = '" . SQLite3::escapeString($os) . "'");

echo json_encode([
    'status' => 'success',
    'link_id' => $link_id,
    'link_url' => $link_url,
    'message' => 'Data recorded successfully',
], JSON_UNESCAPED_UNICODE);
