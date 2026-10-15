<?php
/**
 * Telegram webhook for the owner's support replies (2026-10-10).
 * Reached only via the secret file name (api/support_tg_<secret>.php) AND the
 * X-Telegram-Bot-Api-Secret-Token header; updates are accepted only from TG_CHAT_ID.
 *
 * Owner commands (in the bot):
 *   reply (Telegram "Reply") to a "💬 Поддержка #N" message  -> answer the visitor
 *   /r N текст      -> answer dialog N
 *   /ai N           -> let the AI answer again in dialog N
 *   /help
 */
require_once __DIR__ . '/../functions/support_lib.php';

$cfg = sup_cfg();
$hdr = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
$self = basename(__FILE__, '.php');
if (empty($cfg['header']) || empty($cfg['path']) || $self !== 'support_tg_' . $cfg['path']
    || !hash_equals((string)$cfg['header'], $hdr) || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(404);
    exit;
}

$upd = json_decode((string)file_get_contents('php://input'), true);
http_response_code(200);
header('Content-Type: application/json');
echo '{"ok":true}';
if (!is_array($upd)) exit;

$owner = (string)sup_env('TG_CHAT_ID');
$msg = $upd['message'] ?? null;
if (!is_array($msg) || (string)($msg['chat']['id'] ?? '') !== $owner || (string)($msg['from']['id'] ?? '') !== $owner) {
    sup_log('webhook ignored update=' . (int)($upd['update_id'] ?? 0) . ' (not owner / not a message)');
    exit;
}

// Hand the 200 back to Telegram first; translation can take a few seconds.
if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
ignore_user_abort(true);

try {
    $db = sup_db();
    $uid = (int)($upd['update_id'] ?? 0);
    $ins = $db->prepare('INSERT OR IGNORE INTO tg_updates(update_id, ts) VALUES(?,?)');
    $ins->execute([$uid, time()]);
    if ($ins->rowCount() === 0) exit;   // Telegram retry of an update we already handled

    $text = trim((string)($msg['text'] ?? ''));
    $ownerMid = (int)$msg['message_id'];
    $dialogId = 0; $body = '';

    if (preg_match('#^/ai(?:@\w+)?\s+\#?(\d+)\s*$#u', $text, $m)) {
        $d = sup_dialog((int)$m[1]);
        if (!$d) { sup_tg_owner('⚠️ Диалог #' . (int)$m[1] . ' не найден', 0, 'cmd_ai', $ownerMid); exit; }
        $db->prepare('UPDATE dialogs SET ai_muted_until=0, escalated=0 WHERE id=?')->execute([(int)$d['id']]);
        sup_tg_owner('🤖 AI снова отвечает в диалоге #' . (int)$d['id'], $d['id'], 'cmd_ai', $ownerMid);
        exit;
    }
    if (preg_match('#^/(help|start)\b#', $text)) {
        sup_tg_owner("Поддержка Neuravision:\n• ответьте реплаем на «💬 Поддержка #N» — ответ уйдёт посетителю (переводится на его язык)\n• /r N текст — ответить в диалог N\n• /ai N — снова включить AI в диалоге N (после вашего ответа AI молчит 30 мин)", 0, 'cmd_help', $ownerMid);
        exit;
    }
    if (preg_match('#^/r(?:@\w+)?\s+\#?(\d+)\s+(.+)$#su', $text, $m)) {
        $dialogId = (int)$m[1]; $body = trim($m[2]);
    } elseif (!empty($msg['reply_to_message']['message_id'])) {
        $st = $db->prepare('SELECT dialog_id FROM tg_map WHERE tg_message_id=?');
        $st->execute([(int)$msg['reply_to_message']['message_id']]);
        $dialogId = (int)sup_col($st);
        $body = $text;
        if (!$dialogId) {
            // Replies to other alerts of this bot (downloads, registrations...) are not support dialogs: say so once, briefly.
            if ($text !== '' && $text[0] !== '/') sup_tg_owner('⚠️ Это сообщение не относится к диалогу поддержки. Ответьте на «💬 Поддержка #N» или: /r N текст', 0, 'reply_unmapped', $ownerMid);
            exit;
        }
    } else {
        if ($text !== '' && $text[0] === '/') sup_tg_owner('Команды: /r N текст · /ai N · /help', 0, 'cmd_unknown', $ownerMid);
        exit;
    }
    if ($body === '') { sup_tg_owner('⚠️ Пустой ответ (поддерживается только текст)', $dialogId, 'reply_empty', $ownerMid); exit; }

    $r = sup_deliver_reply($dialogId, $body, 'tg');
    if (empty($r['ok'])) { sup_tg_owner('⚠️ Не доставлено: ' . ($r['error'] ?? '?') . ' (#' . $dialogId . ')', 0, 'reply_fail', $ownerMid); exit; }
    $ago = time() - (int)$r['last_seen'];
    $state = $r['online'] ? '🟢 посетитель на сайте' : ('⚪️ не на сайте ' . nv_alert_dur($ago));
    $mail = '';
    if ($r['emailed'] !== null) $mail = "\n📧 письмо на " . $r['email'] . ': ' . (!empty($r['emailed']['ok']) ? 'отправлено' : 'ошибка ' . ($r['emailed']['error'] ?? '?'));
    elseif (!$r['online'] && $r['email'] === '') $mail = "\n📭 email неизвестен (не залогинен) — увидит при следующем визите";
    elseif ($r['online'] && $r['email'] !== '') $mail = "\n📧 если не прочитает за 5 мин — уйдёт письмо на " . $r['email'];
    $conf = ($r['failed'] ? '⚠️ доставлено БЕЗ перевода (перевод не удался), по-русски: ' : '✅ доставлено (' . $r['lang'] . '): ') . $r['text']
          . "\n#" . $dialogId . ' · ' . $state . ' · AI молчит 30 мин (/ai ' . $dialogId . ')' . $mail;
    sup_tg_owner($conf, $dialogId, 'reply_confirm', $ownerMid);
    sup_sweep_emails();
} catch (Throwable $e) {
    sup_log('webhook error: ' . substr($e->getMessage(), 0, 200));
}
