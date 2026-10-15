<?php
require_once __DIR__ . '/../functions/nv_alert_ctx.php';
/** Telegram alert for new OAuth/key registrations (own keys.db, Neuravision). */
if (!function_exists('nv_api_notify_registration')) {
    function nv_api_env($key) {
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') return $_ENV[$key];
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            $f = __DIR__ . '/.env';
            if (is_file($f)) foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
                $ln = trim($ln); if ($ln === '' || $ln[0] === '#' || strpos($ln,'=') === false) continue;
                list($k,$v) = array_map('trim', explode('=', $ln, 2)); $cache[$k] = $v;
            }
        }
        return $cache[$key] ?? '';
    }
    function nv_api_notify_registration($provider, $email, $name, $userId, $landing = '') {
        try {
            $token = nv_api_env('TG_BOT_TOKEN'); $chat = nv_api_env('TG_CHAT_ID');
            if (!$token || !$chat) return;
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
            $country = 'Unknown';
            if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
                $cch = curl_init('http://ip-api.com/json/' . urlencode($ip) . '?fields=country,countryCode');
                curl_setopt_array($cch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
                $cr = curl_exec($cch); curl_close($cch);
                if ($cr) { $cd = json_decode($cr, true); if (!empty($cd['country'])) $country = $cd['country'] . (!empty($cd['countryCode']) ? ' (' . $cd['countryCode'] . ')' : ''); }
            }
            $msg = "🆕 New Neuravision registration\n"
                 . "Provider: " . $provider . "\n"
                 . "Email: " . ($email !== '' ? $email : '—') . "\n"
                 . "Name: " . ($name !== '' ? $name : '—') . "\n"
                 . "User: " . $userId . "\n"
                 . "🌍 Country: " . $country . "\n"
                 . "🩻 IP: " . $ip . "\n"
                 . "Time: " . date('c');
            nv_alert_defer(function () use ($msg, $email) {
                nv_alert_send($msg . "\n" . nv_alert_ctx(['kind' => 'registration', 'email' => (string)$email]), 'registration_oauth');
            });
            return;
            $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 6,
                CURLOPT_POSTFIELDS => http_build_query(['chat_id'=>$chat,'text'=>$msg,'disable_web_page_preview'=>true]),
            ]);
            curl_exec($ch); curl_close($ch);
        } catch (Exception $e) {}
    }
}

if (!function_exists('nv_api_notify_download')) {
    // Telegram alert for a real desktop-app download (fired from desktop_record_download()).
    // 2026-10-10: one detailed message (IP/geo/ASN, split arm, utm/click-id, device, user, session),
    // sent AFTER the 302/stream has been handed to the visitor so the download is never delayed.
    function nv_api_notify_download($os, $country, $userAgent) {
        try {
            nv_alert_defer(function () use ($os) {
                $cfg = function_exists('desktop_links_all') ? (desktop_links_all()[$os] ?? ['url' => '']) : ['url' => ''];
                $file = $cfg['url'] !== '' ? desktop_name_from_url($cfg['url']) : '';
                $size = function_exists('desktop_remote_size') ? desktop_remote_size($cfg['url']) : 0;
                $label = $os === 'macos' ? 'macOS' : ($os === 'windows' ? 'Windows' : $os);
                $msg = "🖥 Скачивание: " . $label . " · " . ($file !== '' ? $file : '—') . " · "
                     . ($size > 0 ? number_format($size / 1048576, 1) . ' МБ (' . number_format($size) . ' б)' : 'размер неизв.') . "\n"
                     . nv_alert_ctx(['kind' => 'download']);
                nv_alert_send($msg, 'download');
            });
        } catch (Throwable $e) {}
    }
}

if (!function_exists('nv_api_notify_webinar')) {
    // Telegram alert for a new webinar-popup lead (detailed, deferred). 2026-10-10
    function nv_api_notify_webinar($email, $country, $source, $whenIso, $page = '') {
        try {
            nv_alert_defer(function () use ($email, $source, $page) {
                $msg = "🎓 Заявка на вебинар: " . $email . ($source !== '' ? " · форма: " . $source : '') . "\n"
                     . nv_alert_ctx(['kind' => 'webinar', 'email' => $email, 'page' => $page]);
                nv_alert_send($msg, 'webinar');
            });
        } catch (Throwable $e) {}
    }
}
