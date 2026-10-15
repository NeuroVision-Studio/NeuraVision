<?php
/** Polled by the web cabinet while a video generation is in Venice's async queue. */
header('Content-Type: application/json');
error_reporting(0);
require_once __DIR__ . '/venice_client.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'id required']);
    exit;
}

$dbPath = __DIR__ . '/../data/users.sqlite';
try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $st = $db->prepare('SELECT * FROM generation_requests WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'lookup failed']);
    exit;
}

if (!$row) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'not found']);
    exit;
}

if ($row['status'] !== 'processing' || !in_array($row['type'], ['video', 'image_to_video', 'video_to_video'], true) || empty($row['provider_job_id'])) {
    echo json_encode(['status' => 'ok', 'id' => $id, 'state' => $row['status'], 'result_url' => $row['result_path'] ?: null, 'message' => $row['message'] ?: null]);
    exit;
}

$poll = nv_venice_video_retrieve($row['provider_job_id'], $row['model'] ?? null);

if ($poll['state'] === 'done') {
    $genDir = __DIR__ . '/../uploads/gen';
    if (!is_dir($genDir)) @mkdir($genDir, 0755, true);
    $file = "gen_{$id}.mp4";
    file_put_contents($genDir . '/' . $file, $poll['bytes']);
    $resultUrl = '/uploads/gen/' . $file;
    try {
        $db->prepare('UPDATE generation_requests SET status=?, result_path=? WHERE id=?')->execute(['done', $resultUrl, $id]);
    } catch (Exception $e) {}
    echo json_encode(['status' => 'ok', 'id' => $id, 'state' => 'done', 'result_url' => $resultUrl]);
} elseif ($poll['state'] === 'failed') {
    try {
        $db->beginTransaction();
        $db->prepare('UPDATE generation_requests SET status=?, message=? WHERE id=?')->execute(['failed', $poll['message'] ?? 'generation failed', $id]);
        // Refund credits charged upfront for the failed video job.
        if (!empty($row['user_id']) && ctype_digit((string)$row['user_id']) && (int)$row['credits_charged'] > 0) {
            $db->prepare('UPDATE users SET credits = credits + ? WHERE id = ?')->execute([(int)$row['credits_charged'], $row['user_id']]);
        }
        $db->commit();
    } catch (Exception $e) {}
    echo json_encode(['status' => 'error', 'id' => $id, 'state' => 'failed', 'message' => $poll['message'] ?? 'generation failed']);
} else {
    echo json_encode(['status' => 'ok', 'id' => $id, 'state' => 'processing']);
}
