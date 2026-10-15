<?php
/**
 * Neuravision Desktop — which Download buttons are live. GET /api/desktop_status.php
 * JSON: {"windows":{"ready":true},"macos":{"ready":false}}. The download page reads it instead of probing the file.
 */
require_once __DIR__ . '/../functions/desktop_release.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$out = [];
foreach (desktop_release_info() as $os => $row) {
    $out[$os] = ['ready' => $row['available']];
}
echo json_encode($out);
