<?php
/**
 * GET /api/nv_pow.php — issues one signed proof-of-work challenge (see functions/nv_pow.php).
 * Used by the path-3 landing ("download without registration") before /api/desktop_download.php.
 */
require_once __DIR__ . '/../functions/nv_pow.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex');
echo json_encode(nv_pow_challenge(), JSON_UNESCAPED_SLASHES);
