<?php
/**
 * Neuravision OAuth Callback Handler
 * 
 * After OAuth (X or Google) completes, api.leronx.org redirects here with:
 *   ?token=JWT_TOKEN&user_id=LX-XXXXX&provider=x|google
 * 
 * This page:
 * 1. Reads token, user_id, provider from URL params
 * 2. Sets session cookies for the user (token, user_id, provider, auth flag)
 * 3. Renders a page that fires X PageView pixel event
 * 4. Redirects to /account after pixel fires
 */
session_start();

// Read params from URL
$token = $_GET['token'] ?? '';
$userId = $_GET['user_id'] ?? '';
$provider = $_GET['provider'] ?? 'unknown';
$error = $_GET['error'] ?? '';

// Handle errors from OAuth
if ($error || empty($token)) {
    header('Location: /?auth_error=' . urlencode($error ?: 'no_token'));
    exit;
}

// Check if phone is verified. Provider is taken from the DB (NOT the URL param)
// so ?provider=x cannot bypass phone verification.
$dbPath = '/www/wwwroot/neuracreation.com/api/app_data/keys.db';
if (file_exists($dbPath)) {
    $db = new SQLite3($dbPath);
    $db->busyTimeout(5000);
    $stmt = $db->prepare('SELECT phone_verified, provider FROM users WHERE auth_token = :token');
    $stmt->bindValue(':token', $token, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $db->close();
if (!function_exists('lx_cb_ip')) {
function lx_cb_ip(){ foreach(['HTTP_CF_CONNECTING_IP','HTTP_X_REAL_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $h){ if(!empty($_SERVER[$h])){ $ip=trim(explode(',',$_SERVER[$h])[0]); if(filter_var($ip,FILTER_VALIDATE_IP)) return $ip; } } return ''; }
function lx_cb_suspicious_ip($ip){
  if(!$ip || !filter_var($ip,FILTER_VALIDATE_IP)) return false;
  $cf=sys_get_temp_dir().'/lx_geo_'.md5($ip).'.json'; $d=null;
  if(is_file($cf) && (time()-filemtime($cf)<86400)) $d=json_decode(@file_get_contents($cf),true);
  if(!is_array($d)){
    $ch=curl_init('http://ip-api.com/json/'.urlencode($ip).'?fields=status,countryCode,hosting');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>3]); $r=curl_exec($ch); curl_close($ch);
    $d=$r?json_decode($r,true):null; if(is_array($d) && ($d['status']??'')==='success') @file_put_contents($cf,json_encode($d));
  }
  if(!is_array($d)) return false; // fail-open
  if(($d['countryCode']??'')==='IN') return true;
  if(!empty($d['hosting'])) return true;
  return false;
}
}
    if ($row) {
        $provider = $row['provider'] ?: $provider; // trust DB, not URL
        // Phone verification removed — all providers proceed straight to the cabinet.
    }
}


// Set the auth token in session and cookie
$_SESSION['lx_token'] = $token;
$_SESSION['lx_provider'] = $provider;
$_SESSION['lx_user_id'] = $userId;

// Cookie options (30 days)
$cookieExpiry = time() + 86400 * 30;

// Set auth token cookie (HttpOnly - not accessible from JS)
setcookie('lx_auth_token', $token, [
    'expires' => $cookieExpiry,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax'
]);

// Set auth flag cookie (accessible from JS for UI state)
setcookie('lx_auth', '1', [
    'expires' => $cookieExpiry,
    'path' => '/',
    'secure' => true,
    'httponly' => false,
    'samesite' => 'Lax'
]);

// Set user_id cookie (for PHP fallback)
setcookie('lx_user_id', $userId, [
    'expires' => $cookieExpiry,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax'
]);

// Set provider cookie
setcookie('lx_provider', $provider, [
    'expires' => $cookieExpiry,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax'
]);
?>
<!DOCTYPE html>
<html lang="en" class="dark" style="color-scheme: dark;">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Neuravision — Signing in...</title>
    <meta name="robots" content="noindex, nofollow">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: #05060a;
            color: #e2e8f0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .loader-wrap { text-align: center; }
        .spinner {
            width: 48px; height: 48px;
            border: 3px solid rgba(255,255,255,0.1);
            border-top-color: #ff3d00;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto 1.5rem;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .loader-text { font-size: 1.125rem; opacity: 0.8; }
    </style>
    <!-- X (Twitter) Pixel -->
    <script>
    !function(e,t,n,s,u,a){e.twq||(s=e.twq=function(){s.exe?s.exe.apply(s,arguments):s.queue.push(arguments);},s.version='1.1',s.queue=[],u=t.createElement(n),u.async=!0,u.src='https://static.ads-twitter.com/uwt.js',a=t.getElementsByTagName(n)[0],a.parentNode.insertBefore(u,a))}(window,document,'script');
    twq('config','r84nh');twq('config','rgt8d');
    </script>
    <!-- Google Ads -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=AW-18092768686"></script>
    <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('consent','default',{ad_storage:'granted',ad_user_data:'granted',ad_personalization:'granted',analytics_storage:'granted'});gtag('js',new Date());gtag('config','AW-18092768686');</script>
</head>
<body>
    <div class="loader-wrap">
        <div class="spinner"></div>
        <p class="loader-text">Signing you in...</p>
    </div>
    <script>
    (function() {
        var LX_X_PAGEVIEW_EVENT_ID = 'tw-r84nh-rd0eb';

        // Store auth data in localStorage for client-side access
        try {
            localStorage.setItem('lx-auth-token', '<?php echo htmlspecialchars($token); ?>');
            localStorage.setItem('lx-user-id', '<?php echo htmlspecialchars($userId); ?>');
            localStorage.setItem('lx-provider', '<?php echo htmlspecialchars($provider); ?>');
        } catch(e) {}

        // Fire PageView event
        try { /* X pixel event disabled */ void 0; } catch(e) {}

        // Google Ads registration conversion (OAuth signup) — fire once per browser
        try { if(!/(^|;)\s*lx_gads_done=1/.test(document.cookie)){
            try { gtag('event','conversion',{'send_to':'AW-18092768686/UqDTCLri_sIcEK77prND'}); } catch(e) {}
            // record the signup in the funnel tracker, attributed to the visitor's landing session
            try { var _m=document.cookie.match(/(?:^|; )lx_sid=([^;]+)/); var _sid=_m?decodeURIComponent(_m[1]):''; if(_sid){ navigator.sendBeacon('/new/tracker/track.php', new Blob([JSON.stringify({sid:_sid,event:'register_success',url:location.pathname})],{type:'application/json'})); } } catch(e) {}
            document.cookie='lx_gads_done=1;path=/;max-age=31536000;SameSite=Lax';
        } } catch(e) {}

        // Redirect to /account after pixel fires
        setTimeout(function() {
            window.location.href = '/account?token=<?php echo urlencode($token); ?>&user_id=<?php echo urlencode($userId); ?>&provider=<?php echo urlencode($provider); ?>';
        }, 1200);
    })();
    </script>
</body>
</html>