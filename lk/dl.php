<?php
/**
 * /lk/dl.php — was the "dl=1 -> registration -> cabinet C" entry (2026-10-10 morning).
 * The owner cancelled that flow the same day: dl=1 now means landing PATH 3 (download without
 * registration, invisible captcha) -- see lp/lp_path.php. Any old link/bookmark to this URL is
 * forwarded there, never to the signup. Clears the obsolete nv_dl cookie.
 */
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
if (isset($_COOKIE['nv_dl'])) {
    setcookie('nv_dl', '', ['expires' => time() - 3600, 'path' => '/', 'samesite' => 'Lax']);
}
header('Location: /?dl=1', true, 302);
exit;
