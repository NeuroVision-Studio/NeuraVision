<?php
/**
 * Neuravision — Get Download Link API
 * Reads download URL from admin panel config.
 * GET ?platform=windows|macos|apk|ios
 * POST {"platform":"...","count":true} — increment counter
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://leronx.org');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

define('DATA_FILE', dirname(__DIR__) . '/admin/downloads/downloads_data.json');

if (!file_exists(DATA_FILE)) {
    http_response_code(404);
    echo json_encode(['error' => 'not_configured']);
    exit;
}

$data = json_decode(file_get_contents(DATA_FILE), true);
if (!is_array($data) || empty($data['platforms'])) {
    http_response_code(404);
    echo json_encode(['error' => 'no_data']);
    exit;
}

$platform = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    $platform = $body['platform'] ?? '';
    $doCount = !empty($body['count']);
} else {
    $platform = $_GET['platform'] ?? '';
    $doCount = isset($_GET['count']);
}

$platform = strtolower(trim($platform));
$allowed = ['windows', 'macos', 'apk', 'ios'];
if (!in_array($platform, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_platform', 'allowed' => $allowed]);
    exit;
}

if (!isset($data['platforms'][$platform]) || empty($data['platforms'][$platform]['url'])) {
    http_response_code(404);
    echo json_encode(['error' => 'link_not_set', 'platform' => $platform]);
    exit;
}

$info = $data['platforms'][$platform];

if ($doCount) {
    $data['platforms'][$platform]['downloads'] = ((int)$data['platforms'][$platform]['downloads']) + 1;
    file_put_contents(DATA_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

echo json_encode([
    'status'   => 'ok',
    'platform' => $platform,
    'url'      => $info['url'],
    'filename' => $info['filename'] ?? '',
    'downloads' => (int)$data['platforms'][$platform]['downloads'],
]);
