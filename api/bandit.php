<?php
/**
 * Neuravision bandit aggregation endpoint — api/bandit.php
 * GET  /api/bandit?test=<theme_color|hero_video>  -> {arm:{n,c}, ...} real cross-visitor counts.
 * POST /api/bandit  {test,arm,event:"exposure"|"convert"}  -> increments the count, 204.
 * Persisted in the tracker's own SQLite DB (lx_db()) so it survives restarts/deploys.
 * Previously this route had no nginx location, so every call silently fell through to the
 * site's catch-all and returned the homepage HTML -- the bandits never learned. 2026-10-10.
 */
header('Content-Type: application/json');

require __DIR__ . '/../new/tracker/lx_track_lib.php';

const BANDIT_ARMS = [
    'theme_color' => ['dark', 'violet', 'light', 'orange'],
    'hero_video'  => ['hero', 'hero-v1', 'hero-v2', 'hero-v3', 'hero-bright', 'hero-lit'],
];

function bandit_db(): ?PDO {
    $pdo = lx_db();
    if (!$pdo) return null;
    $pdo->exec("CREATE TABLE IF NOT EXISTS bandit_counts (
        test TEXT NOT NULL,
        arm  TEXT NOT NULL,
        n    INTEGER NOT NULL DEFAULT 0,
        c    INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (test, arm)
    )");
    return $pdo;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $test = lx_clean($_GET['test'] ?? '', 32);
    $out = [];
    if ($test !== null && isset(BANDIT_ARMS[$test])) {
        $db = bandit_db();
        if ($db) {
            $st = $db->prepare('SELECT arm, n, c FROM bandit_counts WHERE test = ?');
            $st->execute([$test]);
            foreach ($st->fetchAll() as $row) {
                $out[$row['arm']] = ['n' => (int)$row['n'], 'c' => (int)$row['c']];
            }
        }
        foreach (BANDIT_ARMS[$test] as $arm) {
            if (!isset($out[$arm])) { $out[$arm] = ['n' => 0, 'c' => 0]; }
        }
    }
    echo json_encode($out);
    exit;
}

if ($method === 'POST') {
    $isBot = lx_is_crawler_ua() || lx_is_audit_ua() || (($_COOKIE['lx_notrack'] ?? '') === '1');
    if (!$isBot) {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (is_array($data)) {
            $test  = lx_clean($data['test'] ?? '', 32);
            $arm   = lx_clean($data['arm'] ?? '', 32);
            $event = lx_clean($data['event'] ?? '', 16);
            if ($test !== null && $arm !== null && isset(BANDIT_ARMS[$test])
                && in_array($arm, BANDIT_ARMS[$test], true)
                && in_array($event, ['exposure', 'convert'], true)) {
                $db = bandit_db();
                if ($db) {
                    $col = $event === 'exposure' ? 'n' : 'c';
                    try {
                        $db->prepare("INSERT INTO bandit_counts (test, arm, $col) VALUES (?, ?, 1)
                                      ON CONFLICT(test, arm) DO UPDATE SET $col = $col + 1")
                           ->execute([$test, $arm]);
                    } catch (Throwable $e) {}
                }
            }
        }
    }
    http_response_code(204);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false]);
