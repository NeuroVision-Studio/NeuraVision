<?php
/** CLI only: e-mail human-support replies the visitor never saw (left the site > 5 min). Cron: every 2 min. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en';
require_once __DIR__ . '/support_lib.php';
try { $n = sup_sweep_emails(); if ($n) echo gmdate('c') . " emailed $n\n"; }
catch (Throwable $e) { fwrite(STDERR, 'support_cron: ' . $e->getMessage() . "\n"); }
