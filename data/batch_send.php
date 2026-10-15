<?php
foreach(@file('/www/wwwroot/leronx.org/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $l){
  $l=trim($l); if($l===''||$l[0]==='#'||strpos($l,'=')===false)continue;
  list($k,$v)=array_map('trim',explode('=',$l,2)); putenv("$k=$v"); $_ENV[$k]=$v;
}
require_once '/www/wwwroot/leronx.org/functions/welcome_mail.php';
$list=@file('/www/wwwroot/leronx.org/data/campaign_targets.txt',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[];
$img='https://leronx.org/lp/media/preview/promo_credits.jpg'; $cta='https://leronx.org/account';
$subj='Your 1,000 Neuravision credits expire July 14';
$log=fopen('/www/wwwroot/leronx.org/data/campaign_send.log','a');
$done=[];
foreach(@file('/www/wwwroot/leronx.org/data/campaign_send.log',FILE_IGNORE_NEW_LINES)?:[] as $ll){ if(preg_match('/ OK (\S+)/',$ll,$m)) $done[strtolower($m[1])]=1; }
$sent=0;$fail=0;$rate=0;$skip=0;
foreach($list as $to){
  $to=trim($to); if(!filter_var($to,FILTER_VALIDATE_EMAIL))continue;
  if(isset($done[strtolower($to)])){$skip++;continue;}
  $unsub='https://leronx.org/unsubscribe?e='.rawurlencode($to);
  $html='<!doctype html><html><body style="margin:0;background:#07070c;font-family:Arial,Helvetica,sans-serif;color:#eef0f7"><div style="max-width:520px;margin:0 auto;padding:24px 18px">'
   .'<div style="font-weight:800;font-size:20px;margin-bottom:16px">Neuravision <span style="color:#ff6a1a">2.0</span></div>'
   .'<a href="'.$cta.'"><img src="'.$img.'" width="100%" style="border-radius:16px;display:block;max-width:484px" alt="Your 1,000 credits expire July 14"></a>'
   .'<h1 style="font-size:23px;margin:22px 0 8px">Your 1,000 free credits expire <span style="color:#ffb454">July 14</span></h1>'
   .'<p style="color:#b9bccd;font-size:15px;line-height:1.6">Hi there, you created your Neuravision account but haven\'t installed the app yet. Your <b>1,000 bonus credits</b> (&asymp; 40 AI videos) are still waiting &mdash; but they expire <b>July 14</b> unless you activate. <b style="color:#3ad29f">Download &amp; activate the app and they\'re yours forever.</b></p>'
   .'<p style="color:#b9bccd;font-size:15px;line-height:1.6">Download the app, sign in with this account, and keep every credit.</p>'
   .'<p style="margin:26px 0"><a href="'.$cta.'" style="background:#f59e0b;color:#181004;text-decoration:none;font-weight:800;padding:14px 26px;border-radius:12px;display:inline-block">Get the app &amp; keep my credits &rarr;</a></p>'
   .'<p style="color:#8a8aa0;font-size:12px;line-height:1.6;margin-top:30px;border-top:1px solid #23232e;padding-top:16px">You\'re getting this because you signed up at leronx.org. <a href="'.$unsub.'" style="color:#9aa0b4">Unsubscribe</a> &middot; Neuravision &mdash; AI video studio.</p>'
   .'</div></body></html>';
  $r=resend_send_email($to,$subj,$html,FROM_EMAIL);
  if(!empty($r['ok'])){$sent++;fwrite($log,date('c')." OK $to\n");}
  else{$fail++; if((int)($r['code']??0)===429)$rate++; fwrite($log,date('c')." FAIL $to code=".($r['code']??'')." ".($r['error']??'')."\n");}
  if($rate>=3){fwrite($log,date('c')." STOPPED (rate-limited) after sent=$sent\n");break;}
  usleep(400000);
}
fwrite($log,date('c')." === DONE sent=$sent fail=$fail ===\n"); fclose($log);
echo "sent=$sent fail=$fail skip=$skip\n";
