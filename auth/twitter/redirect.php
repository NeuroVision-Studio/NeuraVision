<?php
/**
 * X (Twitter) OAuth 2.0 — Step 1: redirect to X authorization with PKCE.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../../functions/auth_db.php'; // starts session

if (!lx_twitter_configured()) {
    header('Location: /?x_error=not_configured');
    exit;
}

// PKCE: create code_verifier + code_challenge (S256).
$verifier  = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
$state     = bin2hex(random_bytes(16));

$_SESSION['tw_oauth_verifier'] = $verifier;
$_SESSION['tw_oauth_state']    = $state;
// Per-state verifier so a double click / second tab keeps the first attempt valid. 2026-09-29
if (empty($_SESSION['tw_oauth']) || !is_array($_SESSION['tw_oauth'])) { $_SESSION['tw_oauth'] = []; }
$_SESSION['tw_oauth'][$state] = ['v' => $verifier, 't' => time()];
$_SESSION['tw_oauth'] = array_slice($_SESSION['tw_oauth'], -5, null, true);

$params = http_build_query([
    'response_type'         => 'code',
    'client_id'             => TWITTER_CLIENT_ID,
    'redirect_uri'          => TWITTER_REDIRECT_URI,
    'scope'                 => TWITTER_SCOPES,
    'state'                 => $state,
    'code_challenge'        => $challenge,
    'code_challenge_method' => 'S256',
]);

header('Location: https://x.com/i/oauth2/authorize?' . $params);
exit;
