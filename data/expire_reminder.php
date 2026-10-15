<?php
if (file_exists('/www/wwwroot/leronx.org/data/.maintenance')) { echo "maintenance — skipped\n"; exit; }
/* ============================================================================
 * PER-USER 24h EXPIRY REMINDER  (2026-07-09)
 * Emails non-activated OAuth users (keys.db) whose 1,000 bonus credits are about
 * to expire on their personal 24h deadline, with the REAL hours left. Idempotent
 * (one reminder per user_id). Runs hourly. Honest: only users whose credits
 * genuinely still expire (hwid empty, credits>0, created <24h ago) are targeted.
 *
 *   php expire_reminder.php test    -> sends ONE preview to the owner, no real send
 *   php expire_reminder.php         -> real run (guarded by ENABLE flag below)
 * ==========================================================================*/
date_default_timezone_set('UTC');                 // keys.db created_at is UTC
$OWNER   = 'adrianklrpczki@gmail.com';
$ENABLE  = true;                                  // LIVE: owner approved 2026-07-09 ("давай всем подряд рассылать")
$KEYS_DB = '/www/wwwroot/neuracreation.com/api/app_data/keys.db';
$DL_DB   = '/www/wwwroot/api.leronx.xyz/xi3uFo6q820bPd92B3d4.sqlite';  // user_downloads (who clicked download)
$LOGF    = '/www/wwwroot/leronx.org/data/expire_reminder.log';
$CTA     = 'https://leronx.org/account';
$WINDOW_LEFT_MAX = 8;                             // email when <=8h left (i.e. created >=16h ago)

foreach(@file('/www/wwwroot/leronx.org/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $l){
  $l=trim($l); if($l===''||$l[0]==='#'||strpos($l,'=')===false) continue;
  list($k,$v)=array_map('trim',explode('=',$l,2)); putenv("$k=$v"); $_ENV[$k]=$v;
}
require_once '/www/wwwroot/leronx.org/functions/welcome_mail.php';

$TEST = (isset($argv[1]) && $argv[1]==='test');

function lx_reminder_html($hours,$cta){
  $h = max(1,(int)round($hours));
  $unsub='https://leronx.org/unsubscribe';
  return '<!doctype html><html><body style="margin:0;background:#07070c;font-family:Arial,Helvetica,sans-serif;color:#eef0f7"><div style="max-width:520px;margin:0 auto;padding:24px 18px">'
   .'<div style="font-weight:800;font-size:20px;margin-bottom:18px">Neuravision <span style="color:#ff6a1a">2.0</span></div>'
   .'<div style="background:linear-gradient(100deg,rgba(245,158,11,.16),rgba(239,68,68,.12));border:1px solid rgba(245,158,11,.45);border-radius:16px;padding:18px 20px;text-align:center;margin-bottom:22px">'
     .'<div style="font-size:13px;letter-spacing:.12em;text-transform:uppercase;color:#fdba74;font-weight:700;margin-bottom:6px">⏳ Time is running out</div>'
     .'<div style="font-size:30px;font-weight:800;color:#fff">~'.$h.' hour'.($h==1?'':'s').' left</div>'
     .'<div style="font-size:13px;color:#fde9c8;margin-top:4px">until your 1,000 free credits expire</div>'
   .'</div>'
   .'<h1 style="font-size:22px;margin:0 0 10px">Activate your account before your credits are gone</h1>'
   .'<p style="color:#b9bccd;font-size:15px;line-height:1.6">You created your Neuravision account but haven\'t activated it yet. Your <b>1,000 bonus credits</b> (&asymp; 40 AI videos) expire in about <b style="color:#ffb454">'.$h.' hour'.($h==1?'':'s').'</b>.</p>'
   .'<p style="color:#b9bccd;font-size:15px;line-height:1.6"><b style="color:#3ad29f">Activate now and they\'re yours forever.</b> Just open your account, download the app and sign in &mdash; it takes a minute.</p>'
   .'<p style="margin:26px 0"><a href="'.$cta.'" style="background:#f59e0b;color:#181004;text-decoration:none;font-weight:800;padding:15px 28px;border-radius:12px;display:inline-block">Activate &amp; keep my 1,000 credits &rarr;</a></p>'
   .'<p style="color:#8a8aa0;font-size:12px;line-height:1.6;margin-top:30px;border-top:1px solid #23232e;padding-top:16px">You\'re getting this because you signed up at leronx.org. <a href="'.$unsub.'" style="color:#9aa0b4">Unsubscribe</a> &middot; Neuravision &mdash; AI video studio.</p>'
   .'</div></body></html>';
}

/* ---- TEST MODE: one preview to the owner ---- */
if($TEST){
  $html=lx_reminder_html(6,$CTA);
  $r=resend_send_email($OWNER,'⏳ ~6 hours left — activate to keep your 1,000 Neuravision credits',$html,FROM_EMAIL);
  echo !empty($r['ok']) ? "TEST sent to $OWNER\n" : ("TEST FAILED code=".($r['code']??'')." ".($r['error']??'')."\n");
  exit;
}

/* ---- REAL RUN ---- */
if(!$ENABLE){ echo "disabled (set \$ENABLE=true to allow the real batch)\n"; exit; }

$done=[];
foreach(@file($LOGF,FILE_IGNORE_NEW_LINES)?:[] as $ll){ if(preg_match('/ OK (\S+)/',$ll,$m)) $done[$m[1]]=1; }

$db=new SQLite3($KEYS_DB,SQLITE3_OPEN_READONLY); $db->busyTimeout(5000);
$q=$db->query("SELECT user_id, email,
   ROUND(24-(julianday('now')-julianday(created_at))*24,1) AS hours_left
   FROM users
   WHERE (hwid IS NULL OR hwid='') AND credits>0 AND email!=''
     AND created_at >= '2026-07-14 03:51:00' AND created_at >= datetime('now','-1 day')
     AND (julianday('now')-julianday(created_at))*24 >= (24-$WINDOW_LEFT_MAX)");   /* EVERYONE with credits still at risk, 8h before expiry */
$log=fopen($LOGF,'a'); $sent=0;$fail=0;$skip=0;$rate=0;
while($row=$q->fetchArray(SQLITE3_ASSOC)){
  $uid=$row['user_id']; $to=trim($row['email']); $hrs=(float)$row['hours_left'];
  if($hrs<=0) continue;
  if(isset($done[$uid])){$skip++;continue;}
  if(!filter_var($to,FILTER_VALIDATE_EMAIL)){$skip++;continue;}
  $h=max(1,(int)round($hrs));
  $subj='⏳ ~'.$h.' hour'.($h==1?'':'s').' left — activate to keep your 1,000 Neuravision credits';
  $r=resend_send_email($to,$subj,lx_reminder_html($hrs,$CTA),FROM_EMAIL);
  if(!empty($r['ok'])){$sent++;fwrite($log,date('c')." OK $uid $to ${h}h\n");}
  else{$fail++; if((int)($r['code']??0)===429)$rate++; fwrite($log,date('c')." FAIL $uid code=".($r['code']??'')."\n");}
  if($rate>=3){fwrite($log,date('c')." STOPPED rate-limited sent=$sent\n");break;}
  usleep(400000);
}
$db->close(); fwrite($log,date('c')." === DONE sent=$sent fail=$fail skip=$skip ===\n"); fclose($log);
echo "sent=$sent fail=$fail skip=$skip\n";
