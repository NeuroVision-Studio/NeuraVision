<?php
/**
 * Desktop app download helpers — shared by account.php, api/desktop_download.php, api/desktop_status.php and admin/downloads.
 *
 * The installers are NOT stored on this site. The owner keeps a direct link per platform in
 * data/desktop_links.json (set in /admin/downloads/):
 *   url        https link to the build (e.g. Dropbox ...&dl=1). Empty → "coming soon".
 *   keepName   true (default) → redirect to the link, the file name comes from the link.
 *              false → the file is streamed through this site and named customName.
 *   customName file name used when keepName is false.
 * data/ is closed by nginx, so the JSON is only read by PHP.
 */

if (!defined('DESKTOP_LINKS_FILE')) define('DESKTOP_LINKS_FILE', dirname(__DIR__) . '/data/desktop_links.json');
if (!defined('DESKTOP_META_FILE')) define('DESKTOP_META_FILE', dirname(__DIR__) . '/data/desktop_release.json');
if (!defined('DESKTOP_STATS_DB')) define('DESKTOP_STATS_DB', dirname(__DIR__) . '/data/desktop_stats.sqlite');
require_once dirname(__DIR__) . '/api/nv_notify.php';

function desktop_platform_specs() {
    return [
        'windows' => ['file' => 'Neuravision-Setup.exe', 'ext' => 'exe', 'label' => 'Windows (.exe)', 'mime' => 'application/vnd.microsoft.portable-executable'],
        'macos'   => ['file' => 'Neuravision.dmg', 'ext' => 'dmg', 'label' => 'macOS (.dmg)', 'mime' => 'application/x-apple-diskimage'],
    ];
}

function desktop_detect_os($ua) {
    $ua = (string)$ua;
    if (stripos($ua, 'Windows') !== false) return 'windows';
    if (stripos($ua, 'Macintosh') !== false || stripos($ua, 'Mac OS X') !== false) return 'macos';
    return '';
}

// Only https links without spaces or the cmd-style "^" escape (a copy artifact that breaks the URL).
function desktop_url_is_valid($url) {
    $url = (string)$url;
    return $url !== '' && strlen($url) <= 2000 && preg_match('#^https://\S+$#', $url) === 1 && strpos($url, '^') === false;
}

function desktop_clean_filename($name) {
    $name = preg_replace('/[\x00-\x1f\x7f\\\\\/:*?"<>|]+/u', '', trim((string)$name));
    return mb_substr((string)$name, 0, 120, 'UTF-8');
}

// Last path segment of the link, e.g. ".../NeuroVision.exe?rlkey=..." → "NeuroVision.exe".
function desktop_name_from_url($url) {
    return desktop_clean_filename(rawurldecode(basename((string)parse_url((string)$url, PHP_URL_PATH))));
}

function desktop_links_raw() {
    $raw = is_file(DESKTOP_LINKS_FILE) ? json_decode((string)@file_get_contents(DESKTOP_LINKS_FILE), true) : [];
    return is_array($raw) ? $raw : [];
}

// Normalised per-platform config. A broken or missing url counts as "not published".
function desktop_links_all() {
    $raw = desktop_links_raw();
    $out = [];
    foreach (desktop_platform_specs() as $os => $spec) {
        $row = is_array($raw[$os] ?? null) ? $raw[$os] : [];
        $url = (string)($row['url'] ?? '');
        if (!desktop_url_is_valid($url)) $url = '';
        $out[$os] = [
            'url' => $url,
            'keepName' => array_key_exists('keepName', $row) ? (bool)$row['keepName'] : true,
            'customName' => desktop_clean_filename($row['customName'] ?? ''),
        ];
    }
    return $out;
}

function desktop_save_link($os, $url, $keepName, $customName) {
    $all = desktop_links_raw();
    $all[$os] = ['url' => (string)$url, 'keepName' => (bool)$keepName, 'customName' => desktop_clean_filename($customName)];
    file_put_contents(DESKTOP_LINKS_FILE, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// File name the browser gets when the file is streamed through the site (keepName off).
function desktop_proxy_filename($os, array $cfg) {
    $ext = desktop_platform_specs()[$os]['ext'];
    $name = $cfg['customName'] !== '' ? $cfg['customName'] : desktop_name_from_url($cfg['url']);
    if ($name === '') $name = desktop_platform_specs()[$os]['file'];
    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== $ext) $name .= '.' . $ext;
    return $name;
}

// Per-platform state for the account page and the download page.
function desktop_release_info() {
    $out = [];
    foreach (desktop_platform_specs() as $os => $spec) {
        $cfg = desktop_links_all()[$os];
        $out[$os] = ['os' => $os, 'label' => $spec['label'], 'file' => $spec['file'], 'available' => $cfg['url'] !== '',
                     'url' => $cfg['url'], 'sizeLabel' => '', 'sha256' => '', 'releaseDate' => ''];
    }
    return $out;
}

function desktop_release_meta() {
    $meta = is_file(DESKTOP_META_FILE) ? json_decode((string)@file_get_contents(DESKTOP_META_FILE), true) : [];
    if (!is_array($meta)) $meta = [];
    $vt = (string)($meta['vtUrl'] ?? '');
    if ($vt !== '' && !preg_match('#^https://www\.virustotal\.com/#', $vt)) $vt = '';
    return ['version' => preg_replace('/[^0-9A-Za-z._-]/', '', (string)($meta['version'] ?? '')), 'vtUrl' => $vt];
}

function desktop_stats_db() {
    $db = new SQLite3(DESKTOP_STATS_DB);
    $db->busyTimeout(3000);
    $db->exec('CREATE TABLE IF NOT EXISTS downloads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
        os TEXT NOT NULL,
        ip_hash TEXT NOT NULL,
        user_agent TEXT NOT NULL DEFAULT \'\',
        bytes INTEGER NOT NULL DEFAULT 0,
        file_sha256 TEXT NOT NULL DEFAULT \'\')');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_downloads_created ON downloads(created_at)');
    // Attribution columns (landing path split, 2026-10-10): which landing path / entry block / design /
    // source the download came from, plus the X conversion id and whether the PoW captcha was solved.
    static $migrated = false;
    if (!$migrated) {
        $migrated = true;
        $have = [];
        $res = $db->query('PRAGMA table_info(downloads)');
        while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) $have[$r['name']] = 1;
        foreach (['nv_path INTEGER', 'entry_block TEXT', 'landing TEXT', 'theme TEXT', 'hero TEXT', 'herovid TEXT',
                  'utm_source TEXT', 'utm_campaign TEXT', 'twclid TEXT', 'sid TEXT', 'cid TEXT', 'captcha INTEGER'] as $col) {
            if (!isset($have[explode(' ', $col)[0]])) @$db->exec('ALTER TABLE downloads ADD COLUMN ' . $col);
        }
    }
    return $db;
}

// Records one download click. Never throws: a stats failure must not block the download.
function desktop_record_download($os, $bytes = 0, $sha = '') {
    // Honor the same test/dev suppression cookie track.php and the ad pixels use, so a known
    // test/review account's downloads never hit desktop_stats.sqlite, never mirror into the
    // tracker events table, and never fire the owner's Telegram alert. Added 2026-10-10
    // (LeronX-cleanup + review-account pass) -- this path previously had no notrack guard.
    if (($_COOKIE['lx_notrack'] ?? '') === '1') { return; }
    try {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        // Daily-rotating hash: counts unique visitors per day without storing the raw IP.
        $ipHash = hash('sha256', gmdate('Y-m-d') . '|' . $ip);
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200);
        // Self-declared bots / uptime monitors are not counted as downloads at all. The owner's
        // watchdog polled this endpoint every 2 min on 2026-10-09 and added ~700 phantom rows/day.
        if (preg_match('/bot|crawl|spider|slurp|uptime|pingdom|headless|python-requests|curl\\/|wget\\//i', $ua)) return;
        $db = desktop_stats_db();
        $ck = function ($n, $max = 64) { $v = preg_replace('/[^A-Za-z0-9_\-\.:|]/', '', (string)($_COOKIE[$n] ?? '')); return $v === '' ? null : substr($v, 0, $max); };
        $np = (string)($_COOKIE['nv_path'] ?? '');
        $cidIn = (string)($_GET['cid'] ?? '');
        $cidV = preg_match('/^(dl|lk)-[a-z0-9-]{6,40}$/', $cidIn) ? 'dl-' . substr($cidIn, 3) : null;
        $stmt = $db->prepare('INSERT INTO downloads (os, ip_hash, user_agent, bytes, file_sha256, nv_path, entry_block, landing, theme, hero, herovid, utm_source, utm_campaign, twclid, sid, cid, captcha)
            VALUES (:os, :ip, :ua, :bytes, :sha, :np, :eb, :lp, :th, :he, :hv, :us, :uc, :tw, :sid, :cid, :cap)');
        $stmt->bindValue(':os', $os, SQLITE3_TEXT);
        $stmt->bindValue(':ip', $ipHash, SQLITE3_TEXT);
        $stmt->bindValue(':ua', $ua, SQLITE3_TEXT);
        $stmt->bindValue(':bytes', (int)$bytes, SQLITE3_INTEGER);
        $stmt->bindValue(':sha', (string)$sha, SQLITE3_TEXT);
        $stmt->bindValue(':np', in_array($np, ['1', '2', '3'], true) ? (int)$np : ((string)($_GET['p3'] ?? '') === '1' ? 3 : null), SQLITE3_INTEGER);
        $stmt->bindValue(':eb', $ck('nv_block', 20) ?? $ck('lx_entry_block', 20), SQLITE3_TEXT);
        $stmt->bindValue(':lp', $ck('lp_v', 8), SQLITE3_TEXT);
        $stmt->bindValue(':th', $ck('lx_theme', 12), SQLITE3_TEXT);
        $stmt->bindValue(':he', $ck('lx_hero', 8), SQLITE3_TEXT);
        $stmt->bindValue(':hv', $ck('lx_herovid', 12), SQLITE3_TEXT);
        $stmt->bindValue(':us', $ck('lx_src'), SQLITE3_TEXT);
        $stmt->bindValue(':uc', $ck('lx_camp'), SQLITE3_TEXT);
        $stmt->bindValue(':tw', $ck('lx_twclid', 128) ?? $ck('twclid', 128), SQLITE3_TEXT);
        $stmt->bindValue(':sid', $ck('lx_sid', 36), SQLITE3_TEXT);
        $stmt->bindValue(':cid', $cidV, SQLITE3_TEXT);
        $stmt->bindValue(':cap', (int)($GLOBALS['nv_dl_extra']['captcha'] ?? 0), SQLITE3_INTEGER);
        $stmt->execute();
        $db->close();
        // Telegram alert -- but NOT for every hit. An external monitor polls this endpoint
        // every ~2 min through Cloudflare (no lx_sid cookie, no Referer), which flooded the
        // owner's chat with ~30 "download" messages/hour after the alert was added on
        // 2026-10-09. Two guards, both needed:
        //   1. a request with neither a tracker session cookie nor a Referer is a bot/monitor;
        //   2. per ip_hash+os cooldown, so a real user retrying does not re-alert.
        $hasSid = trim((string)($_COOKIE['lx_sid'] ?? '')) !== '';
        $hasRef = trim((string)($_SERVER['HTTP_REFERER'] ?? '')) !== '';
        $notify = ($hasSid || $hasRef);
        if ($notify) {
            try {
                $cool = desktop_stats_db();
                $q = $cool->prepare("SELECT COUNT(*) c FROM downloads WHERE ip_hash = :ip AND os = :os AND created_at > datetime('now', '-30 minutes') AND id < (SELECT MAX(id) FROM downloads)");
                $q->bindValue(':ip', $ipHash, SQLITE3_TEXT);
                $q->bindValue(':os', $os, SQLITE3_TEXT);
                $res = $q->execute();
                $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
                if ($row && (int)$row['c'] > 0) { $notify = false; }
                $cool->close();
            } catch (Throwable $e) { /* a cooldown lookup failure must not lose a real alert */ }
        }
        if ($notify && function_exists('nv_api_notify_download')) {
            nv_api_notify_download($os, $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '', $ua);
        }
    } catch (Throwable $e) {
        error_log('desktop_record_download: ' . $e->getMessage());
    }
    // Reconciliation (2026-10-09): this is the REAL download path (account.php's buttons link
    // here directly, bypassing window.lxAct), so new/tracker's own 'download_click' events and
    // the "Downloads by OS" dashboard panels used to undercount real downloads badly -- the only
    // accurate count lived in data/desktop_stats.sqlite above. Mirror the download into the
    // tracker's events table too (same event name other panels/lp_ab.php already query), keyed by
    // the same lx_sid cookie the tracker beacons use, so dashboards and the split-test funnel
    // become accurate without duplicating or replacing the desktop_stats_db() count above.
    try {
        $sid = preg_replace('/[^A-Za-z0-9-]/', '', substr((string)($_COOKIE['lx_sid'] ?? ''), 0, 36));
        if ($sid !== '') {
            $lxLib = dirname(__DIR__) . '/new/tracker/lx_track_lib.php';
            if (is_file($lxLib)) {
                require_once $lxLib;
                if (function_exists('lx_event')) {
                    lx_event($sid, 'download_click', 'os:' . $os);
                }
            }
        }
    } catch (Throwable $e) {
        error_log('desktop_record_download (tracker mirror): ' . $e->getMessage());
    }
}

/** Size in bytes of the published installer (HEAD through Dropbox redirects), cached 24 h. 2026-10-10 */
function desktop_remote_size($url) {
    if ($url === '') return 0;
    $f = dirname(__DIR__) . '/data/desktop_size_cache.json';
    $c = is_file($f) ? json_decode((string)@file_get_contents($f), true) : [];
    if (!is_array($c)) $c = [];
    $k = md5($url);
    if (isset($c[$k]) && $c[$k]['t'] > time() - 86400 && $c[$k]['n'] > 0) return (int)$c[$k]['n'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 8,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_USERAGENT => 'NeuravisionSizeProbe/1.0']);
    curl_exec($ch);
    $n = (int)curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    curl_close($ch);
    if ($n > 0) { $c[$k] = ['t' => time(), 'n' => $n]; @file_put_contents($f, json_encode($c), LOCK_EX); }
    return max(0, $n);
}

/**
 * Direct file URL behind a Dropbox share link (fix3, 2026-10-10). Dropbox sniffs the browser: real Chrome /
 * Edge / Firefox get a 302 to the file host, but Safari (macOS and iOS) gets the HTML preview page instead of
 * the .dmg -- the "Mac visitor clicks Download and nothing downloads" bug. Asked server-side (non-browser UA)
 * Dropbox always answers 302 -> https://<id>.dl.dropboxusercontent.com/cd/0/get/... which serves the file
 * with Content-Disposition: attachment to any browser, from any IP (verified 2026-10-10).
 * NOT cached: those URLs are effectively single-use (a 2nd GET gets 403), so one lookup per download (~0.4 s).
 * Returns '' for non-Dropbox links or on any failure (caller falls back to the old behaviour).
 */
function desktop_direct_url($url) {
    $url = (string)$url;
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($host !== 'www.dropbox.com' && $host !== 'dropbox.com') return '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 4,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'NeuravisionDownloadProxy/1.0']);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    if ($code < 300 || $code > 308 || !preg_match('#^https://[a-z0-9.-]+\.dl\.dropboxusercontent\.com/\S+$#i', $loc)) {
        error_log('desktop_direct_url: no direct link (' . $code . ')');
        return '';
    }
    return $loc;
}
