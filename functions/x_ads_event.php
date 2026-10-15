<?php

header('Content-Type: application/json; charset=utf-8');

// LX test session: do not forward conversions to X for test traffic (2026-06-21)
if (!empty($_COOKIE['lx_notrack'])) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'skipped' => 'notrack']);
    exit;
}

function lx_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function lx_post_value(string $key): string
{
    static $jsonBody = null;

    if (isset($_POST[$key])) {
        return trim((string)$_POST[$key]);
    }

    if ($jsonBody === null) {
        $raw = file_get_contents('php://input');
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        $jsonBody = is_array($decoded) ? $decoded : [];
    }

    return isset($jsonBody[$key]) ? trim((string)$jsonBody[$key]) : '';
}

function lx_env_config(): array
{
    $configPath = dirname(__DIR__) . '/includes/x_ads_config.php';
    $config = file_exists($configPath) ? require $configPath : [];

    return is_array($config) ? $config : [];
}

function lx_sanitize_x_id(string $value): string
{
    $value = trim($value);

    return preg_match('/^[A-Za-z0-9_-]{2,80}$/', $value) ? $value : '';
}

function lx_pixel_id_from_event_id(string $eventId): string
{
    if (preg_match('/^tw-([A-Za-z0-9]+)-[A-Za-z0-9]+$/', $eventId, $matches)) {
        return $matches[1];
    }

    return '';
}

function lx_normalize_pixel_id(string $pixelId): string
{
    $pixelId = lx_sanitize_x_id($pixelId);
    if ($pixelId === '') {
        return '';
    }

    return lx_pixel_id_from_event_id($pixelId) ?: $pixelId;
}

function lx_normalize_twclid(string $value): string
{
    $value = trim($value);

    return preg_match('/^[A-Za-z0-9_-]{5,512}$/', $value) ? $value : '';
}

function lx_sha256_identifier(string $value): string
{
    $value = trim(strtolower($value));

    if ($value === '') {
        return '';
    }

    if (preg_match('/^[a-f0-9]{64}$/i', $value)) {
        return strtolower($value);
    }

    return hash('sha256', $value);
}

function lx_client_ip(): string
{
    $headers = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED_FOR',
        'REMOTE_ADDR',
    ];

    foreach ($headers as $header) {
        if (empty($_SERVER[$header])) {
            continue;
        }

        $parts = explode(',', (string)$_SERVER[$header]);
        foreach ($parts as $ip) {
            $ip = trim($ip);

            if (strpos($ip, '::ffff:') === 0) {
                $ip = substr($ip, strrpos($ip, ':') + 1);
            }

            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    return '';
}

function lx_oauth_encode(string $value): string
{
    return str_replace('%7E', '~', rawurlencode($value));
}

function lx_x_ads_oauth_header(
    string $method,
    string $url,
    string $consumerKey,
    string $consumerSecret,
    string $token,
    string $tokenSecret
): string {
    $oauth = [
        'oauth_consumer_key' => $consumerKey,
        'oauth_nonce' => bin2hex(random_bytes(16)),
        'oauth_signature_method' => 'HMAC-SHA1',
        'oauth_timestamp' => (string)time(),
        'oauth_token' => $token,
        'oauth_version' => '1.0',
    ];

    $signatureParams = $oauth;
    ksort($signatureParams);

    $pairs = [];
    foreach ($signatureParams as $key => $value) {
        $pairs[] = lx_oauth_encode($key) . '=' . lx_oauth_encode((string)$value);
    }

    $baseString = strtoupper($method) . '&' . lx_oauth_encode($url) . '&' . lx_oauth_encode(implode('&', $pairs));
    $signingKey = lx_oauth_encode($consumerSecret) . '&' . lx_oauth_encode($tokenSecret);
    $oauth['oauth_signature'] = base64_encode(hash_hmac('sha1', $baseString, $signingKey, true));

    $header = [];
    foreach ($oauth as $key => $value) {
        $header[] = lx_oauth_encode($key) . '="' . lx_oauth_encode((string)$value) . '"';
    }

    return 'OAuth ' . implode(', ', $header);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    lx_json_response(['status' => 'error', 'message' => 'POST required.'], 405);
}

$config = array_merge([
    'enabled' => true,
    'consumer_key' => '',
    'consumer_secret' => '',
    'access_token' => '',
    'access_token_secret' => '',
    'pixel_token' => '',
    'pixel_id' => '',
    'event_id' => '',
    'api_version' => '12',
    'debug' => false,
], lx_env_config());

if (empty($config['enabled'])) {
    lx_json_response(['status' => 'skipped', 'reason' => 'disabled']);
}

$pixelId = lx_normalize_pixel_id(lx_post_value('pixel_id'))
    ?: lx_normalize_pixel_id(lx_post_value('tw_pixel'))
    ?: lx_normalize_pixel_id(lx_post_value('x_pixel_id'))
    ?: lx_normalize_pixel_id((string)$config['pixel_id']);

$eventId = lx_sanitize_x_id(lx_post_value('event_id'))
    ?: lx_sanitize_x_id(lx_post_value('tw_event'))
    ?: lx_sanitize_x_id(lx_post_value('x_event_id'))
    ?: lx_sanitize_x_id(lx_post_value('event_token'))
    ?: lx_sanitize_x_id(lx_post_value('tw_click'))
    ?: lx_sanitize_x_id((string)$config['event_id']);

if ($pixelId === '' && $eventId !== '') {
    $pixelId = lx_pixel_id_from_event_id($eventId);
}

if ($pixelId === '' || $eventId === '') {
    lx_json_response(['status' => 'skipped', 'reason' => 'missing_pixel_or_event_id']);
}

$twclid = lx_normalize_twclid(lx_post_value('twclid'));
if ($twclid === '' && isset($_COOKIE['twclid'])) {
    $twclid = lx_normalize_twclid((string)$_COOKIE['twclid']);
}

$hashedEmail = lx_sha256_identifier(lx_post_value('hashed_email') ?: lx_post_value('email'));
$hashedPhone = lx_sha256_identifier(lx_post_value('hashed_phone_number') ?: lx_post_value('phone'));

$identifiers = [];
if ($twclid !== '') {
    $identifiers[] = ['twclid' => $twclid];
}
if ($hashedEmail !== '') {
    $identifiers[] = ['hashed_email' => $hashedEmail];
}
if ($hashedPhone !== '') {
    $identifiers[] = ['hashed_phone_number' => $hashedPhone];
}

$ip = lx_client_ip();
$userAgent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
if ($ip !== '' && $userAgent !== '') {
    $identifiers[] = [
        'ip_address' => $ip,
        'user_agent' => $userAgent,
    ];
}

if (empty($identifiers)) {
    lx_json_response(['status' => 'skipped', 'reason' => 'missing_identifier']);
}

$pixelToken = trim((string)($config['pixel_token'] ?? ''));
if ($pixelToken === '') {
    lx_json_response(['status' => 'skipped', 'reason' => 'x_ads_not_configured', 'missing' => 'pixel_token']);
}

$conversionId = lx_post_value('conversion_id');
if ($conversionId === '') {
    $conversionId = 'lx_' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(8));
}

$conversion = [
    'conversion_time' => gmdate('Y-m-d\TH:i:s.000\Z'),
    'event_id' => $eventId,
    'identifiers' => $identifiers,
    'conversion_id' => $conversionId,
];

$value = lx_post_value('value');
if ($value !== '' && is_numeric($value)) {
    $conversion['value'] = number_format((float)$value, 2, '.', '');
}

$currency = strtoupper(lx_post_value('price_currency') ?: lx_post_value('currency'));
if (preg_match('/^[A-Z]{3}$/', $currency)) {
    $conversion['price_currency'] = $currency;
}

$description = lx_post_value('description');
if ($description !== '') {
    $conversion['description'] = mb_substr($description, 0, 255);
}

$numberItems = lx_post_value('number_items') ?: lx_post_value('num_items');
if ($numberItems !== '' && ctype_digit($numberItems) && (int)$numberItems > 0) {
    $conversion['number_items'] = (int)$numberItems;
}

$version = preg_replace('/[^0-9]/', '', (string)$config['api_version']) ?: '12';
$url = 'https://ads-api.x.com/' . $version . '/measurement/conversions/' . rawurlencode($pixelId);
$payload = ['conversions' => [$conversion]];
$body = json_encode($payload, JSON_UNESCAPED_SLASHES);

if ($body === false) {
    lx_json_response(['status' => 'error', 'message' => 'Could not encode payload.'], 500);
}

if (!function_exists('curl_init')) {
    lx_json_response(['status' => 'error', 'message' => 'PHP cURL extension is not enabled.'], 500);
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_HTTPHEADER => [
        'X-Pixel-Token: ' . $pixelToken,
        'Content-Type: application/json',
        'Accept: application/json',
    ],
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) {
    lx_json_response(['status' => 'error', 'message' => 'X Ads request failed.', 'error' => $curlError], 502);
}

$decodedResponse = json_decode($response, true);
$responsePayload = is_array($decodedResponse) ? $decodedResponse : ['raw' => $response];

if ($statusCode >= 200 && $statusCode < 300) {
    lx_json_response([
        'status' => 'success',
        'http_code' => $statusCode,
        'response' => $responsePayload,
    ]);
}

lx_json_response([
    'status' => 'error',
    'http_code' => $statusCode,
    'response' => $responsePayload,
], $statusCode > 0 ? $statusCode : 502);
