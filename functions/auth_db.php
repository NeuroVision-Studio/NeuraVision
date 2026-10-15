<?php
/**
 * Neuravision Auth module (SQLite).
 * Handles email/password, Google OAuth, X (Twitter) OAuth, credits,
 * personal activation codes, device binding, and lead capture.
 *
 * PHP 7.4 compatible. Do not break existing Google/email flows.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* ---- Free signup bonus: every brand-new account starts with this many credits ----
 * Changed 2026-10-09 per owner request: 1000 -> 250. A valid promo code (below) ADDS
 * on top of this base amount; it never replaces it. */
if (!defined('LX_FREE_SIGNUP_CREDITS')) {
    define('LX_FREE_SIGNUP_CREDITS', 250);
}

/* ---- Promo codes: on top of the free signup bonus, a valid code adds its credits ---- */
if (!defined('LX_PROMO_CODES')) {
    define('LX_PROMO_CODES', ['HOT1000' => 1000]);
}

/** Credits granted by a promo code (0 if empty or unknown). Codes are case-insensitive. */
function lx_promo_credits($code) {
    $code = strtoupper(trim((string)$code));
    if ($code === '') return 0;
    return (int)(LX_PROMO_CODES[$code] ?? 0);
}

/**
 * Logs a successful promo redemption for the admin panel (/admin/promo/).
 * Never throws into the caller's flow.
 */
function lx_promo_log_redemption($userId, $email, $code, $credits, $source = 'account') {
    try {
        $pdo = lx_auth_db();
        $pdo->prepare('INSERT INTO promo_redemptions (user_id, email, code, credits, source, created_at) VALUES (?,?,?,?,?,?)')
            ->execute([(int)$userId, strtolower((string)$email), strtoupper((string)$code), (int)$credits, (string)$source, date('c')]);
    } catch (Exception $e) { /* logging must never break the redemption */ }
}

/**
 * Server-side, race-safe, once-per-account promo redemption.
 * Adds the code's credits ON TOP of the account's current balance (never replaces it)
 * and is gated by the same `promo_code` column the setup-flow redemption (auth_set_password.php)
 * already uses, so a code can't be claimed twice regardless of which UI it was entered in.
 * Returns ['ok'=>true,'credited'=>N,'balance'=>N] or ['ok'=>false,'message'=>...,'already_used'=>bool].
 */
function lx_promo_redeem($userId, $code) {
    $userId = (int)$userId;
    $codeNorm = strtoupper(trim((string)$code));
    $bonus = lx_promo_credits($codeNorm);
    if ($userId <= 0) {
        return ['ok' => false, 'message' => 'Not signed in'];
    }
    if ($codeNorm === '' || $bonus <= 0) {
        return ['ok' => false, 'message' => 'This promo code is not valid.'];
    }
    try {
        $pdo = lx_auth_db();
        $stmt = $pdo->prepare('SELECT email, promo_code FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'message' => 'Account not found'];
        }
        if ((string)$row['promo_code'] !== '') {
            return ['ok' => false, 'message' => 'Already used', 'already_used' => true];
        }
        // Atomic compare-and-set: only applies while promo_code is still empty, so a
        // concurrent double-submit can only ever win once.
        $upd = $pdo->prepare("UPDATE users SET credits = credits + ?, promo_code = ? WHERE id = ? AND COALESCE(promo_code,'') = ''");
        $upd->execute([$bonus, $codeNorm, $userId]);
        if ($upd->rowCount() === 0) {
            return ['ok' => false, 'message' => 'Already used', 'already_used' => true];
        }
        lx_promo_log_redemption($userId, $row['email'], $codeNorm, $bonus, 'account');
        $bal = $pdo->prepare('SELECT credits FROM users WHERE id = ?');
        $bal->execute([$userId]);
        return ['ok' => true, 'credited' => $bonus, 'balance' => (int)$bal->fetchColumn()];
    } catch (Exception $e) {
        return ['ok' => false, 'message' => 'A server error occurred. Please try again.'];
    }
}

function lx_auth_db() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $dbPath = $dir . '/users.sqlite';

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        email         TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL DEFAULT '',
        google_id     TEXT UNIQUE,
        google_name   TEXT,
        google_avatar TEXT,
        created_at    TEXT NOT NULL,
        ip            TEXT,
        user_agent    TEXT
    )");

    // Idempotent column additions for new account-system features.
    $cols = [];
    foreach ($pdo->query("PRAGMA table_info(users)") as $row) {
        $cols[] = $row['name'];
    }
    $addCol = function ($name, $ddl) use ($pdo, $cols) {
        if (!in_array($name, $cols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN $ddl");
        }
    };
    $addCol('google_id',         "google_id TEXT");
    $addCol('google_name',       "google_name TEXT");
    $addCol('google_avatar',     "google_avatar TEXT");
    $addCol('twitter_id',        "twitter_id TEXT");
    $addCol('twitter_name',      "twitter_name TEXT");
    $addCol('twitter_avatar',    "twitter_avatar TEXT");
    $addCol('display_name',      "display_name TEXT");
    $addCol('avatar',            "avatar TEXT");
    $addCol('credits',           "credits INTEGER NOT NULL DEFAULT 0");
    $addCol('plan',              "plan TEXT NOT NULL DEFAULT 'starter'");
    $addCol('activation_code',   "activation_code TEXT");
    $addCol('device_fingerprint',"device_fingerprint TEXT");
    $addCol('device_name',       "device_name TEXT");
    $addCol('device_bound_at',   "device_bound_at TEXT");
    $addCol('token',             "token TEXT");
    $addCol('phone_verified',    "phone_verified INTEGER NOT NULL DEFAULT 0");
    $addCol('phone',             "phone TEXT");
    $addCol('must_change_password',     "must_change_password INTEGER NOT NULL DEFAULT 0");
    $addCol('temp_password_expires_at', "temp_password_expires_at TEXT");
    $addCol('promo_code',        "promo_code TEXT");
    $addCol('lk_c_email_verified_at', "lk_c_email_verified_at TEXT");

    // Leads table (checkout intents + enterprise inquiries).
    $pdo->exec("CREATE TABLE IF NOT EXISTS leads (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        type        TEXT NOT NULL,          -- 'checkout' | 'enterprise'
        plan        TEXT,                   -- plan/pack key
        billing     TEXT,                   -- monthly|yearly|credits
        amount      TEXT,                   -- displayed price
        email       TEXT,
        name        TEXT,
        company     TEXT,
        team_size   TEXT,
        volume      TEXT,
        needs_api   TEXT,
        message     TEXT,
        user_id     INTEGER,
        ip          TEXT,
        user_agent  TEXT,
        created_at  TEXT NOT NULL
    )");

    // Promo redemption log (admin-visible audit trail). Added 2026-10-09 with the
    // HOT1000 account-dashboard redemption feature. One row per successful redemption.
    $pdo->exec("CREATE TABLE IF NOT EXISTS promo_redemptions (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id     INTEGER NOT NULL,
        email       TEXT NOT NULL,
        code        TEXT NOT NULL,
        credits     INTEGER NOT NULL,
        source      TEXT,                     -- 'setup' | 'account'
        created_at  TEXT NOT NULL
    )");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_promo_redemptions_user_code ON promo_redemptions(user_id, code)");

    return $pdo;
}

function lx_auth_client_ip() {
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($headers as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return 'unknown';
}

/**
 * Generate a human-friendly, unique activation code.
 * Format: LX-XXXX-XXXX-XXXX (unambiguous charset).
 */
function lx_gen_activation_code(PDO $pdo) {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no I,O,0,1
    for ($attempt = 0; $attempt < 12; $attempt++) {
        $groups = [];
        for ($g = 0; $g < 3; $g++) {
            $s = '';
            for ($i = 0; $i < 4; $i++) {
                $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $groups[] = $s;
        }
        $code = 'LX-' . implode('-', $groups);
        $stmt = $pdo->prepare('SELECT 1 FROM users WHERE activation_code = ?');
        $stmt->execute([$code]);
        if (!$stmt->fetch()) {
            return $code;
        }
    }
    // Extremely unlikely fallback
    return 'LX-' . strtoupper(bin2hex(random_bytes(6)));
}

/**
 * Ensure a user row has signup gifts (credits + activation code).
 * Idempotent: only grants if not already set. Used right after creation
 * and as a safety net for older rows that pre-date these columns.
 */
function lx_auth_provision_user(PDO $pdo, $userId) {
    $stmt = $pdo->prepare('SELECT credits, activation_code, plan FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return;

    $needCode = empty($row['activation_code']);
    $needPlan = empty($row['plan']);

    // Credits come only from a promo code at signup (see lx_auth_issue_credentials), never here.
    if ($needCode || $needPlan) {
        $code = $needCode ? lx_gen_activation_code($pdo) : $row['activation_code'];
        $plan = $needPlan ? 'starter' : $row['plan'];
        $pdo->prepare('UPDATE users SET activation_code=?, plan=? WHERE id=?')
            ->execute([$code, $plan, $userId]);
    }
}

/**
 * Email-verified signup: the user never picks their own password.
 * After the emailed 6-digit code is confirmed, we generate a random
 * temporary password, email it to them, and require a change on
 * first login (see `must_change_password` / `temp_password_expires_at`).
 */
if (!defined('LX_TEMP_PASSWORD_TTL_HOURS')) {
    define('LX_TEMP_PASSWORD_TTL_HOURS', 24);
}

function lx_gen_temp_password() {
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no i,l,o,0,1
    $pass = '';
    for ($i = 0; $i < 10; $i++) {
        $pass .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    if (!preg_match('/[0-9]/', $pass)) {
        $pass[0] = (string)random_int(2, 9); // guarantee it passes the "letters + number" rule
    }
    return $pass;
}

/**
 * Creates a brand-new account (or rotates the temp password of one still
 * pending its first real password) and returns the plaintext temp password
 * so the caller can email it. Never returns a password for an account that
 * already has a real, user-chosen password.
 */
function lx_auth_issue_credentials($email, $promo = '') {
    $email = strtolower(trim($email));
    $bonus = lx_promo_credits($promo);
    $pdo = lx_auth_db();

    $stmt = $pdo->prepare('SELECT id, must_change_password FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    $tempPassword = lx_gen_temp_password();
    $hash = password_hash($tempPassword, PASSWORD_BCRYPT);
    $expires = date('c', time() + LX_TEMP_PASSWORD_TTL_HOURS * 3600);

    if ($existing) {
        // Re-issuing a temp password (e.g. the first one expired unused).
        $pdo->prepare('UPDATE users SET password_hash=?, must_change_password=1, temp_password_expires_at=? WHERE id=?')
            ->execute([$hash, $expires, $existing['id']]);
        $userId = (int)$existing['id'];
        lx_auth_provision_user($pdo, $userId); // backfill activation code/plan if this is a legacy row
        // Promo is additive on top of whatever balance the account already has, and is
        // gated on promo_code still being empty, so it can't be claimed twice.
        $applied = 0;
        if ($bonus > 0) {
            $upd = $pdo->prepare("UPDATE users SET credits = credits + ?, promo_code=? WHERE id=? AND COALESCE(promo_code,'')=''");
            $upd->execute([$bonus, strtoupper(trim((string)$promo)), $userId]);
            $applied = $upd->rowCount() > 0 ? $bonus : 0;
            if ($applied > 0) lx_promo_log_redemption($userId, $email, $promo, $applied, 'setup');
        }
        $isNew = false;
    } else {
        $ip    = lx_auth_client_ip();
        $ua    = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : '';
        $code  = lx_gen_activation_code($pdo);
        $token = bin2hex(random_bytes(32));
        $initialCredits = LX_FREE_SIGNUP_CREDITS + $bonus; // free signup bonus + optional promo
        $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, created_at, ip, user_agent, credits, plan, activation_code, token, must_change_password, temp_password_expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)');
        $stmt->execute([$email, $hash, date('c'), $ip, $ua, $initialCredits, 'starter', $code, $token, $expires]);
        $userId = (int)$pdo->lastInsertId();
        if ($bonus > 0) {
            $pdo->prepare('UPDATE users SET promo_code=? WHERE id=?')->execute([strtoupper(trim((string)$promo)), $userId]);
            lx_promo_log_redemption($userId, $email, $promo, $bonus, 'setup');
        }
        $applied = $bonus;
        $isNew = true;
    }

    $_SESSION['user_id']    = $userId;
    $_SESSION['user_email'] = $email;

    return ['user_id' => $userId, 'email' => $email, 'temp_password' => $tempPassword, 'is_new' => $isNew, 'bonus' => $applied];
}

function lx_auth_register($email, $password) {
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Invalid email'];
    }
    if (strlen($password) < 8) {
        return ['ok' => false, 'message' => 'Password must be at least 8 characters'];
    }
    if (strlen($password) > 128) {
        return ['ok' => false, 'message' => 'Password is too long'];
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        return ['ok' => false, 'message' => 'Password must include letters and a number'];
    }

    try {
        $pdo = lx_auth_db();
        if (lx_is_test_email($email)) {
            $pdo->prepare('DELETE FROM users WHERE email = ?')->execute([$email]);
        }
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            return ['ok' => false, 'message' => 'Email already registered'];
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $ip   = lx_auth_client_ip();
        $ua   = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : '';
        $code = lx_gen_activation_code($pdo);

        $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, created_at, ip, user_agent, credits, plan, activation_code, token) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $token = bin2hex(random_bytes(32));
        $stmt->execute([$email, $hash, date('c'), $ip, $ua, LX_FREE_SIGNUP_CREDITS, 'starter', $code, $token]);
        $userId = (int)$pdo->lastInsertId();

        $_SESSION['user_id']    = $userId;
        $_SESSION['user_email'] = $email;

        return ['ok' => true, 'user_id' => $userId, 'email' => $email, 'is_new' => true];
    } catch (Exception $e) {
        return ['ok' => false, 'message' => 'A server error occurred. Please try again.'];
    }
}

function lx_auth_login($email, $password) {
    $email = strtolower(trim($email));
    try {
        $pdo = lx_auth_db();
        $stmt = $pdo->prepare('SELECT id, email, password_hash, must_change_password, temp_password_expires_at FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !password_verify($password, $row['password_hash'])) {
            return ['ok' => false, 'message' => 'Invalid email or password'];
        }
        if (!empty($row['must_change_password']) && !empty($row['temp_password_expires_at'])
            && strtotime($row['temp_password_expires_at']) < time()) {
            return ['ok' => false, 'message' => 'Your temporary password has expired. Please sign up again with the same email to get a new one.', 'expired_temp' => true];
        }
        lx_auth_provision_user($pdo, (int)$row['id']); // backfill for legacy rows
        $_SESSION['user_id']    = (int)$row['id'];
        $_SESSION['user_email'] = $row['email'];
        return ['ok' => true, 'user_id' => (int)$row['id'], 'email' => $row['email']];
    } catch (Exception $e) {
        return ['ok' => false, 'message' => 'A server error occurred. Please try again.'];
    }
}

function lx_auth_google_login(array $profile) {
    $googleId = isset($profile['id'])      ? $profile['id']      : '';
    $email    = strtolower(trim(isset($profile['email']) ? $profile['email'] : ''));
    $name     = isset($profile['name'])    ? $profile['name']    : '';
    $avatar   = isset($profile['picture']) ? $profile['picture'] : '';

    if (!$googleId || !$email) {
        return ['ok' => false, 'message' => 'Invalid Google profile'];
    }

    try {
        $pdo = lx_auth_db();

        if (lx_is_test_email($email)) {
            $pdo->prepare('DELETE FROM users WHERE email = ? OR google_id = ?')->execute([$email, $googleId]);
        }

        // 1. Match by google_id
        $stmt = $pdo->prepare('SELECT id, email FROM users WHERE google_id = ?');
        $stmt->execute([$googleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $pdo->prepare('UPDATE users SET google_name=?, google_avatar=? WHERE id=?')
                ->execute([$name, $avatar, $row['id']]);
            lx_auth_provision_user($pdo, (int)$row['id']);
            $_SESSION['user_id']    = (int)$row['id'];
            $_SESSION['user_email'] = $row['email'];
            return ['ok' => true, 'user_id' => (int)$row['id'], 'email' => $row['email'], 'is_new' => false];
        }

        // 2. Match by email (link google to existing account)
        $stmt = $pdo->prepare('SELECT id, email FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $pdo->prepare('UPDATE users SET google_id=?, google_name=?, google_avatar=? WHERE id=?')
                ->execute([$googleId, $name, $avatar, $row['id']]);
            lx_auth_provision_user($pdo, (int)$row['id']);
            $_SESSION['user_id']    = (int)$row['id'];
            $_SESSION['user_email'] = $row['email'];
            return ['ok' => true, 'user_id' => (int)$row['id'], 'email' => $row['email'], 'is_new' => false];
        }

        // 3. New user
        $ip   = lx_auth_client_ip();
        $ua   = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : '';
        $code = lx_gen_activation_code($pdo);
        $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, google_id, google_name, google_avatar, created_at, ip, user_agent, credits, plan, activation_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$email, '', $googleId, $name, $avatar, date('c'), $ip, $ua, LX_FREE_SIGNUP_CREDITS, 'starter', $code]);
        $userId = (int)$pdo->lastInsertId();

        $_SESSION['user_id']    = $userId;
        $_SESSION['user_email'] = $email;
        return ['ok' => true, 'user_id' => $userId, 'email' => $email, 'is_new' => true];

    } catch (Exception $e) {
        return ['ok' => false, 'message' => 'A server error occurred. Please try again.'];
    }
}

/**
 * X (Twitter) OAuth login.
 * $profile expects: id (string), username, name, avatar, (optional) email.
 * X rarely returns email, so we synthesize a stable placeholder address
 * when none is provided so the NOT NULL/UNIQUE email constraint holds.
 */
function lx_auth_twitter_login(array $profile) {
    $twId     = isset($profile['id'])       ? (string)$profile['id'] : '';
    $username = isset($profile['username']) ? $profile['username']   : '';
    $name     = isset($profile['name'])     ? $profile['name']       : ($username ?: 'X User');
    $avatar   = isset($profile['avatar'])   ? $profile['avatar']     : '';
    $email    = strtolower(trim(isset($profile['email']) ? $profile['email'] : ''));

    if (!$twId) {
        return ['ok' => false, 'message' => 'Invalid X profile'];
    }
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        // Stable synthetic email keyed to X user id.
        $email = 'x_' . $twId . '@x.leronx.org';
    }

    try {
        $pdo = lx_auth_db();

        if (lx_is_test_handle($username) || lx_is_test_email($email)) {
            $pdo->prepare('DELETE FROM users WHERE twitter_id = ? OR email = ?')->execute([$twId, $email]);
        }

        // 1. Match by twitter_id
        $stmt = $pdo->prepare('SELECT id, email FROM users WHERE twitter_id = ?');
        $stmt->execute([$twId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $pdo->prepare('UPDATE users SET twitter_name=?, twitter_avatar=? WHERE id=?')
                ->execute([$name, $avatar, $row['id']]);
            lx_auth_provision_user($pdo, (int)$row['id']);
            $_SESSION['user_id']    = (int)$row['id'];
            $_SESSION['user_email'] = $row['email'];
            return ['ok' => true, 'user_id' => (int)$row['id'], 'email' => $row['email'], 'is_new' => false];
        }

        // 2. Match by real email (link X to existing account)
        if (strpos($email, '@x.leronx.org') === false) {
            $stmt = $pdo->prepare('SELECT id, email FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $pdo->prepare('UPDATE users SET twitter_id=?, twitter_name=?, twitter_avatar=? WHERE id=?')
                    ->execute([$twId, $name, $avatar, $row['id']]);
                lx_auth_provision_user($pdo, (int)$row['id']);
                $_SESSION['user_id']    = (int)$row['id'];
                $_SESSION['user_email'] = $row['email'];
                return ['ok' => true, 'user_id' => (int)$row['id'], 'email' => $row['email'], 'is_new' => false];
            }
        }

        // 3. New user
        $ip   = lx_auth_client_ip();
        $ua   = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : '';
        $code = lx_gen_activation_code($pdo);
        $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, twitter_id, twitter_name, twitter_avatar, created_at, ip, user_agent, credits, plan, activation_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$email, '', $twId, $name, $avatar, date('c'), $ip, $ua, LX_FREE_SIGNUP_CREDITS, 'starter', $code]);
        $userId = (int)$pdo->lastInsertId();

        $_SESSION['user_id']    = $userId;
        $_SESSION['user_email'] = $email;
        return ['ok' => true, 'user_id' => $userId, 'email' => $email, 'is_new' => true];

    } catch (Exception $e) {
        return ['ok' => false, 'message' => 'A server error occurred. Please try again.'];
    }
}

function lx_auth_logout() {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/**
 * Returns the full current user profile, or null.
 * Reads the DB so credits/plan/code/avatar are always fresh.
 */
function lx_auth_user() {
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    try {
        $pdo = lx_auth_db();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([(int)$_SESSION['user_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['id' => (int)$_SESSION['user_id'], 'email' => isset($_SESSION['user_email']) ? $_SESSION['user_email'] : ''];
        }
        $name = '';
        if (!empty($row['google_name']))       $name = $row['google_name'];
        elseif (!empty($row['twitter_name']))  $name = $row['twitter_name'];
        elseif (!empty($row['display_name']))  $name = $row['display_name'];
        $avatar = '';
        if (!empty($row['google_avatar']))      $avatar = $row['google_avatar'];
        elseif (!empty($row['twitter_avatar'])) $avatar = $row['twitter_avatar'];
        elseif (!empty($row['avatar']))         $avatar = $row['avatar'];

        return [
            'id'              => (int)$row['id'],
            'email'           => $row['email'],
            'name'            => $name,
            'avatar'          => $avatar,
            'credits'         => isset($row['credits']) ? (int)$row['credits'] : 0,
            'plan'            => !empty($row['plan']) ? $row['plan'] : 'starter',
            'activation_code' => isset($row['activation_code']) ? $row['activation_code'] : '',
            'device_bound'    => !empty($row['device_fingerprint']),
            'device_name'     => isset($row['device_name']) ? $row['device_name'] : '',
            'device_bound_at' => isset($row['device_bound_at']) ? $row['device_bound_at'] : '',
            'has_google'      => !empty($row['google_id']),
            'has_twitter'     => !empty($row['twitter_id']),
            'must_change_password'     => !empty($row['must_change_password']) ? 1 : 0,
            'temp_password_expires_at' => isset($row['temp_password_expires_at']) ? $row['temp_password_expires_at'] : null,
        ];
    } catch (Exception $e) {
        return ['id' => (int)$_SESSION['user_id'], 'email' => isset($_SESSION['user_email']) ? $_SESSION['user_email'] : ''];
    }
}

function lx_auth_require_or_redirect($redirectTo = '/') {
    if (!lx_auth_user()) {
        header('Location: ' . $redirectTo);
        exit;
    }
}

/**
 * Save a lead (checkout intent or enterprise inquiry).
 * $data keys: type, plan, billing, amount, email, name, company,
 *             team_size, volume, needs_api, message
 */
function lx_save_lead(array $data) {
    try {
        $pdo = lx_auth_db();
        $u   = lx_auth_user();
        $ip  = lx_auth_client_ip();
        $ua  = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : '';
        $get = function ($k) use ($data) { return isset($data[$k]) ? substr((string)$data[$k], 0, 1000) : null; };

        $stmt = $pdo->prepare('INSERT INTO leads (type, plan, billing, amount, email, name, company, team_size, volume, needs_api, message, user_id, ip, user_agent, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
            $get('type') ?: 'checkout',
            $get('plan'), $get('billing'), $get('amount'),
            $get('email'), $get('name'), $get('company'),
            $get('team_size'), $get('volume'), $get('needs_api'),
            $get('message'),
            $u ? (int)$u['id'] : null,
            $ip, $ua, date('c'),
        ]);
        return ['ok' => true, 'id' => (int)$pdo->lastInsertId()];
    } catch (Exception $e) {
        return ['ok' => false, 'message' => 'A server error occurred. Please try again.'];
    }
}

/**
 * Audit log for every auth attempt (success OR failure).
 * Stores ONLY metadata for debugging — never passwords.
 * Added 2026-06-21 for registration/auth observability.
 */
function lx_auth_log_event($type, $email, $ok, $reason = '', $provider = 'email') {
    try {
        $pdo = lx_auth_db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS auth_events (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at  TEXT NOT NULL,
            type        TEXT NOT NULL,
            provider    TEXT,
            email       TEXT,
            ok          INTEGER NOT NULL DEFAULT 0,
            reason      TEXT,
            ip          TEXT,
            user_agent  TEXT
        )");
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : '';
        $stmt = $pdo->prepare('INSERT INTO auth_events
            (created_at, type, provider, email, ok, reason, ip, user_agent)
            VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([
            date('c'),
            (string)$type,
            (string)$provider,
            strtolower(trim((string)$email)),
            $ok ? 1 : 0,
            (string)$reason,
            lx_auth_client_ip(),
            $ua,
        ]);
    } catch (Exception $e) {
        // Logging must never break the auth flow — swallow errors.
    }
}

/**
 * Instant Telegram alert on every NEW registration.
 * Uses TG_BOT_TOKEN / TG_CHAT_ID from .env. Never throws into the auth flow.
 * Added 2026-06-21 — fixes "no notifications on signup".
 */
function lx_notify_new_registration($email, $provider = 'email', $extra = '') {
    try {
        require_once __DIR__ . '/../includes/x_ads_config.php';
        $token = lx_env_value('TG_BOT_TOKEN');
        $chat  = lx_env_value('TG_CHAT_ID');
        if (!$token || !$chat) return;
            // Landing label for the TG message. Map kept in sync with lp_ab_active()'s real
            // arms -- v8/v9/v11/v12/v13 used to fall through to a bare number ("13").
            $lxLand = (function(){
                $m = ['main'=>'Main(official)','1'=>'v1 Classic','2'=>'v2 Pain','3'=>'v3 Gallery',
                      '5'=>'v5 Cinema','6'=>'v6 Light','7'=>'v7 Bold','8'=>'v8 Fire','9'=>'v9 Clean',
                      '11'=>'v11 Proof','12'=>'v12 Studio','13'=>'v13 Neuravision (Higgsfield)'];
                $v = $_COOKIE['lp_v'] ?? '';
                return $v !== '' ? ('v' . $v . (isset($m[$v]) ? ' — ' . $m[$v] : '')) : 'direct/unknown';
            })();
            // Funnel numbers for THIS site. Was @file_get_contents('https://leronx.org/lp/lp_ab.php')
            // -- the wrong domain AND the wrong JSON shape (that endpoint returns
            // {desktop,mobile,all}, so $fd['data'] never existed and the funnel line was always
            // dropped). Now reads neuracreation.com's own lp_ab.php in-process. 2026-10-09
            $lxFunnel = '';
            try {
                $lpAbFile = dirname(__DIR__) . '/lp/lp_ab.php';
                if (is_file($lpAbFile)) {
                    if (!function_exists('lp_ab_weights')) { require_once $lpAbFile; }
                    if (function_exists('lp_ab_weights')) {
                        $fd = lp_ab_weights();
                        if (!empty($fd['data'])) {
                            $V = $R = $D = 0;
                            foreach ($fd['data'] as $xx) {
                                $V += (int)($xx['visits'] ?? 0);
                                $R += (int)($xx['conv'] ?? 0);
                                $D += (int)($xx['dls'] ?? 0);
                            }
                            $crr = $V ? round($R * 100 / $V, 1) : 0;
                            $crd = $R ? round($D * 100 / $R, 1) : 0;
                            $lxFunnel = "📊 Funnel: {$V} visits → {$R} reg ({$crr}%) → {$D} dl ({$crd}%)";
                        }
                    }
                }
            } catch (Throwable $e) { /* a dashboard figure must never break signup */ }
        // 2026-10-10: detailed message (IP/ASN, split arm, utm/click-id, device, session) via the
        // shared builder, sent after the response is flushed so signup is never slowed.
        require_once __DIR__ . '/nv_alert_ctx.php';
        $msg  = "🆕 Регистрация: " . $email . " · способ " . $provider
              . ($extra !== '' ? "\n" . $extra : '');
        nv_alert_defer(function () use ($msg, $email) {
            $d = nv_alert_send($msg . "\n" . nv_alert_ctx(['kind' => 'registration', 'email' => (string)$email]), 'registration');
            @file_put_contents(dirname(__DIR__) . '/data/reg_notify.log',
                date('c') . ' registration ok=' . (!empty($d['ok']) ? '1' : '0')
                . ' message_id=' . ($d['result']['message_id'] ?? '-') . "\n", FILE_APPEND);
        });
    } catch (Exception $e) { /* notifications must never break signup */ }
}

/**
 * Test-account allowlist (configurable via .env). Added 2026-06-21.
 *   LX_TEST_EMAILS    = comma-separated emails always treated as a brand-new signup
 *   LX_TEST_X_HANDLES = comma-separated X/Twitter usernames treated the same
 * Lets you re-test the signup flow repeatedly without manual cleanup.
 */
function lx_test_emails() {
    require_once __DIR__ . '/../includes/x_ads_config.php';
    $out = [];
    foreach (explode(',', (string)lx_env_value('LX_TEST_EMAILS')) as $e) {
        $e = strtolower(trim($e));
        if ($e !== '') $out[] = $e;
    }
    return $out;
}
function lx_test_x_handles() {
    require_once __DIR__ . '/../includes/x_ads_config.php';
    $out = [];
    foreach (explode(',', (string)lx_env_value('LX_TEST_X_HANDLES')) as $h) {
        $h = strtolower(ltrim(trim($h), '@'));
        if ($h !== '') $out[] = $h;
    }
    return $out;
}
function lx_is_test_email($email) {
    return in_array(strtolower(trim((string)$email)), lx_test_emails(), true);
}
function lx_is_test_handle($handle) {
    return in_array(strtolower(ltrim(trim((string)$handle), '@')), lx_test_x_handles(), true);
}

/* The web sign-up / sign-in flow issued a token but never handed the browser the dashboard
   session cookie, so a real visitor who had just registered still hit /account (and the /lk/
   A|B split behind it) as an anonymous user and was bounced to the home page. Set the same
   cookie set that auth_callback.php (Google/X) and account.php already use. 2026-10-10 */
function lx_auth_set_session_cookies($userId, $token) {
    if (headers_sent() || $token === '' || $token === null) { return false; }
    $exp    = time() + 86400 * 30;
    $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie('lx_auth_token', (string)$token, ['expires'=>$exp,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
    // readable by the page JS: this is what flips the header CTA to "Dashboard"
    setcookie('lx_auth', '1', ['expires'=>$exp,'path'=>'/','secure'=>$secure,'httponly'=>false,'samesite'=>'Lax']);
    if ($userId) {
        setcookie('lx_user_id', 'LX-' . str_pad((string)$userId, 8, '0', STR_PAD_LEFT), ['expires'=>$exp,'path'=>'/','secure'=>$secure,'httponly'=>false,'samesite'=>'Lax']);
    }
    setcookie('lx_provider', 'email', ['expires'=>$exp,'path'=>'/','secure'=>$secure,'httponly'=>false,'samesite'=>'Lax']);
    return true;
}
