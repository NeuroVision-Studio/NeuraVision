<?php
if (file_exists('/www/wwwroot/leronx.org/data/.maintenance')) { echo "maintenance — skipped\n"; exit; }
/* Rolling 24h bonus expiry (per new user).
 * Zeroes the 1,000 bonus for NON-activated (hwid empty) OAuth accounts that registered
 * AT/AFTER the switch cutoff and are now older than 24h. Activated accounts keep credits forever.
 * Runs hourly via cron. Only NEW users (created_at >= cutoff) are affected — existing users untouched. */
date_default_timezone_set('UTC'); // keys.db created_at is UTC (server=UTC, php default=PRC)
$cutoff   = '2026-07-14 03:51:00';                 // 24h rule applies only to users from this switch on
$deadline = date('Y-m-d H:i:s', time() - 86400);   // server-local (matches keys.db created_at TZ)
$db = '/www/wwwroot/neuracreation.com/api/app_data/keys.db';
if (!file_exists($db)) { echo "no db\n"; exit; }
$where = "(hwid IS NULL OR hwid='') AND credits>0 AND created_at >= '$cutoff' AND created_at < '$deadline'";
$p = new SQLite3($db); $p->busyTimeout(8000);
$n = (int)$p->querySingle("SELECT COUNT(*) FROM users WHERE $where");
if ($n > 0) {
    @copy($db, $db.'.bak_expire_'.date('Ymd_His'));
    $p->exec("UPDATE users SET credits=0 WHERE $where");
    @file_put_contents('/www/wwwroot/leronx.org/data/expire.log',
        date('c')." expired 24h bonus for $n non-activated new account(s)\n", FILE_APPEND);
}
$p->close();
echo "checked (cutoff $cutoff, deadline $deadline); expired=$n\n";
