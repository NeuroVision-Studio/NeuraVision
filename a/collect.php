<?php
/**
 * Cash Top Analytics - portable drop-in collector (single file, PHP 7.4+).
 *
 * Alternative to the central Python collector for a PHP site (e.g. neuracreation).
 * Drop this file anywhere web-accessible, e.g. /a/collect.php, and point landings at:
 *
 *     <script defer src="https://yoursite.com/a/t.js.php?site=<landing-id>"></script>
 *
 * It mirrors the Python collector's ingestion into the SAME SQLite schema
 * (data/analytics.db beside this file), so the central dashboard.html can read a
 * shared DB. It also serves the tracker and the rrweb bundle, resolves country
 * from the client IP (local CSV DB if present, else cached ip-api.com), and keeps
 * permissive CORS so cross-origin landings work.
 *
 * Endpoints (by ?a= or path):
 *   POST ?a=collect        ingest a batch (text/plain JSON body)
 *   GET  ?a=t.js&site=<id>  serve tracker.js (rewrites collector URL to this file)
 *   GET  ?a=rrweb           serve vendored rrweb.min.js
 *   GET  ?a=health          {"ok":true}
 *
 * Privacy: raw IP is resolved then discarded; only country code/name are stored.
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$HERE = __DIR__;
$DATA = $HERE . '/data';
if (!is_dir($DATA)) @mkdir($DATA, 0775, true);
$DB_PATH = $DATA . '/analytics.db';
$a = isset($_GET['a']) ? $_GET['a'] : '';

function db($path) {
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // wait for the other writer (collector.py shares this DB) instead of failing with "database is locked"
    $pdo->setAttribute(PDO::ATTR_TIMEOUT, 10);
    $pdo->exec('PRAGMA busy_timeout=10000');
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec("CREATE TABLE IF NOT EXISTS sessions (
        site TEXT, session TEXT, visitor TEXT, url TEXT, path TEXT, referrer TEXT,
        ua TEXT, device TEXT, lang TEXT, tz TEXT,
        screen_w INT, screen_h INT, viewport_w INT, viewport_h INT, doc_w INT, doc_h INT,
        first_ts INT, last_ts INT, time_on_page INT DEFAULT 0, max_scroll REAL DEFAULT 0,
        country_code TEXT DEFAULT '', country TEXT DEFAULT '', region TEXT DEFAULT '', city TEXT DEFAULT '',
        PRIMARY KEY (site, session))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS events (
        id INTEGER PRIMARY KEY AUTOINCREMENT, site TEXT, session TEXT, ts INT, type TEXT, data TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS recordings (
        id INTEGER PRIMARY KEY AUTOINCREMENT, site TEXT, session TEXT, ts INT, events TEXT)");
    return $pdo;
}

function client_ip() {
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) {
            $parts = explode(',', $_SERVER[$h]);
            return trim($parts[0]);
        }
    }
    return '';
}

/** Country from IP: local CSV range DB if vendored, else cached ip-api.com. */
function geo_lookup($ip, $here) {
    if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) return [];
    $isV6 = strpos($ip, ':') !== false;
    $csv = $here . '/geo/' . ($isV6 ? 'country-ipv6.csv' : 'country-ipv4.csv');
    if (is_file($csv) && !$isV6) {
        $ipn = ip2long($ip);
        if ($ipn !== false) {
            if ($ipn < 0) $ipn += 4294967296;
            if (($fh = fopen($csv, 'r')) !== false) {
                // linear scan is fine for occasional lookups; cache below avoids repeats
                while (($row = fgetcsv($fh)) !== false) {
                    $s = ip2long($row[0]); $e = ip2long($row[1]);
                    if ($s === false || $e === false) continue;
                    if ($s < 0) $s += 4294967296; if ($e < 0) $e += 4294967296;
                    if ($ipn >= $s && $ipn <= $e) { fclose($fh); return ['code' => $row[2], 'name' => $row[2]]; }
                    if ($s > $ipn) break; // CSV is sorted ascending
                }
                fclose($fh);
            }
        }
    }
    // fallback: ip-api.com with a per-IP-hash file cache (public IPs only)
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        $cacheFile = $here . '/geo/ipapi_cache.json';
        $cache = is_file($cacheFile) ? json_decode(file_get_contents($cacheFile), true) : [];
        $key = substr(hash('sha256', 'ct-salt:' . $ip), 0, 16);
        if (isset($cache[$key])) return $cache[$key];
        $url = 'http://ip-api.com/json/' . urlencode($ip) . '?fields=status,countryCode,country,regionName,city';
        $ctx = stream_context_create(['http' => ['timeout' => 3]]);
        $resp = @file_get_contents($url, false, $ctx);
        $res = [];
        if ($resp) { $j = json_decode($resp, true);
            if (($j['status'] ?? '') === 'success')
                $res = ['code' => $j['countryCode'], 'name' => $j['country'],
                        'region' => $j['regionName'] ?? '', 'city' => $j['city'] ?? '']; }
        $cache[$key] = $res;
        @file_put_contents($cacheFile, json_encode($cache));
        return $res;
    }
    return [];
}

if ($a === 'health') { header('Content-Type: application/json'); echo '{"ok":true}'; exit; }

if ($a === 'rrweb') {
    $f = $HERE . '/vendor/rrweb.min.js';
    if (is_file($f)) { header('Content-Type: application/javascript'); readfile($f); } else http_response_code(404);
    exit;
}

if ($a === 't.js') {
    $tracker = $HERE . '/tracker.js';
    if (!is_file($tracker)) { http_response_code(404); exit; }
    header('Content-Type: application/javascript');
    // tracker derives collector base from its own script URL dir; serving it here
    // means /collect and /rrweb.min.js resolve beside this file. Provide shims:
    $self = basename(__FILE__);
    $js = file_get_contents($tracker);
    // rewrite the two endpoint derivations to query this same PHP file
    $js = str_replace('base + "/collect"', '(base + "/' . $self . '?a=collect")', $js);
    $js = str_replace('base + "/rrweb.min.js"', '(base + "/' . $self . '?a=rrweb")', $js);
    echo $js;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($a === 'collect' || $a === '')) {
    $raw = file_get_contents('php://input');
    $p = json_decode($raw, true);
    if (!is_array($p) || empty($p['session'])) { http_response_code(400); echo '{"ok":false}'; exit; }
    $site = (string)($p['site'] ?? 'default');
    $session = (string)$p['session'];
    $meta = $p['meta'] ?? []; $doc = $p['doc'] ?? [];
    $now = (int)(microtime(true) * 1000);
    $geo = geo_lookup(client_ip(), $HERE);
    $pdo = db($DB_PATH);
    // INSERT OR IGNORE: two batches of a brand-new session can race; a plain check-then-insert hit UNIQUE errors
    $ins = $pdo->prepare('INSERT OR IGNORE INTO sessions
        (site,session,visitor,url,path,referrer,ua,device,lang,tz,
         screen_w,screen_h,viewport_w,viewport_h,doc_w,doc_h,first_ts,last_ts,
         country_code,country,region,city)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $ins->execute([$site,$session,(string)($p['visitor']??''),
        $meta['url']??'',$meta['path']??'',$meta['referrer']??'',$meta['ua']??'',
        $meta['device']??'',$meta['lang']??'',$meta['tz']??'',
        (int)($meta['screen_w']??0),(int)($meta['screen_h']??0),
        (int)($meta['viewport_w']??0),(int)($meta['viewport_h']??0),
        (int)($doc['w']??0),(int)($doc['h']??0),$now,$now,
        $geo['code']??'',$geo['name']??'',$geo['region']??'',$geo['city']??'']);
    $u = $pdo->prepare('UPDATE sessions SET last_ts=?, doc_w=?, doc_h=? WHERE site=? AND session=?');
    $u->execute([$now,(int)($doc['w']??0),(int)($doc['h']??0),$site,$session]);
    $evIns = $pdo->prepare('INSERT INTO events (site,session,ts,type,data) VALUES (?,?,?,?,?)');
    foreach (($p['events'] ?? []) as $e) {
        $t = (string)($e['t'] ?? 'event');
        if ($t === 'pageview' && $geo) { $e['country_code'] = $geo['code']??''; $e['country'] = $geo['name']??''; }
        $evIns->execute([$site,$session,(int)($e['ts']??$now),$t,json_encode($e)]);
        if ($t === 'timing') {
            $pdo->prepare('UPDATE sessions SET time_on_page=?, max_scroll=MAX(max_scroll,?) WHERE site=? AND session=?')
                ->execute([(int)($e['time_on_page']??0),(float)($e['max_scroll']??0),$site,$session]);
        } elseif ($t === 'scroll') {
            $pdo->prepare('UPDATE sessions SET max_scroll=MAX(max_scroll,?) WHERE site=? AND session=?')
                ->execute([(float)($e['depth']??0),$site,$session]);
        }
    }
    if (!empty($p['rr'])) {
        $pdo->prepare('INSERT INTO recordings (site,session,ts,events) VALUES (?,?,?,?)')
            ->execute([$site,$session,$now,json_encode($p['rr'])]);
    }
    header('Content-Type: application/json'); echo '{"ok":true}'; exit;
}

http_response_code(404); echo 'not found';
