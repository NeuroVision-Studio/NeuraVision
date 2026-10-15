<?php
require_once __DIR__.'/../includes/nv_i18n/engine/nv_i18n.php'; /* nv_i18n: site localisation (one line, removable) */
/**
 * /lk/ — router for the 50/50 cabinet split test.
 *
 * One visitor -> one variant, forever: the pick is hashed from a stable id (the signed-in
 * user id, otherwise the lk_vid visitor cookie), then pinned in the `lk_ab` cookie (180 d)
 * and in users.lk_variant so it survives a new device. `?lk=a` / `?lk=b` forces a variant
 * for QA and re-pins it.
 *
 * The analytics side reads the `lk_ab` cookie; lk_core.php also logs an `lk_view` event
 * (meta `lka` / `lkb`) into the existing tracker.
 */
define('LK_ROUTED', 1);
require_once __DIR__ . '/lk_core.php';

$C = lk_boot();
if ($C['variant'] === 'b') { require __DIR__ . '/b.php'; }
elseif ($C['variant'] === 'c') { require __DIR__ . '/c.php'; }
else { require __DIR__ . '/a.php'; }
