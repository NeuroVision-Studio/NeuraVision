<?php
/**
 * Neuravision support chat <-> owner's Telegram bot, with translation. 2026-10-10.
 *  - dialogs/messages live in data/support.sqlite (nginx: /data/ is denied)
 *  - webhook secrets live in data/support_secret.json (640 www)
 *  - translation: xAI (the chat AI provider) -> Venice -> Google (clients5) -> MyMemory
 * Never logs/prints the bot token or API keys.
 */
if (!defined('SUP_LIB_LOADED')) {
define('SUP_LIB_LOADED', 1);

require_once __DIR__ . '/../includes/x_ads_config.php';   // lx_env_value()
require_once __DIR__ . '/../api/nv_notify.php';           // nv_api_env() (api/.env: VENICE_API_KEY) + nv_alert_ctx helpers

define('SUP_ROOT', dirname(__DIR__));
define('SUP_DB_FILE', SUP_ROOT . '/data/support.sqlite');
define('SUP_SECRET_FILE', SUP_ROOT . '/data/support_secret.json');
define('SUP_LOG', SUP_ROOT . '/data/support_tg.log');
define('SUP_AI_MUTE_SEC', 1800);      // AI stays silent 30 min after the owner replied
define('SUP_OFFLINE_SEC', 300);       // visitor considered gone after 5 min without a poll

function sup_env($k) {
    $v = function_exists('lx_env_value') ? (string)lx_env_value($k) : '';
    if ($v === '' && function_exists('nv_api_env')) $v = (string)nv_api_env($k);
    return $v;
}

function sup_log($line) {
    @file_put_contents(SUP_LOG, gmdate('c') . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

function sup_cfg() {
    static $c = null;
    if ($c === null) {
        $c = is_file(SUP_SECRET_FILE) ? json_decode((string)@file_get_contents(SUP_SECRET_FILE), true) : [];
        if (!is_array($c)) $c = [];
    }
    return $c;
}

/* ------------------------------------------------------------------ DB */
function sup_db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $new = !is_file(SUP_DB_FILE);
    $pdo = new PDO('sqlite:' . SUP_DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_TIMEOUT, 5);
    $pdo->exec('PRAGMA busy_timeout=5000');
    if ($new) { $pdo->exec('PRAGMA journal_mode=WAL'); @chmod(SUP_DB_FILE, 0640); }
    $pdo->exec("CREATE TABLE IF NOT EXISTS dialogs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        token TEXT UNIQUE NOT NULL,
        created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, last_seen INTEGER NOT NULL DEFAULT 0,
        lang TEXT, ui_lang TEXT, user_id INTEGER, email TEXT, ip TEXT, country TEXT, page TEXT, ua TEXT,
        escalated INTEGER NOT NULL DEFAULT 0, ai_muted_until INTEGER NOT NULL DEFAULT 0,
        is_test INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT 'open')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        dialog_id INTEGER NOT NULL, role TEXT NOT NULL, text TEXT NOT NULL, text_ru TEXT, lang TEXT,
        tr_provider TEXT, created_at INTEGER NOT NULL, tg_message_id INTEGER,
        seen_at INTEGER, emailed_at INTEGER, email_status TEXT)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_msg_dialog ON messages(dialog_id, id)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tg_map (tg_message_id INTEGER PRIMARY KEY, dialog_id INTEGER NOT NULL, created_at INTEGER NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS rl (k TEXT NOT NULL, ts INTEGER NOT NULL)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS ix_rl ON rl(k, ts)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tg_updates (update_id INTEGER PRIMARY KEY, ts INTEGER NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS kv (k TEXT PRIMARY KEY, v TEXT)");
    return $pdo;
}

/* Fetch one row/value and close the cursor at once: an open read cursor keeps a WAL snapshot, and a later
   write on the same connection then fails with SQLITE_BUSY ("database is locked") without waiting. */
function sup_one($st) { $r = $st->fetch(); $st->closeCursor(); return $r; }
function sup_col($st) { $r = $st->fetchColumn(); $st->closeCursor(); return $r; }
function sup_kv_get($k) { $s = sup_db()->prepare('SELECT v FROM kv WHERE k=?'); $s->execute([$k]); $r = sup_col($s); return $r === false ? null : $r; }
function sup_kv_set($k, $v) { sup_db()->prepare('INSERT OR REPLACE INTO kv(k,v) VALUES(?,?)')->execute([$k, (string)$v]); }

function sup_dialog_by_token($tok) {
    if (!is_string($tok) || !preg_match('/^[a-f0-9]{32}$/', $tok)) return null;
    $s = sup_db()->prepare('SELECT * FROM dialogs WHERE token=?'); $s->execute([$tok]);
    return sup_one($s) ?: null;
}
function sup_dialog($id) {
    $s = sup_db()->prepare('SELECT * FROM dialogs WHERE id=?'); $s->execute([(int)$id]);
    return sup_one($s) ?: null;
}
function sup_add_msg($dialogId, $role, $text, $extra = []) {
    $db = sup_db();
    $db->prepare('INSERT INTO messages(dialog_id, role, text, text_ru, lang, tr_provider, created_at) VALUES(?,?,?,?,?,?,?)')
       ->execute([(int)$dialogId, $role, $text, $extra['text_ru'] ?? null, $extra['lang'] ?? null, $extra['tr_provider'] ?? null, time()]);
    $id = (int)$db->lastInsertId();
    $db->prepare('UPDATE dialogs SET updated_at=? WHERE id=?')->execute([time(), (int)$dialogId]);
    return $id;
}

/** Sliding-window rate limit. Returns true when the call is allowed. */
function sup_rate_ok($key, $max, $windowSec) {
    $db = sup_db(); $now = time();
    if (mt_rand(1, 50) === 1) $db->prepare('DELETE FROM rl WHERE ts < ?')->execute([$now - 7200]);
    $s = $db->prepare('SELECT COUNT(*) FROM rl WHERE k=? AND ts>?'); $s->execute([$key, $now - $windowSec]);
    if ((int)sup_col($s) >= $max) return false;
    $db->prepare('INSERT INTO rl(k, ts) VALUES(?,?)')->execute([$key, $now]);
    return true;
}

/* ------------------------------------------------------------------ languages */
function sup_norm_lang($l) {
    $l = strtolower(trim(str_replace('_', '-', (string)$l)));
    if ($l === '' || $l === 'auto' || $l === 'und') return '';
    if (strpos($l, 'zh') === 0) return 'zh';
    if ($l === 'iw') return 'he';
    $b = explode('-', $l)[0];
    return preg_match('/^[a-z]{2,3}$/', $b) ? $b : '';
}

/** Visitor-facing canned strings (AI failure / operator request / email frame). */
function sup_str($key, $lang) {
    static $S = [
        'queued' => [
            'en' => "Thanks! Your message has been passed to our support team — a team member will reply right here in this chat shortly.",
            'ru' => "Спасибо! Ваше сообщение передано нашей команде поддержки — сотрудник ответит прямо здесь, в этом чате.",
            'de' => "Danke! Deine Nachricht wurde an unser Support-Team weitergeleitet — jemand aus dem Team antwortet dir in Kürze direkt hier im Chat.",
            'es' => "¡Gracias! Tu mensaje se ha enviado a nuestro equipo de soporte; un miembro del equipo te responderá en breve aquí mismo, en este chat.",
            'fr' => "Merci ! Votre message a été transmis à notre équipe d'assistance — un membre de l'équipe vous répondra très bientôt ici même, dans ce chat.",
            'it' => "Grazie! Il tuo messaggio è stato inoltrato al nostro team di supporto: un membro del team ti risponderà a breve proprio qui in questa chat.",
            'pt' => "Obrigado! Sua mensagem foi encaminhada à nossa equipe de suporte — alguém da equipe responderá em breve aqui mesmo neste chat.",
            'ja' => "ありがとうございます！メッセージをサポートチームに転送しました。担当者がまもなくこのチャットでご返信します。",
            'ko' => "감사합니다! 메시지가 지원팀에 전달되었습니다. 곧 담당자가 이 채팅에서 바로 답변드리겠습니다.",
            'zh' => "谢谢！您的消息已转交给我们的支持团队，工作人员很快会在此聊天中直接回复您。",
        ],
        'operator' => [
            'en' => "I've connected you with our support team. A human operator will reply right here shortly. Please describe your issue! 🙌",
            'ru' => "Я передал диалог нашей команде поддержки. Сотрудник скоро ответит прямо здесь. Опишите, пожалуйста, ваш вопрос! 🙌",
            'de' => "Ich habe dich mit unserem Support-Team verbunden. Jemand aus dem Team antwortet dir in Kürze hier im Chat. Beschreib einfach dein Anliegen! 🙌",
            'es' => "Te he conectado con nuestro equipo de soporte. Un agente te responderá aquí en breve. ¡Describe tu problema, por favor! 🙌",
            'fr' => "Je vous ai mis en relation avec notre équipe d'assistance. Un conseiller vous répondra ici très bientôt. Décrivez-nous votre problème ! 🙌",
            'it' => "Ti ho messo in contatto con il nostro team di supporto. Un operatore ti risponderà qui a breve. Descrivi pure il tuo problema! 🙌",
            'pt' => "Conectei você à nossa equipe de suporte. Um atendente responderá aqui em breve. Descreva seu problema, por favor! 🙌",
            'ja' => "サポートチームにおつなぎしました。まもなく担当者がこちらでご返信します。お困りの内容をお書きください！🙌",
            'ko' => "지원팀에 연결해 드렸습니다. 곧 상담원이 여기에서 답변드리겠습니다. 문제를 자세히 적어 주세요! 🙌",
            'zh' => "已为您转接我们的支持团队，工作人员很快会在这里回复您。请描述您遇到的问题！🙌",
        ],
        'mail_subject' => [
            'en' => 'Neuravision Support replied to your message', 'ru' => 'Поддержка Neuravision ответила на ваше сообщение',
            'de' => 'Der Neuravision-Support hat auf deine Nachricht geantwortet', 'es' => 'El soporte de Neuravision ha respondido a tu mensaje',
            'fr' => "Le support Neuravision a répondu à votre message", 'it' => 'Il supporto Neuravision ha risposto al tuo messaggio',
            'pt' => 'O suporte da Neuravision respondeu à sua mensagem', 'ja' => 'Neuravisionサポートからの返信',
            'ko' => 'Neuravision 지원팀이 메시지에 답변했습니다', 'zh' => 'Neuravision 支持团队已回复您的消息',
        ],
        'mail_intro' => [
            'en' => 'Our support team replied to your chat message:', 'ru' => 'Наша команда поддержки ответила на ваше сообщение в чате:',
            'de' => 'Unser Support-Team hat auf deine Chat-Nachricht geantwortet:', 'es' => 'Nuestro equipo de soporte ha respondido a tu mensaje del chat:',
            'fr' => "Notre équipe d'assistance a répondu à votre message dans le chat :", 'it' => 'Il nostro team di supporto ha risposto al tuo messaggio in chat:',
            'pt' => 'Nossa equipe de suporte respondeu à sua mensagem no chat:', 'ja' => 'チャットでいただいたメッセージにサポートチームが返信しました：',
            'ko' => '채팅 메시지에 지원팀이 답변했습니다:', 'zh' => '我们的支持团队已回复您在聊天中的消息：',
        ],
        'mail_cta' => [
            'en' => 'Continue the conversation', 'ru' => 'Продолжить диалог', 'de' => 'Unterhaltung fortsetzen', 'es' => 'Continuar la conversación',
            'fr' => 'Poursuivre la conversation', 'it' => 'Continua la conversazione', 'pt' => 'Continuar a conversa', 'ja' => '会話を続ける',
            'ko' => '대화 계속하기', 'zh' => '继续对话',
        ],
        'mail_you' => [
            'en' => 'Your message', 'ru' => 'Ваше сообщение', 'de' => 'Deine Nachricht', 'es' => 'Tu mensaje', 'fr' => 'Votre message',
            'it' => 'Il tuo messaggio', 'pt' => 'Sua mensagem', 'ja' => 'あなたのメッセージ', 'ko' => '보내신 메시지', 'zh' => '您的消息',
        ],
    ];
    $lang = sup_norm_lang($lang);
    return $S[$key][$lang] ?? $S[$key]['en'];
}

/* ------------------------------------------------------------------ translation */
function sup_provider_down($name) { $v = sup_kv_get('down_' . $name); return $v !== null && (int)$v > time(); }
function sup_provider_mark_down($name, $sec) { sup_kv_set('down_' . $name, time() + $sec); }

function sup_llm_providers() {
    $p = [];
    $k = sup_env('XAI_API_KEY');
    if ($k !== '') $p[] = ['name' => 'xai', 'base' => rtrim(sup_env('XAI_API_BASE') ?: 'https://api.x.ai/v1', '/'), 'key' => $k, 'model' => sup_env('XAI_MODEL') ?: 'grok-3', 'extra' => []];
    $k = sup_env('VENICE_API_KEY');
    if ($k !== '') $p[] = ['name' => 'venice', 'base' => 'https://api.venice.ai/api/v1', 'key' => $k, 'model' => 'llama-3.3-70b',
                           'extra' => ['venice_parameters' => ['include_venice_system_prompt' => false]]];
    return $p;
}

function sup_lang_name($code) {
    static $n = ['ru' => 'Russian', 'en' => 'English', 'de' => 'German', 'es' => 'Spanish', 'fr' => 'French', 'it' => 'Italian', 'pt' => 'Portuguese',
                 'ja' => 'Japanese', 'ko' => 'Korean', 'zh' => 'Chinese (Simplified)', 'tr' => 'Turkish', 'pl' => 'Polish', 'uk' => 'Ukrainian',
                 'nl' => 'Dutch', 'ar' => 'Arabic', 'hi' => 'Hindi', 'id' => 'Indonesian', 'vi' => 'Vietnamese', 'th' => 'Thai', 'he' => 'Hebrew',
                 'sv' => 'Swedish', 'cs' => 'Czech', 'ro' => 'Romanian', 'hu' => 'Hungarian', 'el' => 'Greek', 'da' => 'Danish', 'fi' => 'Finnish', 'no' => 'Norwegian'];
    return $n[$code] ?? $code;
}

function sup_llm_translate($p, $text, $target) {
    $sys = "You are a professional translator for a customer-support chat of the Neuravision AI content platform. "
         . "Detect the language of the user's text and translate it into " . sup_lang_name($target) . " naturally and idiomatically, like a native support agent would write. "
         . "Keep the tone. Do NOT translate or alter brand/product names, personal names, promo codes, coupon codes, URLs, e-mail addresses, numbers or code "
         . "(e.g. Neuravision, HOT1000 stay exactly as written). If the text is already in the target language, return it unchanged. "
         . "Return ONLY a JSON object: {\"lang\":\"<ISO 639-1 code of the source text>\",\"translation\":\"<translated text>\"}";
    $payload = array_merge(['model' => $p['model'], 'temperature' => 0.2, 'max_tokens' => 1500,
        'messages' => [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $text]]], $p['extra']);
    if ($p['name'] === 'xai') $payload['response_format'] = ['type' => 'json_object'];
    $ch = curl_init($p['base'] . '/chat/completions');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $p['key']], CURLOPT_POSTFIELDS => json_encode($payload)]);
    $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200) {
        if (in_array($code, [401, 402, 403, 429], true)) sup_provider_mark_down($p['name'], 900);
        elseif ($code === 0 || $code >= 500) sup_provider_mark_down($p['name'], 120);
        sup_log('translate ' . $p['name'] . ' http=' . $code);
        return null;
    }
    $d = json_decode((string)$r, true);
    $c = trim((string)($d['choices'][0]['message']['content'] ?? ''));
    $c = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $c);
    if (!preg_match('/\{.*\}/s', $c, $m)) return null;
    $j = json_decode($m[0], true);
    if (!is_array($j) || !isset($j['translation']) || trim((string)$j['translation']) === '') return null;
    return ['text' => trim((string)$j['translation']), 'lang' => sup_norm_lang($j['lang'] ?? ''), 'provider' => $p['name']];
}

function sup_google_translate($text, $target, $source = '') {
    $tl = $target === 'zh' ? 'zh-CN' : $target;
    $url = 'https://clients5.google.com/translate_a/t?client=dict-chrome-ex&sl=' . ($source !== '' ? urlencode($source) : 'auto') . '&tl=' . urlencode($tl);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['q' => $text]),
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12, CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/126 Safari/537.36']);
    $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200) { sup_log('translate google http=' . $code); if ($code === 429) sup_provider_mark_down('google', 600); return null; }
    $d = json_decode((string)$r, true);
    if (!is_array($d) || !isset($d[0])) return null;
    $first = $d[0];
    if (is_array($first)) { $t = (string)($first[0] ?? ''); $lang = sup_norm_lang($first[1] ?? $source); }
    else { $t = (string)$first; $lang = sup_norm_lang($source); }
    $t = html_entity_decode($t, ENT_QUOTES, 'UTF-8');
    if (trim($t) === '') return null;
    return ['text' => trim($t), 'lang' => $lang, 'provider' => 'google'];
}

function sup_mymemory_translate($text, $target, $source) {
    if ($source === '' || mb_strlen($text, 'UTF-8') > 480) return null;
    $ch = curl_init('https://api.mymemory.translated.net/get?' . http_build_query(['q' => $text, 'langpair' => $source . '|' . $target, 'de' => 'support@neuracreation.com']));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12]);
    $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $d = $code === 200 ? json_decode((string)$r, true) : null;
    $t = (string)($d['responseData']['translatedText'] ?? '');
    if ((int)($d['responseStatus'] ?? 0) !== 200 || trim($t) === '') { sup_log('translate mymemory http=' . $code); return null; }
    return ['text' => html_entity_decode(trim($t), ENT_QUOTES, 'UTF-8'), 'lang' => $source, 'provider' => 'mymemory'];
}

/**
 * Translate $text into $target. $source is a hint ('' = detect).
 * Returns ['text','lang','provider'] or null when every provider failed.
 */
function sup_translate($text, $target, $source = '') {
    $text = trim((string)$text); $target = sup_norm_lang($target) ?: 'en'; $source = sup_norm_lang($source);
    if ($text === '') return null;
    if ($source !== '' && $source === $target) return ['text' => $text, 'lang' => $source, 'provider' => 'same'];
    foreach (sup_llm_providers() as $p) {
        if (sup_provider_down($p['name'])) continue;
        $r = sup_llm_translate($p, $text, $target);
        if ($r) { if ($r['lang'] === '') $r['lang'] = $source; return $r; }
    }
    if (!sup_provider_down('google')) { $r = sup_google_translate($text, $target, $source); if ($r) return $r; }
    $r = sup_mymemory_translate($text, $target, $source);
    if ($r) return $r;
    sup_log('translate FAILED target=' . $target);
    return null;
}

/* ------------------------------------------------------------------ Telegram */
function sup_tg($method, array $params) {
    $token = sup_env('TG_BOT_TOKEN');
    if ($token === '') return null;
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
        CURLOPT_POSTFIELDS => http_build_query($params)]);
    $r = curl_exec($ch); curl_close($ch);
    return $r ? json_decode($r, true) : null;
}

/** Send to the owner; maps the resulting message_id to the dialog so a Telegram reply finds its way back. */
function sup_tg_owner($text, $dialogId, $tag, $replyTo = 0) {
    if (mb_strlen($text, 'UTF-8') > 4000) $text = mb_substr($text, 0, 3990, 'UTF-8') . '…';
    $p = ['chat_id' => sup_env('TG_CHAT_ID'), 'text' => $text, 'disable_web_page_preview' => 'true'];
    if ($replyTo) { $p['reply_to_message_id'] = (int)$replyTo; $p['allow_sending_without_reply'] = 'true'; }
    $d = sup_tg('sendMessage', $p);
    $mid = (int)($d['result']['message_id'] ?? 0);
    sup_log($tag . ' dialog=' . (int)$dialogId . ' ok=' . (!empty($d['ok']) ? 1 : 0) . ' message_id=' . ($mid ?: '-') . (empty($d['ok']) ? ' err=' . substr((string)($d['description'] ?? 'no response'), 0, 120) : ''));
    if ($mid) @file_put_contents(SUP_ROOT . '/data/support_last_tg.txt', $text, LOCK_EX);
    if ($mid && $dialogId) sup_db()->prepare('INSERT OR REPLACE INTO tg_map(tg_message_id, dialog_id, created_at) VALUES(?,?,?)')->execute([$mid, (int)$dialogId, time()]);
    return $mid;
}

/* ------------------------------------------------------------------ context line */
function sup_ua_short($ua) {
    $dev = preg_match('/iPad|Tablet/i', $ua) ? 'tablet' : (preg_match('/Mobile|iPhone|Android/i', $ua) ? 'mobile' : 'desktop');
    $os = '—';
    if (preg_match('/Windows NT ([\d.]+)/', $ua, $m)) $os = 'Windows' . ($m[1] === '10.0' ? ' 10/11' : ' ' . $m[1]);
    elseif (preg_match('/(?:iPhone|CPU) OS ([\d_]+)/', $ua, $m)) $os = 'iOS ' . str_replace('_', '.', $m[1]);
    elseif (preg_match('/Android ([\d.]+)/', $ua, $m)) $os = 'Android ' . $m[1];
    elseif (preg_match('/Mac OS X ([\d_]+)/', $ua, $m)) $os = 'macOS ' . str_replace('_', '.', $m[1]);
    elseif (stripos($ua, 'Linux') !== false) $os = 'Linux';
    $br = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/OPR\//', $ua) ? 'Opera' : (preg_match('/YaBrowser/', $ua) ? 'Yandex' : (preg_match('/CriOS|Chrome\//', $ua) ? 'Chrome'
        : (preg_match('/FxiOS|Firefox\//', $ua) ? 'Firefox' : (preg_match('/Safari\//', $ua) ? 'Safari' : '—')))));
    if (preg_match('/Instagram/', $ua)) $br = 'Instagram in-app'; elseif (preg_match('/FBAN|FBAV/', $ua)) $br = 'Facebook in-app';
    return $dev . ' · ' . $os . ' · ' . $br;
}

/** Page kind: landing / LK / main etc., plus split-arm & LK-variant cookies. */
function sup_page_kind($page) {
    $path = (string)parse_url((string)$page, PHP_URL_PATH);
    $lpv = (string)($_COOKIE['lp_v'] ?? '');
    $lk = (($_COOKIE['lk_old'] ?? '') === '1') ? 'old' : strtoupper((string)($_COOKIE['lk_ab'] ?? ''));
    if (preg_match('#^/lp/#', $path)) $k = 'лендинг ' . (preg_match('#/lp/v(\d+)#', $path, $m) ? 'v' . $m[1] : ($lpv !== '' ? 'v' . $lpv : ''));
    elseif (preg_match('#^/(lk/|account|app\b|pages/app)#', $path)) $k = 'ЛК' . ($lk !== '' ? ' ' . $lk : '');
    elseif ($path === '' || $path === '/' || $path === '/index.php') $k = 'главная';
    else $k = 'сайт';
    if ($lpv !== '' && strpos($k, 'лендинг') !== 0) $k .= ' (вход: лендинг v' . preg_replace('/\W/', '', $lpv) . ')';
    return trim($k);
}

function sup_ctx_line(array $d) {
    $u = '👤 ' . ($d['email'] ? $d['email'] . ($d['user_id'] ? ' (id ' . (int)$d['user_id'] . ')' : '') : 'гость');
    $geo = ['country' => '', 'cc' => '', 'city' => ''];
    if (function_exists('nv_alert_geo') && !empty($d['ip'])) $geo = nv_alert_geo($d['ip']);
    $g = '🌍 ' . ($geo['country'] !== '' ? $geo['country'] . ($geo['cc'] ? ' (' . $geo['cc'] . ')' : '') . ($geo['city'] ? ', ' . $geo['city'] : '') : '—');
    $page = (string)($d['page'] ?? '');
    $pshort = $page !== '' ? preg_replace('#^https?://(www\.)?neuracreation\.com#i', '', $page) : '—';
    if (mb_strlen($pshort, 'UTF-8') > 90) $pshort = mb_substr($pshort, 0, 90, 'UTF-8') . '…';
    $now = time();
    return $u . ' · ' . $g . ' · 📄 ' . ($pshort === '' ? '/' : $pshort) . ' (' . sup_page_kind($page) . ')'
         . ' · 📱 ' . sup_ua_short((string)($d['ua'] ?? ''))
         . ' · 🕒 ' . gmdate('H:i', $now) . ' UTC / ' . gmdate('H:i', $now + 3 * 3600) . ' МСК';
}

/** Signed-in user from the lx_auth_token cookie only (users.sqlite), or null. */
function sup_logged_in_user() {
    $tok = (string)($_COOKIE['lx_auth_token'] ?? '');
    if ($tok === '' || strlen($tok) > 200 || !function_exists('nv_alert_users_pdo')) return null;
    $db = nv_alert_users_pdo(); if (!$db) return null;
    try { $s = $db->prepare('SELECT id, email FROM users WHERE token = ? LIMIT 1'); $s->execute([$tok]); return $s->fetch(PDO::FETCH_ASSOC) ?: null; }
    catch (Throwable $e) { return null; }
}

/** The visitor's language for replies: last detected from their messages, else site UI cookie, else en. */
function sup_dialog_lang(array $d) {
    $l = sup_norm_lang($d['lang'] ?? '');
    if ($l === '') $l = sup_norm_lang($d['ui_lang'] ?? '');
    return $l !== '' ? $l : 'en';
}

/**
 * Forward one visitor message (+ the AI answer, if any) to the owner. Runs AFTER the response was sent.
 * $flags: ['operator' => bool, 'ai_failed' => bool, 'muted' => bool]
 */
function sup_forward_to_owner($dialogId, $userMsgId, $aiMsgId, array $flags = []) {
    $db = sup_db();
    $d = sup_dialog($dialogId); if (!$d) return;
    $um = sup_one($db->query('SELECT * FROM messages WHERE id=' . (int)$userMsgId));
    if (!$um) return;
    $tr = sup_translate($um['text'], 'ru', '');
    $lang = $tr ? $tr['lang'] : '';
    if ($tr) {
        $db->prepare('UPDATE messages SET text_ru=?, lang=?, tr_provider=? WHERE id=?')->execute([$tr['text'], $lang, $tr['provider'], (int)$userMsgId]);
        // A short "ok"/"HOT1000" says little about the language: only let longer messages override a known one.
        $letters = preg_match_all('/\p{L}/u', $um['text']);
        if ($lang !== '' && ($d['lang'] === null || $d['lang'] === '' || $letters >= 6)) {
            $db->prepare('UPDATE dialogs SET lang=? WHERE id=?')->execute([$lang, (int)$dialogId]);
        }
    }
    $first = (int)sup_col($db->query('SELECT COUNT(*) FROM messages WHERE role=\'user\' AND dialog_id=' . (int)$dialogId)) === 1;
    $hdr = '💬 Поддержка #' . (int)$dialogId . (!empty($flags['operator']) ? ' · 🚨 просит оператора' : '')
         . (!empty($d['is_test']) ? ' · [TEST]' : '') . ($first ? ' · новый диалог' : '');
    $t = $hdr . "\n"
       . 'Оригинал (' . ($lang !== '' ? $lang : '?') . '): ' . $um['text'] . "\n"
       . '🇷🇺 Перевод: ' . ($tr ? ($lang === 'ru' ? '— (написано по-русски)' : $tr['text']) : '(перевод не удался)');
    if ($aiMsgId) {
        $am = sup_one($db->query('SELECT * FROM messages WHERE id=' . (int)$aiMsgId));
        if ($am) {
            if (!empty($flags['ai_failed']) || !empty($flags['operator']) || !empty($flags['muted'])) {
                $t .= "\n🤖 " . (!empty($flags['ai_failed']) ? 'AI недоступен — ' : '') . 'посетителю показано: «ответит сотрудник»';
            } else {
                $atr = sup_translate($am['text'], 'ru', '');
                if ($atr) $db->prepare('UPDATE messages SET text_ru=?, lang=?, tr_provider=? WHERE id=?')->execute([$atr['text'], $atr['lang'], $atr['provider'], (int)$aiMsgId]);
                $al = $atr ? ($atr['lang'] ?: '?') : '?';
                $t .= "\n🤖 AI ответил (" . $al . '): ' . $am['text'] . "\n"
                    . '🇷🇺 Перевод: ' . ($atr ? ($al === 'ru' ? '—' : $atr['text']) : '(перевод не удался)');
            }
        }
    } elseif (!empty($flags['muted'])) {
        $t .= "\n🤫 AI молчит в этом диалоге (вы отвечали / оператор) — /ai " . (int)$dialogId . ' включить';
    }
    if (!empty($flags['legacy'])) $t .= "\n⚠️ у посетителя старая (кэшированная) версия виджета — ответ в чате он увидит только после обновления кэша; если залогинен, придёт письмо";
    $t .= "\nℹ️ " . sup_ctx_line($d);
    if ($first) $t .= "\n↩️ Ответьте реплаем на это сообщение или: /r " . (int)$dialogId . ' текст';
    $mid = sup_tg_owner($t, $dialogId, 'visitor_msg');
    if ($mid) $db->prepare('UPDATE messages SET tg_message_id=? WHERE id=?')->execute([$mid, (int)$userMsgId]);
}

/* ------------------------------------------------------------------ owner reply -> visitor */
function sup_send_reply_email(array $d, $msgId, $text, $lang) {
    $email = (string)$d['email'];
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'no email'];
    // resend_send_email() runs the html through nv_i18n for the *current request's* language; force 'en' (= no-op)
    // because the text is already in the visitor's language.
    $_COOKIE['lx-language'] = 'en';
    require_once SUP_ROOT . '/functions/welcome_mail.php';
    $db = sup_db();
    $last = $db->prepare("SELECT text FROM messages WHERE dialog_id=? AND role='user' AND id<? ORDER BY id DESC LIMIT 1");
    $last->execute([(int)$d['id'], (int)$msgId]); $q = (string)sup_col($last);
    $h = function ($s) { return nl2br(htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8')); };
    $page = (string)($d['page'] ?? '');
    $url = preg_match('#^https://neuracreation\.com/#', $page) ? $page : 'https://neuracreation.com/';
    $url .= (strpos($url, '?') === false ? '?' : '&') . 'support=1';
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
          . '<body style="margin:0;padding:0;background:#0a0a0f;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">'
          . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0a0f;padding:32px 16px;"><tr><td align="center">'
          . '<table width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#13131f;border-radius:16px;border:1px solid rgba(255,255,255,0.06);">'
          . '<tr><td style="padding:28px 32px;color:#e2e8f0;font-size:15px;line-height:1.6;">'
          . '<div style="font-size:18px;font-weight:700;color:#fff;margin-bottom:14px;">Neuravision Support</div>'
          . '<p style="margin:0 0 14px;">' . $h(sup_str('mail_intro', $lang)) . '</p>'
          . '<div style="background:rgba(16,185,129,0.10);border-left:3px solid #10b981;border-radius:8px;padding:14px 16px;color:#fff;margin:0 0 16px;">' . $h($text) . '</div>'
          . ($q !== '' ? '<p style="margin:0 0 18px;color:#94a3b8;font-size:13px;">' . $h(sup_str('mail_you', $lang)) . ': «' . $h(mb_substr($q, 0, 300, 'UTF-8')) . '»</p>' : '')
          . '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:#10b981;color:#fff;text-decoration:none;padding:11px 20px;border-radius:8px;font-weight:600;">'
          . $h(sup_str('mail_cta', $lang)) . '</a>'
          . '</td></tr></table></td></tr></table></body></html>';
    $r = resend_send_email($email, sup_str('mail_subject', $lang), $html, defined('FROM_EMAIL') ? FROM_EMAIL : null);
    $ok = !empty($r['ok']);
    $db->prepare('UPDATE messages SET emailed_at=?, email_status=? WHERE id=?')->execute([time(), $ok ? 'sent:' . ($r['id'] ?? '') : 'fail:' . ($r['error'] ?? ''), (int)$msgId]);
    sup_log('email dialog=' . (int)$d['id'] . ' msg=' . (int)$msgId . ' ok=' . ($ok ? 1 : 0) . ' id=' . ($r['id'] ?? '-'));
    return $r;
}

/**
 * Owner (Russian) -> visitor. Translates, stores (role 'human'), mutes AI 30 min, emails if the visitor is gone.
 * Returns ['ok','lang','text','failed','emailed','online'].
 */
function sup_deliver_reply($dialogId, $ruText, $via = 'tg') {
    $db = sup_db();
    $d = sup_dialog($dialogId);
    if (!$d) return ['ok' => false, 'error' => 'dialog not found'];
    $ruText = trim((string)$ruText);
    if ($ruText === '') return ['ok' => false, 'error' => 'empty'];
    $lang = sup_dialog_lang($d);
    $failed = false; $prov = 'same';
    if ($lang === 'ru') { $out = $ruText; }
    else {
        $tr = sup_translate($ruText, $lang, 'ru');
        if ($tr) { $out = $tr['text']; $prov = $tr['provider']; }
        else { $out = $ruText; $failed = true; $prov = 'failed'; }
    }
    $mid = sup_add_msg($dialogId, 'human', $out, ['text_ru' => $ruText, 'lang' => $failed ? 'ru' : $lang, 'tr_provider' => $prov . ':' . $via]);
    $db->prepare('UPDATE dialogs SET ai_muted_until=?, status=\'answered\' WHERE id=?')->execute([time() + SUP_AI_MUTE_SEC, (int)$dialogId]);
    $online = (time() - (int)$d['last_seen']) <= SUP_OFFLINE_SEC;
    $emailed = null;
    if (!$online && (string)$d['email'] !== '') $emailed = sup_send_reply_email($d, $mid, $out, $failed ? 'ru' : $lang);
    return ['ok' => true, 'msg_id' => $mid, 'lang' => $lang, 'text' => $out, 'failed' => $failed, 'online' => $online,
            'emailed' => $emailed, 'email' => (string)$d['email'], 'last_seen' => (int)$d['last_seen']];
}

/** Email replies the visitor never picked up (left within 5 min of the reply). Called from cron + webhook. */
function sup_sweep_emails() {
    $db = sup_db(); $now = time();
    $st = $db->prepare("SELECT m.*, d.email AS d_email FROM messages m JOIN dialogs d ON d.id=m.dialog_id
        WHERE m.role='human' AND m.seen_at IS NULL AND m.emailed_at IS NULL AND d.email IS NOT NULL AND d.email<>''
          AND m.created_at < ? AND m.created_at > ? AND d.last_seen < ? ORDER BY m.id LIMIT 20");
    $st->execute([$now - SUP_OFFLINE_SEC, $now - 3 * 86400, $now - SUP_OFFLINE_SEC]);
    $n = 0;
    foreach ($st->fetchAll() as $m) {
        $d = sup_dialog($m['dialog_id']); if (!$d) continue;
        $r = sup_send_reply_email($d, $m['id'], $m['text'], $m['lang'] ?: sup_dialog_lang($d));
        $n++;
        sup_tg_owner('📧 Поддержка #' . (int)$d['id'] . ': посетитель ушёл, ответ отправлен на ' . $d['email'] . ' — ' . (!empty($r['ok']) ? 'ok' : 'ошибка: ' . ($r['error'] ?? '?')), $d['id'], 'email_sweep');
    }
    return $n;
}

}
