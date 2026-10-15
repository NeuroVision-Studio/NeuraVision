<?php
/**
 * Neuravision Desktop — target of the Download buttons.
 * GET /api/desktop_download.php?os=windows|macos   (no os = detect from User-Agent)
 * The installer is not stored on this site. The admin sets a direct link per platform (data/desktop_links.json):
 *   keepName on  → 302 redirect to that link; the browser takes the file name from the link.
 *   keepName off → the file is streamed through this site with the custom file name.
 * No link → 503 JSON (not 404: nginx error_page 404 would replace the body). The page shows "coming soon".
 */
require_once __DIR__ . '/../functions/desktop_release.php';
require_once __DIR__ . '/../functions/reddit_capi.php';
require_once __DIR__ . '/../functions/pinterest_capi.php';
require_once __DIR__ . '/../functions/x_capi.php';

$specs = desktop_platform_specs();
$os = strtolower(trim((string)($_GET['os'] ?? '')));
if ($os === '') $os = desktop_detect_os($_SERVER['HTTP_USER_AGENT'] ?? '');

// Ad-platform conversions (X Purchase CAPI, Reddit/Pinterest) must fire ONCE per real visitor, not
// once per repeat download. lx_conv_dl is a long-lived, JS-readable cookie: not set yet -> this is
// the first download, fire the CAPI calls below and mark it; already set -> skip them, file still serves.
$__dl_already_converted = (string)($_COOKIE['lx_conv_dl'] ?? '') === '1';
if (!$__dl_already_converted) {
    setcookie('lx_conv_dl', '1', time() + 86400 * 400, '/', '', true, false);
}

if (!isset($specs[$os])) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'unknown_os', 'allowed' => array_keys($specs)]);
    exit;
}

// Landing PATH 3 ("download without registration", owner 2026-10-10; replaces the cancelled
// dl=1 -> registration gate). A path-3 visitor (cookie nv_path=3 or ?p3=1) gets the file ONLY with a
// valid, unused proof-of-work token (?pow=, functions/nv_pow.php) -- no token = no file. Signed-in
// accounts and every non-path-3 request (LK A/B/C buttons, emailed links, old links) work as before.
$__p3 = ((string)($_COOKIE['nv_path'] ?? '') === '3') || ((string)($_GET['p3'] ?? '') === '1');
$GLOBALS['nv_dl_extra'] = ['captcha' => 0];
if ($__p3) {
    $__signed = false;
    try {
        require_once __DIR__ . '/../functions/auth_db.php';
        $__tok = (string)($_COOKIE['lx_auth_token'] ?? '');
        if ($__tok !== '' && preg_match('/^[A-Za-z0-9._-]{16,512}$/', $__tok)) {
            $__st = lx_auth_db()->prepare('SELECT id FROM users WHERE token = ? LIMIT 1');
            $__st->execute([$__tok]);
            $__signed = (bool)$__st->fetchColumn();
        }
    } catch (Throwable $e) { $__signed = false; }
    if (!$__signed) {
        require_once __DIR__ . '/../functions/nv_pow.php';
        $__err = '';
        $__isHead = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD';
        if (!nv_pow_verify((string)($_GET['pow'] ?? ''), $__err, !$__isHead)) {
            @file_put_contents(__DIR__ . '/../data/nv_pow.log', gmdate('c') . ' reject os=' . $os . ' err=' . $__err
                . ' ua=' . substr(preg_replace('/\s+/', ' ', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 120) . "\n", FILE_APPEND);
            http_response_code(403);
            header('Cache-Control: no-store');
            header('X-Robots-Tag: noindex');
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
               . '<title>Neuravision</title><body style="font:16px/1.5 system-ui,sans-serif;background:#07070c;color:#eee;display:flex;min-height:90vh;align-items:center;justify-content:center;text-align:center">'
               . '<div><p>Please start the download from the Neuravision page.</p><p><a style="color:#ff8f4d" href="/?dl=1">neuracreation.com</a></p></div>';
            exit;
        }
        $GLOBALS['nv_dl_extra']['captcha'] = 1;
    }
}

$cfg = desktop_links_all()[$os];
if ($cfg['url'] === '') {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['error' => 'not_available', 'os' => $os]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

// Redirect mode: the browser goes straight to the link (Chrome names the file from the link).
// fix3 2026-10-10: Dropbox hands Safari an HTML preview page instead of the file, so for real GET requests the
// share link is resolved server-side to its direct file URL (desktop_direct_url) and every browser is sent
// there. If that lookup fails: Safari is streamed through the proxy below (it would get HTML otherwise), the
// rest get the share link as before. HEAD (monitors) keeps the plain share link, no lookup.
$__ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$__safari = (bool)preg_match('~Safari/~', $__ua) && !preg_match('~Chrome|Chromium|CriOS|FxiOS|EdgiOS|Edg/|OPR/|Firefox|Android~', $__ua);
$__direct = '';
if ($cfg['keepName'] && $method !== 'HEAD') {
    $__direct = desktop_direct_url($cfg['url']);
    if ($__direct === '' && $__safari) $cfg['keepName'] = false;
}
if ($cfg['keepName']) {
    if ($method !== 'HEAD') desktop_record_download($os);
    header('Location: ' . ($__direct !== '' ? $__direct : $cfg['url']), true, 302);
    if ($method !== 'HEAD') {
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        if (!$__dl_already_converted) {
            reddit_capi_download($os, $_GET['cid'] ?? '');
            pinterest_capi_download($os, $_GET['cid'] ?? '');
            try { nv_x_capi_download($os, $_GET['cid'] ?? ''); } catch (Throwable $e) { error_log('x_capi_download: ' . $e->getMessage()); }
        }
    }
    exit;
}

// Proxy mode: stream the file through the site under the custom name.
$name = desktop_proxy_filename($os, $cfg);
$asciiName = preg_replace('/[^\x20-\x7E]/', '_', str_replace('"', '', $name));
$disposition = 'attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($name);

if ($method === 'HEAD') {
    header('Content-Type: ' . $specs[$os]['mime']);
    header('Content-Disposition: ' . $disposition);
    exit;
}

desktop_record_download($os);

set_time_limit(0);
while (ob_get_level() > 0) ob_end_clean();

$state = ['started' => false];
$ch = curl_init($cfg['url']);
curl_setopt_array($ch, [
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 8,
    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_USERAGENT => 'NeuravisionDownloadProxy/1.0',
    CURLOPT_WRITEFUNCTION => function ($h, $data) use (&$state, $specs, $os, $disposition) {
        // Headers go out only once the upstream answered 200 with a body; nothing is sent before that.
        if (!$state['started']) {
            if ((int)curl_getinfo($h, CURLINFO_RESPONSE_CODE) !== 200) return 0;
            $state['started'] = true;
            header('Content-Type: ' . $specs[$os]['mime']);
            header('Content-Disposition: ' . $disposition);
            $len = (int)curl_getinfo($h, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
            if ($len > 0) header('Content-Length: ' . $len);
            header('Content-Transfer-Encoding: binary');
            header('X-Content-Type-Options: nosniff');
        }
        echo $data;
        return strlen($data);
    },
]);
curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if (!$state['started']) {
    error_log('desktop_download proxy failed (' . $os . '): ' . ($err !== '' ? $err : 'upstream not 200'));
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'upstream_unavailable', 'os' => $os]);
} else {
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    if (!$__dl_already_converted) {
        reddit_capi_download($os, $_GET['cid'] ?? '');
        pinterest_capi_download($os, $_GET['cid'] ?? '');
        try { nv_x_capi_download($os, $_GET['cid'] ?? ''); } catch (Throwable $e) { error_log('x_capi_download: ' . $e->getMessage()); }
    }
}
exit;
