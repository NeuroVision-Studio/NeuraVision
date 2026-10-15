<?php
/**
 * Neuravision reviews with SHADOW MODERATION (2026-06-22).
 *  - submit: stored as 'pending'. Submitter sees it as published (via lx_rv cookie);
 *            public does NOT see it until approved. TG alert w/ approve/reject links.
 *  - list  : approved reviews (public) + the caller's own pending (by cookie).
 *  - approve/reject/admin: gated by REV_ADMIN_KEY.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
if(session_status()!==PHP_SESSION_ACTIVE){ @session_start(); }

define('REV_DB', __DIR__ . '/../data/reviews.sqlite');
define('REV_ADMIN_KEY', 'lxrev_9f3aD7k2Qm8s');   // admin moderation key (links/panel)

function rv_env($k){
    static $c=null;
    if($c===null){ $c=[]; $f=dirname(__DIR__).'/.env';
        if(is_file($f)) foreach(file($f,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $l){
            $l=trim($l); if($l===''||$l[0]==='#'||strpos($l,'=')===false) continue;
            list($a,$b)=array_map('trim',explode('=',$l,2)); $c[$a]=$b; } }
    return $c[$k] ?? '';
}
function rv_db(){
    $db=new SQLite3(REV_DB); $db->busyTimeout(4000);
    $db->exec("CREATE TABLE IF NOT EXISTS reviews(
        id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, role TEXT, rating INTEGER NOT NULL DEFAULT 5,
        text TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending', source TEXT, ip TEXT, created_at TEXT NOT NULL)");
    @$db->exec("ALTER TABLE reviews ADD COLUMN avatar TEXT");
    return $db;
}
function rv_ip(){ foreach(['HTTP_CF_CONNECTING_IP','HTTP_X_REAL_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $h){
    if(!empty($_SERVER[$h])){ $ip=trim(explode(',',$_SERVER[$h])[0]); if(filter_var($ip,FILTER_VALIDATE_IP)) return $ip; } } return ''; }
function rv_clean($s,$max){ $s=trim((string)$s); $s=preg_replace('/\s+/',' ',$s); return mb_substr($s,0,$max); }
function rv_my_ids(){ $v=$_COOKIE['lx_rv'] ?? ''; $a=array_filter(array_map('intval',explode(',',$v))); return array_slice($a,-20); }

$action=$_GET['action'] ?? 'list';

if($action==='submit'){
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){ http_response_code(405); echo '{"ok":false}'; exit; }
    $in=json_decode(file_get_contents('php://input'),true) ?: $_POST;
    $name=rv_clean($in['name'] ?? '', 40);
    $role=rv_clean($in['role'] ?? '', 40);
    $text=rv_clean($in['text'] ?? '', 600);
    $rating=(int)($in['rating'] ?? 5); if($rating<1)$rating=1; if($rating>5)$rating=5;
    $avatar=rv_clean($in['avatar'] ?? '', 300); if($avatar!=='' && !preg_match('#^https://#i',$avatar)) $avatar='';
    if(mb_strlen($name)<2 || mb_strlen($text)<12){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Please add your name and a few words.']); exit; }
    // light anti-spam: max 3 pending per IP / 24h
    $db=rv_db(); $ip=rv_ip();
    $st=$db->prepare("SELECT COUNT(*) c FROM reviews WHERE ip=:ip AND created_at>=datetime('now','-1 day')");
    $st->bindValue(':ip',$ip,SQLITE3_TEXT); $cnt=$st->execute()->fetchArray(SQLITE3_ASSOC)['c'] ?? 0;
    if($cnt>=3){ http_response_code(429); echo json_encode(['ok'=>false,'error'=>'Thanks! You have already submitted a review.']); exit; }
    $now=gmdate('c');
    $ins=$db->prepare("INSERT INTO reviews(name,role,rating,text,status,source,ip,created_at,avatar) VALUES(:n,:r,:rt,:t,'pending',:s,:ip,:c,:av)");
    $ins->bindValue(':n',$name); $ins->bindValue(':r',$role); $ins->bindValue(':rt',$rating,SQLITE3_INTEGER);
    $ins->bindValue(':t',$text); $ins->bindValue(':s',rv_clean($in['source'] ?? 'lp',16)); $ins->bindValue(':ip',$ip); $ins->bindValue(':c',$now); $ins->bindValue(':av',$avatar);
    $ins->execute(); $id=$db->lastInsertRowID();
    // submitter sees their own (cookie)
    $ids=rv_my_ids(); $ids[]=$id; setcookie('lx_rv', implode(',',$ids), ['expires'=>time()+31536000,'path'=>'/','samesite'=>'Lax']);
    // TG alert with approve/reject links
    $tok=rv_env('TG_BOT_TOKEN'); $chat=rv_env('TG_CHAT_ID');
    if($tok && $chat){
        $base='https://neuracreation.com/functions/reviews.php?key='.urlencode(REV_ADMIN_KEY).'&id='.$id;
        $msg="📝 New review (PENDING)\n".str_repeat('⭐',$rating)."\nName: ".$name.($role?" · ".$role:'')."\nText: ".$text
            ."\nIP: ".$ip."\n\n✅ Approve: ".$base."&action=approve\n🚫 Reject: ".$base."&action=reject";
        $ch=curl_init("https://api.telegram.org/bot{$tok}/sendMessage");
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_TIMEOUT=>6,
            CURLOPT_POSTFIELDS=>http_build_query(['chat_id'=>$chat,'text'=>$msg,'disable_web_page_preview'=>true])]);
        curl_exec($ch); curl_close($ch);
    }
    $granted=0;
    if(!empty($_SESSION['user_id'])){
        try{
            $udb=new SQLite3('/www/wwwroot/neuracreation.com/api/app_data/keys.db'); $udb->busyTimeout(4000);
            @$udb->exec("ALTER TABLE users ADD COLUMN review_bonus INTEGER DEFAULT 0");
            $q=$udb->prepare("SELECT review_bonus FROM users WHERE user_id=:u"); $q->bindValue(':u',$_SESSION['user_id'],SQLITE3_TEXT);
            $row=$q->execute()->fetchArray(SQLITE3_ASSOC);
            if(false){ /* review-for-credits incentive removed for FTC compliance 2026-07 */
                $up=$udb->prepare("UPDATE users SET credits=credits+200, review_bonus=1 WHERE user_id=:u"); $up->bindValue(':u',$_SESSION['user_id'],SQLITE3_TEXT); $up->execute();
                $granted=200;
            }
            $udb->close();
        }catch(Throwable $e){}
    }
    echo json_encode(['ok'=>true,'credits_granted'=>$granted,'review'=>['id'=>$id,'name'=>$name,'role'=>$role,'rating'=>$rating,'text'=>$text,'avatar'=>$avatar,'pending'=>true,'created_at'=>$now]]);
    exit;
}

if($action==='list'){
    $db=rv_db(); $out=[];
    // random subset each call so the feed looks fresh/abundant
    $res=$db->query("SELECT id,name,role,rating,text,avatar,created_at FROM reviews WHERE status='approved' AND text NOT LIKE '%sold%' AND text NOT LIKE '%conversion%' AND text NOT LIKE '%revenue%' AND text NOT LIKE '%closed a%' AND text NOT LIKE '%margin%' AND text NOT LIKE '%sales%' AND text NOT LIKE '%earn%' AND text NOT LIKE '%per property%' AND text NOT LIKE '%fee%' ORDER BY RANDOM() LIMIT 60");
    while($r=$res->fetchArray(SQLITE3_ASSOC)){ $r['pending']=false; $out[]=$r; }
    // meta: real total + average rating (for social-proof header)
    $m=$db->query("SELECT COUNT(*) c, ROUND(AVG(rating),1) a FROM reviews WHERE status='approved' AND text NOT LIKE '%sold%' AND text NOT LIKE '%conversion%' AND text NOT LIKE '%revenue%' AND text NOT LIKE '%closed a%' AND text NOT LIKE '%margin%' AND text NOT LIKE '%sales%' AND text NOT LIKE '%earn%' AND text NOT LIKE '%per property%' AND text NOT LIKE '%fee%'")->fetchArray(SQLITE3_ASSOC);
    // caller's own pending appended (only they see these) — shown first, looks published to them
    $mine=rv_my_ids();
    if($mine){ $in=implode(',',array_map('intval',$mine));
        $res=$db->query("SELECT id,name,role,rating,text,avatar,created_at FROM reviews WHERE status='pending' AND id IN ($in) ORDER BY id DESC");
        while($r=$res->fetchArray(SQLITE3_ASSOC)){ $r['pending']=true; array_unshift($out,$r); } }
    echo json_encode(['ok'=>true,'reviews'=>$out,'total'=>(int)($m['c']??0),'avg'=>(float)($m['a']??5)]); exit;
}

if($action==='stats' || $action==='all'){
    $db=rv_db();
    $F="status='approved' AND text NOT LIKE '%sold%' AND text NOT LIKE '%conversion%' AND text NOT LIKE '%revenue%' AND text NOT LIKE '%closed a%' AND text NOT LIKE '%margin%' AND text NOT LIKE '%sales%' AND text NOT LIKE '%earn%' AND text NOT LIKE '%per property%' AND text NOT LIKE '%fee%'";
    if($action==='stats'){
        $t=$db->query("SELECT COUNT(*) c, ROUND(AVG(rating),1) a FROM reviews WHERE $F")->fetchArray(SQLITE3_ASSOC);
        $dist=array('5'=>0,'4'=>0,'3'=>0,'2'=>0,'1'=>0);
        $r=$db->query("SELECT rating, COUNT(*) c FROM reviews WHERE $F GROUP BY rating");
        while($row=$r->fetchArray(SQLITE3_ASSOC)){ $k=(string)(int)$row['rating']; if(isset($dist[$k])) $dist[$k]=(int)$row['c']; }
        echo json_encode(array('ok'=>true,'total'=>(int)($t['c']??0),'avg'=>(float)($t['a']??5),'dist'=>$dist)); exit;
    } else {
        $page=max(0,(int)($_GET['page']??0)); $per=24; $off=$page*$per;
        $res=$db->query("SELECT id,name,role,rating,text,avatar,created_at FROM reviews WHERE $F ORDER BY created_at DESC, id DESC LIMIT $per OFFSET $off");
        $out=array(); while($row=$res->fetchArray(SQLITE3_ASSOC)){ $row['pending']=false; $out[]=$row; }
        $tot=$db->query("SELECT COUNT(*) c FROM reviews WHERE $F")->fetchArray(SQLITE3_ASSOC);
        echo json_encode(array('ok'=>true,'reviews'=>$out,'page'=>$page,'per'=>$per,'total'=>(int)($tot['c']??0))); exit;
    }
}

// ---- moderation (admin key required) ----
if(in_array($action,['approve','reject','admin'],true)){
    if(($_GET['key'] ?? '')!==REV_ADMIN_KEY){ http_response_code(403); echo 'forbidden'; exit; }
    $db=rv_db();
    if($action==='admin'){
        header('Content-Type: text/html; charset=utf-8');
        $res=$db->query("SELECT * FROM reviews WHERE status='pending' ORDER BY id DESC");
        echo '<html><head><meta name=viewport content="width=device-width,initial-scale=1"><style>body{font-family:system-ui;background:#0b0b12;color:#eee;padding:20px;max-width:760px;margin:0 auto}.c{background:#15151f;border:1px solid #2a2a3a;border-radius:12px;padding:16px;margin:0 0 14px}.a{color:#3ad29f}.r{color:#f87171}h1{font-size:18px}small{color:#888}</style></head><body><h1>Pending reviews</h1>';
        $n=0; while($r=$res->fetchArray(SQLITE3_ASSOC)){ $n++;
            $b='?key='.urlencode(REV_ADMIN_KEY).'&id='.$r['id'];
            echo '<div class=c><b>'.htmlspecialchars($r['name']).'</b> '.str_repeat('⭐',$r['rating']).' <small>'.htmlspecialchars($r['role']).' · '.htmlspecialchars($r['created_at']).' · '.htmlspecialchars($r['ip']).'</small><p>'.htmlspecialchars($r['text']).'</p><a class=a href="'.$b.'&action=approve">✅ Approve</a> &nbsp; <a class=r href="'.$b.'&action=reject">🚫 Reject</a></div>'; }
        if(!$n) echo '<p>No pending reviews.</p>';
        echo '</body></html>'; exit;
    }
    $id=(int)($_GET['id'] ?? 0); $new=$action==='approve'?'approved':'rejected';
    $st=$db->prepare("UPDATE reviews SET status=:s WHERE id=:id"); $st->bindValue(':s',$new); $st->bindValue(':id',$id,SQLITE3_INTEGER); $st->execute();
    header('Content-Type: text/html; charset=utf-8');
    echo '<html><body style="font-family:system-ui;background:#0b0b12;color:#eee;text-align:center;padding:60px"><h2>Review #'.$id.' → '.$new.'</h2><p><a style="color:#7c5cff" href="?action=admin&key='.urlencode(REV_ADMIN_KEY).'">← back to pending</a></p></body></html>';
    exit;
}
http_response_code(400); echo '{"ok":false,"error":"bad action"}';
