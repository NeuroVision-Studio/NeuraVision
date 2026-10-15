<?php
/* Neuravision "credits expire Jul 7" campaign — sends via Resend (same ESP as welcome mail).
   Usage: php send_campaign.php recipient@x.com "Name" */
$envf='/www/wwwroot/leronx.org/.env';
foreach(@file($envf,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $l){
    $l=trim($l); if($l===''||$l[0]==='#'||strpos($l,'=')===false)continue;
    list($k,$v)=array_map('trim',explode('=',$l,2)); putenv("$k=$v"); $_ENV[$k]=$v;
}
require_once '/www/wwwroot/leronx.org/functions/welcome_mail.php';
$to=$argv[1]??''; if(!$to){fwrite(STDERR,"no recipient\n");exit(1);}
$name=$argv[2]??''; $nm=$name?htmlspecialchars($name):'there';
$img='https://leronx.org/lp/media/preview/promo_credits.jpg';
$cta='https://leronx.org/account';
$unsub='https://leronx.org/unsubscribe?e='.rawurlencode($to);
$subj='Your 1,000 Neuravision credits expire July 7';
$html='<!doctype html><html><body style="margin:0;background:#07070c;font-family:Arial,Helvetica,sans-serif;color:#eef0f7">'
 .'<div style="max-width:520px;margin:0 auto;padding:24px 18px">'
 .'<div style="font-weight:800;font-size:20px;margin-bottom:16px">Neuravision <span style="color:#ff6a1a">2.0</span></div>'
 .'<a href="'.$cta.'"><img src="'.$img.'" width="100%" style="border-radius:16px;display:block;max-width:484px" alt="Your 1,000 credits expire July 7"></a>'
 .'<h1 style="font-size:23px;margin:22px 0 8px">Your 1,000 free credits expire <span style="color:#ffb454">July 7</span></h1>'
 .'<p style="color:#b9bccd;font-size:15px;line-height:1.6">Hi '.$nm.', you created your Neuravision account but haven\'t installed the app yet. Your <b>1,000 bonus credits</b> (≈ 40 AI videos) are still waiting — but after <b>July 7</b> they\'re gone for good.</p>'
 .'<p style="color:#b9bccd;font-size:15px;line-height:1.6">Download the app, sign in with this account, and keep every credit.</p>'
 .'<p style="margin:26px 0"><a href="'.$cta.'" style="background:#f59e0b;color:#181004;text-decoration:none;font-weight:800;padding:14px 26px;border-radius:12px;display:inline-block">Get the app &amp; keep my credits &rarr;</a></p>'
 .'<p style="color:#8a8aa0;font-size:12px;line-height:1.6;margin-top:30px;border-top:1px solid #23232e;padding-top:16px">You\'re getting this because you signed up at leronx.org. '
 .'<a href="'.$unsub.'" style="color:#9aa0b4">Unsubscribe</a> &middot; Neuravision — AI video studio.</p>'
 .'</div></body></html>';
$r=resend_send_email($to,$subj,$html,FROM_EMAIL);
echo ($r['ok']?('SENT id='.$r['id']):('FAIL '.($r['error']??'').' code='.($r['code']??'?')))." -> $to\n";
