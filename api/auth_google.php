<?php

// Load environment variables from .env
$_lx_env_file = __DIR__ . '/.env';
if (is_file($_lx_env_file)) {
    foreach (file($_lx_env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_lx_line) {
        $_lx_line = trim($_lx_line);
        if ($_lx_line === '' || $_lx_line[0] === '#' || strpos($_lx_line, '=') === false) continue;
        list($_lx_k, $_lx_v) = array_map('trim', explode('=', $_lx_line, 2));
        if ($_lx_k !== '' && !isset($_ENV[$_lx_k])) $_ENV[$_lx_k] = $_lx_v;
    }
}
/**
 * Neuravision - Google OAuth 2.0 Authorization
 *
 * Endpoints (query-param routed, no rewrite needed):
 *   GET /api/auth_google.php          - Redirect user to Google login
 *   GET /api/auth_google.php?cb=1     - Handle callback from Google
 */

define('GOOGLE_CLIENT_ID', isset($_ENV['GOOGLE_CLIENT_ID']) ? $_ENV['GOOGLE_CLIENT_ID'] : '');
define('GOOGLE_CLIENT_SECRET', isset($_ENV['GOOGLE_CLIENT_SECRET']) ? $_ENV['GOOGLE_CLIENT_SECRET'] : '');
define('GOOGLE_REDIRECT_URI', 'https://neuracreation.com/api/auth_google.php?cb=1');
define('GOOGLE_SCOPES', 'openid email profile');
define('ALLOWED_CALLBACK_HOSTS', ['neuracreation.com', 'www.neuracreation.com']);

function validateAppCallback($url) {
    if (empty($url)) return '';
    $parsed = parse_url($url);
    if (!$parsed || empty($parsed['host'])) return '';
    if (!in_array($parsed['host'], ALLOWED_CALLBACK_HOSTS)) return '';
    if (isset($parsed['scheme']) && $parsed['scheme'] !== 'https') return '';
    return $url;
}

define('STATE_DIR', __DIR__ . '/app_data/oauth_states');

function storeState($state, $data) {
    if (!is_dir(STATE_DIR)) mkdir(STATE_DIR, 0755, true);
    $data['created_at'] = time();
    file_put_contents(STATE_DIR . '/' . $state . '.json', json_encode($data));
}

function retrieveState($state) {
    $file = STATE_DIR . '/' . $state . '.json';
    if (!file_exists($file)) return null;
    $data = json_decode(file_get_contents($file), true);
    unlink($file);
    if (isset($data['created_at']) && (time() - $data['created_at']) > 600) return null;
    return $data;
}

function cleanupStates() {
    if (!is_dir(STATE_DIR)) return;
    foreach (glob(STATE_DIR . '/*.json') as $file) {
        if (filemtime($file) < time() - 900) @unlink($file);
    }
}

function startGoogleAuth() {
    if (rand(1, 10) === 1) cleanupStates();
    $state = bin2hex(random_bytes(16));
    $appCallback = validateAppCallback($_GET['app_callback'] ?? '');
    $lpv = preg_replace('/[^a-z0-9]/i','',(string)($_GET['lpv'] ?? ''));
    storeState($state, ['app_callback' => $appCallback, 'lpv' => $lpv]);
    $params = http_build_query([
        'client_id' => GOOGLE_CLIENT_ID,
        'redirect_uri' => GOOGLE_REDIRECT_URI,
        'response_type' => 'code',
        'scope' => GOOGLE_SCOPES,
        'state' => $state,
        'access_type' => 'offline',
        'prompt' => 'consent',
    ]);
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
    exit;
}

function handleGoogleCallback() {
    $code = $_GET['code'] ?? '';
    $state = $_GET['state'] ?? '';
    $error = $_GET['error'] ?? '';

    if ($error) { returnGoogleErrorWithState($state, 'Google authorization denied: ' . $error); return; }
    if (!$code || !$state) { returnGoogleErrorWithState($state, 'Missing authorization code or state'); return; }

    $stateData = retrieveState($state);
    if (!$stateData) {
        header('Location: https://neuracreation.com/?auth_error=' . urlencode('Session expired. Please try again.'));
        exit;
    }

    $appCallback = $stateData['app_callback'] ?? '';
    $lpv = $stateData['lpv'] ?? '';

    $tokenData = exchangeGoogleCode($code);
    if (!$tokenData || isset($tokenData['error'])) {
        $errMsg = 'Failed to exchange code: ' . ($tokenData['error_description'] ?? $tokenData['error'] ?? 'Unknown');
        redirectError($appCallback, $errMsg);
    }

    $accessToken = $tokenData['access_token'];
    $userInfo = getGoogleUserInfo($accessToken);

    if (!$userInfo || isset($userInfo['error'])) {
        redirectError($appCallback, 'Failed to get user info from Google');
    }

    $user = createOrUpdateGoogleUser($userInfo, $accessToken, $lpv);

    if ($appCallback) {
        $separator = (strpos($appCallback, '?') !== false) ? '&' : '?';
        header('Location: ' . $appCallback . $separator . 'token=' . urlencode($user['auth_token']) . '&user_id=' . urlencode($user['user_id']) . '&provider=google');
    } else {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'message' => 'Successfully authenticated with Google', 'user' => $user]);
    }
    exit;
}

function redirectError($appCallback, $errMsg) {
    if ($appCallback) {
        $separator = (strpos($appCallback, '?') !== false) ? '&' : '?';
        header('Location: ' . $appCallback . $separator . 'error=' . urlencode($errMsg));
    } else {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $errMsg]);
    }
    exit;
}

function exchangeGoogleCode($code) {
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query([
            'code' => $code,
            'client_id' => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'redirect_uri' => GOOGLE_REDIRECT_URI,
            'grant_type' => 'authorization_code',
        ]),
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

function getGoogleUserInfo($accessToken) {
    $ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

function createOrUpdateGoogleUser($userInfo, $accessToken, $lpv = '') {
    $dbDir = __DIR__ . '/app_data';
    if (!is_dir($dbDir)) mkdir($dbDir, 0755, true);
    $db = new SQLite3($dbDir . '/keys.db');
    $db->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT UNIQUE NOT NULL,
        provider TEXT NOT NULL,
        provider_id TEXT NOT NULL,
        username TEXT DEFAULT "",
        display_name TEXT DEFAULT "",
        email TEXT DEFAULT "",
        credits INTEGER DEFAULT 1000,
        plan TEXT DEFAULT "starter",
        access_token TEXT DEFAULT "",
        auth_token TEXT UNIQUE NOT NULL,
        hwid TEXT DEFAULT "",
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        last_login TEXT DEFAULT CURRENT_TIMESTAMP
    )');

    $providerId = $userInfo['id'];
    $email = $userInfo['email'] ?? '';
    $displayName = $userInfo['name'] ?? '';
    $username = explode('@', $email)[0] ?? '';

    $stmt = $db->prepare('SELECT * FROM users WHERE provider = :provider AND provider_id = :pid');
    $stmt->bindValue(':provider', 'google', SQLITE3_TEXT);
    $stmt->bindValue(':pid', $providerId, SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    if ($row) {
        $stmt = $db->prepare('UPDATE users SET access_token = :token, last_login = :now, email = :email WHERE id = :id');
        $stmt->bindValue(':token', $accessToken, SQLITE3_TEXT);
        $stmt->bindValue(':now', date('Y-m-d H:i:s'), SQLITE3_TEXT);
        $stmt->bindValue(':email', $email, SQLITE3_TEXT);
        $stmt->bindValue(':id', $row['id'], SQLITE3_INTEGER);
        $stmt->execute();

        return [
            'user_id' => $row['user_id'], 'username' => $row['username'] ?: $username,
            'display_name' => $row['display_name'] ?: $displayName, 'email' => $email,
            'credits' => (int)$row['credits'], 'plan' => $row['plan'],
            'auth_token' => $row['auth_token'], 'provider' => 'google',
        ];
    } else {
        $userId = 'NV-' . strtoupper(substr(md5('google' . $providerId . time()), 0, 8));
        $authToken = bin2hex(random_bytes(32));
        $stmt = $db->prepare('INSERT INTO users (user_id, provider, provider_id, username, display_name, email, access_token, auth_token, credits) VALUES (:uid, :provider, :pid, :uname, :dname, :email, :token, :atoken, 1000)');
        $stmt->bindValue(':uid', $userId, SQLITE3_TEXT);
        $stmt->bindValue(':provider', 'google', SQLITE3_TEXT);
        $stmt->bindValue(':pid', $providerId, SQLITE3_TEXT);
        $stmt->bindValue(':uname', $username, SQLITE3_TEXT);
        $stmt->bindValue(':dname', $displayName, SQLITE3_TEXT);
        $stmt->bindValue(':email', $email, SQLITE3_TEXT);
        $stmt->bindValue(':token', $accessToken, SQLITE3_TEXT);
        $stmt->bindValue(':atoken', $authToken, SQLITE3_TEXT);
        $stmt->execute();
        require_once __DIR__ . '/nv_notify.php';
        nv_api_notify_registration('google', $email, $displayName, $userId, $lpv);

        return [
            'user_id' => $userId, 'username' => $username, 'display_name' => $displayName, 'email' => $email,
            'credits' => 1000, 'plan' => 'starter', 'auth_token' => $authToken, 'provider' => 'google',
        ];
    }
}

function returnGoogleErrorWithState($state, $message) {
    $appCallback = '';
    if ($state) {
        $file = STATE_DIR . '/' . $state . '.json';
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            $appCallback = $data['app_callback'] ?? '';
            @unlink($file);
        }
    }
    redirectError($appCallback, $message);
}

if (isset($_GET['cb'])) {
    handleGoogleCallback();
} else {
    startGoogleAuth();
}
