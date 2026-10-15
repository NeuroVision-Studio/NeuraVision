<?php
/**
 * X (Twitter) OAuth 2.0 — Step 2: handle callback, exchange code, log in.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../../functions/auth_db.php'; // starts session
@require_once __DIR__ . '/../../functions/welcome_mail.php';
require_once __DIR__ . '/../../functions/flow_oauth.php';

function x_error($msg = 'oauth_error') {
    header('Location: /?x_error=' . urlencode($msg));
    exit;
}

if (!lx_twitter_configured()) {
    x_error('not_configured');
}

$code  = isset($_GET['code'])  ? $_GET['code']  : '';
$state = isset($_GET['state']) ? $_GET['state'] : '';

if (!$code && empty($_GET['error'])) {
    x_error('no_code');
}
// X sends ?error=access_denied when the user presses Cancel — report it as such.
if (!empty($_GET['error']) && !$code) {
    x_error(preg_replace('/[^a-z_]/', '', strtolower((string)$_GET['error'])) ?: 'oauth_error');
}
$twMap = (!empty($_SESSION['tw_oauth']) && is_array($_SESSION['tw_oauth'])) ? $_SESSION['tw_oauth'] : [];
$verifier = '';
if (is_string($state) && $state !== '' && isset($twMap[$state]) && (time() - (int)$twMap[$state]['t']) < 900) {
    $verifier = (string)$twMap[$state]['v'];
} elseif (!empty($_SESSION['tw_oauth_state']) && is_string($state) && hash_equals($_SESSION['tw_oauth_state'], $state)) {
    $verifier = isset($_SESSION['tw_oauth_verifier']) ? $_SESSION['tw_oauth_verifier'] : '';
} else {
    if (!empty($_SESSION['user_id'])) { header('Location: /app'); exit; }
    x_error('bad_state');
}
if (!$verifier) {
    x_error('no_verifier');
}

// --- Exchange authorization code for access token ---
// X requires HTTP Basic auth (client_id:client_secret) for confidential clients.
$basic = base64_encode(TWITTER_CLIENT_ID . ':' . TWITTER_CLIENT_SECRET);
$tokenBody = http_build_query([
    'grant_type'    => 'authorization_code',
    'code'          => $code,
    'redirect_uri'  => TWITTER_REDIRECT_URI,
    'code_verifier' => $verifier,
    'client_id'     => TWITTER_CLIENT_ID,
]);

$tokenResponse = file_get_contents('https://api.twitter.com/2/oauth2/token', false, stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n" .
                     'Authorization: Basic ' . $basic . "\r\n",
        'content' => $tokenBody,
        'ignore_errors' => true,
    ]
]));

if (!$tokenResponse) {
    x_error('token_fetch_failed');
}
$tokenData   = json_decode($tokenResponse, true);
$accessToken = isset($tokenData['access_token']) ? $tokenData['access_token'] : '';
if (!$accessToken) {
    x_error('no_access_token');
}

// --- Fetch user profile ---
$profileResponse = file_get_contents('https://api.twitter.com/2/users/me?user.fields=profile_image_url,name,username', false, stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => 'Authorization: Bearer ' . $accessToken . "\r\n",
        'ignore_errors' => true,
    ]
]));

if (!$profileResponse) {
    x_error('profile_fetch_failed');
}
$pj = json_decode($profileResponse, true);
$data = isset($pj['data']) ? $pj['data'] : null;
if (!$data || empty($data['id'])) {
    x_error('invalid_profile');
}

$profile = [
    'id'       => (string)$data['id'],
    'username' => isset($data['username']) ? $data['username'] : '',
    'name'     => isset($data['name']) ? $data['name'] : '',
    'avatar'   => isset($data['profile_image_url']) ? $data['profile_image_url'] : '',
];

// Clear PKCE state.
unset($_SESSION['tw_oauth_verifier'], $_SESSION['tw_oauth_state'], $_SESSION['tw_oauth'][$state]);

$res = lx_auth_twitter_login($profile);
lx_auth_log_event('oauth', isset($res['email']) ? $res['email'] : '', $res['ok'], $res['ok'] ? '' : (isset($res['message']) ? $res['message'] : 'db_error'), 'twitter');
if (!$res['ok']) {
    x_error('db_error');
}

if (!empty($res['is_new'])) {
    lx_notify_new_registration($res['email'], 'twitter');
}

// An account without its own password goes through email code -> login/password -> download.
header('Location: ' . lx_flow_after_oauth('x'));
exit;
