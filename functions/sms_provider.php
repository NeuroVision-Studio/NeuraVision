<?php
/**
 * Neuravision — SMS OTP provider layer (provider-agnostic).
 * We generate the 6-digit code ourselves, send via the configured SMS provider's
 * plain "send SMS" API, and verify locally (same store pattern as the email path).
 * This works with ANY provider that can send an SMS — no dependence on their
 * proprietary "Verify" product, and easy to swap.
 *
 * Configure in .env:
 *   SMS_PROVIDER=plivo | vonage | twilio        (which one is active)
 *   Plivo:   PLIVO_AUTH_ID, PLIVO_AUTH_TOKEN, PLIVO_SRC          (src = your Plivo number or alphanumeric sender)
 *   Vonage:  VONAGE_API_KEY, VONAGE_API_SECRET, VONAGE_FROM      (from = sender id/number)
 *   Twilio:  TWILIO_SID, TWILIO_TOKEN, TWILIO_FROM               (from = your Twilio number)
 *
 * Usage from phone_verify.php:
 *   require_once __DIR__.'/sms_provider.php';
 *   lx_sms_send_code($userId, $phone);          // returns ['ok'=>bool,'error'=>?]
 *   lx_sms_check_code($userId, $code);          // returns ['ok'=>bool,'verified'=>bool,'error'=>?]
 */

if (!function_exists('lx_sms_provider')) {

function lx_sms_provider() {
    $p = strtolower((string)(function_exists('lx_env_value') ? lx_env_value('SMS_PROVIDER') : (getenv('SMS_PROVIDER') ?: '')));
    return $p ?: '';
}
function lx_sms_env($k) {
    return function_exists('lx_env_value') ? lx_env_value($k) : (getenv($k) ?: (isset($_ENV[$k]) ? $_ENV[$k] : ''));
}
function lx_sms_code_file($userId) { return sys_get_temp_dir().'/lx_scode_'.md5((string)$userId).'.json'; }

/** Low-level: send raw SMS via the active provider. Returns true on accepted-for-delivery. */
function lx_sms_send_raw($to, $text) {
    $prov = lx_sms_provider();
    $to = preg_replace('/[^0-9+]/', '', (string)$to);
    if ($to === '' || $prov === '') return false;

    if ($prov === 'plivo') {
        $id = lx_sms_env('PLIVO_AUTH_ID'); $tok = lx_sms_env('PLIVO_AUTH_TOKEN'); $src = lx_sms_env('PLIVO_SRC');
        if (!$id || !$tok || !$src) return false;
        $ch = curl_init('https://api.plivo.com/v1/Account/'.$id.'/Message/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15, CURLOPT_POST=>true,
            CURLOPT_USERPWD=>$id.':'.$tok,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
            CURLOPT_POSTFIELDS=>json_encode(['src'=>$src,'dst'=>ltrim($to,'+'),'text'=>$text]),
        ]);
        $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return $code >= 200 && $code < 300;   // Plivo returns 202 Accepted
    }

    if ($prov === 'vonage') {
        $key = lx_sms_env('VONAGE_API_KEY'); $sec = lx_sms_env('VONAGE_API_SECRET'); $from = lx_sms_env('VONAGE_FROM') ?: 'Neuravision';
        if (!$key || !$sec) return false;
        $ch = curl_init('https://rest.nexmo.com/sms/json');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15, CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>http_build_query(['api_key'=>$key,'api_secret'=>$sec,'to'=>ltrim($to,'+'),'from'=>$from,'text'=>$text]),
        ]);
        $r = curl_exec($ch); curl_close($ch);
        $d = $r ? json_decode($r, true) : null;
        return is_array($d) && isset($d['messages'][0]['status']) && $d['messages'][0]['status'] === '0';
    }

    if ($prov === 'twilio') {
        $sid = lx_sms_env('TWILIO_SID'); $tok = lx_sms_env('TWILIO_TOKEN'); $from = lx_sms_env('TWILIO_FROM');
        if (!$sid || !$tok || !$from) return false;
        $ch = curl_init('https://api.twilio.com/2010-04-01/Accounts/'.$sid.'/Messages.json');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15, CURLOPT_POST=>true,
            CURLOPT_USERPWD=>$sid.':'.$tok,
            CURLOPT_POSTFIELDS=>http_build_query(['From'=>$from,'To'=>$to,'Body'=>$text]),
        ]);
        $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return $code >= 200 && $code < 300;
    }

    if ($prov === 'telnyx') {
        $key = lx_sms_env('TELNYX_API_KEY'); $from = lx_sms_env('TELNYX_FROM');
        if (!$key || !$from) return false;
        $ch = curl_init('https://api.telnyx.com/v2/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15, CURLOPT_POST=>true,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$key],
            CURLOPT_POSTFIELDS=>json_encode(['from'=>$from,'to'=>$to,'text'=>$text]),
        ]);
        $r=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
        return $code>=200 && $code<300;
    }

    return false; // unknown/!configured -> caller falls back to email
}

/** Generate + store + send a 6-digit code to the phone. */
function lx_sms_send_code($userId, $phone) {
    if (lx_sms_provider() === '') return ['ok'=>false,'error'=>'sms_not_configured'];
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    @file_put_contents(lx_sms_code_file($userId),
        json_encode(['hash'=>password_hash($code, PASSWORD_DEFAULT),'exp'=>time()+600,'tries'=>0]), LOCK_EX);
    $ok = lx_sms_send_raw($phone, 'Your Neuravision verification code: '.$code.'. Expires in 10 min.');
    return $ok ? ['ok'=>true,'channel'=>'sms'] : ['ok'=>false,'error'=>'sms_send_failed'];
}

/** Verify the code the user entered. */
function lx_sms_check_code($userId, $code) {
    $code = preg_replace('/\D/', '', (string)$code);
    $f = lx_sms_code_file($userId);
    $rec = is_file($f) ? json_decode(@file_get_contents($f), true) : null;
    if (!$rec || ($rec['exp'] ?? 0) < time()) return ['ok'=>true,'verified'=>false,'error'=>'Code expired. Request a new one.'];
    if (($rec['tries'] ?? 0) >= 5) { @unlink($f); return ['ok'=>true,'verified'=>false,'error'=>'Too many tries. Request a new code.']; }
    if (!password_verify($code, $rec['hash'] ?? '')) {
        $rec['tries'] = ($rec['tries'] ?? 0) + 1; @file_put_contents($f, json_encode($rec), LOCK_EX);
        return ['ok'=>true,'verified'=>false,'error'=>'Incorrect code.'];
    }
    @unlink($f);
    return ['ok'=>true,'verified'=>true];
}

} // function_exists guard
