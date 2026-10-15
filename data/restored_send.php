<?php
/* ============================================================================
 * "SERVICE RESTORED + APOLOGY" broadcast to everyone who registered (2026-07-14)
 *   php restored_send.php         -> dry run (audience count only)
 *   php restored_send.php test    -> one preview to the owner
 *   php restored_send.php send    -> real broadcast (idempotent, rate-limited)
 * ==========================================================================*/
date_default_timezone_set('UTC');
$OWNER='adrianklrpczki@gmail.com';
$CTA='https://leronx.org/account';
$LOGF='/www/wwwroot/leronx.org/data/restored_send.log';
$KEYS='/www/wwwroot/neuracreation.com/api/app_data/keys.db';
$USERS='/www/wwwroot/leronx.org/data/users.sqlite';
$SUBJ="We're back — and sorry for the wait 🙏 Your 1,000 credits are ready";

foreach(@file('/www/wwwroot/leronx.org/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $l){
  $l=trim($l); if($l===''||$l[0]==='#'||strpos($l,'=')===false) continue;
  list($k,$v)=array_map('trim',explode('=',$l,2)); putenv("$k=$v"); $_ENV[$k]=$v;
}
require_once '/www/wwwroot/leronx.org/functions/welcome_mail.php';

function lx_restored_html($cta){
  $unsub='https://leronx.org/unsubscribe';
  return '<!doctype html><html><body style="margin:0;background:#07070c;font-family:Arial,Helvetica,sans-serif;color:#eef0f7"><div style="max-width:520px;margin:0 auto;padding:24px 18px">'
   .'<div style="font-weight:800;font-size:20px;margin-bottom:18px">Neuravision <span style="color:#ff6a1a">2.0</span></div>'
   .'<div style="background:linear-gradient(100deg,rgba(58,210,159,.15),rgba(6,182,212,.12));border:1px solid rgba(58,210,159,.4);border-radius:16px;padding:18px 20px;text-align:center;margin-bottom:22px">'
     .'<div style="font-size:13px;letter-spacing:.12em;text-transform:uppercase;color:#6ee7b7;font-weight:700;margin-bottom:6px">✅ Service restored</div>'
     .'<div style="font-size:24px;font-weight:800;color:#fff">We&rsquo;re back up and running</div>'
   .'</div>'
   .'<h1 style="font-size:22px;margin:0 0 10px">Sorry for the wait — thank you for your patience 🙏</h1>'
   .'<p style="color:#b9bccd;font-size:15px;line-height:1.6">We just finished maintenance on Neuravision. Everything is <b style="color:#3ad29f">fully restored</b> and working again &mdash; download and activation included.</p>'
   .'<p style="color:#b9bccd;font-size:15px;line-height:1.6">Your account and your <b>1,000 free credits</b> (&asymp; 40 AI videos) are safe and ready. Jump back in, download the app and activate to start creating.</p>'
   .'<p style="margin:26px 0"><a href="'.$cta.'" style="background:#f59e0b;color:#181004;text-decoration:none;font-weight:800;padding:15px 28px;border-radius:12px;display:inline-block">Open my account &amp; start creating &rarr;</a></p>'
   .'<p style="color:#b9bccd;font-size:14px;line-height:1.6">Thanks again for sticking with us. If you hit any issue, just reply to this email.</p>'
   .'<p style="color:#8a8aa0;font-size:12px;line-height:1.6;margin-top:30px;border-top:1px solid #23232e;padding-top:16px">You&rsquo;re getting this because you signed up at leronx.org. <a href="'.$unsub.'" style="color:#9aa0b4">Unsubscribe</a> &middot; Neuravision &mdash; AI video studio.</p>'
   .'</div></body></html>';
}

$mode = isset($argv[1]) ? $argv[1] : 'dry';

if($mode==='test'){
  $r=resend_send_email($OWNER,$SUBJ,lx_restored_html($CTA),FROM_EMAIL);
  echo !empty($r['ok']) ? "TEST sent to $OWNER\n" : ("TEST FAIL code=".($r['code']??'')." ".($r['error']??'')."\n");
  exit;
}

/* gather everyone who registered (keys.db OAuth + users.sqlite email), dedup by lowercase email */
$emails=[];
try{ $k=new SQLite3($KEYS,SQLITE3_OPEN_READONLY); $k->busyTimeout(4000);
  $res=$k->query("SELECT DISTINCT lower(email) e FROM users WHERE email LIKE '%@%.%'");
  while($row=$res->fetchArray(SQLITE3_ASSOC)){ $e=trim($row['e']); if($e) $emails[$e]=1; }
  $k->close(); }catch(Throwable $e){}
try{ $u=new PDO('sqlite:'.$USERS); foreach($u->query("SELECT DISTINCT lower(email) e FROM users WHERE email LIKE '%@%.%'") as $row){ $e=trim($row['e']); if($e) $emails[$e]=1; } }catch(Throwable $e){}
$list=array_keys($emails);

$done=[]; foreach(@file($LOGF,FILE_IGNORE_NEW_LINES)?:[] as $ll){ if(preg_match('/ OK (\S+)/',$ll,$m)) $done[strtolower($m[1])]=1; }
$valid=array_filter($list,function($e){return filter_var($e,FILTER_VALIDATE_EMAIL);});
$pending=array_filter($valid,function($e)use($done){return !isset($done[$e]);});

if($mode!=='send'){
  echo "DRY: total_unique=".count($list)." valid=".count($valid)." already_sent=".count($done)." pending=".count($pending)."\n";
  exit;
}

$log=fopen($LOGF,'a'); $sent=0;$fail=0;$rate=0;
foreach($pending as $to){
  $r=resend_send_email($to,$SUBJ,lx_restored_html($CTA),FROM_EMAIL);
  if(!empty($r['ok'])){$sent++;fwrite($log,date('c')." OK $to\n");}
  else{$fail++; if((int)($r['code']??0)===429)$rate++; fwrite($log,date('c')." FAIL $to code=".($r['code']??'')."\n");}
  if($rate>=3){fwrite($log,date('c')." STOPPED rate-limited sent=$sent\n");break;}
  usleep(350000);
}
fwrite($log,date('c')." === DONE sent=$sent fail=$fail ===\n"); fclose($log);
echo "sent=$sent fail=$fail\n";
