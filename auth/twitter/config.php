<?php
/**
 * X (Twitter) OAuth 2.0 configuration.
 *
 * HOW TO FILL THIS IN (see also the setup guide delivered with this build):
 * 1. Go to https://developer.x.com/ → Projects & Apps → your App → "User authentication settings".
 * 2. Set "Type of App" = Web App, Automated App or Bot (Confidential client).
 * 3. App permissions: at least "Read".
 * 4. Callback URI / Redirect URL: https://neuracreation.com/auth/twitter/callback.php
 * 5. Website URL: https://neuracreation.com
 * 6. Copy the OAuth 2.0 "Client ID" and "Client Secret" below.
 *
 * Until real keys are filled in, the buttons will show a friendly
 * "X login is being set up" notice instead of erroring.
 */

// Credentials read from .env (2026-06-21); fallback keeps the "being set up" notice if unset.
require_once __DIR__ . '/../../includes/x_ads_config.php'; // loads .env, defines lx_env_value()
define('TWITTER_CLIENT_ID',     lx_env_value('X_CLIENT_ID')     ?: 'REPLACE_WITH_X_OAUTH2_CLIENT_ID');
define('TWITTER_CLIENT_SECRET', lx_env_value('X_CLIENT_SECRET') ?: 'REPLACE_WITH_X_OAUTH2_CLIENT_SECRET');
define('TWITTER_REDIRECT_URI',  lx_env_value('X_REDIRECT_URI')  ?: 'https://neuracreation.com/auth/twitter/callback.php');

// OAuth 2.0 Authorization Code with PKCE scopes.
define('TWITTER_SCOPES', 'users.read tweet.read offline.access');

function lx_twitter_configured() {
    return TWITTER_CLIENT_ID !== ''
        && TWITTER_CLIENT_SECRET !== ''
        && strpos(TWITTER_CLIENT_ID, 'REPLACE_WITH') !== 0;
}
