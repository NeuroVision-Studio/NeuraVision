<?php
require_once __DIR__ . '/../../includes/x_ads_config.php'; // loads .env, defines lx_env_value()
define('GOOGLE_CLIENT_ID',    lx_env_value('GOOGLE_CLIENT_ID'));
define('GOOGLE_REDIRECT_URI', lx_env_value('GOOGLE_REDIRECT_URI'));

if (empty(GOOGLE_CLIENT_ID)) {
    http_response_code(503);
    die('Google OAuth not configured yet.');
}

// CSRF protection: store state in session, verify it in callback (2026-06-21).
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_start();
}
$state = bin2hex(random_bytes(16));
$_SESSION['g_oauth_state'] = $state;
// Keep the last few issued states so a double click / second tab does not invalidate
// the login that is already in progress (was the main source of 'bad_state'). 2026-09-29
if (empty($_SESSION['g_oauth_states']) || !is_array($_SESSION['g_oauth_states'])) { $_SESSION['g_oauth_states'] = []; }
$_SESSION['g_oauth_states'][$state] = time();
$_SESSION['g_oauth_states'] = array_slice($_SESSION['g_oauth_states'], -5, null, true);

$params = http_build_query([
    'client_id'     => GOOGLE_CLIENT_ID,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'access_type'   => 'online',
    'prompt'        => 'select_account',
    'state'         => $state,
]);

header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
exit;
