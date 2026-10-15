<?php
/**
 * Minimal Venice AI client for the /account web generator.
 * Mirrors the request/response shapes already verified working in
 * cash-top-project/scripts/{image,video}_generate_cli.py.
 */
require_once __DIR__ . '/nv_notify.php'; // for nv_api_env()

define('NV_VENICE_IMAGE_ENDPOINT', 'https://api.venice.ai/api/v1/image/generate');
define('NV_VENICE_API_BASE', 'https://api.venice.ai/api/v1');
define('NV_VENICE_IMAGE_MODEL', 'seedream-v5-pro');
define('NV_VENICE_VIDEO_MODEL', 'pixverse-v5.6-text-to-video');

// Curated allow-list, verified live against GET /api/v1/models on 2026-09-30.
// Keep the keys in sync with the <select id="genModel"> options built in account.php.
function nv_venice_image_models() {
    return [
        'seedream-v5-pro' => 'Seedream v5 Pro (default, balanced)',
        'flux-2-pro'       => 'Flux 2 Pro (flagship quality)',
        'gpt-image-2'      => 'GPT Image 2 (versatile, great text)',
        'nano-banana-pro'  => 'Nano Banana Pro (photoreal)',
        'ideogram-v4'      => 'Ideogram v4 (best for text-in-image)',
        'venice-sd35'      => 'Venice SD3.5 (fast & cheap)',
    ];
}

// Durations are in whole seconds; all five models accept 16:9 / 9:16 / 1:1 (the only
// aspect ratios this UI offers), so aspect ratio does not need to vary by model.
function nv_venice_video_models() {
    return [
        'pixverse-v5.6-text-to-video'     => ['label' => 'PixVerse v5.6 (default, cinematic)', 'durations' => [5, 8]],
        'seedance-2-5-text-to-video-basic' => ['label' => 'Seedance 2.5 (long-form)', 'durations' => [5, 8, 12, 16, 20]],
        'kling-2.5-turbo-pro-text-to-video' => ['label' => 'Kling 2.5 Turbo Pro (cinematic)', 'durations' => [5, 10]],
        'wan-3-0-text-to-video'            => ['label' => 'Wan 3.0 (fast, flexible)', 'durations' => [5, 10, 15]],
        'minimax-h3-text-to-video'         => ['label' => 'MiniMax H3 (motion-heavy)', 'durations' => [5, 10, 15]],
    ];
}

// Image-to-video counterparts of the same 5 model families. Verified live 2026-09-30:
// these all reject an aspect_ratio field (framing follows the source image instead) and
// require `image_url` (a URL Venice can fetch — we upload the user's photo first and pass
// our own public /uploads/src/... URL).
function nv_venice_image_to_video_models() {
    return [
        'pixverse-v5.6-image-to-video'      => ['label' => 'PixVerse v5.6 (default, cinematic)', 'durations' => [5, 8]],
        'seedance-2-5-image-to-video-basic' => ['label' => 'Seedance 2.5 (long-form)', 'durations' => [5, 8, 12, 16, 20]],
        'kling-2.5-turbo-pro-image-to-video' => ['label' => 'Kling 2.5 Turbo Pro (cinematic)', 'durations' => [5, 10]],
        'wan-3-0-image-to-video'            => ['label' => 'Wan 3.0 (fast, flexible)', 'durations' => [5, 10, 15]],
        'minimax-h3-image-to-video'         => ['label' => 'MiniMax H3 (motion-heavy)', 'durations' => [5, 10, 15]],
    ];
}
define('NV_VENICE_IMG2VID_MODEL', 'pixverse-v5.6-image-to-video');

// Genuine video-to-video (style/motion transfer, whole clip re-rendered) is a much smaller
// slice of Venice's catalog than text/image-to-video. Verified live 2026-09-30: this model
// requires `video_url`, takes no duration/aspect_ratio (output length follows the source clip).
function nv_venice_video_to_video_models() {
    return [
        'wan-2-7-video-to-video' => ['label' => 'Wan 2.7 (style transfer, matches source length)'],
    ];
}
define('NV_VENICE_VID2VID_MODEL', 'wan-2-7-video-to-video');

function nv_venice_resolve_image_model($model) {
    $models = nv_venice_image_models();
    return isset($models[$model]) ? $model : NV_VENICE_IMAGE_MODEL;
}

/** Returns [validated_model, validated_duration_seconds]. */
function nv_venice_resolve_image_to_video_model($model, $durationSeconds) {
    $models = nv_venice_image_to_video_models();
    if (!isset($models[$model])) $model = NV_VENICE_IMG2VID_MODEL;
    $allowed = $models[$model]['durations'];
    if (!in_array((int)$durationSeconds, $allowed, true)) {
        $closest = $allowed[0];
        foreach ($allowed as $d) {
            if (abs($d - (int)$durationSeconds) < abs($closest - (int)$durationSeconds)) $closest = $d;
        }
        $durationSeconds = $closest;
    }
    return [$model, $durationSeconds];
}

function nv_venice_resolve_video_to_video_model($model) {
    $models = nv_venice_video_to_video_models();
    return isset($models[$model]) ? $model : NV_VENICE_VID2VID_MODEL;
}

/** All Venice video model ids this cabinet knows about, for /video/retrieve's model fallback. */
function nv_venice_all_video_models() {
    return nv_venice_video_models() + nv_venice_image_to_video_models() + nv_venice_video_to_video_models();
}

/** Returns [validated_model, validated_duration_seconds]. */
function nv_venice_resolve_video_model($model, $durationSeconds) {
    $models = nv_venice_video_models();
    if (!isset($models[$model])) $model = NV_VENICE_VIDEO_MODEL;
    $allowed = $models[$model]['durations'];
    if (!in_array((int)$durationSeconds, $allowed, true)) {
        // Snap to the closest duration this model actually accepts.
        $closest = $allowed[0];
        foreach ($allowed as $d) {
            if (abs($d - (int)$durationSeconds) < abs($closest - (int)$durationSeconds)) $closest = $d;
        }
        $durationSeconds = $closest;
    }
    return [$model, $durationSeconds];
}

function nv_venice_key() {
    return nv_api_env('VENICE_API_KEY');
}

function nv_venice_size_for_aspect($aspect) {
    switch ($aspect) {
        case '9:16': return [720, 1280];
        case '1:1':  return [1024, 1024];
        case '16:9':
        default:     return [1280, 720];
    }
}

/** Returns raw PNG bytes on success, throws Exception with a user-safe message on failure. */
function nv_venice_image_generate($prompt, $aspect, $model = null) {
    $key = nv_venice_key();
    if (!$key) throw new Exception('generator not configured (VENICE_API_KEY missing)');
    $model = nv_venice_resolve_image_model($model);
    list($w, $h) = nv_venice_size_for_aspect($aspect);
    $payload = [
        'model' => $model,
        'prompt' => $prompt,
        'width' => $w,
        'height' => $h,
        'safe_mode' => true,
        'hide_watermark' => true,
        'format' => 'png',
        'return_binary' => false,
    ];
    $ch = curl_init(NV_VENICE_IMAGE_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) throw new Exception('Venice unreachable: ' . $err);
    if ($code === 402) throw new Exception('generator account has no balance (Venice 402)');
    if ($code !== 200) throw new Exception('Venice HTTP ' . $code);
    $data = json_decode($resp, true);
    $b64 = (isset($data['images'][0])) ? $data['images'][0] : '';
    if (!$b64) throw new Exception('Venice returned no image data');
    $bytes = base64_decode($b64);
    if ($bytes === false || strlen($bytes) < 100) throw new Exception('Venice returned invalid image data');
    return $bytes;
}

/** Returns [provider queue id, resolved model, resolved duration] on success, throws Exception on failure. */
function nv_venice_video_submit($prompt, $aspect, $durationSeconds, $model = null) {
    $key = nv_venice_key();
    if (!$key) throw new Exception('generator not configured (VENICE_API_KEY missing)');
    list($model, $durationSeconds) = nv_venice_resolve_video_model($model, $durationSeconds);
    // Venice's /video/queue schema is strict per-model (verified live 2026-09-30): passing
    // resolution/safe_mode/hide_watermark is rejected with 400 "Unrecognized key(s)" on every
    // model tested (pixverse, seedance, kling, wan, minimax) — only these four fields are accepted.
    $payload = [
        'model' => $model,
        'prompt' => $prompt,
        'duration' => $durationSeconds . 's',
        'aspect_ratio' => $aspect ?: '16:9',
    ];
    $ch = curl_init(NV_VENICE_API_BASE . '/video/queue');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) throw new Exception('Venice unreachable: ' . $err);
    if ($code === 402) throw new Exception('generator account has no balance (Venice 402)');
    if ($code !== 200 && $code !== 202) {
        $errBody = json_decode($resp, true);
        $detail = is_array($errBody) ? ($errBody['error'] ?? '') : '';
        throw new Exception('Venice HTTP ' . $code . ($detail ? (': ' . $detail) : ''));
    }
    $data = json_decode($resp, true);
    $queueId = $data['id'] ?? $data['queue_id'] ?? $data['request_id'] ?? '';
    if (!$queueId) throw new Exception('Venice returned no job id');
    return [(string)$queueId, $model, $durationSeconds];
}

/** Shared low-level POST to /video/queue for the image/video-source variants below. */
function nv_venice_video_queue_post($payload) {
    $key = nv_venice_key();
    if (!$key) throw new Exception('generator not configured (VENICE_API_KEY missing)');
    $ch = curl_init(NV_VENICE_API_BASE . '/video/queue');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) throw new Exception('Venice unreachable: ' . $err);
    if ($code === 402) throw new Exception('generator account has no balance (Venice 402)');
    if ($code !== 200 && $code !== 202) {
        $errBody = json_decode($resp, true);
        $detail = is_array($errBody) ? ($errBody['error'] ?? '') : '';
        throw new Exception('Venice HTTP ' . $code . ($detail ? (': ' . $detail) : ''));
    }
    $data = json_decode($resp, true);
    $queueId = $data['id'] ?? $data['queue_id'] ?? $data['request_id'] ?? '';
    if (!$queueId) throw new Exception('Venice returned no job id');
    return (string)$queueId;
}

/** Returns [provider queue id, resolved model, resolved duration] on success, throws Exception on failure. */
function nv_venice_image_to_video_submit($prompt, $imageUrl, $durationSeconds, $model = null) {
    if (!$imageUrl) throw new Exception('source photo is required');
    list($model, $durationSeconds) = nv_venice_resolve_image_to_video_model($model, $durationSeconds);
    $queueId = nv_venice_video_queue_post([
        'model' => $model,
        'prompt' => $prompt,
        'image_url' => $imageUrl,
        'duration' => $durationSeconds . 's',
    ]);
    return [$queueId, $model, $durationSeconds];
}

/** Returns [provider queue id, resolved model] on success, throws Exception on failure. Duration follows the source clip. */
function nv_venice_video_to_video_submit($prompt, $videoUrl, $model = null) {
    if (!$videoUrl) throw new Exception('source video is required');
    $model = nv_venice_resolve_video_to_video_model($model);
    $queueId = nv_venice_video_queue_post([
        'model' => $model,
        'prompt' => $prompt,
        'video_url' => $videoUrl,
    ]);
    return [$queueId, $model];
}

/**
 * One poll attempt. Returns:
 *   ['state' => 'done', 'bytes' => <raw mp4 bytes>]
 *   ['state' => 'processing']
 *   ['state' => 'failed', 'message' => '...']
 */
function nv_venice_video_retrieve($queueId, $model = null) {
    $key = nv_venice_key();
    if (!$key) return ['state' => 'failed', 'message' => 'generator not configured'];
    $videoModels = nv_venice_all_video_models();
    if (!isset($videoModels[$model])) $model = NV_VENICE_VIDEO_MODEL;
    $ch = curl_init(NV_VENICE_API_BASE . '/video/retrieve');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['model' => $model, 'queue_id' => $queueId]),
        CURLOPT_HEADER => true,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    if ($resp === false) return ['state' => 'processing']; // transient network hiccup, retry next poll
    $headers = substr($resp, 0, $headerSize);
    $body = substr($resp, $headerSize);
    // 4xx from Venice is a permanent rejection (bad input, e.g. "Video duration is too short") —
    // verified live 2026-09-30 against wan-2-7-video-to-video — never becomes 200 no matter how
    // long we poll, so treat it as failed instead of "processing" forever. 5xx/429 stay transient.
    if ($code >= 400 && $code < 500) {
        $errBody = json_decode($body, true);
        $detail = is_array($errBody) ? ($errBody['error'] ?? '') : '';
        return ['state' => 'failed', 'message' => $detail ?: ('Venice HTTP ' . $code)];
    }
    if ($code !== 200) return ['state' => 'processing'];
    if (stripos($headers, 'video/') !== false || (strlen($body) > 5000 && substr($body, 4, 4) === 'ftyp')) {
        return ['state' => 'done', 'bytes' => $body];
    }
    $data = json_decode($body, true);
    if (!is_array($data)) return ['state' => 'processing'];
    $url = $data['url'] ?? $data['video_url'] ?? $data['output'] ?? '';
    if ($url) {
        $bytes = @file_get_contents($url);
        if ($bytes) return ['state' => 'done', 'bytes' => $bytes];
        return ['state' => 'processing'];
    }
    $status = $data['status'] ?? '';
    if (in_array($status, ['failed', 'error', 'cancelled'], true)) {
        return ['state' => 'failed', 'message' => 'generation ' . $status];
    }
    return ['state' => 'processing'];
}
