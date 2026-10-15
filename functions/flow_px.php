<?php
/**
 * /functions/flow_px.php -- Google Ads REGISTRATION conversion for the static /flow/ pages (2026-10-10).
 *
 * functions/auth_verify_code.php and functions/auth_google_finish.php set cookie lx_gads_reg=1 after a sign-up.
 * flow/flow-track.js (setup.html, fallback download.html) loads THIS file as a <script> only when that cookie is
 * present. Static HTML cannot evaluate the pixel rules, so they are applied here, exactly like includes/gtag.php:
 *   - cookie lx_notrack (any non-empty value)  -> no pixel
 *   - includes/geo_block.php lx_geo_blocked()  -> no pixel (owner policy IN/PK/BD/NG, not changed here)
 *   - account older than 24 h (Google sign-in of an existing user also sets the flag) -> no pixel
 *   - transaction_id already sent (data/tracker.sqlite gads_reg_fired)            -> no pixel
 * Otherwise it returns JS that loads gtag, applies the site's consent defaults (includes/consent.php) and fires
 * AW-18092768686/UqDTCLri_sIcEK77prND once with transaction_id = "nvreg-<user id>" (dedup key), clears the cookie,
 * and acks back here (?ack=1) so the tid is recorded and a reload / second tab never fires again.
 */
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex');

const NV_PX_SEND_TO = 'AW-18092768686/UqDTCLri_sIcEK77prND';
const NV_PX_TAG     = 'AW-18092768686';

function nv_px_clear_cookie() {
    if (!headers_sent()) setcookie('lx_gads_reg', '', ['expires' => time() - 3600, 'path' => '/', 'samesite' => 'Lax']);
}
function nv_px_sid() {
    return preg_replace('/[^A-Za-z0-9-]/', '', substr((string)($_COOKIE['lx_sid'] ?? ''), 0, 36));
}

$trk = __DIR__ . '/../new/tracker/lx_track_lib.php';
if (is_file($trk)) require_once $trk;

$page = preg_replace('/[^a-z]/', '', substr((string)($_GET['p'] ?? ''), 0, 12));

/* ---- ack: the browser queued the conversion ---- */
if (isset($_GET['ack'])) {
    header('Content-Type: application/json');
    nv_px_clear_cookie();
    $tid = preg_replace('/[^A-Za-z0-9-]/', '', substr((string)($_GET['tid'] ?? ''), 0, 64));
    if ($tid !== '' && function_exists('lx_db') && ($db = lx_db())) {
        $u = function_exists('lx_cookie_user') ? lx_cookie_user() : null;
        try {
            $st = $db->prepare('INSERT OR IGNORE INTO gads_reg_fired (tid, user_id, sid, page, fired_at) VALUES (?,?,?,?,?)');
            $st->execute([$tid, $u ? $u['id'] : null, nv_px_sid(), $page, lx_now()]);
            if ($st->rowCount() > 0 && nv_px_sid() !== '' && empty($_COOKIE['lx_notrack'])) lx_event(nv_px_sid(), 'gads_reg_conv', $tid . '|' . $page);
        } catch (Throwable $e) {}
    }
    echo '{"ok":true}';
    exit;
}

header('Content-Type: application/javascript; charset=utf-8');

$reason = '';
if ((string)($_COOKIE['lx_gads_reg'] ?? '') !== '1') {
    $reason = 'no_flag';
} elseif (!empty($_COOKIE['lx_notrack'])) {
    $reason = 'notrack';
} else {
    require_once __DIR__ . '/../includes/geo_block.php';
    if (function_exists('lx_geo_blocked') && lx_geo_blocked()) $reason = 'geo';
}
$u = null; $tid = '';
if ($reason === '') {
    $u = function_exists('lx_cookie_user') ? lx_cookie_user() : null;
    if ($u && $u['created_ts'] > 0 && (time() - $u['created_ts']) > 86400) {
        $reason = 'existing_account';
    } else {
        $tid = $u ? ('nvreg-' . $u['id']) : ('nvreg-s' . substr(hash('sha256', 'nvreg|' . (nv_px_sid() ?: bin2hex(random_bytes(8)))), 0, 24));
        if (function_exists('lx_db') && ($db = lx_db())) {
            try {
                $st = $db->prepare('SELECT 1 FROM gads_reg_fired WHERE tid = ? LIMIT 1');
                $st->execute([$tid]);
                if ($st->fetchColumn()) $reason = 'already_sent';
            } catch (Throwable $e) {}
        }
    }
}

if ($reason !== '') {
    if ($reason !== 'no_flag') nv_px_clear_cookie();
    echo "/* nv flow px: skip (" . $reason . ") */\n";
    exit;
}

require_once __DIR__ . '/../includes/consent.php';
$consent = function_exists('lx_consent_default_js') ? lx_consent_default_js() : '';
?>
/* nv flow px: Google Ads registration conversion, once */
(function(){
  var TID=<?php echo json_encode($tid); ?>, P=<?php echo json_encode($page); ?>;
  document.cookie='lx_gads_reg=;path=/;max-age=0;SameSite=Lax';
  window.dataLayer=window.dataLayer||[];
  if(typeof window.gtag!=='function'){window.gtag=function(){dataLayer.push(arguments);};}
  <?php echo $consent; ?>

  gtag('js',new Date());gtag('config',<?php echo json_encode(NV_PX_TAG); ?>);
  var acked=false;function ack(){if(acked)return;acked=true;try{var u='/functions/flow_px.php?ack=1&p='+encodeURIComponent(P)+'&tid='+encodeURIComponent(TID);if(!(navigator.sendBeacon&&navigator.sendBeacon(u)))fetch(u,{method:'POST',credentials:'same-origin',keepalive:true});}catch(e){}}
  gtag('event','conversion',{'send_to':<?php echo json_encode(NV_PX_SEND_TO); ?>,'transaction_id':TID,'event_callback':ack});
  setTimeout(ack,3000);
  var s=document.createElement('script');s.async=true;s.src='https://www.googletagmanager.com/gtag/js?id=<?php echo NV_PX_TAG; ?>';(document.head||document.documentElement).appendChild(s);
})();
