<?php
/**
 * POST /api/desktop_email_link.php  — "send me the download link by email".
 *
 * Mobile visitors (iOS/Android) can't do anything useful with a .exe/.dmg, so instead of
 * pushing the binary at them the download UI offers to mail them the link for later.
 * Body (form-encoded or JSON): email, os=windows|macos, website (honeypot), page (optional).
 * Replies JSON {ok:true} or {ok:false,error:"<code>",message:"<human text>"}.
 */
require_once __DIR__ . '/../functions/welcome_mail.php';
require_once __DIR__ . '/../functions/desktop_release.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function dlm_fail($code, $msg, $http = 400) {
    http_response_code($http);
    echo json_encode(['ok' => false, 'error' => $code, 'message' => $msg]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    dlm_fail('method', 'Use POST.', 405);
}

$in = $_POST;
if (!$in) {
    $raw = file_get_contents('php://input');
    $j = json_decode((string)$raw, true);
    if (is_array($j)) $in = $j;
}

// Honeypot: real people never fill a hidden field in.
if (trim((string)($in['website'] ?? '')) !== '') {
    echo json_encode(['ok' => true]); // look successful to the bot, do nothing
    exit;
}

$email = strtolower(trim((string)($in['email'] ?? '')));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
    dlm_fail('email', 'Please enter a valid email address.');
}

$os = strtolower(trim((string)($in['os'] ?? '')));
if (!in_array($os, ['windows', 'macos'], true)) $os = 'windows';

$page = substr(preg_replace('/[^\x20-\x7E]/', '', (string)($in['page'] ?? '')), 0, 200);

// --- rate limit: 5 per IP per 15 min, 3 per email per hour (file-backed, no schema needed) ---
$salt    = getenv('LXTRACK_IP_SALT') ?: 'nv-dl-mail';
$ip      = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ipHash  = substr(hash('sha256', $salt . '|' . $ip), 0, 32);
$logPath = __DIR__ . '/../data/desktop_email_links.log';
$now     = time();
$ipHits = $mailHits = 0;
if (is_readable($logPath)) {
    $fh = @fopen($logPath, 'r');
    if ($fh) {
        while (($line = fgets($fh)) !== false) {
            $row = json_decode(trim($line), true);
            if (!is_array($row) || empty($row['t'])) continue;
            $age = $now - (int)$row['t'];
            if ($age < 900  && ($row['ip'] ?? '') === $ipHash) $ipHits++;
            if ($age < 3600 && ($row['email'] ?? '') === $email) $mailHits++;
        }
        fclose($fh);
    }
}
if ($ipHits >= 5 || $mailHits >= 3) {
    dlm_fail('rate', 'Too many requests. Please try again in a few minutes.', 429);
}

// --- build the link ---
$info  = function_exists('desktop_release_info') ? desktop_release_info() : [];
$ready = !empty($info[$os]['available']);
if (!$ready) {
    dlm_fail('not_available', 'That build is not available right now. Please try the other platform.', 503);
}
$link  = 'https://neuracreation.com/api/desktop_download.php?os=' . urlencode($os);
// Landing path 3 (download without registration, 2026-10-10): the link opens the path-3 download page
// (dl=1 -> path 3, invisible captcha, install guide) -- never the signup. No account is needed to ask.
$p3 = (string)($in['p3'] ?? '') === '1';
if ($p3) {
    $lg = preg_replace('/[^a-z]/', '', strtolower((string)($in['lang'] ?? '')));
    $link = 'https://neuracreation.com/?dl=1&dlos=' . urlencode($os) . '&utm_source=email&utm_medium=dl_link'
          . (in_array($lg, ['de', 'es', 'fr', 'it', 'pt', 'ja', 'ko', 'zh'], true) ? '&lang=' . $lg : '');
}
$label = $os === 'macos' ? 'macOS (.dmg)' : 'Windows (.exe)';
$linkH = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');

$subject = 'Your Neuravision Desktop download link';
$html = <<<HTML
<!doctype html><html><body style="margin:0;padding:0;background:#0b0b0f;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0b0b0f;padding:28px 12px;">
 <tr><td align="center">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#14141b;border-radius:14px;padding:28px;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#eceaf3;">
   <tr><td style="font-size:20px;font-weight:700;padding-bottom:10px;">Neuravision Desktop</td></tr>
   <tr><td style="font-size:15px;line-height:1.6;color:#b9b6c8;padding-bottom:20px;">
     Here is your download link for <strong style="color:#eceaf3;">{$label}</strong>.
     Open it on your computer &mdash; the installer takes about a minute.
   </td></tr>
   <tr><td style="padding-bottom:18px;">
     <a href="{$linkH}" style="display:inline-block;background:#ff6a1f;color:#fff;text-decoration:none;font-weight:700;font-size:16px;padding:13px 26px;border-radius:10px;">Download for {$label}</a>
   </td></tr>
   <tr><td style="font-size:13px;line-height:1.6;color:#8d8a9c;padding-bottom:6px;">
     If the button does not work, copy this address into your browser:<br>
     <span style="color:#b9b6c8;word-break:break-all;">{$linkH}</span>
   </td></tr>
   <tr><td style="font-size:12px;color:#6f6c7d;padding-top:18px;border-top:1px solid #272735;">
     You asked for this link on neuracreation.com. If that wasn&rsquo;t you, just ignore this email.
   </td></tr>
  </table>
 </td></tr>
</table>
</body></html>
HTML;

$res = resend_send_email($email, $subject, $html, defined('FROM_NOREPLY') ? FROM_NOREPLY : null);

@file_put_contents($logPath, json_encode([
    't' => $now, 'email' => $email, 'os' => $os, 'ip' => $ipHash,
    'page' => $page, 'p3' => $p3 ? 1 : 0, 'sent' => !empty($res['ok']), 'id' => $res['id'] ?? null,
]) . "\n", FILE_APPEND | LOCK_EX);

if (empty($res['ok'])) {
    dlm_fail('send', 'We could not send the email right now. Please try again in a minute.', 502);
}

echo json_encode(['ok' => true, 'os' => $os]);
