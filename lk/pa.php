<?php
/**
 * Neuravision prompt assistant v5  (drop-in package: pa/prompt-assistant.php)
 * ---------------------------------------------------------------------------
 * v5 (2026-10-09) — packaged for the NEW Neuravision site:
 *   - all deployment config lives in pa/pa.env next to this file (loaded first,
 *     then <site>/.env and <site>/api/.env as before): PA_ALLOWED_ORIGINS,
 *     PA_COOKIE_PATH, PA_PROVIDER_ORDER, ANTHROPIC_API_BASE (Claude bridge or
 *     api.anthropic.com), ANTHROPIC_API_KEY, ANTHROPIC_MODEL
 *   - provider order is configurable; default anthropic,xai,venice so the
 *     funded Claude path is tried first and dead keys do not add latency
 *   - session cookie 'secure' flag follows the real scheme (http staging works)
 *   - state files renamed neuravision-prompt.log / -limits.json
 *
 * Same split as v2 — the LLM only UNDERSTANDS the user and returns a
 * syntax-free "intent"; this file's deterministic adapter RENDERS it in the
 * dialect of the model actually selected (model-profiles.json).
 *
 * v3 changes (2026-10-08):
 *   - provider chain: xAI first, Venice (same OpenAI-compatible chat API,
 *     key the product already has) when xAI answers 401/402/403/429 — the
 *     assistant was dead for every user while the xAI team was out of credits
 *   - richer intent: shots[] (beats for clips >= 8 s), audio (ambient by default
 *     on sound-capable models, speech/music only when asked),
 *     enrichment of short-but-concrete ideas with declared assumptions, any
 *     input language -> English fields (quotes/brands verbatim)
 *   - per-model adapter: Higgsfield DoP camera presets (one move per clip),
 *     audio line only for models that render sound, beats, i2v "describe the
 *     change" rule, tags dialect with quality tags at the end, text-in-image
 *     first and quoted, negatives to a real negative_prompt or dissolved
 *   - CLI self-test: `php prompt-assistant-v2.php < cases.json` prints one
 *   - 2026-10-08 review pass (Fable 5.1 max): participle framing clauses, i2v
 *     subject, MiniMax bracket camera commands, camera de-duplication, danbooru
 *     tags + default negative for anime checkpoints, language validator with
 *     one corrective retry (lx_lang_mismatch / lx_ask)
 *     lx_adapt() result per case, so the adapter is testable without HTTP/LLM
 *   - error texts follow the UI language (context.uiLang / body.uiLang)
 *   - v4 (2026-10-09): added Anthropic (Claude) as a third provider, chained
 *     after xAI/Venice — both were out of credits/balance, so this is what
 *     actually keeps the assistant alive. Anthropic's Messages API is NOT the
 *     OpenAI chat-completions dialect the other two speak (different endpoint,
 *     auth header, request/response shape), so lx_call_llm()/lx_payload()
 *     branch on $prov['kind'] instead of assuming one wire format everywhere.
 *     Live-tested against the real contract (8 real cases via the Claude
 *     subscription, see pa_test/claude_check/): vague ideas and outright
 *     contradictions correctly come back as questions[] instead of a guess,
 *     Higgsfield camera.movement snaps to an exact preset name, i2v fields
 *     stay empty per pipeline rule, logo lighting/angle/mood stay empty.
 *     Needs a real ANTHROPIC_API_KEY in .env to go live — the CLI access used
 *     to validate this contract lives in the dev sandbox only, it is not a
 *     portable API key this server can call.
 *
 * Actions
 *   GET                -> {token}
 *   POST action=analyze -> {summary, questions[], assumptions[]}
 *   POST action=compose -> {summary, questions[], intent{}, prompt, negative_prompt,
 *                           assumptions[], adapted_for, warnings[], removed_negatives[]}
 *   POST action=adapt   -> NO LLM CALL. Re-renders a stored intent for another model.
 *
 * Error contract: 422 bad input / model refused   504 upstream timeout
 *                 503 upstream failure / no credits  429 rate limit
 *   nginx intercepts 502 only, so a 502 is never emitted here (v1 bug).
 */
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (!defined('LX_PROFILES_PATH')) define('LX_PROFILES_PATH', __DIR__ . '/model-profiles.json');

/* ------------------------------------------------------------------ *
 * CLI self-test of the adapter (functions below are hoisted by PHP):
 *   php prompt-assistant-v2.php < cases.json
 *   cases.json = [{"intent":{...},"model":"<id>","kind":"video","haystack":"...","duration":"8s","lang":"ru"}]
 * ------------------------------------------------------------------ */
if (PHP_SAPI === 'cli') {
    $in = json_decode(stream_get_contents(STDIN), true);
    if (!is_array($in)) { fwrite(STDERR, "cli: expected a JSON array on stdin\n"); exit(2); }
    foreach ($in as $c) {
        $profile = lx_profile((string)($c['model'] ?? '')) ?: lx_fallback_profile(($c['kind'] ?? '') === 'video' ? 'video' : 'image');
        $res = lx_adapt(is_array($c['intent'] ?? null) ? $c['intent'] : [], $profile, (string)($c['haystack'] ?? ''),
                        ['duration' => (string)($c['duration'] ?? ''), 'lang' => (string)($c['lang'] ?? 'en')]);
        echo json_encode($res, JSON_UNESCAPED_UNICODE), "\n";
    }
    exit(0);
}

/* State dir: `<site>/data` is inside open_basedir and nginx denies /data/ and *.log. */
function lx_state_dir()
{
    static $dir = null;
    if ($dir !== null) return $dir;
    $c = dirname(__DIR__) . '/data';
    $dir = (is_dir($c) && is_writable($c)) ? $c : sys_get_temp_dir();
    return $dir;
}
define('LX_LOG', lx_state_dir() . '/neuravision-prompt.log');
define('LX_LIMITS', lx_state_dir() . '/neuravision-prompt-limits.json');
$LX_T0 = microtime(true);
$LX_UI_LANG = 'en';

function lx_log($action, $code, $extra = [])
{
    global $LX_T0;
    $line = json_encode(array_merge([
        't'      => gmdate('c'),
        'action' => $action,
        'code'   => $code,
        'ms'     => (int)round((microtime(true) - $LX_T0) * 1000),
    ], $extra), JSON_UNESCAPED_UNICODE);
    @file_put_contents(LX_LOG, $line . "\n", FILE_APPEND | LOCK_EX);
}

/** Error text in the UI language (ru|en). */
function lx_msg($ru, $en)
{
    global $LX_UI_LANG;
    return $LX_UI_LANG === 'ru' ? $ru : $en;
}

function reply($code, $data, $action = '-', $extra = [])
{
    lx_log($action, $code, $extra);
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---- deployment config: pa/pa.env (next to this file) > <site>/.env > <site>/api/.env ---- */
function lx_read_env_file($path, array &$env)
{
    if (!is_readable($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (preg_match('/^([A-Z0-9_]+)=(.*)$/', $line, $m) && !isset($env[$m[1]])) $env[$m[1]] = trim(trim($m[2]), "\"'");
    }
}
$env = [];
lx_read_env_file(__DIR__ . '/pa.env', $env);
lx_read_env_file(dirname(__DIR__) . '/.env', $env);
lx_read_env_file(dirname(__DIR__) . '/api/.env', $env);   // the generation API keeps its Venice key here

$lxHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
session_name($env['PA_SESSION_NAME'] ?? 'nv_prompt_assistant');
session_set_cookie_params(['secure' => $lxHttps, 'httponly' => true, 'samesite' => 'Strict',
                           'path' => $env['PA_COOKIE_PATH'] ?? '/']);
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    reply(200, ['token' => $_SESSION['csrf']], 'csrf');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(405, ['error' => 'Method not allowed.']);

/* Origins allowed to call this endpoint: PA_ALLOWED_ORIGINS=https://a.com,https://www.a.com (pa.env).
   Default: the Neuravision domains. */
$ALLOWED_ORIGINS = array_values(array_filter(array_map('trim', explode(',',
    $env['PA_ALLOWED_ORIGINS'] ?? 'https://neuracreation.com,https://www.neuracreation.com,https://neuravision.app,https://www.neuravision.app'))));
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (!in_array($origin, $ALLOWED_ORIGINS, true)) {
    reply(403, ['error' => 'Open the assistant on the studio site.'], 'origin', ['origin' => substr($origin, 0, 80)]);
}
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    reply(403, ['error' => 'Refresh the page and try again.'], 'csrf');
}
session_write_close();

if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 60000) reply(413, ['error' => 'Request too long.']);
$raw = file_get_contents('php://input', false, null, 0, 60001);
if (strlen($raw) > 60000) reply(413, ['error' => 'Request too long.']);
$d = json_decode($raw, true);
if (!is_array($d)) reply(422, ['error' => 'Malformed request.']);

$LX_UI_LANG = (($d['uiLang'] ?? ($d['context']['uiLang'] ?? '')) === 'ru') ? 'ru' : 'en';

$action = $d['action'] ?? '';
if (!in_array($action, ['analyze', 'compose', 'adapt'], true)) reply(422, ['error' => lx_msg('Неизвестное действие.', 'Unknown action.')]);

/* ------------------------------------------------------------------ *
 * Model profile registry (real Venice catalog, built by build_profiles.py)
 * ------------------------------------------------------------------ */
function lx_profiles()
{
    static $p = null;
    if ($p !== null) return $p;
    $raw = @file_get_contents(LX_PROFILES_PATH);
    $p = $raw ? (json_decode($raw, true) ?: []) : [];
    if (!isset($p['image'])) $p['image'] = [];
    if (!isset($p['video'])) $p['video'] = [];
    return $p;
}

function lx_profile($id)
{
    if ($id === '') return null;
    $p = lx_profiles();
    foreach (['image', 'video'] as $k) {
        foreach (($p[$k] ?? []) as $m) if (($m['id'] ?? '') === $id) return $m;
    }
    return null;
}

/* Fallback profile so the adapter never crashes on an unknown/legacy model id. */
function lx_fallback_profile($kind)
{
    return [
        'id' => '', 'name' => '', 'kind' => $kind === 'video' ? 'video' : 'image',
        'dialect' => 'natural', 'max_chars' => 1800,
        'negative' => ['mode' => 'passthrough', 'max_chars' => 1800, 'note' => ''],
        'params' => [], 'text_render' => 'ok', 'pipeline' => $kind === 'video' ? 't2v' : 'image',
    ];
}

/* ================================================================== *
 *  DETERMINISTIC ADAPTER  — intent -> prompt in the model's dialect
 *  (mirrors assistant-core.js NVPA.adapt; keep the two in sync)
 * ================================================================== */

function lx_clean($s)
{
    $s = trim(preg_replace('/\s+/u', ' ', (string)$s));
    $s = preg_replace('/\s+([,;.])/u', '$1', $s);
    return trim($s, " .,;");
}
function lx_norm($s)
{
    $s = mb_strtolower(lx_clean($s), 'UTF-8');
    $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s);
    return trim(preg_replace('/\s+/u', ' ', $s));
}
/** Lower the first letter of a fragment, but never break an acronym ("LED headlights"). */
function lx_lc1($s)
{
    if (!preg_match('/^[A-Z](?:[a-z]|\s)/', (string)$s)) return (string)$s;
    return mb_strtolower(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($s, 1, null, 'UTF-8');
}
/** "The cat bats the ball" / "He runs" is a sentence, not a participle phrase. */
function lx_is_sentence($s)
{
    return (bool)preg_match('/^(the|a|an|he|she|it|they|this|that|his|her|their|its)\b/i', (string)$s);
}
/** "Medium-wide full-body shot with the woman centered" -> [lead "Medium-wide full-body shot", rest "with the woman centered"] */
function lx_split_comp($comp)
{
    $c = lx_clean($comp);
    if ($c === '') return ['', ''];
    if (preg_match('/^((?:[\w\'-]+\s+){0,3}?[\w\'-]*(?:shot|close[- ]?up|view|portrait|framing))\b[,:]?\s*(.*)$/iu', $c, $m)) {
        return [lx_uc1($m[1]), lx_clean($m[2])];
    }
    $shotRe = '/^(extreme |very |low |high )?(close[- ]?up|medium|wide|full|long|establishing|cowboy|two[- ]shot|over[- ]the[- ]shoulder|top[- ]down|overhead|bird\'?s[- ]eye|low[- ]angle|high[- ]angle|eye[- ]level|macro|aerial|pov|insert|detail|portrait|three[- ]quarter|dutch)/iu';
    if (preg_match($shotRe, $c) && count(preg_split('/\s+/u', $c)) <= 3) return [lx_uc1($c) . ' shot', ''];
    return ['', $c];
}
/** subject + action + composition -> leading fragments (see NVPA.headParts). */
function lx_head_parts(array &$parts, $subject, $action, $composition)
{
    list($lead, $rest) = lx_split_comp($composition);
    $head = lx_clean($subject);
    $act  = lx_clean($action);
    if ($head !== '' && $act !== '' && !lx_is_sentence($act)) { $head .= ' ' . $act; $act = ''; }
    $first = $head !== '' ? ($lead !== '' ? $lead . ' of ' . lx_lc1($head) : $head) : $lead;
    /* the remainder of a long composition stays glued to the head (see NVPA) */
    if ($first !== '' && $rest !== '') $first .= ', ' . lx_lc1(lx_participle_clause($rest));
    elseif ($rest !== '') $first = lx_participle_clause($rest);
    if ($first !== '') $parts[] = lx_lc1($first);   // sentence case is applied by the sentence builder
    if ($act !== '')   $parts[] = lx_lc1($act);
}
/** "that keeps the runner prominent" -> "keeping the runner prominent" (see NVPA.participleClause). */
function lx_ing($v)
{
    $v = strtolower($v);
    if (preg_match('/ies$/', $v)) return preg_replace('/ies$/', 'ying', $v);
    if (preg_match('/(ch|sh|ss|x|z|o)es$/', $v)) return preg_replace('/es$/', 'ing', $v);
    if (preg_match('/es$/', $v)) return preg_replace('/es$/', 'ing', $v);
    if (preg_match('/s$/', $v)) return preg_replace('/s$/', 'ing', $v);
    return $v;
}
function lx_participle_clause($rest)
{
    if (preg_match('/^(?:that|which)\s+([a-z]+s)\b(.*)$/iu', (string)$rest, $m)) return lx_ing($m[1]) . $m[2];
    return $rest;
}
function lx_uc1($s)
{
    return $s === '' ? $s : mb_strtoupper(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($s, 1, null, 'UTF-8');
}

/** Drop fragments that repeat (or are contained in) something already said. */
function lx_dedupe(array $parts)
{
    $out = []; $seen = [];
    foreach ($parts as $p) {
        $n = lx_norm($p);
        if ($n === '') continue;
        $dup = false;
        foreach ($seen as $s) {
            if ($s === $n || (mb_strlen($n, 'UTF-8') > 6 && mb_strpos($s, $n, 0, 'UTF-8') !== false)) { $dup = true; break; }
        }
        if ($dup) continue;
        /* a longer newcomer swallows an earlier shorter duplicate */
        for ($j = count($out) - 1; $j >= 0; $j--) {
            if (mb_strlen($seen[$j], 'UTF-8') > 6 && mb_strpos($n, $seen[$j], 0, 'UTF-8') !== false) {
                array_splice($out, $j, 1); array_splice($seen, $j, 1);
            }
        }
        $out[] = lx_clean($p); $seen[] = $n;
    }
    return $out;
}

/** Negation patterns we treat as a prohibition inside a POSITIVE prompt. */
function lx_negation_regex()
{
    return '/\b(?:no|without|avoid|avoiding|free of|devoid of|not\s+(?:showing|including|featuring))\b[^.,;]{0,80}/iu';
}

/**
 * Strip prohibitions the model invented on its own. A prohibition survives only
 * if the user actually asked for it (idea, answers, or the explicit
 * user_negatives list). Audit 2026-09-23: grok added "no text" three runs in a row.
 */
function lx_strip_invented_negatives($text, $userHaystack, $userNegatives)
{
    $removed = [];
    $hay = mb_strtolower($userHaystack, 'UTF-8');
    foreach ($userNegatives as $n) $hay .= ' ' . mb_strtolower($n, 'UTF-8');
    /* a non-Latin user text cannot be keyword-matched against English: when the
       user did prohibit things, trust the translation instead of stripping it */
    $latinUser = !preg_match('/[^\x{0000}-\x{024F}\s\p{P}]/u', $userHaystack);

    $out = preg_replace_callback(lx_negation_regex(), function ($m) use ($hay, $latinUser, $userNegatives, &$removed) {
        $frag = trim($m[0]);
        $kw = trim(preg_replace('/^\s*(?:no|without|avoid|avoiding|free of|devoid of|not\s+\S+)\s*/iu', '', $frag));
        $kw = mb_strtolower($kw, 'UTF-8');
        if ($kw === '') return $m[0];
        $words = preg_split('/[^\p{L}\p{N}]+/u', $kw, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($words as $w) {
            if (mb_strlen($w, 'UTF-8') < 3) continue;
            if (mb_strpos($hay, $w, 0, 'UTF-8') !== false) return $m[0];   // user really asked for it
        }
        if (!$latinUser && count($userNegatives)) return $m[0];
        $removed[] = $frag;
        return '';
    }, $text);

    $out = preg_replace('/\s*,\s*,+/u', ', ', $out);
    $out = preg_replace('/\s{2,}/u', ' ', $out);
    return [lx_clean($out), $removed];
}

/**
 * "no cars" -> "an empty street": positive rewrites for models without real
 * negative conditioning. Replacements contain no negation words on purpose.
 */
function lx_positive_rewrite($neg)
{
    $map = [
        '/\b(text|letters|words|typography|caption|writing)\b/iu' => 'clean surfaces with no lettering anywhere',
        '/\b(watermark|logo|brand)/iu'                           => 'an unbranded, watermark-free frame',
        '/\b(people|person|human|crowd|bystander)/iu'             => 'the scene is empty of other people',
        '/\bcars?\b|traffic|vehicle/iu'                           => 'an empty road with no vehicles',
        '/\bblur|out of focus\b/iu'                               => 'sharp focus throughout',
        '/\bnoise|grain\b/iu'                                     => 'clean low-noise rendering',
        '/\bclutter|mess\b/iu'                                    => 'a tidy minimal arrangement',
        '/\bextra (fingers|limbs)|deformed|malformed/iu'          => 'anatomically correct hands and body',
        '/\bplastic|waxy\b/iu'                                    => 'natural skin texture with pores',
    ];
    foreach ($map as $re => $rewrite) if (preg_match($re, $neg)) return $rewrite;
    return '';
}

/** Higgsfield DoP camera presets: the model wants ONE named move per clip. */
function lx_dop_presets()
{
    return [
        ['Crash Zoom In',  '/crash zoom in|fast zoom in|snap zoom/i'],
        ['Crash Zoom Out', '/crash zoom out|fast zoom out/i'],
        ['Dolly Zoom',     '/dolly zoom|vertigo|hitchcock/i'],
        ['Dolly In',       '/dolly in|push[- ]?in|move(s)? (in|closer)|zoom(s|ing)? in|approach|toward the subject|closer to/i'],
        ['Dolly Out',      '/dolly out|pull[- ]?back|zoom(s|ing)? out|move(s)? away|reveal(ing)? the scene|retreat/i'],
        ['360 Orbit',      '/orbit|360|circle(s)? around|around the subject|revolv/i'],
        ['Arc Left',       '/arc left|sweep(s)? left|curve(s)? left/i'],
        ['Arc Right',      '/arc right|sweep(s)? right|curve(s)? right/i'],
        ['FPV Drone',      '/drone|aerial|fpv|fly(ing)? over|bird\'?s[- ]eye|flyover/i'],
        ['Bullet Time',    '/bullet time|frozen moment|time freeze|matrix/i'],
        ['Robo Arm',       '/robo(t)? arm|robotic arm|mechanical sweep/i'],
        ['Crane Up',       '/crane up|rise(s|ing)? up|lift(s|ing)? up|ascend|boom up/i'],
        ['Crane Down',     '/crane down|descend|lower(s|ing)? down|boom down/i'],
        ['Handheld',       '/handheld|hand-held|shaky|documentary/i'],
        ['Whip Pan',       '/whip pan|fast pan|snap pan/i'],
        ['Overhead',       '/overhead|top[- ]down|from above/i'],
        ['Low Angle',      '/low angle|from below|looking up/i'],
        ['Head Tracking',  '/track(s|ing)? the subject|follow(s|ing)? the subject|follows? (him|her|them)|tracking shot/i'],
        ['Focus Change',   '/rack focus|focus (shift|pull|change)|refocus/i'],
        ['Tilt Up',        '/tilt(s)? up|pan(s)? up/i'],
        ['Tilt Down',      '/tilt(s)? down|pan(s)? down/i'],
        ['3D Rotation',    '/3d rotation|rotate(s)? in 3d|turntable/i'],
        ['Static',         '/static|locked[- ]off|tripod|still camera|no camera movement/i'],
    ];
}
function lx_dop_preset($movement, $presets = null)
{
    $m = lx_clean($movement);
    if ($m === '') return '';
    $names = (is_array($presets) && $presets) ? $presets : array_map(function ($p) { return $p[0]; }, lx_dop_presets());
    $nm = lx_norm($m);
    foreach ($names as $name) {
        /* the LLM is told the preset list, so an exact (case-insensitive) name wins */
        $nn = lx_norm($name);
        if ($nn !== '' && ($nn === $nm || mb_strpos($nm, $nn, 0, 'UTF-8') !== false)) return $name;
    }
    foreach (lx_dop_presets() as $p) if (preg_match($p[1], $m)) return $p[0];
    return '';
}
/** danbooru dialect for Illustrious/Pony checkpoints (see NVPA.animeTag). */
function lx_anime_tag($t, array &$tags)
{
    $shot = '';
    if (preg_match('/^(?:(extreme )?close[- ]?up|medium( full[- ]body)?|full[- ]body|wide|cowboy|upper[- ]body|portrait)( shot)? of (.+)$/iu', $t, $m)) {
        $kind = preg_replace('/ of .*$/u', '', $t);
        if (preg_match('/close/i', $kind)) $shot = 'close-up';
        elseif (preg_match('/full/i', $kind)) $shot = 'full body';
        elseif (preg_match('/cowboy/i', $kind)) $shot = 'cowboy shot';
        elseif (preg_match('/wide/i', $kind)) $shot = 'wide shot';
        elseif (preg_match('/portrait/i', $kind)) $shot = 'portrait';
        else $shot = 'upper body';
        $t = $m[4];
    }
    if ($shot !== '' && !in_array($shot, $tags, true)) $tags[] = $shot;
    $t = preg_replace('/\b([a-z]+)-haired\b/iu', '$1 hair', $t);
    $t = preg_replace('/\bpink bob\b/iu', 'pink hair, bob cut', $t);
    $t = preg_replace('/\b([a-z]+) bob\b/iu', '$1 hair, bob cut', $t);
    $t = preg_replace('/^(?:two|2) (?:women|girls)\b/iu', '2girls', $t);
    $t = preg_replace('/^(?:two|2) (?:men|boys)\b/iu', '2boys', $t);
    $person = '';
    if (preg_match('/\b(?:woman|girl|lady|female)\b/iu', $t) && !preg_match('/\d(girl|boy)s?\b/u', $t)) {
        $t = preg_replace('/\b(?:young |teen(?:age)? |adult )?(?:woman|girl|lady|female)\b/iu', '1girl', $t, 1); $person = '1girl';
    } elseif (preg_match('/\b(?:man|boy|guy|male)\b/iu', $t) && !preg_match('/\d(girl|boy)s?\b/u', $t)) {
        $t = preg_replace('/\b(?:young |teen(?:age)? |adult )?(?:man|boy|guy|male)\b/iu', '1boy', $t, 1); $person = '1boy';
    }
    if ($person === '') return $t;
    /* "pink hair 1girl in black techwear looking over her shoulder" -> 1girl, solo, pink hair, black techwear, looking back */
    $out = [$person, 'solo'];
    if (preg_match('/\b([a-z]+ hair)\b/iu', $t, $h)) { $out[] = strtolower($h[1]); $t = str_replace($h[0], ' ', $t); }
    $t = lx_clean(preg_replace('/\b1girl\b|\b1boy\b/u', ' ', $t));
    foreach (preg_split('/\s+(?:in|wearing|with|while|and)\s+|\s+(?=looking\b)|,\s*/iu', $t) as $x) {
        $x = preg_replace('/^(?:in|wearing|with|while|and)\s+/iu', '', preg_replace('/^looking over (?:her|his|their) shoulder$/iu', 'looking back', lx_clean($x)));
        if ($x !== '' && !in_array($x, $out, true)) $out[] = $x;
    }
    return implode(', ', $out);
}
/** MiniMax (Hailuo) native bracket camera commands (see NVPA.minimaxCam). */
function lx_minimax_cam($move)
{
    $m = strtolower((string)$move);
    if ($m === '') return '';
    $map = [
        '/static|locked|fixed/' => '[Static shot]', '/pan left/' => '[Pan left]', '/pan right/' => '[Pan right]',
        '/tilt up/' => '[Tilt up]', '/tilt down/' => '[Tilt down]', '/zoom in/' => '[Zoom in]', '/zoom out/' => '[Zoom out]',
        '/dolly in|push in|push-in|moves? closer|approach/' => '[Push in]', '/dolly out|pull out|pull-out|pull back/' => '[Pull out]',
        '/crane up|pedestal up|rises?/' => '[Pedestal up]', '/crane down|pedestal down/' => '[Pedestal down]',
        '/truck left/' => '[Truck left]', '/truck right/' => '[Truck right]', '/handheld|shake|shaky/' => '[Shake]',
        '/track|follow|lateral|side/' => '[Tracking shot]',
    ];
    foreach ($map as $re => $cmd) if (preg_match($re, $m)) return $cmd;
    return '';
}
function lx_is_minimax($profile) { return (bool)preg_match('/minimax|hailuo/i', ($profile['id'] ?? '') . ' ' . ($profile['vendor'] ?? '')); }
/** "Wide tracking shot" + camera "tracking shot" -> the camera line is the single source of truth. */
function lx_strip_cam_words($comp, $camMove)
{
    if ($camMove === '') return $comp;
    return lx_clean(preg_replace('/\b(tracking|handheld|dolly|orbit(?:ing)?|drone|aerial(?= tracking)|crane|steadicam|gimbal|pan(?:ning)?|tilt(?:ing)?)\s+(?=shot|view)/iu', '', (string)$comp));
}
function lx_palette($color)
{
    $c = lx_clean($color);
    if ($c === '') return '';
    return preg_match('/palette|tones?|colou?rs?|hues?|grade|scheme/iu', $c) ? $c : $c . ' palette';
}
function lx_is_higgsfield($profile)
{
    return (bool)preg_match('/higgsfield/i', ($profile['id'] ?? '') . ' ' . ($profile['vendor'] ?? ''));
}
function lx_seconds($duration)
{
    return preg_match('/(\d+(?:\.\d+)?)\s*(?:s\b|sec|с\b|сек)/iu', (string)$duration, $m) ? (float)$m[1] : 0.0;
}
/** Assumption strings the adapter itself adds (conversation language). */
function lx_adapter_strings($lang)
{
    $t = [
        'ru' => ['camera' => 'камера: медленный наезд (Dolly In) — выбрано по умолчанию',
                 'keep'   => 'лицо и фон на фото оставлены без изменений',
                 'audio'  => 'модель генерирует звук: запрошен естественный фоновый звук сцены, без музыки и речи'],
        'en' => ['camera' => 'assumed a slow push-in (Dolly In) as the camera move',
                 'keep'   => 'assumed the face and background of the photo stay unchanged',
                 'audio'  => 'this model generates sound: natural ambient audio requested, no music or speech'],
    ];
    return $t[$lang] ?? $t['en'];
}

/**
 * Render the intent.
 * @return array {prompt, negative_prompt, negative_mode, warnings[], removed_negatives[], assumptions[], adapted_for, dialect}
 */
function lx_adapt($intent, $profile, $userHaystack, $opts = [])
{
    $kind      = $profile['kind'] ?? 'image';
    $dialect   = ($profile['dialect'] ?? 'natural') === 'tags' ? 'tags' : 'natural';
    $pipeline  = $profile['pipeline'] ?? ($kind === 'video' ? 't2v' : 'image');
    $negMode   = (($profile['negative']['mode'] ?? 'passthrough') === 'native') ? 'native' : 'passthrough';
    $maxChars  = (int)($profile['max_chars'] ?? 1800);
    if ($maxChars < 200) $maxChars = 1800;
    $audioOk   = !empty($profile['params']['audio']);
    $higgs     = lx_is_higgsfield($profile);
    $secs      = lx_seconds($opts['duration'] ?? '');
    $L         = lx_adapter_strings($opts['lang'] ?? 'en');
    $warnings  = []; $assumptions = [];

    $g = function ($k) use ($intent) {
        $v = $intent[$k] ?? '';
        return is_string($v) ? lx_clean($v) : '';
    };
    $camAngle = ''; $camMove = '';
    if (isset($intent['camera']) && is_array($intent['camera'])) {
        $camAngle = lx_clean($intent['camera']['angle'] ?? '');
        $camMove  = lx_clean($intent['camera']['movement'] ?? '');
    }
    $listOf = function ($k) use ($intent) {
        $out = [];
        foreach ((array)($intent[$k] ?? []) as $n) if (is_string($n) && trim($n) !== '') $out[] = lx_clean($n);
        return $out;
    };
    $userNeg  = $listOf('user_negatives');
    $preserve = $listOf('i2v_preserve');
    $extras   = $listOf('extras');
    $shots    = $listOf('shots');
    $audio    = $g('audio');
    $isSource = in_array($pipeline, ['i2v', 'r2v', 'v2v'], true);

    $parts = [];

    /* --- exact on-screen text always goes first, verbatim and quoted ------- */
    $textIn = trim($g('text_in_image'), " \"'«»“”");
    if ($textIn !== '') {
        if (($profile['text_render'] ?? 'ok') === 'weak') $warnings[] = 'text_render_weak';
        $parts[] = $dialect === 'tags'
            ? 'text "' . $textIn . '"'
            : 'the text "' . $textIn . '" written clearly in crisp, correctly spelled letters';
    }

    $presetNote = function ($preset, $move) {
        if ($move === '' || $preset === '') return '';
        $re = '/' . str_replace(' ', '.?', preg_quote($preset, '/')) . '/i';
        return preg_match($re, $move) ? '' : ' (' . $move . ')';
    };

    if ($kind === 'video' && $isSource) {
        /* THE i2v RULE: describe the CHANGE, never re-describe the frame. */
        $mv = [];
        if ($g('action') !== '') $mv[] = (preg_match('/^\w+ing\b/iu', $g('action')) && !lx_is_sentence($g('action'))) ? (($g('subject') !== '' ? lx_lc1($g('subject')) : 'the subject') . ' ' . lx_lc1($g('action'))) : $g('action');
        $preset = $higgs ? lx_dop_preset($camMove !== '' ? $camMove : $camAngle, $profile['camera_presets'] ?? null) : '';
        if ($higgs) {
            if ($preset === '' && $camMove === '') { $preset = 'Dolly In'; $assumptions[] = $L['camera']; }
            if ($preset !== '') $mv[] = 'camera preset: ' . $preset . $presetNote($preset, $camMove);
            elseif ($camMove !== '') $mv[] = 'camera ' . lx_lc1($camMove);
        } elseif ($camMove !== '') {
            $mv[] = 'camera ' . lx_lc1($camMove);
        }
        if ($camAngle !== '' && !$higgs) $mv[] = lx_lc1($camAngle);
        if ($g('lighting') !== '' && !preg_match('/original|unchanged|same|consistent|remains|keep/i', $g('lighting'))) $mv[] = 'lighting: ' . lx_lc1($g('lighting'));
        if (!$mv) $mv[] = 'subtle natural motion, gentle breathing of the scene';
        $parts[] = ($pipeline === 'v2v' ? 'Edit the source video: ' : 'Animate the source frame: ') . implode('; ', $mv);
        if ($preserve) {
            $parts[] = 'keep unchanged: ' . implode(', ', array_map('lx_lc1', $preserve));
        } else {
            $parts[] = $pipeline === 'v2v'
                ? 'keep the original motion, timing and framing'
                : 'keep the subject identity, face, wardrobe and background of the source frame unchanged';
            if ($pipeline !== 'v2v') $assumptions[] = $L['keep'];
        }
        if ($secs >= 8 && count($shots) > 1) {
            $b = []; foreach ($shots as $i => $s) $b[] = 'beat ' . ($i + 1) . ': ' . lx_lc1($s);
            $parts[] = implode('; ', $b);
        }
        if ($g('mood') !== '')  $parts[] = lx_lc1($g('mood')) . (preg_match('/\bmood\b|\batmosphere\b/i', $g('mood')) ? '' : ' mood');
        if ($g('style') !== '') $parts[] = lx_lc1($g('style'));
        foreach ($extras as $e) $parts[] = lx_lc1($e);
        if ($audio !== '') { if ($audioOk) $parts[] = 'audio: ' . lx_lc1($audio); else $warnings[] = 'audio_unsupported'; }
        elseif ($audioOk && $parts) { $parts[] = 'audio: natural ambient sound of the scene, no music, no speech'; $assumptions[] = $L['audio'] ?? 'this model generates sound: natural ambient audio requested, no music or speech'; }
    } elseif ($kind === 'video') {
        lx_head_parts($parts, $g('subject'), $g('action'), lx_strip_cam_words($g('composition'), $camMove));
        if ($g('environment') !== '') $parts[] = lx_lc1($g('environment'));
        if ($g('lighting') !== '')    $parts[] = lx_lc1($g('lighting'));
        if ($g('color') !== '')       $parts[] = lx_lc1(lx_palette($g('color')));
        $presetT = $higgs ? lx_dop_preset($camMove !== '' ? $camMove : $camAngle, $profile['camera_presets'] ?? null) : '';
        $mmCam = lx_is_minimax($profile) ? lx_minimax_cam($camMove) : '';
        if ($higgs && ($presetT !== '' || $camMove !== '')) {
            $p = $presetT !== '' ? $presetT : 'Dolly In';
            $parts[] = 'camera preset: ' . $p . ($presetT !== '' ? $presetNote($presetT, $camMove) : ' (' . $camMove . ')');
        } else {
            if ($camAngle !== '') $parts[] = lx_lc1($camAngle);
            if ($mmCam !== '')    array_unshift($parts, $mmCam);   // MiniMax reads the bracket command before the description
            elseif ($camMove !== '') $parts[] = 'camera ' . lx_lc1($camMove);
        }
        if ($secs >= 8 && count($shots) > 1) {
            $b = []; foreach ($shots as $i => $s) $b[] = 'beat ' . ($i + 1) . ': ' . lx_lc1($s);
            $parts[] = implode('; ', $b);
        }
        if ($g('mood') !== '')  $parts[] = lx_lc1($g('mood')) . (preg_match('/\bmood\b|\batmosphere\b/i', $g('mood')) ? '' : ' mood');
        if ($g('style') !== '') $parts[] = lx_lc1($g('style'));
        foreach ($extras as $e) $parts[] = lx_lc1($e);
        if ($audio !== '') { if ($audioOk) $parts[] = 'audio: ' . lx_lc1($audio); else $warnings[] = 'audio_unsupported'; }
        elseif ($audioOk && $parts) { $parts[] = 'audio: natural ambient sound of the scene, no music, no speech'; $assumptions[] = $L['audio'] ?? 'this model generates sound: natural ambient audio requested, no music or speech'; }
    } else {
        lx_head_parts($parts, $g('subject'), $g('action'), $g('composition'));
        if ($g('environment') !== '') $parts[] = lx_lc1($g('environment'));
        if ($camAngle !== '')         $parts[] = lx_lc1($camAngle);
        if ($g('lighting') !== '')    $parts[] = lx_lc1($g('lighting'));
        if ($g('color') !== '')       $parts[] = lx_lc1(lx_palette($g('color')));
        if ($g('mood') !== '')        $parts[] = lx_lc1($g('mood')) . (preg_match('/\bmood\b|\batmosphere\b/i', $g('mood')) ? '' : ' mood');
        if ($g('style') !== '')       $parts[] = lx_lc1($g('style'));
        foreach ($extras as $e) $parts[] = lx_lc1($e);
    }

    $parts = lx_dedupe($parts);

    /* --- post-filter on model-authored text only, per part (keeps a list such
           as "keep unchanged: face, wardrobe, background" as one block) ------- */
    $removed = []; $kept = [];
    foreach ($parts as $p) {
        if (preg_match('/^audio:/i', $p)) { $kept[] = $p; continue; }   // "no music, no speech" is a sound instruction, not a visual negative
        list($txt, $rm) = lx_strip_invented_negatives($p, $userHaystack, $userNeg);
        $removed = array_merge($removed, $rm);
        if ($txt !== '') $kept[] = $txt;
    }
    $parts = lx_dedupe($kept);

    /* --- negatives routing ------------------------------------------------ */
    $negativePrompt = '';
    if ($userNeg) {
        if ($negMode === 'native') {
            $negativePrompt = implode(', ', $userNeg);
        } else {
            $leftover = [];
            foreach ($userNeg as $n) {
                $r = lx_positive_rewrite($n);
                if ($r !== '') { if (!in_array($r, $parts, true)) $parts[] = $r; }
                else $leftover[] = $n;
            }
            if ($leftover) { $negativePrompt = implode(', ', $leftover); $warnings[] = 'negative_best_effort'; }
        }
    }

    /* --- join in the model's dialect -------------------------------------- */
    if ($dialect === 'tags') {
        $tags = [];
        $anime = (bool)preg_match('/illustrious|pony|anime/i', ($profile['id'] ?? '') . ' ' . implode(' ', (array)($profile['model_sets'] ?? [])));
        foreach ($parts as $p) {
            foreach (preg_split('/,|;| and /u', $p) as $t) {
                $t = mb_strtolower(preg_replace('/^(the|a|an)\s+/iu', '', lx_clean($t)), 'UTF-8');
                $t = preg_replace('/\s+(palette|mood|atmosphere|tones?|colou?rs?)$/iu', '', $t);
                if ($anime) $t = lx_anime_tag($t, $tags);
                if ($t !== '' && !in_array($t, $tags, true)) $tags[] = $t;
            }
        }
        if ($anime) {
            array_unshift($tags, 'masterpiece, best quality');
            if ($negMode === 'native') $negativePrompt = lx_clean($negativePrompt !== '' ? $negativePrompt . ', ' : '') . 'lowres, bad anatomy, bad hands, worst quality, low quality, watermark, signature';
        }
        $prompt = implode(', ', $tags);
    } else {
        /* sentences: head sentence, then one sentence per "block" for readability */
        $sentences = []; $cur = [];
        foreach ($parts as $p) {
            if (preg_match('/^(camera|camera preset|audio|beat 1|animate the source|edit the source|keep unchanged|keep the|\[)/i', $p)) {
                if ($cur) { $sentences[] = implode(', ', $cur); $cur = []; }
                $sentences[] = $p;           // a block part is a sentence of its own
                continue;
            }
            $cur[] = $p;
            if (mb_strlen(implode(', ', $cur), 'UTF-8') > 140) { $sentences[] = implode(', ', $cur); $cur = []; }
        }
        if ($cur) $sentences[] = implode(', ', $cur);
        $out = [];
        foreach ($sentences as $s) { $s = lx_uc1(lx_clean($s)); $out[] = preg_match('/[.!?\]]$/u', $s) ? $s : $s . '.'; }
        $prompt = implode(' ', $out);
    }

    /* --- length cap ------------------------------------------------------- */
    if (mb_strlen($prompt, 'UTF-8') > $maxChars) {
        $cut = mb_substr($prompt, 0, $maxChars - 1, 'UTF-8');
        $at = max((int)mb_strrpos($cut, '. ', 0, 'UTF-8'), (int)mb_strrpos($cut, ', ', 0, 'UTF-8'));
        $prompt = lx_clean($at > $maxChars * 0.5 ? mb_substr($cut, 0, $at, 'UTF-8') : $cut) . ($dialect === 'tags' ? '' : '.');
        $warnings[] = 'truncated_to_model_limit';
    }
    $negMax = (int)($profile['negative']['max_chars'] ?? $maxChars);
    if ($negMax > 0 && mb_strlen($negativePrompt, 'UTF-8') > $negMax) {
        $negativePrompt = mb_substr($negativePrompt, 0, $negMax, 'UTF-8');
    }

    return [
        'prompt'            => $prompt,
        'negative_prompt'   => $negativePrompt,
        'negative_mode'     => $negMode,
        'warnings'          => array_values(array_unique($warnings)),
        'removed_negatives' => $removed,
        'assumptions'       => $assumptions,
        'adapted_for'       => !empty($profile['id']) ? ($profile['name'] . ' (' . $profile['id'] . ')') : 'generic',
        'dialect'           => $dialect,
    ];
}

/* ------------------------------------------------------------------ *
 * action=adapt — no LLM, no rate limit: pure re-render on model change
 * ------------------------------------------------------------------ */
if ($action === 'adapt') {
    $intent = $d['intent'] ?? null;
    if (!is_array($intent)) reply(422, ['error' => lx_msg('Нет сохранённого замысла — сначала подготовьте промпт.', 'No saved intent yet — compose the prompt first.')], 'adapt');
    $modelId = is_string($d['model'] ?? null) ? $d['model'] : '';
    $kind    = ($d['kind'] ?? '') === 'video' ? 'video' : 'image';
    $profile = lx_profile($modelId);
    if (!$profile) $profile = lx_fallback_profile($kind);
    $hay = (string)($d['haystack'] ?? '');
    $res = lx_adapt($intent, $profile, $hay, ['duration' => (string)($d['duration'] ?? ''), 'lang' => $LX_UI_LANG]);
    reply(200, $res, 'adapt', ['model' => $modelId, 'len' => mb_strlen($res['prompt'], 'UTF-8')]);
}

/* ================================================================== *
 *  analyze / compose  — validation, limits, LLM call
 * ================================================================== */
if (!is_string($d['idea'] ?? null) || mb_strlen(trim($d['idea']), 'UTF-8') < 3 || mb_strlen($d['idea'], 'UTF-8') > 2000) {
    reply(422, ['error' => lx_msg('Опишите идею: от 3 до 2000 символов.', 'Describe the idea: 3 to 2000 characters.')], $action);
}
$context = $d['context'] ?? [];
$answers = $d['answers'] ?? [];
if (!is_array($context) || !is_array($answers) || count($answers) > 9) reply(422, ['error' => lx_msg('Некорректные уточнения.', 'Malformed answers.')], $action);
$round = $d['round'] ?? 1;
if (!is_int($round) || $round < 1 || $round > 3) reply(422, ['error' => lx_msg('Некорректный этап уточнения.', 'Malformed round.')], $action);
$latestCount = $d['latestAnswerCount'] ?? min(3, count($answers));
if (!is_int($latestCount) || $latestCount < 0 || $latestCount > 3 || $latestCount > count($answers)
    || ($action === 'compose' && count($answers) > 0 && $latestCount === 0)) {
    reply(422, ['error' => lx_msg('Некорректные уточнения.', 'Malformed answers.')], $action);
}
$clean = [];
foreach (['title', 'badge', 'kind', 'durationOrSize', 'quality', 'source', 'selectionType', 'modelId'] as $k) {
    if (isset($context[$k]) && !is_string($context[$k])) reply(422, ['error' => lx_msg('Некорректный контекст.', 'Malformed context.')], $action);
    $clean[$k] = mb_substr($context[$k] ?? '', 0, 300, 'UTF-8');
}
/* A generator label is not a scene subject; keep it out of the creative brief. */
if (($clean['selectionType'] ?? '') === 'model') $clean['title'] = '';
foreach ($answers as $a) {
    if (!is_array($a) || !is_string($a['question'] ?? null) || !is_string($a['answer'] ?? null)
        || mb_strlen($a['question'], 'UTF-8') > 900 || mb_strlen($a['answer'], 'UTF-8') > 450) {
        reply(422, ['error' => lx_msg('Сократите ответы на уточнения.', 'Shorten the answers.')], $action);
    }
}
/* User-typed negative prompt: protected from the post-filter, never invented by us. */
$userNegativeRaw = is_string($d['negative'] ?? null) ? mb_substr(trim($d['negative']), 0, 800, 'UTF-8') : '';

$kindGuess = (mb_stripos($clean['badge'] . ' ' . $clean['kind'], 'video') !== false) ? 'video' : 'image';
$profile = lx_profile($clean['modelId']);
if (!$profile) $profile = lx_fallback_profile($kindGuess);

/* --- persistent atomic limits (unchanged from v1) --------------------- */
$f = fopen(LX_LIMITS, 'c+');
if (!$f || !flock($f, LOCK_EX)) reply(503, ['error' => lx_msg('Помощник временно недоступен. Повторите позже.', 'The assistant is temporarily unavailable. Try again later.')], $action);
$limits = json_decode(stream_get_contents($f), true) ?: [];
$now = time();
foreach ($limits as $k => $v) if ($v['until'] < $now) unset($limits[$k]);
$ip = 'ip:' . hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|lx-v2-salt');
$day = 'day:' . gmdate('Y-m-d');
foreach ([$ip => [30, 3600], $day => [150, 86400]] as $k => $rule) {
    if (($limits[$k]['count'] ?? 0) >= $rule[0]) {
        flock($f, LOCK_UN); fclose($f);
        reply(429, ['error' => lx_msg('Лимит запросов демо-помощника исчерпан. Попробуйте позже или напишите промпт вручную.', 'The demo assistant limit is reached. Try later or write the prompt by hand.')], $action);
    }
}
foreach ([$ip => 3600, $day => 86400] as $k => $ttl) {
    if (!isset($limits[$k])) $limits[$k] = ['count' => 0, 'until' => $now + $ttl];
    $limits[$k]['count']++;
}
ftruncate($f, 0); rewind($f); fwrite($f, json_encode($limits)); flock($f, LOCK_UN); fclose($f);

/* $env already loaded above (pa.env > .env > api/.env). */

/* Provider chain. 'kind' picks the wire dialect: 'openai' = chat-completions
   (xAI, Venice — both OpenAI-compatible), 'anthropic' = native Messages API. */
$providers = [];
$lxCatalog = [];
if (!empty($env['XAI_API_KEY'])) {
    $base = $env['XAI_API_BASE'] ?? '';
    if (!preg_match('#^https://[a-z0-9.\-]+(/[^\s]*)?$#i', $base)) $base = 'https://api.x.ai/v1';
    $base = rtrim($base, '/');
    if (!preg_match('#/v\d+$#', $base)) $base .= '/v1';          // .env keeps XAI_API_BASE=https://api.x.ai
    $lxCatalog['xai'] = ['name' => 'xai', 'kind' => 'openai', 'base' => $base, 'key' => $env['XAI_API_KEY'],
                    'model' => $env['XAI_MODEL'] ?? 'grok-4.3',
                    /* grok-4.3 is a reasoning model; the intent needs no chain-of-thought (v2 note) */
                    'extra' => ['reasoning_effort' => 'none']];
}
if (!empty($env['VENICE_API_KEY'])) {
    $lxCatalog['venice'] = ['name' => 'venice', 'kind' => 'openai', 'base' => 'https://api.venice.ai/api/v1', 'key' => $env['VENICE_API_KEY'],
                    'model' => $env['VENICE_MODEL'] ?? 'grok-4-3',
                    'extra' => ['venice_parameters' => ['include_venice_system_prompt' => false]]];
}
if (!empty($env['ANTHROPIC_API_KEY'])) {
    /* ANTHROPIC_API_BASE: https://api.anthropic.com/v1 (real key) or the Claude bridge
       (http://127.0.0.1:8931/v1 — CASH_TOP subscription via reverse SSH tunnel). */
    $base = rtrim($env['ANTHROPIC_API_BASE'] ?? 'https://api.anthropic.com/v1', '/');
    if (!preg_match('#^https?://#i', $base)) $base = 'https://api.anthropic.com/v1';
    $lxCatalog['anthropic'] = ['name' => 'anthropic', 'kind' => 'anthropic', 'base' => $base,
                    'key' => $env['ANTHROPIC_API_KEY'], 'model' => $env['ANTHROPIC_MODEL'] ?? 'claude-sonnet-5-5', 'extra' => []];
}
/* PA_PROVIDER_ORDER=anthropic,xai,venice — unknown names ignored, missing ones appended in default order. */
foreach (array_merge(array_map('trim', explode(',', $env['PA_PROVIDER_ORDER'] ?? 'anthropic,xai,venice')), ['anthropic', 'xai', 'venice']) as $n) {
    if (isset($lxCatalog[$n])) { $providers[] = $lxCatalog[$n]; unset($lxCatalog[$n]); }
}
if (!$providers) reply(503, ['error' => lx_msg('Подключение ИИ пока не настроено.', 'The AI connection is not configured yet.')], $action, ['upstream' => 'no-provider']);

/* ------------------------------------------------------------------ *
 * System contract — v3 asks for an INTENT, not for formatted syntax.
 * ------------------------------------------------------------------ */
$system = <<<'TXT'
Ты помощник режиссёра и автора изображений в Neuravision Studio. Ты НЕ форматируешь промпт под конкретную модель и НЕ генерируешь медиа: ты понимаешь пользователя и возвращаешь структурированный замысел (intent). Форматированием под модель занимается код.
Верни JSON без Markdown: {"summary":"кратко как понял идею","questions":[{"question":"один понятный вопрос","options":["вариант 1","вариант 2","На ваш выбор"]}],"intent":{"subject":"","action":"","environment":"","composition":"","lighting":"","color":"","mood":"","style":"","camera":{"angle":"","movement":""},"shots":[],"audio":"","text_in_image":"","user_negatives":[],"i2v_preserve":[],"extras":[]},"assumptions":["что предложено тобой, а не указано пользователем"]}
INPUT: пользователь пишет простыми словами на любом языке, с опечатками, сленгом, без знаков препинания и иногда в неверной раскладке. Пойми смысл и переведи его в точные английские визуальные формулировки. Цитаты, имена собственные и бренды сохраняй дословно на языке оригинала.
INTENT CONTRACT (highest priority):
- Все поля intent — ПО-АНГЛИЙСКИ, конкретными содержательными фразами без синтаксиса генераторов: никаких тегов через запятую, никаких "masterpiece, 8k", "--ar 16:9", названий моделей и служебных слов.
- subject: главный объект с 2–4 конкретными визуальными признаками (вид/порода/возраст/материал/цвет/одежда). Если пользователь их не задал — предложи правдоподобные и перечисли в assumptions. action: ОДНО непрерывное выполнимое действие в форме английского present participle («walking barefoot along the shoreline», «pouring coffee»), чтобы фраза читалась после subject (для видео — то, что реально происходит за указанную длительность, не мини-реклама из нескольких сцен; для kind=image — поза или пойманный момент). environment: место и 1–2 детали фона. composition: ОДИН тип плана (close-up, medium shot, wide shot, top-down…) без движения камеры и без филлеров вроде «keeping the cat in frame»; уточнение кадрирования только если оно несёт информацию («the product centered, label facing the camera»), в форме participle, без «that/which». lighting: источник и характер света. color: палитра. mood: настроение. style: визуальный стиль (photorealistic, anime, 3D render, watercolor…) — только если пользователь намекнул или это очевидно из задачи.
- camera.angle — ОДИН ракурс (low angle, eye level, top-down), не два сразу. Устройство съёмки (drone, camera) никогда не субъект сцены: не «the drone glides over», а субъект — пейзаж, движение — camera.movement. camera.movement — ОДНО движение камеры для видео (slow dolly in, 360 orbit, handheld tracking shot, static…); для kind=image — пустая строка. Если TARGET перечисляет доступные приёмы камеры — выбери movement из этого списка.
- shots — только для видео длительностью 8 секунд и больше: 2–3 коротких beat'а по-английски в порядке времени ("the cat crouches", "it pounces on the ball", "the ball rolls under the table"); при slow motion — максимум один beat; beat'ы не описывают камеру. Иначе [].
- audio — если TARGET говорит, что модель генерирует звук: одной короткой фразой по-английски опиши естественный звук сцены (ambient: wind, waves, footsteps, engine…) и отметь это в assumptions; речь, конкретную музыку или голос добавляй только по просьбе пользователя или когда это очевидно («он говорит …»). Если модель звук НЕ генерирует или TARGET не указан — пустая строка.
- НИКОГДА не пиши отрицания ("no text", "without people", "avoid …") ни в одном поле, кроме user_negatives. Поля intent описывают только то, что ДОЛЖНО быть в кадре.
- user_negatives — ТОЛЬКО то, что пользователь явно попросил убрать, своими словами, переведёнными на английский. Иначе []. Не добавляй собственные догадки: это проверяется кодом и лишнее будет вырезано.
- text_in_image — только точная надпись, которую пользователь явно попросил показать, дословно и на языке оригинала. Иначе пустая строка. Не выдумывай текст.
- i2v_preserve — что обязано остаться неизменным на исходном фото/видео. Исходник тебе НЕ виден: не описывай внешность, одежду или фон, которых не назвал пользователь.
- Для генерации по исходному фото/видео (пайплайн i2v, r2v, v2v) subject и environment описывают ИЗМЕНЕНИЕ, а не пересказ кадра: что начинает двигаться и как. Не пересказывай содержимое исходника.
- extras — до 2 редких важных деталей, не вошедших в другие поля. Пустые поля оставляй пустыми строками или массивами, не заполняй ради полноты.
Для логотипов, иконок и плоской графики (kind=image) lighting, camera.angle и mood оставляй пустыми — у них нет света и ракурса.
Не повторяй одни и те же слова в разных полях (если environment уже назвал окно — lighting не повторяет «window»; subject не повторяется в action и composition).
ENRICHMENT: короткая, но конкретная идея («кот играет с мячом на кухне») — не повод спрашивать. Дополни её 3–6 конкретными визуальными деталями (признаки объекта, свет, ракурс, движение камеры, палитра), не меняя смысла, и перечисли добавленное в assumptions. Запрещено придумывать бренды, продукты, оффер, цены, знаменитостей, текст в кадре и дополнительных персонажей, которых пользователь не упоминал.
CONVERSATION LANGUAGE (строго): summary, questions, options и assumptions пиши на ЯЗЫКЕ ИДЕИ пользователя (или содержательного последнего свободного ответа, если он сменил язык) — английская идея → всё по-английски, испанская → по-испански, русская → по-русски. Язык этой инструкции (русский) НЕ является языком общения. Поле idea_script в сообщении подсказывает письменность идеи. Явная просьба о языке общения приоритетна. Интерфейс, короткий выбор варианта, пустой ответ и цитаты НЕ определяют язык общения.
При action=analyze выдели уже известные объект, действие, окружение, стиль и параметры context. Задай от 0 до 3 коротких вопросов только о том, что существенно меняет результат. Приоритет: противоречия → отсутствующий главный объект/действие → ключевой визуальный выбор. Один вопрос — одно решение. Не спрашивай уже указанное и не переспрашивай durationOrSize/quality/kind. Если пользователь делегирует детали или идея уже конкретна — сразу заполни intent, questions=[].
Вопросы должны быть понятны новичку и привязаны к его идее. В options 2–4 конкретных коротких ответа, последний — эквивалент «На ваш выбор» на языке общения. При наличии вопросов intent можно оставить с пустыми полями.
QUALITY CONTRACT: расплывчатое задание — это задача на выяснение, а не разрешение придумать бизнес пользователя. Для «сделай что-нибудь крутое», «красиво для бизнеса» сначала выясни, ЧТО показано или продаётся и ЧТО должно происходить. Никогда не выдумывай продукт, аудиторию, бренд, оффер, скидку или рекламное утверждение. Название модели — не объект съёмки. Избегай жаргона и абстракций вроде «высокое качество». Если объект неизвестен, не задавай подчинённых вопросов, предполагающих конкретный объект.
Для action=compose оцени, решают ли ответы ключевую неопределённость. Если содержательный ответ всё ещё расплывчат или вносит критическое противоречие — верни 1–2 точечных вопроса и пустой intent при round < 3. Опирайся на все предыдущие ответы, не повторяй решённые вопросы. Если пользователь не понял вопрос — объясни недостающее одной дружелюбной фразой в summary и задай более простой вопрос с конкретным примером («Что вы продаёте? Например, цветочные букеты, кофе или ремонт автомобилей»). Примеры — это предложения, а не факты о пользователе. Явное делегирование, пустой ответ или «на ваш выбор» означают: выбери разумные значения по умолчанию и заполняй intent сейчас. При round >= 3 всегда заполняй intent с questions=[] и раскрывай нерешённое в assumptions.
Не меняй модель, качество, длительность и размер и не вписывай их в intent: этим управляет код. assumptions — до 4 коротких пунктов на языке общения, описывающих ТОЛЬКО то, что добавил ты. Summary до 200 символов.
TXT;

$allBlank = $latestCount > 0;
foreach (array_slice($answers, -$latestCount) as $a) if (trim($a['answer']) !== '') $allBlank = false;
$mustCompose = $action === 'compose' && ($round >= 3 || $allBlank);
if ($mustCompose) {
    $system .= "\nCURRENT TURN DECISION (overrides clarification rules): заполни intent сейчас, questions=[]. Пользователь делегировал ответы или достигнут последний раунд. Выбери простой связный визуальный замысел для нерешённых деталей и раскрой их в assumptions. Не задавай вопросов.";
}
/* Target facts, purely so the intent fits the model (one camera move for a DoP
   model, no audio line for a silent model). Formatting stays with the adapter. */
if (!empty($profile['id'])) {
    $hint = sprintf("\nTARGET (справочно; форматированием занимается код): %s, тип %s, пайплайн %s, длительность/размер: %s, звук: %s.",
        $profile['name'], $profile['kind'], $profile['pipeline'] ?? '-',
        $clean['durationOrSize'] !== '' ? $clean['durationOrSize'] : '-',
        !empty($profile['params']['audio']) ? 'модель генерирует звук' : 'модель НЕ генерирует звук, audio оставь пустым');
    if (lx_is_higgsfield($profile)) {
        $presets = array_map(function ($p) { return $p[0]; }, lx_dop_presets());
        if (!empty($profile['camera_presets']) && is_array($profile['camera_presets'])) $presets = $profile['camera_presets'];
        $hint .= ' Доступные приёмы камеры (camera.movement — ровно один из них): ' . implode(', ', $presets) . '.';
    }
    if (($profile['dialect'] ?? '') === 'tags') $hint .= ' Модель понимает короткие фразы: держи каждое поле коротким (2–6 слов).';
    if (preg_match('/illustrious|pony|anime/i', ($profile['id'] ?? '') . ' ' . implode(' ', (array)($profile['model_sets'] ?? [])))) {
        $hint .= ' Это аниме-модель с danbooru-тегами: subject формулируй как короткие теги через запятую (1girl, solo, pink hair, short hair, black techwear), environment/lighting — тоже тегами (cyberpunk city, night, rain, neon lights), без длинных описаний.';
    }
    if (preg_match('/^(i2v|r2v|v2v)$/', $profile['pipeline'] ?? '')) {
        $hint .= ' Исходный кадр/видео уже есть: subject — коротко, как опорное слово (the man, the jar), без внешности; environment, composition и color оставь пустыми (их задаёт кадр); action — только то, что ДОЛЖНО ИЗМЕНИТЬСЯ (движение, мимика); lighting — только если свет должен измениться; i2v_preserve — что сохранить; assumptions — только про движение, камеру, свет и звук, не про внешность и палитру.';
    }
    if (($profile['text_render'] ?? '') === 'weak') $hint .= ' Модель плохо рисует надписи — если текст просили, всё равно заполни text_in_image, код предупредит пользователя.';
    $system .= $hint;
}

/** Script of the idea, so the model never answers an English user in Russian. */
function lx_script_hint($text)
{
    if (preg_match('/\p{Cyrillic}/u', $text)) return 'cyrillic';
    if (preg_match('/\p{Han}|\p{Hiragana}|\p{Katakana}|\p{Hangul}/u', $text)) return 'cjk';
    if (preg_match('/\p{Arabic}/u', $text)) return 'arabic';
    if (preg_match('/\p{Hebrew}/u', $text)) return 'hebrew';
    if (preg_match('/\p{Devanagari}|\p{Bengali}|\p{Thai}|\p{Greek}|\p{Georgian}|\p{Armenian}/u', $text)) return 'other';
    return 'latin (NOT Russian: answer in the idea\'s own language)';
}
$userPayload = [
    'action'      => $action,
    'idea'        => trim($d['idea']),
    'idea_script' => lx_script_hint(trim($d['idea'])),
    'context' => $clean,
    'answers' => $answers,
    'round'   => $round,
];
if ($userNegativeRaw !== '') $userPayload['user_negative_prompt'] = $userNegativeRaw;

/** Build the request payload for one provider, in its own wire dialect. */
function lx_payload($prov, $system, $userPayload)
{
    if (($prov['kind'] ?? 'openai') === 'anthropic') {
        /* Messages API: system is a top-level field, not a message; no
           response_format — the contract text itself demands raw JSON. */
        return array_merge([
            'model'       => $prov['model'],
            'system'      => $system,
            'messages'    => [
                ['role' => 'user', 'content' => json_encode($userPayload, JSON_UNESCAPED_UNICODE)
                    . "\n\nRespond with ONLY the JSON object described above — no Markdown fences, no commentary before or after it."],
            ],
            'temperature' => 0.2,
            'max_tokens'  => 2400,
        ], $prov['extra']);
    }
    return array_merge([
        'model'           => $prov['model'],
        'messages'        => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => json_encode($userPayload, JSON_UNESCAPED_UNICODE)],
        ],
        'temperature'     => 0.2,
        'max_tokens'      => 2400,
        'response_format' => ['type' => 'json_object'],
    ], $prov['extra']);
}

/** One upstream call; timeout is distinguished from transport failure. */
function lx_call_llm($prov, $payload)
{
    $anthropic = ($prov['kind'] ?? 'openai') === 'anthropic';
    $url     = $prov['base'] . ($anthropic ? '/messages' : '/chat/completions');
    $headers = $anthropic
        ? ['Content-Type: application/json', 'x-api-key: ' . $prov['key'], 'anthropic-version: 2023-06-01']
        : ['Content-Type: application/json', 'Authorization: Bearer ' . $prov['key']];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 75,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);
    $res    = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno  = curl_errno($ch);
    curl_close($ch);
    return [$res, $status, $errno];
}

/** Pull the assistant's text out of either wire dialect's response body. */
function lx_response_text($prov, $body)
{
    if (($prov['kind'] ?? 'openai') === 'anthropic') {
        foreach ((array)($body['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) return $block['text'];
        }
        return '';
    }
    return $body['choices'][0]['message']['content'] ?? '';
}

/** The model sometimes wraps JSON in ``` fences despite response_format. */
function lx_parse_json($content)
{
    $c = trim((string)$content);
    $c = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $c);
    $cand = json_decode($c, true);
    if (is_array($cand)) return $cand;
    if (preg_match('/\{.*\}/su', $c, $m)) { $cand = json_decode($m[0], true); if (is_array($cand)) return $cand; }
    return null;
}

/** Walk the provider chain once; returns [out|null, used, errors[], attempts]. */
function lx_ask(array $providers, $system, array $userPayload)
{
    $out = null; $attempts = 0; $used = ''; $errors = [];
    foreach ($providers as $prov) {
        $payload = lx_payload($prov, $system, $userPayload);
        for ($try = 0; $try < 2; $try++) {
            $attempts++;
            list($res, $status, $errno) = lx_call_llm($prov, $payload);
            if ($errno === CURLE_OPERATION_TIMEDOUT) { $errors[] = $prov['name'] . ':timeout'; if ($try === 0) continue; break; }
            if ($errno) { $errors[] = $prov['name'] . ':curl' . $errno; break; }
            /* auth / credits / quota: retrying the same key is pointless — next provider */
            if (in_array($status, [401, 402, 403, 429], true)) { $errors[] = $prov['name'] . ':' . $status; break; }
            if ($status !== 200) { $errors[] = $prov['name'] . ':' . $status; if ($try === 0) continue; break; }
            $body = json_decode($res, true);
            $cand = lx_parse_json(lx_response_text($prov, (array)$body));
            if (is_array($cand)) { $out = $cand; $used = $prov['name'] . '/' . $prov['model']; break 2; }
            $errors[] = $prov['name'] . ':badjson';           // one automatic retry
        }
    }
    return [$out, $used, $errors, $attempts];
}

/**
 * The one language failure seen in testing: an English idea answered with Russian
 * summary/assumptions (the contract itself is written in Russian). Compare the
 * script of the user's own words with the script of the conversational fields.
 * Quoted text (exact captions) is ignored on both sides.
 */
function lx_lang_mismatch($userText, $out)
{
    $strip = function ($t) { return preg_replace('/["«»“”][^"«»“”]{0,120}["«»“”]/u', ' ', (string)$t); };
    $u = $strip($userText);
    $o = $strip(($out['summary'] ?? '') . ' ' . implode(' ', array_filter((array)($out['assumptions'] ?? []), 'is_string')));
    foreach ((array)($out['questions'] ?? []) as $q) if (is_array($q)) $o .= ' ' . $strip($q['question'] ?? '');
    if (mb_strlen(trim($o), 'UTF-8') < 12) return false;
    $has = function ($t, $re) { return (bool)preg_match($re, $t); };
    $cyr = '/\p{Cyrillic}/u'; $cjk = '/\p{Han}|\p{Hiragana}|\p{Katakana}|\p{Hangul}/u'; $ar = '/\p{Arabic}/u';
    if ($has($u, $cyr))  return !$has($o, $cyr);
    if ($has($u, $cjk))  return !$has($o, $cjk);
    if ($has($u, $ar))   return !$has($o, $ar);
    if ($has($u, '/\p{Hebrew}/u')) return !$has($o, '/\p{Hebrew}/u');
    /* latin idea: any Cyrillic/CJK/Arabic in the conversational fields is a leak */
    return $has($o, $cyr) || $has($o, $cjk) || $has($o, $ar);
}

list($out, $used, $errors, $attempts) = lx_ask($providers, $system, $userPayload);
$langRetried = false;
if (is_array($out)) {
    $userText = trim($d['idea']);
    foreach ($answers as $a) { $v = trim((string)($a['answer'] ?? '')); if ($v !== '') $userText .= ' ' . $v; }
    if (lx_lang_mismatch($userText, $out)) {
        $langRetried = true;
        $fix = $system . "\nLANGUAGE FIX (highest priority): предыдущий ответ был на неверном языке. summary, questions, options и assumptions — СТРОГО на языке, на котором написана идея пользователя (" . lx_script_hint($userText) . "). Поля intent остаются по-английски.";
        list($out2, $used2, $errors2, $attempts2) = lx_ask($providers, $fix, $userPayload);
        $attempts += $attempts2;
        if (is_array($out2) && !lx_lang_mismatch($userText, $out2)) { $out = $out2; $used = $used2; }
    }
}
if (!is_array($out)) {
    $onlyTimeouts = $errors && count(array_filter($errors, function ($e) { return substr($e, -8) === ':timeout'; })) === count($errors);
    if ($onlyTimeouts) {
        reply(504, ['error' => lx_msg('ИИ думает дольше обычного. Повторите запрос — ваша идея сохранена.', 'The AI is taking longer than usual. Try again — your idea is kept.')], $action, ['upstream' => implode(',', $errors)]);
    }
    reply(503, ['error' => lx_msg('Сервис ИИ сейчас недоступен. Повторите через минуту.', 'The AI service is unavailable right now. Try again in a minute.')], $action, ['upstream' => implode(',', $errors), 'tries' => $attempts]);
}

/* --- shape validation ------------------------------------------------- */
if (!is_string($out['summary'] ?? null) || !is_array($out['questions'] ?? null)
    || count($out['questions']) > 3 || !is_array($out['assumptions'] ?? null)) {
    reply(503, ['error' => lx_msg('Ответ ИИ пришёл в неожиданном виде. Повторите запрос.', 'Unexpected AI answer. Try again.')], $action, ['shape' => 'bad', 'provider' => $used]);
}
foreach ($out['questions'] as $q) {
    if (!is_array($q) || !is_string($q['question'] ?? null) || !is_array($q['options'] ?? null) || count($q['options']) > 4) {
        reply(503, ['error' => lx_msg('Повторите запрос: некорректные уточнения.', 'Try again: malformed questions.')], $action, ['shape' => 'questions', 'provider' => $used]);
    }
    foreach ($q['options'] as $o) if (!is_string($o)) reply(503, ['error' => lx_msg('Некорректный ответ ИИ.', 'Malformed AI answer.')], $action);
}
foreach ($out['assumptions'] as $a) if (!is_string($a)) reply(503, ['error' => lx_msg('Некорректный ответ ИИ.', 'Malformed AI answer.')], $action);

$intent = is_array($out['intent'] ?? null) ? $out['intent'] : [];
$hasQuestions = count($out['questions']) > 0;

$intentEmpty = true;
foreach (['subject', 'action', 'environment', 'composition', 'lighting', 'color', 'mood', 'style', 'text_in_image'] as $k) {
    if (is_string($intent[$k] ?? null) && trim($intent[$k]) !== '') { $intentEmpty = false; break; }
}
if ($mustCompose && $hasQuestions) {
    $hasQuestions = false;          // final round: never keep interviewing
    $out['questions'] = [];
}
if (!$hasQuestions && $intentEmpty && $action === 'compose') {
    reply(422, [
        'error'  => lx_msg('ИИ не смог составить замысел по этому описанию. Переформулируйте идею конкретнее — что показываем и что происходит.',
                           'The AI could not build a scene from this description. Say more concretely what is shown and what happens.'),
        'reason' => 'empty_intent',
    ], $action, ['reason' => 'empty_intent', 'provider' => $used]);
}

$response = [
    'summary'     => $out['summary'],
    'questions'   => $out['questions'],
    'assumptions' => array_slice($out['assumptions'], 0, 4),
    'round'       => $round,
];

if ($hasQuestions) {
    $response['intent'] = null;
    $response['prompt'] = '';
    $response['negative_prompt'] = '';
    reply(200, $response, $action, ['questions' => count($out['questions']), 'provider' => $used]);
}

/* User-typed negatives are authoritative and survive the post-filter. */
$intentNeg = [];
foreach ((array)($intent['user_negatives'] ?? []) as $n) if (is_string($n)) $intentNeg[] = $n;
if ($userNegativeRaw !== '') {
    foreach (preg_split('/[,\n;]+/u', $userNegativeRaw) as $piece) {
        $piece = trim($piece);
        if ($piece !== '') $intentNeg[] = $piece;
    }
}
$intent['user_negatives'] = array_values(array_unique($intentNeg));

/* Everything the user actually wrote — the post-filter's evidence base. */
$hay = trim($d['idea']) . ' ' . $userNegativeRaw;
foreach ($answers as $a) $hay .= ' ' . $a['answer'];

$adapted = lx_adapt($intent, $profile, $hay, ['duration' => $clean['durationOrSize'], 'lang' => $LX_UI_LANG]);
$response['intent']            = $intent;
$response['prompt']            = $adapted['prompt'];
$response['negative_prompt']   = $adapted['negative_prompt'];
$response['negative_mode']     = $adapted['negative_mode'];
$response['adapted_for']       = $adapted['adapted_for'];
$response['dialect']           = $adapted['dialect'];
$response['warnings']          = $adapted['warnings'];
$response['removed_negatives'] = $adapted['removed_negatives'];
if ($adapted['assumptions']) $response['assumptions'] = array_slice(array_merge($response['assumptions'], $adapted['assumptions']), 0, 6);

reply(200, $response, $action, [
    'model'    => $profile['id'],
    'provider' => $used,
    'len'      => mb_strlen($adapted['prompt'], 'UTF-8'),
    'removed'  => count($adapted['removed_negatives']),
    'tries'    => $attempts,
]);
