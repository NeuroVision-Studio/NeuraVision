<?php
/**
 * First-touch twclid capture (X / Twitter Ads click id).
 *
 * track.php reads $_COOKIE['lx_twclid'] but nothing ever wrote it, so twclid was NULL for all
 * sessions. This mirrors the existing utm_source/utm_campaign/utm_medium -> lx_src/lx_camp/lx_aud
 * capture in lp/lp_common.php: 30-day, first-touch (does NOT overwrite an existing value).
 *
 * The cookie name lx_twclid matches the tracker; functions/x_capi.php and functions/x_ads_event.php
 * read it (with a `twclid` fallback) for the server-side X Conversions API.
 *
 * Hook: call nv_twclid_capture() early, before output, on any entry point that can receive the ad
 * landing URL (lp/lp_common.php, index.php). Must run before headers are sent.
 */
if (!function_exists('nv_twclid_capture')) {
    function nv_twclid_capture(): void
    {
        // X "ref" links carry the click id as tw_click (?ref=nNNN&tw=<pixel>&tw_click=<id>): use it when twclid is absent. 2026-10-10 xads
        $src = !empty($_GET['twclid']) ? $_GET['twclid'] : ($_GET['tw_click'] ?? '');
        if (headers_sent() || !is_string($src) || $src === '' || !empty($_COOKIE['lx_twclid'])) {
            return;
        }
        $val = substr($src, 0, 128);
        // twclid is URL-safe base64-ish: letters, digits, '-' and '_'.
        if (!preg_match('/^[A-Za-z0-9_-]{5,128}$/', $val)) {
            return;
        }
        setcookie('lx_twclid', $val, ['expires' => time() + 2592000, 'path' => '/', 'samesite' => 'Lax']);
        $_COOKIE['lx_twclid'] = $val;
    }
}
