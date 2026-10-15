<?php
/**
 * Resume Upload Endpoint for Neuravision Careers
 * Stores uploaded resumes and returns a public URL
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://neuracreation.com'); // tightened from * (2026-06-21)
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'POST required']);
    exit;
}

// Rate limiting: protect against upload-spam / disk-fill abuse (2026-06-21).
(function () {
    $ip = 'unknown';
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_REAL_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) { $cand = trim(explode(',', $_SERVER[$h])[0]); if (filter_var($cand, FILTER_VALIDATE_IP)) { $ip = $cand; break; } }
    }
    $file = sys_get_temp_dir() . '/lx_upload_' . md5($ip) . '.json';
    $now = time(); $win = 600; $max = 5;
    $hits = is_file($file) ? (json_decode(@file_get_contents($file), true) ?: []) : [];
    $hits = array_values(array_filter($hits, function ($t) use ($now, $win) { return ($now - $t) < $win; }));
    if (count($hits) >= $max) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many uploads. Please wait a few minutes.']);
        exit;
    }
    $hits[] = $now;
    @file_put_contents($file, json_encode($hits), LOCK_EX);
})();

// Upload directory
$uploadDir = __DIR__ . '/resumes/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Check if file was uploaded
if (empty($_FILES['resume'])) {
    echo json_encode(['error' => 'No file uploaded']);
    exit;
}

$file = $_FILES['resume'];

// Validate
$maxSize = 10 * 1024 * 1024; // 10MB

if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'Upload error: ' . $file['error']]);
    exit;
}

if ($file['size'] > $maxSize) {
    echo json_encode(['error' => 'File too large. Max 10MB.']);
    exit;
}

// Generate unique filename
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed = ['pdf', 'doc', 'docx', 'png', 'jpg', 'jpeg', 'txt', 'rtf'];
if (!in_array($ext, $allowed)) {
    echo json_encode(['error' => 'File type not allowed. Use PDF, DOC, DOCX, PNG, JPG.']);
    exit;
}

$safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
$uniqueName = date('Y-m-d_His') . '_' . $safeName . '.' . $ext;

$destination = $uploadDir . $uniqueName;

if (move_uploaded_file($file['tmp_name'], $destination)) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $url = $protocol . '://' . $host . '/chat/resumes/' . $uniqueName;
    
    echo json_encode([
        'status' => 'success',
        'url' => $url,
        'filename' => $uniqueName
    ]);
} else {
    echo json_encode(['error' => 'Failed to save file']);
}
