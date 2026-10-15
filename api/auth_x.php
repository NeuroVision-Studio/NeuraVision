<?php
/**
 * Neuravision - X (Twitter) OAuth 2.0 Authorization
 * Query-param routed: /api/auth_x.php (start) and /api/auth_x.php?cb=1 (callback)
 */

$_envFile = __DIR__ . '/.env';
if (file_exists($_envFile)) {
    foreach (file($_envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_line) {
        if (strpos($_line, '#') === 0 || strpos($_line, '=') === false) continue;
        [$_k, $_v] = explode('=', $_line, 2);
        $_ENV[trim($_k)] = trim($_v);
    }
}

define('X_CLIENT_ID',     $_ENV['X_CLIENT_ID']    ?? '');
define('X_CLIENT_SECRET', $_ENV['X_CLIENT_SECRET'] ?? '');
define('X_REDIRECT_URI',  'https://neuracreation.com/api/auth_x.php?cb=1');
define('X_SCOPES',        'tweet.read users.read offline.access');
define('STATE_DIR',       __DIR__ . '/app_data/oauth_states');
define('ALLOWED_CALLBACK_HOSTS', ['neuracreation.com', 'www.neuracreation.com']);

function validateAppCallback($url) {
    if (empty($url)) return '';
    $parsed = parse_url($url);
    if (!$parsed || empty($parsed['host'])) return '';
    if (!in_array($parsed['host'], ALLOWED_CALLBACK_HOSTS)) return '';
    if (isset($parsed['scheme']) && $parsed['scheme'] !== 'https') return '';
    return $url;
}

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

function generatePKCE() {
    $verifier  = bin2hex(random_bytes(32));
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    return ['verifier' => $verifier, 'challenge' => $challenge];
}

function startXAuth() {
    if (rand(1, 10) === 1) cleanupStates();
    $pkce        = generatePKCE();
    $state       = bin2hex(random_bytes(16));
    $appCallback = validateAppCallback($_GET['app_callback'] ?? '');
    $lpv = preg_replace('/[^a-z0-9]/i','',(string)($_GET['lpv'] ?? ''));
    storeState($state, ['verifier' => $pkce['verifier'], 'app_callback' => $appCallback, 'lpv' => $lpv]);
    $params = http_build_query([
        'response_type'         => 'code',
        'client_id'             => X_CLIENT_ID,
        'redirect_uri'          => X_REDIRECT_URI,
        'scope'                 => X_SCOPES,
        'state'                 => $state,
        'code_challenge'        => $pkce['challenge'],
        'code_challenge_method' => 'S256',
    ]);
    header('Location: https://x.com/i/oauth2/authorize?' . $params);
    exit;
}

function handleXCallback() {
    $code  = $_GET['code']  ?? '';
    $state = $_GET['state'] ?? '';
    $error = $_GET['error'] ?? '';

    if ($error) { returnErrorWithState($state, 'X authorization denied: ' . $error); return; }
    if (!$code || !$state) { returnErrorWithState($state, 'Missing authorization code or state'); return; }

    $stateData = retrieveState($state);
    if (!$stateData) {
        header('Location: https://neuracreation.com/?auth_error=' . urlencode('Session expired. Please try again.'));
        exit;
    }

    $verifier    = $stateData['verifier']     ?? '';
    $appCallback = $stateData['app_callback'] ?? '';
    $lpv         = $stateData['lpv'] ?? '';

    $tokenData = exchangeXCode($code, $verifier);
    if (!$tokenData || isset($tokenData['error'])) {
        $errMsg = 'Failed to exchange code: ' . ($tokenData['error_description'] ?? $tokenData['error'] ?? 'Unknown error');
        redirectError($appCallback, $errMsg);
    }

    $accessToken = $tokenData['access_token'];
    $userInfo    = getXUserInfo($accessToken);

    if (!$userInfo || empty($userInfo['data']['id'])) {
        $apiErr = $userInfo['errors'][0]['message'] ?? ($userInfo['detail'] ?? '');
        $errMsg = 'Failed to get user info from X' . ($apiErr ? ': ' . $apiErr : '');
        redirectError($appCallback, $errMsg);
    }

    $xUser = $userInfo['data'];
    $user  = createOrUpdateUser('x', $xUser['id'], $xUser['username'] ?? '', $xUser['name'] ?? '', $accessToken, $lpv);

    if ($appCallback) {
        $sep = (strpos($appCallback, '?') !== false) ? '&' : '?';
        header('Location: ' . $appCallback . $sep . 'token=' . urlencode($user['auth_token']) . '&user_id=' . urlencode($user['user_id']) . '&provider=x');
    } else {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'message' => 'Successfully authenticated with X', 'user' => $user]);
    }
    exit;
}

function redirectError($appCallback, $errMsg) {
    if ($appCallback) {
        $sep = (strpos($appCallback, '?') !== false) ? '&' : '?';
        header('Location: ' . $appCallback . $sep . 'error=' . urlencode($errMsg));
    } else {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $errMsg]);
    }
    exit;
}

function exchangeXCode($code, $verifier) {
    $ch = curl_init('https://api.twitter.com/2/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_POST          => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER    => [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . base64_encode(X_CLIENT_ID . ':' . X_CLIENT_SECRET),
        ],
        CURLOPT_POSTFIELDS    => http_build_query([
            'code'          => $code,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => X_REDIRECT_URI,
            'code_verifier' => $verifier,
        ]),
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

function getXUserInfo($accessToken) {
    $ch = curl_init('https://api.twitter.com/2/users/me?user.fields=id,name,username,profile_image_url');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER    => ['Authorization: Bearer ' . $accessToken],
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

function createOrUpdateUser($provider, $providerId, $username, $displayName, $accessToken, $lpv = '') {
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
        credits INTEGER DEFAULT 100,
        plan TEXT DEFAULT "starter",
        access_token TEXT DEFAULT "",
        auth_token TEXT UNIQUE NOT NULL,
        hwid TEXT DEFAULT "",
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        last_login TEXT DEFAULT CURRENT_TIMESTAMP
    )');

    $stmt = $db->prepare('SELECT * FROM users WHERE provider = :provider AND provider_id = :pid');
    $stmt->bindValue(':provider', $provider, SQLITE3_TEXT);
    $stmt->bindValue(':pid', $providerId, SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    if ($row) {
        $s = $db->prepare('UPDATE users SET access_token = :token, last_login = :now, username = :uname WHERE id = :id');
        $s->bindValue(':token', $accessToken, SQLITE3_TEXT);
        $s->bindValue(':now',   date('Y-m-d H:i:s'), SQLITE3_TEXT);
        $s->bindValue(':uname', $username, SQLITE3_TEXT);
        $s->bindValue(':id',    $row['id'], SQLITE3_INTEGER);
        $s->execute();
        return [
            'user_id' => $row['user_id'], 'username' => $username, 'display_name' => $row['display_name'] ?: $displayName,
            'credits' => (int)$row['credits'], 'plan' => $row['plan'], 'auth_token' => $row['auth_token'], 'provider' => $provider,
        ];
    }

    $userId    = 'NV-' . strtoupper(substr(md5($provider . $providerId . time()), 0, 8));
    $authToken = bin2hex(random_bytes(32));
    $s = $db->prepare('INSERT INTO users (user_id, provider, provider_id, username, display_name, access_token, auth_token, credits) VALUES (:uid, :provider, :pid, :uname, :dname, :token, :atoken, 1000)');
    $s->bindValue(':uid',      $userId,      SQLITE3_TEXT);
    $s->bindValue(':provider', $provider,    SQLITE3_TEXT);
    $s->bindValue(':pid',      $providerId,  SQLITE3_TEXT);
    $s->bindValue(':uname',    $username,    SQLITE3_TEXT);
    $s->bindValue(':dname',    $displayName, SQLITE3_TEXT);
    $s->bindValue(':token',    $accessToken, SQLITE3_TEXT);
    $s->bindValue(':atoken',   $authToken,   SQLITE3_TEXT);
    $s->execute();
    require_once __DIR__ . '/nv_notify.php';
    nv_api_notify_registration($provider, '', $displayName, $userId, $lpv);
    return [
        'user_id' => $userId, 'username' => $username, 'display_name' => $displayName,
        'credits' => 1000, 'plan' => 'starter', 'auth_token' => $authToken, 'provider' => $provider,
    ];
}

function returnErrorWithState($state, $message) {
    $appCallback = '';
    if ($state) {
        $file = STATE_DIR . '/' . $state . '.json';
        if (file_exists($file)) {
            $data        = json_decode(file_get_contents($file), true);
            $appCallback = $data['app_callback'] ?? '';
            @unlink($file);
        }
    }
    redirectError($appCallback, $message);
}

if (isset($_GET['cb'])) {
    handleXCallback();
} else {
    startXAuth();
}
