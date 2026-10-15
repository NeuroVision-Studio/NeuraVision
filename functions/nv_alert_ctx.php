<?php
/**
 * Rich context block for the owner's Telegram alerts (download / registration / webinar).
 * 2026-10-10. Everything is best-effort: any lookup that fails just leaves its field as "—".
 * Never throws, never blocks the response -- callers defer sending with nv_alert_defer().
 */
if (!function_exists('nv_alert_ctx')) {

function nv_alert_e($s, $max = 300) {
    $s = trim(preg_replace('/\s+/u', ' ', (string)$s));
    if ($s === '') return '—';
    return mb_strlen($s, 'UTF-8') > $max ? mb_substr($s, 0, $max, 'UTF-8') . '…' : $s;
}

function nv_alert_ip() {
    // Real visitor IP (nginx real_ip from Cloudflare edges; CF header only if the peer is a CF edge). 2026-10-10 xads
    if (is_file(__DIR__ . '/nv_client_ip.php')) { require_once __DIR__ . '/nv_client_ip.php'; $ip = nv_client_ip(); if ($ip !== '0.0.0.0') return $ip; }
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '';
}

/** country / city / ISP / ASN from ip-api (3 s timeout; cached 7 days per IP in data/). */
function nv_alert_geo($ip) {
    $out = ['country' => '', 'cc' => '', 'city' => '', 'region' => '', 'isp' => '', 'as' => '', 'hosting' => false, 'proxy' => false];
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return $out;
    $cacheF = dirname(__DIR__) . '/data/alert_geo_cache.json';
    $cache = is_file($cacheF) ? json_decode((string)@file_get_contents($cacheF), true) : [];
    if (!is_array($cache)) $cache = [];
    $k = hash('sha256', $ip);
    if (isset($cache[$k]) && ($cache[$k]['t'] ?? 0) > time() - 7 * 86400) return array_merge($out, $cache[$k]['d']);
    $ch = curl_init('http://ip-api.com/json/' . urlencode($ip) . '?fields=status,country,countryCode,regionName,city,isp,org,as,proxy,hosting');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 2]);
    $r = curl_exec($ch); curl_close($ch);
    $d = $r ? json_decode($r, true) : null;
    if (is_array($d) && ($d['status'] ?? '') === 'success') {
        $out = ['country' => (string)($d['country'] ?? ''), 'cc' => (string)($d['countryCode'] ?? ''),
                'city' => (string)($d['city'] ?? ''), 'region' => (string)($d['regionName'] ?? ''),
                'isp' => (string)($d['isp'] ?? ($d['org'] ?? '')), 'as' => (string)($d['as'] ?? ''),
                'hosting' => !empty($d['hosting']), 'proxy' => !empty($d['proxy'])];
        if (count($cache) > 3000) $cache = array_slice($cache, -1500, null, true);
        $cache[$k] = ['t' => time(), 'd' => $out];
        @file_put_contents($cacheF, json_encode($cache), LOCK_EX);
    }
    return $out;
}

function nv_alert_tracker_pdo() {
    try {
        $p = new PDO('sqlite:' . dirname(__DIR__) . '/data/tracker.sqlite');
        $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $p->setAttribute(PDO::ATTR_TIMEOUT, 2);
        $p->exec('PRAGMA busy_timeout=2000');
        return $p;
    } catch (Throwable $e) { return null; }
}

function nv_alert_users_pdo() {
    try {
        $p = new PDO('sqlite:' . dirname(__DIR__) . '/data/users.sqlite');
        $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $p->setAttribute(PDO::ATTR_TIMEOUT, 2);
        $p->exec('PRAGMA busy_timeout=2000');
        return $p;
    } catch (Throwable $e) { return null; }
}

function nv_alert_dur($sec) {
    $sec = max(0, (int)$sec);
    if ($sec < 60) return $sec . ' с';
    if ($sec < 3600) return floor($sec / 60) . ' мин ' . ($sec % 60) . ' с';
    if ($sec < 86400) return floor($sec / 3600) . ' ч ' . floor(($sec % 3600) / 60) . ' мин';
    return floor($sec / 86400) . ' д ' . floor(($sec % 86400) / 3600) . ' ч';
}

function nv_alert_landing_label($v) {
    $m = ['main'=>'Main','1'=>'v1 Classic','2'=>'v2 Pain','3'=>'v3 Gallery','5'=>'v5 Cinema','6'=>'v6 Light',
          '7'=>'v7 Bold','8'=>'v8 Fire','9'=>'v9 Clean','11'=>'v11 Proof','12'=>'v12 Studio','13'=>'v13 Higgsfield'];
    $v = (string)$v;
    if ($v === '') return '—';
    return $m[$v] ?? ('v' . $v);
}

/** Locate the signed-in user: auth token cookie first, then the LX-000… id cookie, then an explicit email. */
function nv_alert_user($email = '') {
    $db = nv_alert_users_pdo();
    if (!$db) return null;
    try {
        $tok = (string)($_COOKIE['lx_auth_token'] ?? '');
        if ($tok !== '') {
            $st = $db->prepare('SELECT * FROM users WHERE token = ? LIMIT 1'); $st->execute([$tok]);
            if ($u = $st->fetch(PDO::FETCH_ASSOC)) return $u;
        }
        if ($email !== '') {
            $st = $db->prepare('SELECT * FROM users WHERE lower(email) = lower(?) LIMIT 1'); $st->execute([$email]);
            if ($u = $st->fetch(PDO::FETCH_ASSOC)) return $u;
        }
        $uid = (int)preg_replace('/\D+/', '', (string)($_COOKIE['lx_user_id'] ?? ''));
        if ($uid > 0) {
            $st = $db->prepare('SELECT * FROM users WHERE id = ? LIMIT 1'); $st->execute([$uid]);
            if ($u = $st->fetch(PDO::FETCH_ASSOC)) return $u;
        }
    } catch (Throwable $e) {}
    return null;
}

/**
 * Build the context lines. $opt: ['email' => known email, 'kind' => 'download'|'registration'|'webinar',
 * 'page' => page url posted by the client].
 */
function nv_alert_ctx(array $opt = []) {
    $now = time();
    $ip  = nv_alert_ip();
    $geo = nv_alert_geo($ip);
    $sid = preg_replace('/[^A-Za-z0-9-]/', '', substr((string)($_COOKIE['lx_sid'] ?? ''), 0, 36));
    $sess = null; $pages = 0; $firstTs = null; $evCount = 0;
    if ($sid !== '' && ($t = nv_alert_tracker_pdo())) {
        try {
            $st = $t->prepare('SELECT * FROM sessions WHERE sid = ? LIMIT 1'); $st->execute([$sid]);
            $sess = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            $st = $t->prepare("SELECT SUM(event IN ('pageview','lk_view','cabinet_view','mobile_view')) pv, COUNT(*) n, MIN(created_at) f FROM events WHERE sid = ?");
            $st->execute([$sid]);
            if ($r = $st->fetch(PDO::FETCH_ASSOC)) { $pages = (int)$r['pv']; $evCount = (int)$r['n']; $firstTs = $r['f']; }
        } catch (Throwable $e) {}
    }
    $start = $sess['created_at'] ?? $firstTs;
    if ($firstTs && $start && strcmp($firstTs, $start) < 0) $start = $firstTs;

    // Entry URL + its query: utm_* and click ids.
    $entry = (string)($sess['landing_url'] ?? '');
    $q = [];
    if ($entry !== '') { parse_str((string)parse_url($entry, PHP_URL_QUERY), $q); }
    $utm = [];
    foreach (['utm_source','utm_medium','utm_campaign','utm_content','utm_term','ref'] as $k) {
        if (!empty($q[$k]) && is_string($q[$k])) $utm[] = str_replace('utm_', '', $k) . '=' . nv_alert_e($q[$k], 80);
    }
    if (!$utm && !empty($sess['source'])) $utm[] = 'source=' . nv_alert_e($sess['source'], 60);
    if (!empty($sess['campaign']) && empty($q['utm_campaign'])) $utm[] = 'campaign=' . nv_alert_e($sess['campaign'], 60);
    if (!empty($_COOKIE['lx_cr'])) $utm[] = 'крео=' . nv_alert_e($_COOKIE['lx_cr'], 40);
    $clicks = [];
    foreach (['gclid','gbraid','wbraid','rdt_cid','fbclid','twclid','ttclid','msclkid','epik','li_fat_id'] as $k) {
        $v = (!empty($q[$k]) && is_string($q[$k])) ? $q[$k] : ($k === 'twclid' ? (string)($sess['twclid'] ?? '') : '');
        if ($v !== '') $clicks[] = $k . '=' . nv_alert_e($v, 60);
    }
    $ref = (string)($sess['referrer'] ?? '');

    // Landing split arm / design.
    $lpv = (string)($_COOKIE['lp_v'] ?? '');
    if ($lpv === '' && $entry !== '' && preg_match('#/lp/v(\d+)\.php#', $entry, $mm)) $lpv = $mm[1];
    $theme = (string)($sess['theme'] ?? ($_COOKIE['lx_theme'] ?? ''));
    $hero = (string)($sess['hero'] ?? ($_COOKIE['lx_hero'] ?? ''));
    $herovid = (string)($sess['herovid'] ?? ($_COOKIE['lx_herovid'] ?? ''));
    // Entry block from the v13 bandit router: prefer the session column, fall back to the cookies
    // (nv_block = pinned choice, lx_entry_block = tracker mirror) so it shows even before the column exists.
    $entryBlock = (string)($sess['entry_block'] ?? ($_COOKIE['nv_block'] ?? ($_COOKIE['lx_entry_block'] ?? '')));

    // dl=1 in the entry URL (or the session's dl1 marker). Since 2026-10-10 dl=1 = landing path 3.
    $dl1 = !empty($sess['dl1']) || ((string)($q['dl'] ?? '') === '1');
    // Landing path split (lp/lp_path.php): 1 = signup->LK A, 2 = signup->LK B, 3 = download without registration.
    $nvPath = (string)($_COOKIE['nv_path'] ?? '');                  // current pin (this browser)
    if (!in_array($nvPath, ['1', '2', '3'], true)) $nvPath = (string)($sess['nv_path'] ?? '');
    if (!in_array($nvPath, ['1', '2', '3'], true)) $nvPath = '';
    $captcha = !empty($GLOBALS['nv_dl_extra']['captcha']);

    // User.
    $u = nv_alert_user((string)($opt['email'] ?? ''));
    $lk = (($_COOKIE['lk_old'] ?? '') === '1') ? 'old' : strtolower((string)($_COOKIE['lk_ab'] ?? ''));
    if ($lk === '' && $u) $lk = strtolower((string)($u['lk_variant'] ?? ''));
    if ($lk === '' && $sess) $lk = strtolower((string)($sess['lk_variant'] ?? ''));

    // Device.
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $dev = $sess['device_type'] ?? null; $os = $sess['os_name'] ?? null; $osv = $sess['os_version'] ?? null; $br = $sess['browser'] ?? null;
    if (!$sess) {
        $p = function_exists('lx_parse_ua') ? lx_parse_ua($ua) : [];
        if (!$p) {
            $p = ['device_type' => preg_match('/Mobile|iPhone|Android/i', $ua) ? 'mobile' : 'desktop',
                  'os_name' => preg_match('/Windows/i', $ua) ? 'Windows' : (preg_match('/iPhone|iPad/i', $ua) ? 'iOS' : (preg_match('/Mac OS X/i', $ua) ? 'macOS' : (preg_match('/Android/i', $ua) ? 'Android' : ''))),
                  'os_version' => '', 'browser' => preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/Chrome\//', $ua) ? 'Chrome' : (preg_match('/Firefox\//', $ua) ? 'Firefox' : (preg_match('/Safari\//', $ua) ? 'Safari' : '')))];
        }
        $dev = $p['device_type'] ?? ''; $os = $p['os_name'] ?? ''; $osv = $p['os_version'] ?? ''; $br = $p['browser'] ?? '';
    }
    if (!$br) {
        $u2 = (string)($sess['user_agent'] ?? $ua);
        $br = preg_match('/CriOS/', $u2) ? 'Chrome iOS' : (preg_match('/FxiOS/', $u2) ? 'Firefox iOS' : (preg_match('/Instagram/', $u2) ? 'Instagram in-app' : (preg_match('/FBAN|FBAV/', $u2) ? 'Facebook in-app' : (preg_match('/Safari\//', $u2) && !preg_match('/Chrome/', $u2) ? 'Safari' : ''))));
    }
    $lang = (string)($sess['lang'] ?? '');
    if ($lang === '' && !empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) $lang = strtolower(substr(trim(explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE'])[0]), 0, 8));
    $uiLang = (string)($_COOKIE['lx-language'] ?? '');
    $scr = preg_replace('/[^0-9x@. ]/', '', (string)($_COOKIE['lx_scr'] ?? ''));

    $L = [];
    $geoS = trim(($geo['country'] ? $geo['country'] . ($geo['cc'] ? ' (' . $geo['cc'] . ')' : '') : ($sess['country'] ?? '')) . ($geo['city'] ? ' · ' . $geo['city'] . ($geo['region'] && $geo['region'] !== $geo['city'] ? ', ' . $geo['region'] : '') : ''));
    $L[] = '🌐 IP ' . ($ip ?: '—') . ' · ' . ($geoS !== '' ? $geoS : '—')
         . ' · ' . ($geo['isp'] ? $geo['isp'] : '—') . ($geo['as'] ? ' · ' . $geo['as'] : '')
         . ($geo['hosting'] ? ' · ⚠️датацентр' : '') . ($geo['proxy'] ? ' · ⚠️прокси/VPN' : '');
    $L[] = '🛬 Лендинг: ' . nv_alert_landing_label($lpv) . ' · путь ' . ($nvPath !== '' ? $nvPath . ($nvPath === '3' ? ' (без регистрации' . ($captcha ? ', капча ✓' : '') . ')' : '') : '—')
         . ' · блок ' . ($entryBlock ?: '—')
         . ' · вход ' . ($entry !== '' ? nv_alert_e($entry, 400) : '—')
         . ' · тема ' . ($theme ?: '—') . ' · hero ' . ($hero ?: '—') . ' · herovid ' . ($herovid ?: '—')
         . ' · ЛК ' . ($lk !== '' ? strtoupper($lk) : '—')
         . ($dl1 ? ' · dl=1' : '');
    if (!empty($opt['page'])) $L[] = '📄 Страница действия: ' . nv_alert_e($opt['page'], 300);
    elseif (!empty($_SERVER['HTTP_REFERER'])) $L[] = '📄 Страница действия: ' . nv_alert_e($_SERVER['HTTP_REFERER'], 300);
    $L[] = '📣 Источник: ' . ($utm ? implode(' · ', $utm) : 'utm нет') . ' · referrer ' . ($ref !== '' ? nv_alert_e($ref, 200) : 'прямой/нет')
         . ' · click-id ' . ($clicks ? implode(' · ', $clicks) : 'нет');
    // Ad campaign + creative (sessions columns parsed from landing_url by lx_track_lib.php, URL fallback). 2026-10-10
    $af = function ($k) use ($sess, $q) { $v = (string)($sess[$k] ?? ''); if ($v === '' && !empty($q[$k]) && is_string($q[$k])) $v = $q[$k]; return $v; };
    $camp = $af('utm_campaign') !== '' ? $af('utm_campaign') : ($af('gad_campaignid') !== '' ? 'gads:' . $af('gad_campaignid') : (string)($sess['campaign'] ?? ''));
    // X Ads (2026-10-10 xads): format of the X link + creative = utm_content | ref | crN / ref-label.
    $src0 = (string)($sess['source'] ?? '');
    $xfmt = (string)($sess['x_format'] ?? '');
    $us0 = $af('utm_source');
    $creo = $af('utm_content') !== '' ? $af('utm_content') : ($af('ref') !== '' ? $af('ref')
          : (preg_match('/^(cr\d+|ref\d+-\d+)$/i', $us0) ? $us0 : (string)($sess['creative'] ?? '')));
    if ($src0 === '' && ($us0 !== '' || $af('tw') !== '' || $af('tw_click') !== '' || $af('twclid') !== '')
        && (preg_match('/^(cr\d+|ref\d+-\d+|x|twitter|tw)$/i', $us0) || $af('tw') !== '' || $af('tw_click') !== '' || $af('twclid') !== '')) $src0 = 'x';
    $xFmtL = ['crN' => 'utm_source=crN', 'ref_label' => 'utm_source=ref-метка', 'ref_tw' => 'ref+tw+tw_click',
              'utm_x' => 'utm_source=x', 'twclid' => 'twclid', 'ref_n' => 'ref=nNNN', 'referrer' => 'referrer t.co/x.com'];
    $L[] = '🎯 Кампания: ' . nv_alert_e($src0 ?: '—', 32)
         . ($src0 === 'x' ? ' (X Ads' . ($xfmt !== '' ? ', формат ' . ($xFmtL[$xfmt] ?? $xfmt) : '') . ')' : '')
         . ' · кампания ' . ($camp !== '' ? nv_alert_e($camp, 80) : '—')
         . ' · креатив ' . ($creo !== '' ? nv_alert_e($creo, 80) : '—')
         . ($af('tw') !== '' ? ' · tw ' . nv_alert_e($af('tw'), 24) : '')
         . ($af('tw_click') !== '' ? ' · tw_click ' . nv_alert_e($af('tw_click'), 40) : '')
         . ($af('utm_term') !== '' ? ' · term ' . nv_alert_e($af('utm_term'), 60) : '')
         . ($af('gad_source') !== '' ? ' · gad_source ' . nv_alert_e($af('gad_source'), 4) : '');
    // All split dimensions in one line (2026-10-10 xads), so a download/registration can be read per split arm.
    $lpvS = (string)($sess['lp_v'] ?? '') !== '' ? (string)$sess['lp_v'] : $lpv;
    $L[] = '🧪 Сплиты: путь ' . ($nvPath !== '' ? $nvPath : '—') . ' · entry_block ' . ($entryBlock ?: '—')
         . ' · лендинг ' . nv_alert_landing_label($lpvS) . ' · тема ' . ($theme ?: '—') . ' · hero ' . ($hero ?: '—')
         . ' · herovid ' . ($herovid ?: '—') . ' · ЛК ' . ($lk !== '' ? strtoupper($lk) : '—')
         . ' · язык ' . ($lang ?: '—') . ' · страна ' . (($sess['country'] ?? '') ?: ($geo['cc'] ?: '—'))
         . ' · ОС ' . ($os ?: '—');
    $L[] = '📱 Устройство: ' . ($dev ?: '—') . ' · ' . trim(($os ?: '—') . ' ' . ($osv ?: '')) . ' · ' . ($br ?: '—')
         . ' · язык ' . ($lang ?: '—') . ($uiLang !== '' ? ' (сайт: ' . nv_alert_e($uiLang, 8) . ')' : '')
         . ' · экран ' . ($scr !== '' ? $scr : 'неизв.');
    if ($u) {
        $regTs = strtotime((string)$u['created_at'] . (preg_match('/[zZ]|[+-]\d\d:?\d\d$/', (string)$u['created_at']) ? '' : ' UTC'));
        $promo = (string)($u['promo_code'] ?? '');
        try {
            $pdb = nv_alert_users_pdo();
            if ($pdb) { $st = $pdb->prepare('SELECT code, credits, source FROM promo_redemptions WHERE user_id = ? ORDER BY id'); $st->execute([(int)$u['id']]);
                $pp = []; foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $pp[] = $r['code'] . ' +' . $r['credits'] . ' (' . $r['source'] . ')';
                if ($pp) $promo = implode(', ', $pp); }
        } catch (Throwable $e) {}
        $L[] = '👤 Юзер: ' . nv_alert_e($u['email'], 120) . ' (id ' . (int)$u['id'] . ')'
             . ' · рег. ' . ($regTs ? gmdate('Y-m-d H:i', $regTs) . ' UTC' : '—')
             . (($opt['kind'] ?? '') === 'registration' ? '' : ' · от регистрации ' . ($regTs ? nv_alert_dur($now - $regTs) : '—'))
             . ' · баланс ' . number_format((int)$u['credits']) . ' кр.'
             . ' · промо ' . ($promo !== '' ? nv_alert_e($promo, 120) : 'нет');
    } else {
        $L[] = '👤 Юзер: не залогинен' . (!empty($opt['email']) ? ' (' . nv_alert_e($opt['email'], 120) . ')' : '');
    }
    $startTs = $start ? strtotime($start . ' UTC') : 0;
    $L[] = '🧭 Сессия: ' . ($sid !== '' ? $sid : 'нет lx_sid') . ' · страниц ' . $pages . ' (событий ' . $evCount . ')'
         . ' · на сайте ' . ($startTs ? nv_alert_dur($now - $startTs) : '—')
         . ($startTs ? ' (с ' . gmdate('H:i', $startTs) . ' UTC)' : '');
    $L[] = '🕒 ' . gmdate('Y-m-d H:i:s', $now) . ' UTC · ' . gmdate('H:i:s', $now + 3 * 3600) . ' МСК';
    return implode("\n", $L);
}

/** Send a TG message; logs {ok,message_id} (never the token). */
function nv_alert_send($text, $logTag) {
    $env = function ($k) {
        if (function_exists('nv_api_env')) { $v = nv_api_env($k); if ($v !== '') return $v; }
        if (function_exists('lx_env_value')) { $v = lx_env_value($k); if ($v) return $v; }
        return '';
    };
    $token = $env('TG_BOT_TOKEN'); $chat = $env('TG_CHAT_ID');
    if (!$token || !$chat) return null;
    if (mb_strlen($text, 'UTF-8') > 4000) $text = mb_substr($text, 0, 3990, 'UTF-8') . '…';
    $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 8,
        CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $chat, 'text' => $text, 'disable_web_page_preview' => true])]);
    $resp = curl_exec($ch); curl_close($ch);
    $d = $resp ? json_decode($resp, true) : null;
    @file_put_contents(dirname(__DIR__) . '/data/alert_notify.log',
        gmdate('c') . ' ' . $logTag . ' ok=' . (!empty($d['ok']) ? '1' : '0') . ' message_id=' . ($d['result']['message_id'] ?? '-') . "\n", FILE_APPEND);
    if (!empty($d['ok'])) @file_put_contents(dirname(__DIR__) . '/data/alert_last_' . preg_replace('/\W/', '', $logTag) . '.txt', $text, LOCK_EX);
    return $d;
}

/** Run $fn after the response has been handed to the visitor (PHP-FPM), so alerts never slow a page. */
function nv_alert_defer(callable $fn) {
    register_shutdown_function(function () use ($fn) {
        try {
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
            ignore_user_abort(true);
            $fn();
        } catch (Throwable $e) { error_log('nv_alert_defer: ' . $e->getMessage()); }
    });
}

}
