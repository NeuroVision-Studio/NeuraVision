<?php
foreach(@file('/www/wwwroot/leronx.org/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $l){$l=trim($l);if($l===''||$l[0]==='#'||strpos($l,'=')===false)continue;list($k,$v)=array_map('trim',explode('=',$l,2));putenv("$k=$v");$_ENV[$k]=$v;}
require_once '/www/wwwroot/leronx.org/functions/welcome_mail.php';
$list=@file('/www/wwwroot/leronx.org/data/ios_targets.txt',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[];
$cta='https://leronx.org/account'; $subj='Neuravision for iOS is coming — start now & keep your credits';
$log=fopen('/www/wwwroot/leronx.org/data/ios_send.log','a'); $ok=0;$f=0;
foreach($list as $to){ $to=trim($to); if(!filter_var($to,FILTER_VALIDATE_EMAIL))continue;
  $unsub='https://leronx.org/unsubscribe?e='.rawurlencode($to);
  $html='<!doctype html><html><body style="margin:0;background:#07070c;font-family:Arial,Helvetica,sans-serif;color:#eef0f7"><div style="max-width:520px;margin:0 auto;padding:24px 18px">'
   .'<div style="font-weight:800;font-size:20px;margin-bottom:16px">Neuravision <span style="color:#ff6a1a">2.0</span></div>'
   .'<h1 style="font-size:22px;margin:8px 0 10px">Thanks for trying Neuravision for iPhone 🍏</h1>'
   .'<p style="color:#b9bccd;font-size:15px;line-height:1.6">You tapped download for iOS — we noticed! The <b>iOS app is coming very soon</b>, we\'re putting on the finishing touches. We\'ll email you the moment it\'s live.</p>'
   .'<p style="color:#b9bccd;font-size:15px;line-height:1.6">In the meantime, don\'t lose your <b style="color:#ffb454">1,000 free credits</b> — they expire <b>July 14</b> — but <b style="color:#3ad29f">activate the app and they\'re yours forever</b>. Start right now on your <b>computer</b> (Windows or Mac): open Neuravision, sign in with this account, and download there.</p>'
   .'<p style="margin:24px 0"><a href="'.$cta.'" style="background:#f59e0b;color:#181004;text-decoration:none;font-weight:800;padding:14px 26px;border-radius:12px;display:inline-block">Start on desktop &amp; keep my credits &rarr;</a></p>'
   .'<p style="color:#8a8aa0;font-size:12.5px;line-height:1.6">📲 We\'ll notify you here as soon as the iOS app launches. Hang tight!</p>'
   .'<p style="color:#8a8aa0;font-size:12px;line-height:1.6;margin-top:26px;border-top:1px solid #23232e;padding-top:16px">You signed up at leronx.org. <a href="'.$unsub.'" style="color:#9aa0b4">Unsubscribe</a> &middot; Neuravision — AI video studio.</p>'
   .'</div></body></html>';
  $r=resend_send_email($to,$subj,$html,FROM_EMAIL);
  if(!empty($r['ok'])){$ok++;fwrite($log,date('c')." OK $to\n");}else{$f++;fwrite($log,date('c')." FAIL $to ".($r['error']??'')."\n");}
  usleep(400000);
}
fwrite($log,date('c')." DONE ok=$ok fail=$f\n"); echo "ios: ok=$ok fail=$f\n";
