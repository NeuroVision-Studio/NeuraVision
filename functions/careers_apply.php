<?php
/**
 * Neuravision — careers application receiver.
 * Replaces the missing api.<domain>/careers-apply endpoint. Forwards each
 * application (and the attached resume) to the owner's Telegram. 2026-07-18.
 */
header('Content-Type: application/json; charset=UTF-8');

$BOT  = getenv('TG_BOT_TOKEN') ?: '';
$CHAT = getenv('TG_CHAT_ID')   ?: '';

function jexit($status, $msg = '') { echo json_encode(['status' => $status, 'message' => $msg]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jexit('error', 'POST only');

$g = function ($k) { return trim((string)($_POST[$k] ?? '')); };
$first = $g('first_name'); $last = $g('last_name');
$name  = trim($first . ' ' . $last);
$email = $g('email');
$vacancy = $g('vacancy');
$twitter = $g('twitter');
$phone = $g('phone');
$telegram = $g('telegram');
$no_tg = $g('no_telegram');
$contact_other = $g('contact_other');
$relocation = $g('relocation');

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jexit('error', 'Please provide your name and a valid email.');
}

$ip = '';
foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $h) {
    if (!empty($_SERVER[$h])) { $ip = trim(explode(',', $_SERVER[$h])[0]); break; }
}
$esc = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

$relMap = ['already' => 'Already based in location', 'yes' => 'Can relocate', 'no' => 'Remote only'];
$rel = isset($relMap[$relocation]) ? $relMap[$relocation] : ($relocation !== '' ? $relocation : '—');
$tg  = ($no_tg === '1')
     ? ('No Telegram — ' . ($contact_other !== '' ? $contact_other : 'n/a'))
     : ($telegram !== '' ? $telegram : '—');

$text = "💼 <b>New Job Application — Neuravision</b>\n\n"
      . "<b>Position:</b> " . $esc($vacancy !== '' ? $vacancy : '—') . "\n"
      . "<b>Name:</b> " . $esc($name) . "\n"
      . "<b>Email:</b> " . $esc($email) . "\n"
      . "<b>Phone:</b> " . $esc($phone !== '' ? $phone : '—') . "\n"
      . "<b>Telegram:</b> " . $esc($tg) . "\n"
      . ($twitter !== '' ? ("<b>X / Twitter:</b> " . $esc($twitter) . "\n") : '')
      . "<b>Relocation:</b> " . $esc($rel) . "\n"
      . "<b>IP:</b> <code>" . $esc($ip) . "</code>\n"
      . "<b>Time:</b> " . gmdate('Y-m-d H:i:s') . " UTC";

$api = 'https://api.telegram.org/bot' . $BOT;
$tg_post = function ($url, $fields) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_POSTFIELDS => $fields]);
    $r = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$c, $r];
};

list($code, $resp) = $tg_post($api . '/sendMessage', http_build_query([
    'chat_id' => $CHAT, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true,
]));
$ok = ($code === 200 && strpos((string)$resp, '"ok":true') !== false);

// Attach the resume if one was uploaded (<= 6MB).
if (!empty($_FILES['resume']) && is_uploaded_file($_FILES['resume']['tmp_name'])
    && $_FILES['resume']['size'] > 0 && $_FILES['resume']['size'] <= 6 * 1024 * 1024) {
    $fname = $_FILES['resume']['name'] ?: 'resume';
    $cf = new CURLFile($_FILES['resume']['tmp_name'], $_FILES['resume']['type'] ?: 'application/octet-stream', $fname);
    $tg_post($api . '/sendDocument', [
        'chat_id' => $CHAT, 'caption' => '📎 Resume — ' . $name . ' (' . $vacancy . ')', 'document' => $cf,
    ]);
}

if ($ok) jexit('success');
jexit('error', 'Could not submit right now. Please email careers@neuracreation.com.');
