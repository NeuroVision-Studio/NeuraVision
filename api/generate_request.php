<?php
/**
 * Real photo/video generation for the web cabinet, backed by Venice AI.
 * Photo: generated synchronously (single Venice call, saved to disk, returned immediately).
 * Video / Photo->Video / Video->Video: submitted to Venice's async queue;
 * /api/generation_status.php polls it to completion.
 * Photo->Video and Video->Video arrive as multipart/form-data (a source file to animate/restyle),
 * so $_POST/$_FILES are used directly instead of the JSON body photo/video already used.
 */
header('Content-Type: application/json');
error_reporting(0);
require_once __DIR__ . '/venice_client.php';

$raw = file_get_contents('php://input');
$in = json_decode($raw, true);
if (!is_array($in)) $in = $_POST;

$allowedTypes = ['photo', 'video', 'image_to_video', 'video_to_video'];
$type = in_array(($in['type'] ?? ''), $allowedTypes, true) ? $in['type'] : 'photo';
$prompt = trim((string)($in['prompt'] ?? ''));
$aspect = trim((string)($in['aspect'] ?? '16:9'));
$duration = (int)($in['duration'] ?? 5);
$requestedModel = trim((string)($in['model'] ?? ''));
$userId = trim((string)($in['user_id'] ?? ''));
$userEmail = trim((string)($in['user_email'] ?? ''));

if ($prompt === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'prompt required']);
    exit;
}
$prompt = mb_substr($prompt, 0, 600);

// Photo->Video and Video->Video need a source file Venice can fetch by URL. Upload it first,
// validate it, and turn it into our own public /uploads/src/... URL.
$sourceUrl = null;
if ($type === 'image_to_video' || $type === 'video_to_video') {
    $isImage = $type === 'image_to_video';
    $f = $_FILES['source_file'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $isImage ? 'source photo required' : 'source video required']);
        exit;
    }
    $maxBytes = $isImage ? 15 * 1024 * 1024 : 120 * 1024 * 1024;
    if ($f['size'] > $maxBytes) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'file too large']);
        exit;
    }
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $allowedExt = $isImage ? ['jpg', 'jpeg', 'png', 'webp'] : ['mp4', 'mov', 'webm'];
    if (!in_array($ext, $allowedExt, true)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'unsupported file type']);
        exit;
    }
    $srcDir = __DIR__ . '/../uploads/src';
    if (!is_dir($srcDir)) @mkdir($srcDir, 0755, true);
    $fname = 'src_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $srcDir . '/' . $fname)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'upload failed']);
        exit;
    }
    // Venice fetches this URL itself, so it must be a real public absolute URL, not a local path.
    $sourceUrl = 'https://neuracreation.com/uploads/src/' . $fname;
}

if ($type === 'photo') {
    $model = nv_venice_resolve_image_model($requestedModel);
    $cost = 10;
} elseif ($type === 'video') {
    list($model, $duration) = nv_venice_resolve_video_model($requestedModel, $duration);
    $cost = (int)round(25 * ($duration / 4));
} elseif ($type === 'image_to_video') {
    list($model, $duration) = nv_venice_resolve_image_to_video_model($requestedModel, $duration);
    $cost = (int)round(25 * ($duration / 4));
} else { // video_to_video — output length follows the source clip, so charge a flat rate.
    $model = nv_venice_resolve_video_to_video_model($requestedModel);
    $duration = 0;
    $cost = 60;
}

$dbPath = __DIR__ . '/../data/users.sqlite';
try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE IF NOT EXISTS generation_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at TEXT NOT NULL,
        user_id TEXT,
        user_email TEXT,
        type TEXT NOT NULL,
        prompt TEXT NOT NULL,
        aspect TEXT,
        duration TEXT,
        ip TEXT
    )");
    $cols = array_column($db->query("PRAGMA table_info(generation_requests)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach ([
        'status' => "TEXT NOT NULL DEFAULT 'processing'",
        'provider_job_id' => 'TEXT',
        'result_path' => 'TEXT',
        'credits_charged' => 'INTEGER NOT NULL DEFAULT 0',
        'message' => 'TEXT',
        'model' => 'TEXT',
        'source_url' => 'TEXT',
    ] as $col => $def) {
        if (!in_array($col, $cols, true)) $db->exec("ALTER TABLE generation_requests ADD COLUMN $col $def");
    }

    // Best-effort balance check; users created before the credits column always default to 1000+ (see account.php).
    $credits = null;
    if ($userId !== '' && ctype_digit($userId)) {
        $u = $db->prepare('SELECT credits FROM users WHERE id = ? LIMIT 1');
        $u->execute([$userId]);
        $row = $u->fetch(PDO::FETCH_ASSOC);
        if ($row) $credits = (int)$row['credits'];
    }
    if ($credits !== null && $credits < $cost) {
        http_response_code(402);
        echo json_encode(['status' => 'error', 'message' => 'insufficient_credits', 'balance' => $credits, 'cost' => $cost]);
        exit;
    }

    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $st = $db->prepare('INSERT INTO generation_requests (created_at, user_id, user_email, type, prompt, aspect, duration, ip, status, model, source_url) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $st->execute([gmdate('c'), $userId, $userEmail, $type, $prompt, $aspect, (string)$duration, $ip, 'processing', $model, $sourceUrl]);
    $id = $db->lastInsertId();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'could not queue']);
    exit;
}

$genDir = __DIR__ . '/../uploads/gen';
if (!is_dir($genDir)) @mkdir($genDir, 0755, true);

$state = 'processing';
$resultUrl = null;
$errMsg = null;

if ($type === 'photo') {
    try {
        $bytes = nv_venice_image_generate($prompt, $aspect, $model);
        $file = "gen_{$id}.png";
        file_put_contents($genDir . '/' . $file, $bytes);
        $state = 'done';
        $resultUrl = '/uploads/gen/' . $file;
        try {
            $db->prepare('UPDATE generation_requests SET status=?, result_path=?, credits_charged=? WHERE id=?')
               ->execute(['done', $resultUrl, $cost, $id]);
            if ($credits !== null) {
                $db->prepare('UPDATE users SET credits = credits - ? WHERE id = ?')->execute([$cost, $userId]);
            }
        } catch (Exception $e) {}
    } catch (Exception $e) {
        $state = 'failed';
        $errMsg = $e->getMessage();
        try {
            $db->prepare('UPDATE generation_requests SET status=?, message=? WHERE id=?')->execute(['failed', $errMsg, $id]);
        } catch (Exception $e2) {}
    }
} elseif ($type === 'video') {
    try {
        list($queueId, $usedModel, $usedDuration) = nv_venice_video_submit($prompt, $aspect, $duration, $model);
        $state = 'processing';
        try {
            $db->prepare('UPDATE generation_requests SET provider_job_id=?, credits_charged=?, model=?, duration=? WHERE id=?')
               ->execute([$queueId, $cost, $usedModel, (string)$usedDuration, $id]);
            if ($credits !== null) {
                $db->prepare('UPDATE users SET credits = credits - ? WHERE id = ?')->execute([$cost, $userId]);
            }
        } catch (Exception $e) {}
    } catch (Exception $e) {
        $state = 'failed';
        $errMsg = $e->getMessage();
        try {
            $db->prepare('UPDATE generation_requests SET status=?, message=? WHERE id=?')->execute(['failed', $errMsg, $id]);
        } catch (Exception $e2) {}
    }
} elseif ($type === 'image_to_video') {
    try {
        list($queueId, $usedModel, $usedDuration) = nv_venice_image_to_video_submit($prompt, $sourceUrl, $duration, $model);
        $state = 'processing';
        try {
            $db->prepare('UPDATE generation_requests SET provider_job_id=?, credits_charged=?, model=?, duration=? WHERE id=?')
               ->execute([$queueId, $cost, $usedModel, (string)$usedDuration, $id]);
            if ($credits !== null) {
                $db->prepare('UPDATE users SET credits = credits - ? WHERE id = ?')->execute([$cost, $userId]);
            }
        } catch (Exception $e) {}
    } catch (Exception $e) {
        $state = 'failed';
        $errMsg = $e->getMessage();
        try {
            $db->prepare('UPDATE generation_requests SET status=?, message=? WHERE id=?')->execute(['failed', $errMsg, $id]);
        } catch (Exception $e2) {}
    }
} else { // video_to_video
    try {
        list($queueId, $usedModel) = nv_venice_video_to_video_submit($prompt, $sourceUrl, $model);
        $state = 'processing';
        try {
            $db->prepare('UPDATE generation_requests SET provider_job_id=?, credits_charged=?, model=? WHERE id=?')
               ->execute([$queueId, $cost, $usedModel, $id]);
            if ($credits !== null) {
                $db->prepare('UPDATE users SET credits = credits - ? WHERE id = ?')->execute([$cost, $userId]);
            }
        } catch (Exception $e) {}
    } catch (Exception $e) {
        $state = 'failed';
        $errMsg = $e->getMessage();
        try {
            $db->prepare('UPDATE generation_requests SET status=?, message=? WHERE id=?')->execute(['failed', $errMsg, $id]);
        } catch (Exception $e2) {}
    }
}

// Best-effort Telegram ping, reusing the same bot as registration alerts.
try {
    $token = nv_api_env('TG_BOT_TOKEN');
    $chat = nv_api_env('TG_CHAT_ID');
    if ($token && $chat) {
        $msg = "🎬 Generation request #{$id} [{$state}]\n"
             . "Type: {$type} · Model: {$model}\n"
             . "User: " . ($userId ?: '—') . ' ' . ($userEmail ?: '') . "\n"
             . "Aspect: " . ($aspect ?: '—') . (in_array($type, ['video', 'image_to_video'], true) ? " · {$duration}s" : '') . "\n"
             . ($sourceUrl ? "Source: {$sourceUrl}\n" : '')
             . "Prompt: {$prompt}"
             . ($errMsg ? "\nError: {$errMsg}" : '');
        $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 5,
            CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $chat, 'text' => $msg]),
        ]);
        curl_exec($ch); curl_close($ch);
    }
} catch (Exception $e) {}

echo json_encode([
    'status' => $state === 'failed' ? 'error' : 'ok',
    'id' => $id,
    'state' => $state,
    'result_url' => $resultUrl,
    'message' => $errMsg,
]);
