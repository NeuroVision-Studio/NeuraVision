<?php
/**
 * "Notify me" waitlist for platforms whose build is not published yet (macOS, Android, other),
 * plus webinar popup signups (platform=webinar). Contacts go to data/waitlist.sqlite (data/ is
 * closed by nginx). Nothing is sent from here for macos/android/other — the owner triggers that
 * notification later; webinar signups DO get a Telegram alert (see api/waitlist.php).
 */
if (!defined('WAITLIST_DB')) define('WAITLIST_DB', dirname(__DIR__) . '/data/waitlist.sqlite');

function waitlist_platforms() {
    return ['macos' => 'macOS', 'android' => 'Android', 'other' => 'Other platform', 'webinar' => 'Webinar'];
}

// Self-contained .env reader (site root .env), mirrors api/nv_notify.php's nv_api_env().
function waitlist_env($key) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $f = dirname(__DIR__) . '/.env';
        if (is_file($f)) foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
            $ln = trim($ln); if ($ln === '' || $ln[0] === '#' || strpos($ln, '=') === false) continue;
            list($k, $v) = array_map('trim', explode('=', $ln, 2)); $cache[$k] = $v;
        }
    }
    return $cache[$key] ?? '';
}

// One-way hash of an IP for rate-limiting only — never store raw IPs.
function waitlist_ip_hash($ip) {
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) return '';
    $salt = waitlist_env('LXTRACK_IP_SALT') ?: 'neuracreation-waitlist';
    return hash('sha256', $ip . '|' . $salt);
}

function waitlist_db() {
    $db = new SQLite3(WAITLIST_DB);
    $db->busyTimeout(3000);
    $db->exec('CREATE TABLE IF NOT EXISTS waitlist (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
        platform TEXT NOT NULL,
        email TEXT NOT NULL,
        phone TEXT NOT NULL DEFAULT \'\',
        UNIQUE(platform, email))');
    $db->exec("CREATE TABLE IF NOT EXISTS waitlist_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ts TEXT NOT NULL DEFAULT (datetime('now')),
        ip_hash TEXT NOT NULL,
        platform TEXT NOT NULL)");
    waitlist_migrate($db);
    return $db;
}

// Adds any columns that didn't exist on the original (pre-webinar) table — safe to call every time.
function waitlist_migrate($db) {
    $cols = [];
    $res = $db->query('PRAGMA table_info(waitlist)');
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) $cols[$row['name']] = true;
    $add = [
        'source' => "TEXT NOT NULL DEFAULT ''",
        'page' => "TEXT NOT NULL DEFAULT ''",
        'lang' => "TEXT NOT NULL DEFAULT ''",
        'country' => "TEXT NOT NULL DEFAULT ''",
        'utm_source' => "TEXT NOT NULL DEFAULT ''",
        'utm_medium' => "TEXT NOT NULL DEFAULT ''",
        'utm_campaign' => "TEXT NOT NULL DEFAULT ''",
        'referrer' => "TEXT NOT NULL DEFAULT ''",
        'user_agent' => "TEXT NOT NULL DEFAULT ''",
        'ip_hash' => "TEXT NOT NULL DEFAULT ''",
        // 2026-10-09: the tracker's anonymous session id (lx_sid cookie), when present on the
        // request. Lets the admin dashboard join webinar leads back to a split-test arm (sid is
        // already set by lp_common.php / includes/track_actions.php on every lp/vN.php page).
        // Nullable/blank for signups before this fix or with cookies blocked -- honestly unknown.
        'sid' => "TEXT NOT NULL DEFAULT ''",
        // fix3 2026-10-10: landing path (1/2/3), device (mobile/desktop), paid-traffic flag -- webinar Lead attribution
        'nv_path' => "TEXT NOT NULL DEFAULT ''",
        'device' => "TEXT NOT NULL DEFAULT ''",
        'paid' => "INTEGER NOT NULL DEFAULT 0",
    ];
    foreach ($add as $name => $def) {
        if (!isset($cols[$name])) $db->exec("ALTER TABLE waitlist ADD COLUMN $name $def");
    }
}

// Saves one signup. Returns true when it is a new row, false when this email already waits for this platform.
function waitlist_add($platform, $email, $phone, array $meta = []) {
    $db = waitlist_db();
    $stmt = $db->prepare('INSERT OR IGNORE INTO waitlist
        (platform, email, phone, source, page, lang, country, utm_source, utm_medium, utm_campaign, referrer, user_agent, ip_hash, sid, nv_path, device, paid)
        VALUES (:p, :e, :ph, :source, :page, :lang, :country, :us, :um, :uc, :ref, :ua, :iph, :sid, :nvp, :dev, :paid)');
    $stmt->bindValue(':p', $platform, SQLITE3_TEXT);
    $stmt->bindValue(':e', $email, SQLITE3_TEXT);
    $stmt->bindValue(':ph', $phone, SQLITE3_TEXT);
    $stmt->bindValue(':source', (string)($meta['source'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':page', (string)($meta['page'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':lang', (string)($meta['lang'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':country', (string)($meta['country'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':us', (string)($meta['utm_source'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':um', (string)($meta['utm_medium'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':uc', (string)($meta['utm_campaign'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':ref', (string)($meta['referrer'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':ua', (string)($meta['user_agent'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':iph', (string)($meta['ip_hash'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':sid', (string)($meta['sid'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':nvp', (string)($meta['nv_path'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':dev', (string)($meta['device'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':paid', (int)($meta['paid'] ?? 0), SQLITE3_INTEGER);
    $stmt->execute();
    $isNew = $db->changes() === 1;
    // A repeat signup may add a phone number that was missing before.
    if (!$isNew && $phone !== '') {
        $upd = $db->prepare('UPDATE waitlist SET phone = :ph WHERE platform = :p AND email = :e AND phone = \'\'');
        $upd->bindValue(':ph', $phone, SQLITE3_TEXT);
        $upd->bindValue(':p', $platform, SQLITE3_TEXT);
        $upd->bindValue(':e', $email, SQLITE3_TEXT);
        $upd->execute();
    }
    $db->close();
    return $isNew;
}

function waitlist_delete($platform, $email) {
    $db = waitlist_db();
    $stmt = $db->prepare('DELETE FROM waitlist WHERE platform = :p AND email = :e');
    $stmt->bindValue(':p', $platform, SQLITE3_TEXT);
    $stmt->bindValue(':e', $email, SQLITE3_TEXT);
    $stmt->execute();
    $n = $db->changes();
    $db->close();
    return $n;
}

function waitlist_list($platform, $limit = 2000) {
    $db = waitlist_db();
    $stmt = $db->prepare('SELECT * FROM waitlist WHERE platform = :p ORDER BY created_at DESC LIMIT :lim');
    $stmt->bindValue(':p', $platform, SQLITE3_TEXT);
    $stmt->bindValue(':lim', (int)$limit, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $rows = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
    $db->close();
    return $rows;
}

function waitlist_count($platform) {
    $db = waitlist_db();
    $stmt = $db->prepare('SELECT COUNT(*) c FROM waitlist WHERE platform = :p');
    $stmt->bindValue(':p', $platform, SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    $db->close();
    return (int)($row['c'] ?? 0);
}

// Records one POST attempt (success or not) for rate-limiting — independent of the UNIQUE constraint,
// so a flood of distinct fake emails from one IP is still counted.
function waitlist_record_attempt($ipHash, $platform) {
    if ($ipHash === '') return;
    $db = waitlist_db();
    $stmt = $db->prepare('INSERT INTO waitlist_attempts (ip_hash, platform) VALUES (:h, :p)');
    $stmt->bindValue(':h', $ipHash, SQLITE3_TEXT);
    $stmt->bindValue(':p', $platform, SQLITE3_TEXT);
    $stmt->execute();
    $db->close();
}

function waitlist_recent_attempts($ipHash, $seconds) {
    if ($ipHash === '') return 0;
    $db = waitlist_db();
    $stmt = $db->prepare("SELECT COUNT(*) c FROM waitlist_attempts WHERE ip_hash = :h AND ts >= datetime('now', :win)");
    $stmt->bindValue(':h', $ipHash, SQLITE3_TEXT);
    $stmt->bindValue(':win', '-' . (int)$seconds . ' seconds', SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    $db->close();
    return (int)($row['c'] ?? 0);
}
