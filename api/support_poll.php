<?php
/**
 * Support chat widget poll (2026-10-10).
 * GET ?s=<dialog token>&after=<msg id>
 *   full=1   -> full history (last 60 messages, all roles) for a returning visitor
 *   else     -> only new human-support replies with id > after
 * Also a heartbeat: dialogs.last_seen (used for the "visitor left > 5 min -> e-mail" rule).
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../functions/support_lib.php';

$out = function ($a, $code = 200) { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };

try {
    $d = sup_dialog_by_token($_GET['s'] ?? '');
    if (!$d) $out(['ok' => false, 'error' => 'unknown']);
    if (!sup_rate_ok('poll:' . (int)$d['id'], 40, 60)) $out(['ok' => false, 'error' => 'rate_limited'], 429);
    $db = sup_db(); $now = time();
    if ($now - (int)$d['last_seen'] >= 10) $db->prepare('UPDATE dialogs SET last_seen=? WHERE id=?')->execute([$now, (int)$d['id']]);
    $after = max(0, (int)($_GET['after'] ?? 0));
    if (!empty($_GET['full'])) {
        $st = $db->prepare("SELECT id, role, text, created_at, seen_at FROM messages WHERE dialog_id=? AND role IN ('user','ai','human') ORDER BY id DESC LIMIT 60");
        $st->execute([(int)$d['id']]);
        $rows = array_reverse($st->fetchAll());
    } else {
        $st = $db->prepare("SELECT id, role, text, created_at, seen_at FROM messages WHERE dialog_id=? AND role='human' AND id>? ORDER BY id LIMIT 20");
        $st->execute([(int)$d['id'], $after]);
        $rows = $st->fetchAll();
    }
    $ids = [];
    foreach ($rows as $r) if ($r['role'] === 'human') $ids[] = (int)$r['id'];
    if ($ids) $db->exec('UPDATE messages SET seen_at=' . $now . ' WHERE seen_at IS NULL AND id IN (' . implode(',', $ids) . ')');
    $msgs = array_map(function ($r) { return ['id' => (int)$r['id'], 'role' => $r['role'], 'text' => $r['text'], 't' => (int)$r['created_at'],
        'fresh' => $r['role'] === 'human' && $r['seen_at'] === null]; }, $rows);
    $out(['ok' => true, 'msgs' => $msgs, 'human_mode' => (int)$d['ai_muted_until'] > $now]);
} catch (Throwable $e) {
    error_log('support_poll: ' . $e->getMessage());
    $out(['ok' => false, 'error' => 'busy']);   // widget simply polls again
}
