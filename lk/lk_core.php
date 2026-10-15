<?php
/**
 * /lk/ — shared core for the 50/50 account-cabinet split test (variant A and variant B).
 *
 * Responsibilities
 *   1. Authenticate exactly the way account.php does (token cookie -> api/auth_verify.php ->
 *      users.sqlite token lookup) and expose one $LK_USER array.
 *   2. Assign + pin a cabinet variant ('a' | 'b') 50/50 and persist it in the `lk_ab` cookie
 *      (180 days) and, for signed-in users, in users.lk_variant so the pin survives a new device.
 *   3. Expose the real desktop-release data (functions/desktop_release.php) so both variants
 *      serve the live installers through /api/desktop_download.php.
 *
 * Nothing here is a mock: credits come from data/users.sqlite, installers from the production
 * download endpoint, build readiness from /api/desktop_status.php.
 *
 * Owned by the LK-split work. account.php, functions/auth_db.php and new/tracker/* are NOT
 * modified by this file — they are only read / called.
 */

if (!defined('LK_CORE')) {
define('LK_CORE', 1);

if (session_status() === PHP_SESSION_NONE) { session_start(); }
error_reporting(0);

define('LK_ROOT', dirname(__DIR__));                 // /www/wwwroot/neuracreation.com
define('LK_FREE_BONUS', 250);                        // free signup bonus shown in the copy
define('LK_PROMO_CODE', 'HOT1000');
define('LK_COOKIE', 'lk_ab');
define('LK_VISITOR_COOKIE', 'lk_vid');
define('LK_COOKIE_TTL', 180 * 86400);
/* Review/QA accounts (owner 2026-10-10): only these may switch the cabinet with ?lk=a|b|c,
 * and they are always excluded from stats / Telegram / ad pixels (lx_notrack=1). */
define('LK_REVIEW_EMAILS', 'owner-review@neuracreation.com');
function lk_is_review_email($email) {
    $email = strtolower(trim((string)$email));
    return $email !== '' && in_array($email, array_map('trim', explode(',', LK_REVIEW_EMAILS)), true);
}

require_once LK_ROOT . '/functions/auth_db.php';          // lx_auth_db(), lx_promo_credits()
require_once LK_ROOT . '/functions/desktop_release.php';  // desktop_release_info/meta, desktop_detect_os

define('LK_PROMO_CREDITS', lx_promo_credits(LK_PROMO_CODE));

/* ------------------------------------------------------------ X Ads pixel (owner 2026-10-10)
 * account.php, lp_common.php etc. already load twq('config','rgt8d') directly in their own
 * markup. The LK cabinet (a/b/c) has no such line of its own, and includes/gtag.php
 * intentionally serves NO ad pixels on private pages (account/app/download/lk), so without
 * this, window.twq is simply undefined inside the cabinet and lk-common.js's download-click
 * handler silently does nothing. This reuses gtag.php's own notrack/geo-block checks so a
 * visitor who is exempted from tracking elsewhere on the site stays exempted here too. */
function lk_x_pixel_head() {
    if (!empty($_COOKIE['lx_notrack'])) { return; }
    require_once LK_ROOT . '/includes/geo_block.php';
    if (function_exists('lx_geo_blocked') && lx_geo_blocked()) { return; }
    echo "<script>!function(e,t,n,s,u,a){e.twq||(s=e.twq=function(){s.exe?s.exe.apply(s,arguments):s.queue.push(arguments);},s.version='1.1',s.queue=[],u=t.createElement(n),u.async=!0,u.src='https://static.ads-twitter.com/uwt.js',a=t.getElementsByTagName(n)[0],a.parentNode.insertBefore(u,a))}(window,document,'script');twq('config','r84nh');twq('config','rgt8d');</script>\n";
}

/* Meta (Facebook) Pixel on the cabinet (download/registration events only), same privacy
 * gating as lk_x_pixel_head() above. Client-side fbpixel.js still applies its own
 * lx_notrack/lx_consent/EEA gate on top of this. */
function lk_fb_pixel_head() {
    if (!empty($_COOKIE['lx_notrack'])) { return; }
    require_once LK_ROOT . '/includes/geo_block.php';
    if (function_exists('lx_geo_blocked') && lx_geo_blocked()) { return; }
    echo "<script async src=\"/assets/fbpixel.js?v=2\"></script>\n";
}

/* ------------------------------------------------------------------ cookies */
function lk_setcookie($name, $value, $ttl) {
    $secure = !empty($_SERVER['HTTPS']);
    if (PHP_VERSION_ID >= 70300) {
        setcookie($name, $value, [
            'expires'  => time() + $ttl,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => false,     // the split dashboards and page JS read it
            'samesite' => 'Lax',
        ]);
    } else {
        setcookie($name, $value, time() + $ttl, '/; SameSite=Lax', '', $secure, false);
    }
    $_COOKIE[$name] = $value;
}

/* ------------------------------------------------------------- authentication
 * Same three steps as account.php, in the same order. The users.sqlite lookup goes
 * through lx_auth_db() (functions/auth_db.php) instead of its own PDO handle.
 */
function lk_verify_token_api($token) {
    if ($token === '') return null;
    $ch = curl_init('https://neuracreation.com/api/auth_verify.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['auth_token' => $token]),
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    if (!$response) return null;
    $data = json_decode($response, true);
    if ($data && ($data['status'] ?? '') === 'ok' && isset($data['user'])) return $data['user'];
    return null;
}

function lk_user_from_db($token) {
    if ($token === '') return null;
    try {
        $st = lx_auth_db()->prepare('SELECT id, email, credits, plan, display_name, twitter_name, google_name, must_change_password, promo_code, lk_variant FROM users WHERE token = ? LIMIT 1');
        $st->execute([$token]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u) return null;
        return [
            'id'            => 'LX-' . str_pad($u['id'], 8, '0', STR_PAD_LEFT),
            'db_id'         => (int)$u['id'],
            'display_name'  => trim((string)($u['display_name'] ?: ($u['twitter_name'] ?: ($u['google_name'] ?: '')))),
            'username'      => strstr((string)$u['email'], '@', true) ?: '',
            'email'         => (string)$u['email'],
            'plan'          => $u['plan'] ?: 'starter',
            'credits'       => (int)$u['credits'],
            'provider'      => 'email',
            'promo_code'    => (string)($u['promo_code'] ?? ''),
            'lk_variant'    => (string)($u['lk_variant'] ?? ''),
            'must_change_password' => (int)($u['must_change_password'] ?? 0),
        ];
    } catch (Exception $e) { return null; }
}

/** Fresh DB row for a user already identified by api/auth_verify.php (OAuth / keys.db path). */
function lk_db_row_by_email($email) {
    if ($email === '') return null;
    try {
        $st = lx_auth_db()->prepare('SELECT id, credits, plan, promo_code, lk_variant, must_change_password FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Exception $e) { return null; }
}

function lk_auth_or_bounce() {
    $authToken = (string)($_COOKIE['lx_auth_token'] ?? '');
    $urlTok = isset($_GET['token']) && is_string($_GET['token']) ? trim($_GET['token']) : '';
    if ($urlTok !== '' && preg_match('/^[A-Za-z0-9._-]{16,512}$/', $urlTok) && $urlTok !== $authToken) {
        $authToken = $urlTok;
        $_COOKIE['lx_auth_token'] = $urlTok;
    }

    $u = null;
    if ($authToken !== '') {
        $api = lk_verify_token_api($authToken);
        if ($api) {
            $email = (string)($api['email'] ?? '');
            $row = lk_db_row_by_email($email);
            $u = [
                'id'           => (string)($api['id'] ?? ''),
                'db_id'        => $row ? (int)$row['id'] : 0,
                'display_name' => (string)($api['display_name'] ?? ''),
                'username'     => (string)($api['username'] ?? (strstr($email, '@', true) ?: '')),
                'email'        => $email,
                'plan'         => $row ? ($row['plan'] ?: 'starter') : (string)($api['plan'] ?? 'starter'),
                // Balance always from data/users.sqlite when the account exists there.
                'credits'      => $row ? (int)$row['credits'] : (int)($api['credits'] ?? 0),
                'provider'     => (string)($api['provider'] ?? 'unknown'),
                'promo_code'   => $row ? (string)($row['promo_code'] ?? '') : '',
                'lk_variant'   => $row ? (string)($row['lk_variant'] ?? '') : '',
                'must_change_password' => $row ? (int)($row['must_change_password'] ?? 0) : 0,
            ];
        }
        if (!$u) $u = lk_user_from_db($authToken);
    }

    if (!$u) {
        // Cookie may be blocked (Safari ITP / in-app webview): let the client replay the
        // localStorage token once, then bounce home. Same recovery account.php uses.
        if ($urlTok === '') {
            header('Content-Type: text/html; charset=utf-8');
            $self = $_SERVER['REQUEST_URI'] ?? '/lk/';
            $sep = strpos($self, '?') === false ? '?' : '&';
            echo '<!doctype html><meta name="robots" content="noindex"><title>Neuravision</title>'
               . '<script>try{var t=localStorage.getItem("lx-auth-token")||localStorage.getItem("lx_auth_token");'
               . 'if(t){location.replace(' . json_encode($self . $sep . 'token=') . '+encodeURIComponent(t));}'
               . 'else{location.replace("/");}}catch(e){location.replace("/");}</script>'
               . '<noscript><meta http-equiv="refresh" content="0;url=/"></noscript>';
            exit;
        }
        header('Location: /');
        exit;
    }

    if ($authToken !== '') {
        setcookie('lx_auth_token', $authToken, time() + 86400 * 30, '/', '', true, true);
        setcookie('lx_auth', '1', time() + 86400 * 30, '/', '', true, false);
        setcookie('lx_user_id', (string)$u['id'], time() + 86400 * 30, '/', '', true, false);
    }

    if (!empty($u['must_change_password'])) {
        header('Location: /set-password.php?token=' . urlencode($authToken));
        exit;
    }

    $u['token'] = $authToken;
    return $u;
}

/* ------------------------------------------------- dl=1 ad visits (2026-10-10)
 * Cookie nv_dl (1 day) is set by the landing on ?dl=1 (lp/lp_common.php) and by /lk/dl.php.
 *   '1' = autostart the installer on the next /lk/ view, '2' = autostart already done.
 * Returns '1' | '2' | ''. */
function lk_dl1_flag() {
    // The dl=1 -> registration -> LK C autostart flow was CANCELLED by the owner (2026-10-10, same day):
    // dl=1 now means landing path 3 (download without registration, lp/lp_path.php). Always ''.
    return '';
}

/* ------------------------------------------------------ variant assignment */
/** Stable 50/50 A/B bucket from any identifier. LK C is out of the split for NEW users (owner
 *  2026-10-10); accounts already pinned to C keep it (cookie lk_ab / users.lk_variant, see lk_variant). */
function lk_bucket($seed) {
    $h = hash('sha256', 'lk-split-v1|' . $seed);
    // first 8 hex chars -> integer -> parity of the 50/50 split
    return (hexdec(substr($h, 0, 8)) % 2) === 0 ? 'a' : 'b';
}

/** Landing path (lp/lp_path.php, cookie nv_path): 1 -> cabinet A, 2 -> cabinet B, else ''. */
function lk_variant_from_path() {
    $p = (string)($_COOKIE['nv_path'] ?? '');
    return $p === '1' ? 'a' : ($p === '2' ? 'b' : '');
}

function lk_persist_variant($user, $v) {
    lk_setcookie(LK_COOKIE, $v, LK_COOKIE_TTL);
    if ($user && !empty($user['db_id']) && (string)($user['lk_variant'] ?? '') !== $v) {
        try {
            lx_auth_db()->prepare('UPDATE users SET lk_variant = ? WHERE id = ?')->execute([$v, (int)$user['db_id']]);
        } catch (Exception $e) { /* pin still works via the cookie */ }
    }
}

/**
 * Resolve the pinned variant.
 *  ?lk=a|b            -> force (and re-pin), for QA
 *  cookie lk_ab       -> keep
 *  users.lk_variant   -> keep (new device, same account)
 *  cookie nv_path 1|2 -> A|B (landing path split, new pins only), then pin
 *  otherwise          -> hash(user id | visitor cookie) 50/50 A/B, then pin
 */
function lk_variant($user = null) {
    static $v = null;
    if ($v !== null) return $v;

    $forced = strtolower((string)($_GET['lk'] ?? ''));
    if (($forced === 'a' || $forced === 'b' || $forced === 'c') && $user && lk_is_review_email($user['email'] ?? '')) {
        // review account only: show the requested version, never touch anyone's pin
        $v = $forced; return $v;
    }

    $cookie = strtolower((string)($_COOKIE[LK_COOKIE] ?? ''));
    if ($cookie === 'a' || $cookie === 'b' || $cookie === 'c') {
        $v = $cookie;
        // keep the DB copy in sync so the pin follows the account to a new device
        if ($user && !empty($user['db_id']) && (string)($user['lk_variant'] ?? '') !== $v) lk_persist_variant($user, $v);
        return $v;
    }

    $dbv = strtolower((string)($user['lk_variant'] ?? ''));
    if ($dbv === 'a' || $dbv === 'b' || $dbv === 'c') { $v = $dbv; lk_setcookie(LK_COOKIE, $v, LK_COOKIE_TTL); return $v; }

    // New pin only from here on (nothing in the cookie or the DB yet): landing path 1 -> A, 2 -> B.
    $fromPath = lk_variant_from_path();
    if ($fromPath !== '') { $v = $fromPath; lk_persist_variant($user, $v); return $v; }

    if ($user && !empty($user['db_id'])) {
        $seed = 'uid:' . (int)$user['db_id'];
    } else {
        $vid = (string)($_COOKIE[LK_VISITOR_COOKIE] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $vid)) {
            $vid = bin2hex(random_bytes(16));
            lk_setcookie(LK_VISITOR_COOKIE, $vid, LK_COOKIE_TTL);
        }
        $seed = 'vid:' . $vid;
    }
    $v = lk_bucket($seed);
    lk_persist_variant($user, $v);
    return $v;
}

/** Mirror the variant into the existing tracker (new/tracker/lx_track_lib.php is only read). */
function lk_track_variant($v, $user = null) {
    if (!empty($_COOKIE['lx_notrack'])) { return; }   // review/test browsers stay out of the split stats
    try {
        $lib = LK_ROOT . '/new/tracker/lx_track_lib.php';
        if (!is_file($lib)) return;
        require_once $lib;
        $uid = ($user && !empty($user['db_id'])) ? (int)$user['db_id'] : 0;
        $sid = preg_replace('/[^A-Za-z0-9-]/', '', substr((string)($_COOKIE['lx_sid'] ?? ''), 0, 36));
        if ($sid === '' && $uid > 0 && function_exists('lx_user_last_sid')) {
            // Cabinet opened without the tracker cookie (email link, another browser, cookie cleared):
            // rejoin the session this account signed up / last browsed with, so landing -> flow -> LK ->
            // download stay ONE session with the landing's entry_block / path / attribution. 2026-10-10
            $sid = preg_replace('/[^A-Za-z0-9-]/', '', substr(lx_user_last_sid($uid), 0, 36));
            if ($sid !== '') lk_setcookie('lx_sid', $sid, 90 * 86400);
        }
        if ($sid === '') {
            // First hit on /lk/ in a fresh browser with no known session: mint the same cookie the
            // tracker uses, server-side, before the page renders (the page JS reuses it). 2026-10-09
            $sid = sprintf('%s-%s-4%s-%x%s-%s',
                bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), substr(bin2hex(random_bytes(2)), 1),
                8 + random_int(0, 3), substr(bin2hex(random_bytes(2)), 1), bin2hex(random_bytes(6)));
            lk_setcookie('lx_sid', $sid, 90 * 86400);
        }
        if (function_exists('lx_event')) lx_event($sid, 'lk_view', 'lk' . $v);
        if ($uid > 0 && function_exists('lx_link_user_sid')) lx_link_user_sid($uid, $sid);
    } catch (Throwable $e) { /* analytics must never break the cabinet */ }
}

/* ----------------------------------------------------------------- helpers */
function lk_is_mobile_ua($ua = null) {
    $ua = $ua === null ? (string)($_SERVER['HTTP_USER_AGENT'] ?? '') : (string)$ua;
    if (preg_match('/iPhone|iPod|Android.*Mobile|Windows Phone|IEMobile|BlackBerry/i', $ua)) return true;
    // iPad / Android tablets: no desktop installer either
    if (preg_match('/iPad|Android|Silk/i', $ua)) return true;
    return false;
}

/** Everything a variant template needs. Call once at the top of a/b.php. */
function lk_boot() {
    static $ctx = null;
    if ($ctx !== null) return $ctx;

    $user = lk_auth_or_bounce();
    if (lk_is_review_email($user['email'] ?? '')) {
        $_COOKIE['lx_notrack'] = '1';
        lk_setcookie('lx_notrack', '1', 365 * 86400);
    }
    $variant = lk_variant($user);
    lk_track_variant($variant, $user);

    $rel  = desktop_release_info();
    $meta = desktop_release_meta();
    $os   = desktop_detect_os($_SERVER['HTTP_USER_AGENT'] ?? '');
    $mob  = lk_is_mobile_ua();
    if ($os === '') $os = 'windows';

    // dl=1: autostart the installer once per flag (desktop only; phones get the email-link form).
    $dl1 = lk_dl1_flag();
    $dlAuto = false;
    if ($dl1 === '1' && $variant === 'c') {
        $dlAuto = !$mob && ($rel['windows']['available'] || $rel['macos']['available']);
        lk_setcookie('nv_dl', '2', 86400);   // consumed: reloading /lk/ never re-downloads
        if (empty($_COOKIE['lx_notrack'])) {
            try {
                $sid = preg_replace('/[^A-Za-z0-9-]/', '', substr((string)($_COOKIE['lx_sid'] ?? ''), 0, 36));
                if ($sid !== '' && function_exists('lx_event')) lx_event($sid, $dlAuto ? 'dl1_autostart' : 'dl1_mobile', 'lkc|' . ($mob ? 'mobile' : $os));
            } catch (Throwable $e) {}
        }
    }

    $name = $user['display_name'] ?: ($user['username'] ?: (strstr((string)$user['email'], '@', true) ?: 'creator'));
    $digits = preg_replace('/\D+/', '', (string)$user['id']);

    // Extra "confirm it's you" email-code gate used so far only by /lk/c.php (owner 2026-10-10):
    // once an account has passed it, it never needs it again on later visits.
    $emailVerified = false;
    if (!empty($user['db_id'])) {
        try {
            $st = lx_auth_db()->prepare('SELECT lk_c_email_verified_at FROM users WHERE id = ?');
            $st->execute([(int)$user['db_id']]);
            $emailVerified = (bool)$st->fetchColumn();
        } catch (Exception $e) { /* treat as not-yet-verified */ }
    }

    $ctx = [
        'user'     => $user,
        'variant'  => $variant,
        'name'     => $name,
        'initial'  => strtoupper(mb_substr($name !== '' ? $name : 'U', 0, 1)),
        'email'    => (string)$user['email'],
        'credits'  => (int)$user['credits'],
        'creditsFmt' => number_format((int)$user['credits']),
        'plan'     => (string)$user['plan'],
        'license'  => 'NEURA-' . (1004570 + (int)$digits) . '-PRO',
        'rel'      => $rel,
        'meta'     => $meta,
        'os'       => $os,                                   // 'windows' | 'macos'
        'osOrder'  => $os === 'macos' ? ['macos', 'windows'] : ['windows', 'macos'],
        'anyBuild' => $rel['windows']['available'] || $rel['macos']['available'],
        'mobile'   => $mob,
        'maint'    => @file_exists('/www/wwwroot/leronx.org/data/.maintenance'),
        'bonus'    => LK_FREE_BONUS,
        'promo'    => LK_PROMO_CODE,
        'promoCredits' => LK_PROMO_CREDITS,
        'dl1'      => $dl1 !== '',
        'dlAuto'   => $dlAuto,
        'emailVerified' => $emailVerified,
    ];
    return $ctx;
}

function lk_e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

} // LK_CORE
