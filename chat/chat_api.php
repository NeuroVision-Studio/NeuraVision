<?php
/**
 * Neuravision AI Support Chat API
 * - AI responses via xAI (OpenAI-compatible)
 * - 2026-10-10: every visitor message (+ AI answer) goes to the owner's Telegram bot with a Russian
 *   translation; the owner replies from the bot (api/support_tg_*.php webhook). Dialogs live in
 *   data/support.sqlite (functions/support_lib.php). Honeypot + per-IP rate limit.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ==================== CONFIG ====================
// Secrets moved to .env (2026-06-21). Loader defines lx_env_value().
require_once __DIR__ . '/../includes/x_ads_config.php';
require_once __DIR__ . '/../functions/support_lib.php';
define('OPENAI_API_KEY', lx_env_value('XAI_API_KEY'));
define('OPENAI_API_BASE', lx_env_value('XAI_API_BASE') ?: 'https://api.x.ai/v1');
define('OPENAI_MODEL', lx_env_value('XAI_MODEL') ?: 'grok-3');

define('TG_BOT_TOKEN', lx_env_value('TG_BOT_TOKEN'));
define('TG_CHAT_ID', lx_env_value('TG_CHAT_ID'));

define('KNOWLEDGE_FILE', __DIR__ . '/knowledge.json');

// ==================== HELPERS ====================

function getGeoIP($ip) {
    if ($ip === '127.0.0.1' || $ip === '::1') {
        return ['country' => 'Local', 'city' => 'Localhost'];
    }
    $url = "http://ip-api.com/json/{$ip}?fields=status,country,city,regionName,isp";
    $ctx = stream_context_create(['http' => ['timeout' => 3]]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp) {
        $data = json_decode($resp, true);
        if ($data && $data['status'] === 'success') {
            return [
                'country' => $data['country'] ?? 'Unknown',
                'city' => $data['city'] ?? 'Unknown',
                'region' => $data['regionName'] ?? '',
                'isp' => $data['isp'] ?? ''
            ];
        }
    }
    return ['country' => 'Unknown', 'city' => 'Unknown'];
}

function getUserIP() {
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = trim(explode(',', $_SERVER[$header])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function loadKnowledge() {
    if (file_exists(KNOWLEDGE_FILE)) {
        return json_decode(file_get_contents(KNOWLEDGE_FILE), true);
    }
    return [];
}

function buildSystemPrompt($knowledge) {
    $k = json_encode($knowledge, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return <<<PROMPT
You are Neuravision Support Assistant — a friendly, helpful AI support agent for the Neuravision content creation platform.

Your role:
1. Answer questions about Neuravision product, features, pricing, and technical details
2. Help users troubleshoot common issues
3. Be concise, friendly, and professional
4. If you cannot answer a question or the user has a complex technical issue, suggest calling a human operator

Rules:
- Answer ONLY based on the knowledge base provided below
- If you don't know the answer, say "I'm not sure about that. Would you like me to connect you with a human operator?"
- Keep responses short (2-4 sentences max)
- Use the user's language (if they write in Russian, respond in Russian; English by default)
- Never make up information not in the knowledge base
- If user asks to speak to a human/operator, respond with exactly: [ESCALATE_TO_OPERATOR]
- If user seems frustrated or you can't help after 2 attempts, suggest operator escalation
- Messages in the history that start with "[Human support]" were written by a human team member — stay consistent with them

Knowledge Base:
{$k}
PROMPT;
}

function callOpenAI($messages) {
    if (sup_provider_down('chat_xai')) return null;   // credits exhausted / key rejected: don't add latency
    $url = OPENAI_API_BASE . '/chat/completions';
    $payload = json_encode([
        'model' => OPENAI_MODEL,
        'messages' => $messages,
        'max_tokens' => 300,
        'temperature' => 0.7
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENAI_API_KEY
        ],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$resp || $httpCode !== 200) {
        if (in_array((int)$httpCode, [401, 402, 403], true)) sup_provider_mark_down('chat_xai', 600);
        sup_log('chat_ai http=' . (int)$httpCode);
        return null;
    }

    $data = json_decode($resp, true);
    return $data['choices'][0]['message']['content'] ?? null;
}

function chatOut($arr, $code = 200) {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
}

// ==================== MAIN ====================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'POST required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !is_string($input['message'] ?? null) || trim($input['message']) === '') {
    echo json_encode(['error' => 'Message required']);
    exit;
}

$userMessage = trim($input['message']);
if (mb_strlen($userMessage, 'UTF-8') > 2000) $userMessage = mb_substr($userMessage, 0, 2000, 'UTF-8');
$userIP = getUserIP();

// ===== CAREERS APPLICATION - send directly to Telegram (legacy path, unchanged) =====
if (!empty($input['is_careers_application'])) {
    $geo = getGeoIP($userIP);
    $resumeUrl = $input['resume_url'] ?? '';
    $tgText = $userMessage;
    $tgText .= "\n\n🌍 Geo: " . ($geo['city'] ?? 'Unknown') . ", " . ($geo['country'] ?? 'Unknown');
    $tgText .= "\n🔗 IP: " . $userIP;
    if ($resumeUrl) $tgText .= "\n\n📎 Resume: " . $resumeUrl;
    sup_tg('sendMessage', ['chat_id' => TG_CHAT_ID, 'text' => $tgText]);
    echo json_encode([
        'reply' => 'Your application has been received and forwarded to our team!',
        'session_id' => $input['session_id'] ?? '',
        'escalated' => true
    ]);
    exit;
}

// ===== Honeypot: bots fill the hidden field. Pretend success, store/forward nothing. =====
if (!empty($input['hp'])) {
    sup_log('honeypot ip=' . substr(hash('sha256', $userIP), 0, 10));
    chatOut(['reply' => null, 'session_id' => bin2hex(random_bytes(16)), 'escalated' => false, 'human_mode' => true]);
    exit;
}

try {
    $db = sup_db();
    $ipKey = 'ip:' . hash('sha256', $userIP . '|nvsup');
    if (!sup_rate_ok($ipKey . ':m', 6, 60) || !sup_rate_ok($ipKey . ':h', 40, 3600)) {
        chatOut(['error' => 'rate_limited', 'reply' => null, 'retry_after' => 60], 429);
        exit;
    }

    // ---- dialog
    $now = time();
    $page = is_string($input['page'] ?? null) ? mb_substr($input['page'], 0, 500, 'UTF-8') : (string)($_SERVER['HTTP_REFERER'] ?? '');
    if ($page !== '' && !preg_match('#^https?://#i', $page)) $page = '';
    $uiLang = sup_norm_lang($input['lang'] ?? ($_COOKIE['lx-language'] ?? ''));
    if ($uiLang === '' && !empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) $uiLang = sup_norm_lang(explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE'])[0]);
    $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400, 'UTF-8');
    $u = sup_logged_in_user();

    $dlg = sup_dialog_by_token($input['session_id'] ?? '');
    if (!$dlg) {
        $tok = bin2hex(random_bytes(16));
        $db->prepare('INSERT INTO dialogs(token, created_at, updated_at, last_seen, ui_lang, user_id, email, ip, page, ua) VALUES(?,?,?,?,?,?,?,?,?,?)')
           ->execute([$tok, $now, $now, $now, $uiLang, $u ? (int)$u['id'] : null, $u ? (string)$u['email'] : null, $userIP, $page, $ua]);
        $dlg = sup_dialog_by_token($tok);
    } else {
        $db->prepare('UPDATE dialogs SET last_seen=?, ui_lang=COALESCE(NULLIF(?,\'\'), ui_lang), ip=?, page=COALESCE(NULLIF(?,\'\'), page), ua=?,
                      user_id=COALESCE(?, user_id), email=COALESCE(?, email) WHERE id=?')
           ->execute([$now, $uiLang, $userIP, $page, $ua, $u ? (int)$u['id'] : null, $u ? (string)$u['email'] : null, (int)$dlg['id']]);
        $dlg = sup_dialog($dlg['id']);
    }
    $did = (int)$dlg['id'];
    if (!sup_rate_ok('dlg:' . $did, 30, 600)) {
        chatOut(['error' => 'rate_limited', 'reply' => null, 'retry_after' => 60], 429);
        exit;
    }

    // History BEFORE this message (for the AI context)
    $hist = $db->prepare("SELECT role, text FROM messages WHERE dialog_id=? AND role IN ('user','ai','human') ORDER BY id DESC LIMIT 10");
    $hist->execute([$did]);
    $history = array_reverse($hist->fetchAll());

    $userMsgId = sup_add_msg($did, 'user', $userMessage);
    $replyLang = sup_norm_lang($dlg['lang'] ?? '') ?: ($uiLang ?: 'en');

    $muted = (int)$dlg['ai_muted_until'] > $now;
    $flags = ['operator' => false, 'ai_failed' => false, 'muted' => $muted];
    $aiReply = null; $aiMsgId = 0;

    // Explicit operator request (same keywords as before)
    $operatorKeywords = ['operator', 'оператор', 'human', 'человек', 'support', 'поддержка', 'менеджер', 'manager', 'помощь человека', 'live chat', 'real person'];
    $isOperatorRequest = false;
    foreach ($operatorKeywords as $kw) {
        if (stripos($userMessage, $kw) !== false) { $isOperatorRequest = true; break; }
    }

    if ($muted) {
        // Human is handling this dialog: no AI answer.
    } elseif ($isOperatorRequest && !(int)$dlg['escalated']) {
        $flags['operator'] = true;
        $aiReply = sup_str('operator', $replyLang);
        $db->prepare('UPDATE dialogs SET escalated=1, ai_muted_until=? WHERE id=?')->execute([$now + SUP_AI_MUTE_SEC, $did]);
    } else {
        $aiMessages = [['role' => 'system', 'content' => buildSystemPrompt(loadKnowledge())]];
        foreach ($history as $m) {
            $aiMessages[] = ['role' => $m['role'] === 'user' ? 'user' : 'assistant', 'content' => ($m['role'] === 'human' ? '[Human support] ' : '') . $m['text']];
        }
        $aiMessages[] = ['role' => 'user', 'content' => $userMessage];
        $aiReply = callOpenAI($aiMessages);
        if (!$aiReply) {
            // AI unavailable: the owner gets the message in Telegram anyway, so promise a human reply.
            $flags['ai_failed'] = true;
            $aiReply = sup_str('queued', $replyLang);
        } elseif (strpos($aiReply, '[ESCALATE_TO_OPERATOR]') !== false) {
            $flags['operator'] = true;
            $aiReply = trim(str_replace('[ESCALATE_TO_OPERATOR]', '', $aiReply)) ?: sup_str('operator', $replyLang);
            $db->prepare('UPDATE dialogs SET escalated=1, ai_muted_until=? WHERE id=?')->execute([$now + SUP_AI_MUTE_SEC, $did]);
        }
    }
    // Widget cached by Cloudflare/browser from before 2026-10-10 (no 'page' field, no polling): never leave it without an answer.
    $legacy = !array_key_exists('page', $input);
    if ($legacy) $flags['legacy'] = true;
    if ($aiReply === null && $legacy) $aiReply = sup_str('queued', $replyLang);
    if ($aiReply !== null) $aiMsgId = sup_add_msg($did, 'ai', $aiReply);

    $dlg = sup_dialog($did);
    chatOut([
        'reply' => $aiReply,
        'session_id' => $dlg['token'],
        'escalated' => (bool)$dlg['escalated'],
        'human_mode' => $aiReply === null,
        'user_msg_id' => $userMsgId,
        'ai_msg_id' => $aiMsgId ?: null,
    ]);

    // After the visitor has the answer: translate + forward to the owner's Telegram.
    $fwd = function () use ($did, $userMsgId, $aiMsgId, $flags) {
        sup_forward_to_owner($did, $userMsgId, $aiMsgId, $flags);
    };
    if (function_exists('nv_alert_defer')) nv_alert_defer($fwd); else register_shutdown_function($fwd);
} catch (Throwable $e) {
    error_log('chat_api support: ' . $e->getMessage());
    if (!headers_sent()) http_response_code(200);
    echo json_encode(['reply' => "Sorry, I'm having connection issues. Please try again or email us at support@neuracreation.com", 'session_id' => $input['session_id'] ?? null]);
}
