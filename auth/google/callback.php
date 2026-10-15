<?php
require_once __DIR__ . '/../../includes/x_ads_config.php'; // loads .env, defines lx_env_value()
define('GOOGLE_CLIENT_ID',     lx_env_value('GOOGLE_CLIENT_ID'));
define('GOOGLE_CLIENT_SECRET', lx_env_value('GOOGLE_CLIENT_SECRET'));
define('GOOGLE_REDIRECT_URI',  lx_env_value('GOOGLE_REDIRECT_URI'));

require_once __DIR__ . '/../../functions/auth_db.php';
require_once __DIR__ . '/../../functions/welcome_mail.php';
require_once __DIR__ . '/../../functions/flow_oauth.php';

// Start the session with the SAME cookie params redirect.php used, BEFORE the CSRF
// state check below — otherwise the session is empty and every callback fails 'bad_state'
// and bounces to the homepage. (fix 2026-07-19)
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_start();
}

function google_not_configured() {
    header('Location: /?google_error=not_configured');
    exit;
}

function google_error($msg = 'oauth_error') {
    header('Location: /?google_error=' . urlencode($msg));
    exit;
}

if (empty(GOOGLE_CLIENT_ID) || empty(GOOGLE_CLIENT_SECRET)) {
    google_not_configured();
}

$code = $_GET['code'] ?? '';
if (!$code) {
    google_error('no_code');
}

// CSRF protection: verify the state set in redirect.php (2026-06-21).
$state = $_GET['state'] ?? '';
$gStates = (!empty($_SESSION['g_oauth_states']) && is_array($_SESSION['g_oauth_states'])) ? $_SESSION['g_oauth_states'] : [];
$gStateOk = is_string($state) && $state !== ''
    && isset($gStates[$state]) && (time() - (int)$gStates[$state]) < 900;
if (!$gStateOk && !empty($_SESSION['g_oauth_state']) && is_string($state) && hash_equals($_SESSION['g_oauth_state'], $state)) {
    $gStateOk = true;
}
if (!$gStateOk) {
    // Replayed callback (back button / refresh) from a user who is already signed in:
    // send them to the cabinet instead of showing an error. 2026-09-29
    if (!empty($_SESSION['user_id'])) {
        header('Location: /app');
        exit;
    }
    google_error('bad_state');
}
unset($_SESSION['g_oauth_states'][$state], $_SESSION['g_oauth_state']);

// 1. Обмен code → access_token
$tokenResponse = file_get_contents('https://oauth2.googleapis.com/token', false, stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query([
            'code'          => $code,
            'client_id'     => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'redirect_uri'  => GOOGLE_REDIRECT_URI,
            'grant_type'    => 'authorization_code',
        ]),
        'ignore_errors' => true,
    ]
]));

if (!$tokenResponse) {
    google_error('token_fetch_failed');
}

$tokenData = json_decode($tokenResponse, true);
$accessToken = $tokenData['access_token'] ?? '';

if (!$accessToken) {
    google_error('no_access_token');
}

// 2. Получаем профиль пользователя
$profileResponse = file_get_contents('https://www.googleapis.com/oauth2/v2/userinfo', false, stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => 'Authorization: Bearer ' . $accessToken,
        'ignore_errors' => true,
    ]
]));

if (!$profileResponse) {
    google_error('profile_fetch_failed');
}

$profile = json_decode($profileResponse, true);
if (empty($profile['id']) || empty($profile['email'])) {
    google_error('invalid_profile');
}

// 3. Логин / регистрация
$res = lx_auth_google_login($profile);
lx_auth_log_event('oauth', isset($res['email']) ? $res['email'] : (isset($profile['email']) ? $profile['email'] : ''), $res['ok'], $res['ok'] ? '' : (isset($res['message']) ? $res['message'] : 'db_error'), 'google');

if (!$res['ok']) {
    google_error('db_error');
}

// 4. Новый — уведомление; письмо с кодом уходит из lx_flow_after_oauth()
if (!empty($res['is_new'])) {
    lx_notify_new_registration(isset($res['email']) ? $res['email'] : $profile['email'], 'google');
}

// Google confirmed this address itself -> the flow skips the emailed code (2026-10-10)
$_SESSION['lx_google_verified'] = (!empty($profile['verified_email']) || !empty($profile['email_verified'])) ? strtolower((string)$profile['email']) : '';

// 5. Аккаунт без своего пароля проходит код -> логин/пароль -> скачку; остальные сразу на скачку.
header('Location: ' . lx_flow_after_oauth('google'));
exit;
