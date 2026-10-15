<?php
/* LX_DISABLED_VERIFY: phone verification removed — page killed, no vulnerable redirect/token logic runs. */
if (PHP_SAPI!=='cli'){ header('Location: /account'); exit; }

/**
 * Phone Verification Page
 * Shown after Google/Email auth if phone not yet verified
 * Skipped for X (Twitter) auth
 */

// Get auth params from URL
$token = $_GET['token'] ?? '';
$userId = $_GET['user_id'] ?? '';
$provider = $_GET['provider'] ?? '';
$redirect = $_GET['redirect'] ?? '/account';
// SECURITY: allow only same-site relative paths — blocks open redirect & auth-token leak to external domains
if (!is_string($redirect) || $redirect === '' || $redirect[0] !== '/' || (strlen($redirect) > 1 && $redirect[1] === '/') || strpos($redirect, '\\') !== false || strpos($redirect, ':') !== false) { $redirect = '/account'; }

// If no auth params, redirect to home
if (empty($token) || empty($userId)) {
    header('Location: /');
    exit;
}

// Server-side geo -> reliable phone country auto-detect (no third-party rate limits)
$lxGeoCC = '';
$lxGeoIp = '';
foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_REAL_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $h) {
    if (!empty($_SERVER[$h])) { $x = trim(explode(',', $_SERVER[$h])[0]); if (filter_var($x, FILTER_VALIDATE_IP)) { $lxGeoIp = $x; break; } }
}
if ($lxGeoIp) {
    $cf = sys_get_temp_dir().'/lx_geo_'.md5($lxGeoIp).'.json'; $d = null;
    if (is_file($cf) && (time()-filemtime($cf) < 86400)) $d = json_decode(@file_get_contents($cf), true);
    if (!is_array($d)) {
        $ch = curl_init('http://ip-api.com/json/'.urlencode($lxGeoIp).'?fields=status,countryCode');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>2]); $r = curl_exec($ch); curl_close($ch);
        $d = $r ? json_decode($r, true) : null;
        if (is_array($d) && ($d['status'] ?? '') === 'success') @file_put_contents($cf, json_encode($d));
    }
    if (is_array($d) && !empty($d['countryCode'])) $lxGeoCC = $d['countryCode'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script>window.__lxGeoCC=<?php echo json_encode($lxGeoCC ?? ''); ?>;</script>
    <!-- Google Ads conversion: Регистрация (fires once per new signup via lx_gads_reg cookie) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=AW-18092768686"></script>
    <script>
    window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}
    gtag('consent','default',{ad_storage:'granted',ad_user_data:'granted',ad_personalization:'granted',analytics_storage:'granted'});
    gtag('js',new Date());gtag('config','AW-18092768686');
    /* Google Ads conversion moved to verified-completion (success branch), not page load */
    !function(e,t,n,s,u,a){e.twq||(s=e.twq=function(){s.exe?s.exe.apply(s,arguments):s.queue.push(arguments);},s.version='1.1',s.queue=[],u=t.createElement(n),u.async=!0,u.src='https://static.ads-twitter.com/uwt.js',a=t.getElementsByTagName(n)[0],a.parentNode.insertBefore(u,a))}(window,document,'script');twq('config','r84nh');twq('config','rgt8d');
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Phone — Neuravision</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0a0a0f;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            overflow: hidden;
        }
        
        /* Animated background */
        body::before {
            content: '';
            position: fixed;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(ellipse at 30% 20%, rgba(99, 102, 241, 0.08) 0%, transparent 50%),
                        radial-gradient(ellipse at 70% 80%, rgba(168, 85, 247, 0.06) 0%, transparent 50%);
            animation: bgFloat 20s ease-in-out infinite;
            z-index: 0;
        }
        
        @keyframes bgFloat {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            33% { transform: translate(2%, -2%) rotate(1deg); }
            66% { transform: translate(-1%, 1%) rotate(-0.5deg); }
        }
        
        .container {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 420px;
            padding: 20px;
        }
        
        .card {
            background: rgba(19, 19, 31, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 20px;
            padding: 40px 32px;
            backdrop-filter: blur(20px);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5),
                        0 0 0 1px rgba(255, 255, 255, 0.02);
        }
        
        .logo {
            text-align: center;
            margin-bottom: 8px;
        }
        
        .logo svg {
            width: 40px;
            height: 40px;
        }
        
        .title {
            text-align: center;
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 8px;
            background: linear-gradient(135deg, #fff 0%, rgba(255,255,255,0.8) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .subtitle {
            text-align: center;
            color: rgba(255, 255, 255, 0.5);
            font-size: 14px;
            margin-bottom: 32px;
            line-height: 1.5;
        }
        
        .step { display: none; }
        .step.active { display: block; }
        
        .input-group {
            margin-bottom: 20px;
        }
        
        .input-group label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: rgba(255, 255, 255, 0.7);
            margin-bottom: 8px;
        }
        
        .phone-input-wrapper {
            display: flex;
            gap: 8px;
        }
        
        .country-select {
            width: 90px;
            padding: 12px 10px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            color: #fff;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
            cursor: pointer;
            -webkit-appearance: none;
        }
        
        .country-select:focus {
            border-color: rgba(99, 102, 241, 0.5);
        }
        
        .country-select option {
            background: #1a1a2e;
            color: #fff;
        }
        
        input[type="tel"],
        input[type="text"] {
            flex: 1;
            padding: 12px 16px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            color: #fff;
            font-size: 16px;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        
        input:focus {
            border-color: rgba(99, 102, 241, 0.5);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }
        
        input::placeholder {
            color: rgba(255, 255, 255, 0.25);
        }
        
        .code-inputs {
            display: flex;
            gap: 8px;
            justify-content: center;
            margin-bottom: 20px;
        }
        
        .code-inputs input {
            width: 48px;
            height: 56px;
            text-align: center;
            font-size: 22px;
            font-weight: 600;
            padding: 0;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #fff;
            outline: none;
            transition: all 0.2s;
        }
        
        .code-inputs input:focus {
            border-color: rgba(99, 102, 241, 0.6);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
            background: rgba(99, 102, 241, 0.05);
        }
        
        .btn {
            width: 100%;
            padding: 14px 24px;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #ff3d00 0%, #ff6a1a 100%);
            color: #fff;
            box-shadow: 0 4px 14px rgba(99, 102, 241, 0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
        }
        
        .btn-primary:active {
            transform: translateY(0);
        }
        
        .btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .btn-link {
            background: none;
            color: rgba(255, 255, 255, 0.5);
            font-size: 13px;
            margin-top: 16px;
            padding: 8px;
        }
        
        .btn-link:hover {
            color: rgba(255, 255, 255, 0.8);
        }
        
        .error-msg {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #fca5a5;
            padding: 10px 14px;
            border-radius: 10px;
            font-size: 13px;
            margin-bottom: 16px;
            display: none;
        }
        
        .error-msg.visible {
            display: block;
        }
        
        .success-icon {
            text-align: center;
            margin-bottom: 20px;
        }
        
        .success-icon svg {
            width: 64px;
            height: 64px;
        }
        
        .spinner {
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .resend-timer {
            text-align: center;
            color: rgba(255, 255, 255, 0.4);
            font-size: 13px;
            margin-top: 12px;
        }
        
        .resend-timer a {
            color: #818cf8;
            text-decoration: none;
            cursor: pointer;
        }
        
        .resend-timer a:hover {
            text-decoration: underline;
        }
        
        .phone-display {
            text-align: center;
            color: rgba(255, 255, 255, 0.6);
            font-size: 14px;
            margin-bottom: 24px;
        }
        
        .phone-display strong {
            color: #fff;
        }
        
        @media (max-width: 480px) {
            .card { padding: 32px 24px; }
            .code-inputs input { width: 42px; height: 50px; font-size: 20px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <!-- Step 1: Enter Phone Number -->
            <div class="step active" id="step-phone">
                <div class="logo">
                    <svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <rect width="40" height="40" rx="10" fill="url(#grad1)"/>
                        <path d="M12 20C12 15.58 15.58 12 20 12C24.42 12 28 15.58 28 20C28 24.42 24.42 28 20 28" stroke="white" stroke-width="2.5" stroke-linecap="round"/>
                        <path d="M20 16V20L23 23" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                        <defs>
                            <linearGradient id="grad1" x1="0" y1="0" x2="40" y2="40">
                                <stop stop-color="#ff3d00"/>
                                <stop offset="1" stop-color="#ff6a1a"/>
                            </linearGradient>
                        </defs>
                    </svg>
                </div>
                <div style="display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,rgba(255,90,26,.15),rgba(255,106,26,.15));border:1px solid rgba(255,90,26,.25);padding:6px 14px;border-radius:20px;font-size:12px;color:#a5b4fc;font-weight:600;margin-bottom:12px">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    Free Credits Ready
                </div>
                <h1 class="title">Unlock your free AI videos</h1>
                <p class="subtitle">Your credits are waiting — just confirm your number to claim them. We do this to keep bots out, so 100% of the free credits go to real people who actually want to create. Takes a few seconds.</p>
                
                <div class="error-msg" id="phone-error"></div>
                
                <!-- email verification disabled (phone-only) -->
                
                <div class="input-group">
                    <label>Phone Number</label>
                    <div class="phone-input-wrapper">
                        <select class="country-select" id="country-code">
                            <option value="+1">🇺🇸 +1</option>
                            <option value="+7">🇷🇺 +7</option>
                            <option value="+44">🇬🇧 +44</option>
                            <option value="+49">🇩🇪 +49</option>
                            <option value="+33">🇫🇷 +33</option>
                            <option value="+34">🇪🇸 +34</option>
                            <option value="+39">🇮🇹 +39</option>
                            <option value="+81">🇯🇵 +81</option>
                            <option value="+82">🇰🇷 +82</option>
                            <option value="+86">🇨🇳 +86</option>
                            <option value="+91">🇮🇳 +91</option>
                            <option value="+380">🇺🇦 +380</option>
                            <option value="+971">🇦🇪 +971</option>
                            <option value="+972">🇮🇱 +972</option>
                            <option value="+55">🇧🇷 +55</option>
                            <option value="+52">🇲🇽 +52</option>
                            <option value="+61">🇦🇺 +61</option>
                            <option value="+64">🇳🇿 +64</option>
                            <option value="+90">🇹🇷 +90</option>
                            <option value="+48">🇵🇱 +48</option>
                            <option value="+31">🇳🇱 +31</option>
                            <option value="+46">🇸🇪 +46</option>
                            <option value="+47">🇳🇴 +47</option>
                            <option value="+358">🇫🇮 +358</option>
                            <option value="+41">🇨🇭 +41</option>
                            <option value="+43">🇦🇹 +43</option>
                            <option value="+32">🇧🇪 +32</option>
                            <option value="+351">🇵🇹 +351</option>
                            <option value="+30">🇬🇷 +30</option>
                            <option value="+36">🇭🇺 +36</option>
                            <option value="+420">🇨🇿 +420</option>
                            <option value="+40">🇷🇴 +40</option>
                            <option value="+375">🇧🇾 +375</option>
                            <option value="+995">🇬🇪 +995</option>
                            <option value="+374">🇦🇲 +374</option>
                            <option value="+998">🇺🇿 +998</option>
                            <option value="+996">🇰🇬 +996</option>
                            <option value="+992">🇹🇯 +992</option>
                            <option value="+993">🇹🇲 +993</option>
                            <option value="+994">🇦🇿 +994</option>
                            <option value="+373">🇲🇩 +373</option>
                            <option value="+370">🇱🇹 +370</option>
                            <option value="+371">🇱🇻 +371</option>
                            <option value="+372">🇪🇪 +372</option>
                        </select>
                        <input type="tel" id="phone-number" placeholder="Phone number" autocomplete="tel">
                    </div>
                </div>
                
                <!-- Trust badges -->
                <div style="display:flex;gap:12px;justify-content:center;margin:16px 0 8px;flex-wrap:wrap">
                    <div style="display:flex;align-items:center;gap:5px;font-size:11px;color:rgba(255,255,255,.45);background:rgba(255,255,255,.04);padding:5px 10px;border-radius:8px;border:1px solid rgba(255,255,255,.06)">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                        Never shared
                    </div>
                    <div style="display:flex;align-items:center;gap:5px;font-size:11px;color:rgba(255,255,255,.45);background:rgba(255,255,255,.04);padding:5px 10px;border-radius:8px;border:1px solid rgba(255,255,255,.06)">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
                        One text, one code
                    </div>
                    <div style="display:flex;align-items:center;gap:5px;font-size:11px;color:rgba(255,255,255,.45);background:rgba(255,255,255,.04);padding:5px 10px;border-radius:8px;border:1px solid rgba(255,255,255,.06)">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
                        Zero spam or calls
                    </div>
                </div>
                <button class="btn btn-primary" id="btn-send" onclick="sendCode()">
                    🚀 Claim My Free Credits
                </button>
                <button class="btn btn-link" id="btn-email" onclick="sendCodeEmail()" style="margin-top:10px;display:none">✉️ Get the code by email instead</button>
            </div>
            
            <!-- Step 2: Enter Code -->
            <div class="step" id="step-code">
                <div class="logo">
                    <svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <rect width="40" height="40" rx="10" fill="url(#grad2)"/>
                        <path d="M14 20L18 24L26 16" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                        <defs>
                            <linearGradient id="grad2" x1="0" y1="0" x2="40" y2="40">
                                <stop stop-color="#ff3d00"/>
                                <stop offset="1" stop-color="#ff6a1a"/>
                            </linearGradient>
                        </defs>
                    </svg>
                </div>
                <h1 class="title">Enter Code</h1>
                <div class="phone-display">Code sent to <strong id="display-phone"></strong></div>
                
                <div class="error-msg" id="code-error"></div>
                
                <div class="code-inputs" id="code-inputs">
                    <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="one-time-code">
                    <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]">
                    <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]">
                    <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]">
                    <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]">
                    <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]">
                </div>
                
                <button class="btn btn-primary" id="btn-verify" onclick="verifyCode()" disabled>
                    Verify
                </button>
                
                <div class="resend-timer" id="resend-timer">
                    Resend code in <span id="timer-count">60</span>s
                </div>
                
                <button class="btn btn-link" onclick="goBack()">← Change phone number</button>
                <button class="btn btn-link" id="btn-email2" onclick="sendCodeEmail()" style="display:none">Didn’t get the SMS? Email me the code</button>
            </div>
            
            <!-- Step 3: Success -->
            <div class="step" id="step-success">
                <div class="success-icon">
                    <svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="32" cy="32" r="30" fill="url(#grad3)" opacity="0.15"/>
                        <circle cx="32" cy="32" r="24" fill="url(#grad3)" opacity="0.3"/>
                        <circle cx="32" cy="32" r="18" fill="url(#grad3)"/>
                        <path d="M24 32L29 37L40 26" stroke="white" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                        <defs>
                            <linearGradient id="grad3" x1="8" y1="8" x2="56" y2="56">
                                <stop stop-color="#22c55e"/>
                                <stop offset="1" stop-color="#16a34a"/>
                            </linearGradient>
                        </defs>
                    </svg>
                </div>
                <h1 class="title">Phone Verified!</h1>
                <p class="subtitle">Your phone number has been verified successfully. Redirecting you to the app...</p>
            </div>
        </div>
    </div>
    
    <script>
        // Auth data from URL
        const AUTH = {
            token: '<?php echo htmlspecialchars($token); ?>',
            userId: '<?php echo htmlspecialchars($userId); ?>',
            provider: '<?php echo htmlspecialchars($provider); ?>',
            redirect: '<?php echo htmlspecialchars($redirect); ?>'
        };
        
        let currentPhone = '';
        let verifyMode = 'email';
        let resendInterval = null;
        
        // Code input handling
        const codeInputs = document.querySelectorAll('#code-inputs input');
        codeInputs.forEach((input, index) => {
            input.addEventListener('input', (e) => {
                const val = e.target.value.replace(/[^0-9]/g, '');
                e.target.value = val;
                if (val && index < codeInputs.length - 1) {
                    codeInputs[index + 1].focus();
                }
                checkCodeComplete();
            });
            
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && !e.target.value && index > 0) {
                    codeInputs[index - 1].focus();
                }
            });
            
            input.addEventListener('paste', (e) => {
                e.preventDefault();
                const paste = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '');
                codeInputs.forEach((inp, i) => {
                    inp.value = paste[i] || '';
                });
                if (paste.length >= codeInputs.length) {
                    codeInputs[codeInputs.length - 1].focus();
                }
                checkCodeComplete();
            });
        });
        
        function checkCodeComplete() {
            const code = getCode();
            document.getElementById('btn-verify').disabled = code.length < 6;
        }
        
        function getCode() {
            return Array.from(codeInputs).map(i => i.value).join('');
        }
        
        function showError(elementId, message) {
            const el = document.getElementById(elementId);
            el.textContent = message;
            el.classList.add('visible');
        }
        
        function hideError(elementId) {
            document.getElementById(elementId).classList.remove('visible');
        }
        
        function setLoading(btnId, loading) {
            const btn = document.getElementById(btnId);
            if (loading) {
                btn.disabled = true;
                btn.dataset.originalText = btn.innerHTML;
                btn.innerHTML = '<div class="spinner"></div>';
            } else {
                btn.disabled = false;
                btn.innerHTML = btn.dataset.originalText;
            }
        }
        
        async function sendCode() {
            hideError('phone-error');
            
            const countryCode = document.getElementById('country-code').value;
            const phoneNumber = document.getElementById('phone-number').value.trim();
            
            if (!phoneNumber) {
                showError('phone-error', 'Please enter your phone number');
                return;
            }
            
            // Format phone: remove spaces, dashes, leading zeros
            let fullPhone = countryCode + phoneNumber.replace(/[\s\-\(\)]/g, '').replace(/^0+/, '');
            currentPhone = fullPhone;

            // Region restriction: India (+91) not supported (bot/abuse mitigation)
            if (countryCode === '+91' || fullPhone.indexOf('+91') === 0 || /^\+?0091/.test(fullPhone)) {
                showError('phone-error', 'Sorry, your country is not supported at this time.');
                return;
            }

            setLoading('btn-send', true);
            
            try {
                const res = await fetch('/functions/phone_verify.php?action=send', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        phone: fullPhone,
                        user_id: AUTH.userId,
                        token: AUTH.token
                    })
                });
                
                const data = await res.json();
                
                if (data.ok) {
                    // Show code input step
                    document.getElementById('step-phone').classList.remove('active');
                    document.getElementById('step-code').classList.add('active');
                    document.getElementById('display-phone').textContent = fullPhone;
                    codeInputs[0].focus();
                    startResendTimer();
                } else {
                    showError('phone-error', data.error || 'Failed to send code. Please try again.');
                }
            } catch (err) {
                showError('phone-error', 'Network error. Please try again.');
            }
            
            setLoading('btn-send', false);
        }
        
        async function sendCodeEmail() {
            hideError('phone-error'); hideError('code-error');
            try {
                const res = await fetch('/functions/phone_verify.php?action=send_email', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ user_id: AUTH.userId, token: AUTH.token })
                });
                const data = await res.json();
                if (data.ok) {
                    verifyMode = 'email';
                    document.getElementById('step-phone').classList.remove('active');
                    document.getElementById('step-code').classList.add('active');
                    var dp = document.getElementById('display-phone'); if (dp) dp.textContent = 'your email';
                    if (codeInputs && codeInputs[0]) codeInputs[0].focus();
                    if (typeof startResendTimer === 'function') startResendTimer();
                } else {
                    showError('phone-error', data.error || 'Could not send the email code. Please try again.');
                }
            } catch (e) { showError('phone-error', 'Network error. Please try again.'); }
        }

        async function verifyCode() {
            hideError('code-error');
            const code = getCode();
            
            if (code.length < 6) {
                showError('code-error', 'Please enter the full code');
                return;
            }
            
            setLoading('btn-verify', true);
            
            try {
                const res = await fetch('/functions/phone_verify.php?action=' + (verifyMode === 'email' ? 'check_email' : 'check'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        phone: currentPhone,
                        code: code,
                        user_id: AUTH.userId,
                        token: AUTH.token
                    })
                });
                
                const data = await res.json();
                
                if (data.ok && data.verified) {
                    // Success!
                    document.getElementById('step-code').classList.remove('active');
                    document.getElementById('step-success').classList.add('active');
                    
                    // Store auth in localStorage
                    localStorage.setItem('lx-auth-token', AUTH.token);
                    localStorage.setItem('lx-user-id', AUTH.userId);
                    localStorage.setItem('lx-provider', AUTH.provider);
                    
                    // Redirect after animation
                    setTimeout(() => {
                        // Go to account dashboard with token so it sets auth cookies and shows the LK.
                        // A bare redirect (e.g. /app) lands on the landing page, so route through /account.
                        var dest = AUTH.redirect;
                        if (!dest || dest === "/" || dest === "/app" || dest === "/app/") {
                            dest = "/account";
                        }
                        var sep = dest.indexOf("?") === -1 ? "?" : "&";
                        window.location.href = dest + sep
                            + "token=" + encodeURIComponent(AUTH.token)
                            + "&user_id=" + encodeURIComponent(AUTH.userId)
                            + "&provider=" + encodeURIComponent(AUTH.provider);
                    }, 1500);
                } else if (data.ok && !data.verified) {
                    showError('code-error', 'Invalid code. Please try again.');
                    // Clear inputs
                    codeInputs.forEach(i => i.value = '');
                    codeInputs[0].focus();
                } else {
                    showError('code-error', data.error || 'Verification failed. Please try again.');
                }
            } catch (err) {
                showError('code-error', 'Network error. Please try again.');
            }
            
            setLoading('btn-verify', false);
        }
        
        function goBack() {
            document.getElementById('step-code').classList.remove('active');
            document.getElementById('step-phone').classList.add('active');
            if (resendInterval) clearInterval(resendInterval);
        }
        
        function startResendTimer() {
            let seconds = 60;
            const timerEl = document.getElementById('timer-count');
            const timerWrap = document.getElementById('resend-timer');
            
            timerWrap.innerHTML = 'Resend code in <span id="timer-count">60</span>s';
            
            resendInterval = setInterval(() => {
                seconds--;
                document.getElementById('timer-count').textContent = seconds;
                
                if (seconds <= 0) {
                    clearInterval(resendInterval);
                    timerWrap.innerHTML = '<a onclick="resendCode()">Resend code</a>';
                }
            }, 1000);
        }
        
        async function resendCode() {
            document.getElementById('resend-timer').innerHTML = 'Sending...';

            try {
                if (verifyMode === 'firebase') {
                    if (!window.lxFbSend) { throw new Error('fb-not-ready'); }
                    await window.lxFbSend(currentPhone);
                    startResendTimer();
                    return;
                }

                const res = await fetch('/functions/phone_verify.php?action=send', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        phone: currentPhone,
                        user_id: AUTH.userId,
                        token: AUTH.token
                    })
                });

                const data = await res.json();
                if (data.ok) {
                    startResendTimer();
                } else {
                    document.getElementById('resend-timer').innerHTML = '<a onclick="resendCode()">Failed. Try again</a>';
                }
            } catch (err) {
                const msg = (verifyMode === 'firebase' && err && err.code) ? ('Failed. Try again (' + err.code + ')') : 'Failed. Try again';
                document.getElementById('resend-timer').innerHTML = '<a onclick="resendCode()">' + msg + '</a>';
            }
        }
        
        // Auto-detect country from browser locale
        (function() {
            const lang = navigator.language || '';
            const countryMap = {
                'ru': '+7', 'uk': '+380', 'de': '+49', 'fr': '+33',
                'es': '+34', 'it': '+39', 'pt': '+351', 'ja': '+81',
                'ko': '+82', 'zh': '+86', 'tr': '+90', 'pl': '+48',
                'nl': '+31', 'sv': '+46', 'no': '+47', 'fi': '+358'
            };
            const langCode = lang.split('-')[0].toLowerCase();
            if (countryMap[langCode]) {
                document.getElementById('country-code').value = countryMap[langCode];
            }
        })();
    </script>
<script>/*lxAutoEmail*/window.addEventListener("DOMContentLoaded",function(){try{var pv=(new URLSearchParams(location.search)).get("provider")||"";if(pv==='email'&&typeof sendCodeEmail==='function')setTimeout(sendCodeEmail,250); if(pv==='google'||pv==='x'){var _be=document.getElementById('btn-email2'); if(_be)_be.style.display='';}}catch(e){}});</script>
<div id="recaptcha-container"></div>
<script type="module">
  import { initializeApp } from "https://www.gstatic.com/firebasejs/12.15.0/firebase-app.js";
  import { getAuth, RecaptchaVerifier, signInWithPhoneNumber } from "https://www.gstatic.com/firebasejs/12.15.0/firebase-auth.js";
  const firebaseConfig = { apiKey:"AIzaSyDZjCrvsa74rPXdd1i7dwAgJ0ew8ubfUIs", authDomain:"leron-test.firebaseapp.com", projectId:"leron-test", storageBucket:"leron-test.firebasestorage.app", messagingSenderId:"386444523884", appId:"1:386444523884:web:c9ff8b503f39cd29269b49", measurementId:"G-565QNTMBC1" };
  const app = initializeApp(firebaseConfig);
  const auth = getAuth(app); try{ auth.languageCode = "en"; }catch(e){}
  let verifier=null;
  function ensureVerifier(){
    if(!document.getElementById('recaptcha-container')){
      var div=document.createElement('div'); div.id='recaptcha-container'; document.body.appendChild(div);
    }
    if(!verifier){ verifier=new RecaptchaVerifier(auth,'recaptcha-container',{size:'invisible'}); }
    return verifier;
  }
  window.lxFbReset = function(){ try{ if(verifier&&verifier.clear) verifier.clear(); }catch(e){} verifier=null; try{ var c=document.getElementById('recaptcha-container'); if(c) c.innerHTML=''; }catch(e){} };
  window.lxFbSend = async (phone)=>{ const v=ensureVerifier(); window.__lxConf = await signInWithPhoneNumber(auth, phone, v); };
  window.lxFbConfirm = async (code)=>{ const cred = await window.__lxConf.confirm(code); return await cred.user.getIdToken(); };
  window.lxFbReady = true;
</script>
<script>
/* Firebase phone flow overrides (phone-first). Email path stays as manual fallback. */
window.sendCode = async function(){
  hideError('phone-error');
  var input=document.getElementById('phone-number');
  var raw=((input&&input.value)||'').trim();
  if(!raw){ showError('phone-error','Please enter your phone number'); return; }
  var full='', iso='';
  if(window.__iti && typeof window.__iti.getNumber === 'function'){
    if(window.intlTelInputUtils && !window.__iti.isValidNumber()){ showError('phone-error','Please enter a valid phone number for your country.'); return; }
    full=window.__iti.getNumber();
    try{ iso=((window.__iti.getSelectedCountryData()||{}).iso2||'').toLowerCase(); }catch(e){}
  } else {
    var cc=(document.getElementById('country-code')||{}).value||'';
    full=cc+raw.replace(/[\s\-\(\)]/g,'').replace(/^0+/,'');
  }
  if(!/^\+\d{6,15}$/.test(full||'')){ showError('phone-error','Please enter a valid phone number.'); return; }
  if(iso==='in'||iso==='pk'||iso==='bd'||iso==='ng'||/^\+(91|92|880|234)/.test(full)){ showError('phone-error','Sorry, your country is not supported at this time.'); return; }
  currentPhone=full; verifyMode='firebase';
  setLoading('btn-send',true);
  try{
    if(!window.lxFbSend){ throw new Error('fb-not-ready'); }
    await window.lxFbSend(full);
    document.getElementById('step-phone').classList.remove('active');
    document.getElementById('step-code').classList.add('active');
    var dp=document.getElementById('display-phone'); if(dp) dp.textContent=full;
    if(codeInputs[0]) codeInputs[0].focus();
    if(typeof startResendTimer==='function') startResendTimer();
  }catch(e){
    try{fetch('/functions/phone_verify.php?action=fbdiag',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({err:((e&&e.code)?e.code:(''+(e&&e.message||e))).slice(0,150)}),keepalive:true});}catch(_){}
    /* phone-only: email fallback disabled */
    var m=(e&&e.code)?('Could not send the code ('+e.code+').'):'Could not send the code.';
    showError('phone-error',m);
  }
  setLoading('btn-send',false);
};
window.verifyCode = async function(){
  hideError('code-error');
  var code=getCode();
  if(code.length<6){ showError('code-error','Please enter the full code'); return; }
  setLoading('btn-verify',true);
  try{
    var data;
    if(verifyMode==='firebase'){
      var idToken=await window.lxFbConfirm(code);
      var res=await fetch('/functions/phone_verify.php?action=firebase_verify',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id_token:idToken,user_id:AUTH.userId,token:AUTH.token})});
      data=await res.json();
    } else {
      var res2=await fetch('/functions/phone_verify.php?action='+(verifyMode==='email'?'check_email':'check'),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({phone:currentPhone,code:code,user_id:AUTH.userId,token:AUTH.token})});
      data=await res2.json();
    }
    if(data.ok&&data.verified){
      if(verifyMode==='email' && AUTH.provider==='email' && !window.__lxEmailDone){ window.__lxEmailDone=true; document.getElementById('step-code').classList.remove('active'); document.querySelectorAll('#code-inputs input').forEach(function(i){i.value='';}); verifyMode=''; document.getElementById('step-phone').classList.add('active'); var _st=document.querySelector('#step-phone .subtitle'); if(_st)_st.textContent='✓ Email verified — now confirm your phone by SMS.'; setLoading('btn-verify',false); return; }
      document.getElementById('step-code').classList.remove('active');
      document.getElementById('step-success').classList.add('active');
      try{ if(window.gtag){ gtag('event','conversion',{'send_to':'AW-18092768686/UqDTCLri_sIcEK77prND','transaction_id':'LX-'+Date.now()}); } document.cookie='lx_gads_reg=;Max-Age=0;path=/'; }catch(e){}
      try{ if(window.twq){ /* X pixel event disabled */ void 0; } }catch(e){}
      try{ if(window.twq){ void 0;/*X conv: ЛК-download only*/ } }catch(e){}   /* LX: fire trained SIGN_UP conversion (new_12_06_test_1/13kkqg) on VERIFIED registration */
      try{ navigator.sendBeacon('/functions/phone_verify.php?action=tg_verified', new Blob([JSON.stringify({user_id:AUTH.userId,provider:AUTH.provider,phone:currentPhone})],{type:'application/json'})); }catch(e){}
      try{ var _gm=document.cookie.match(/(?:^|; )lx_sid=([^;]+)/); var _gsid=_gm?decodeURIComponent(_gm[1]):''; if(_gsid) navigator.sendBeacon('/new/tracker/track.php', new Blob([JSON.stringify({sid:_gsid,event:'register_success',url:location.pathname})],{type:'application/json'})); }catch(e){}
      localStorage.setItem('lx-auth-token',AUTH.token); localStorage.setItem('lx-user-id',AUTH.userId); localStorage.setItem('lx-provider',AUTH.provider);
      setTimeout(function(){ var dest=AUTH.redirect; if(!dest||dest==='/'||dest==='/app'||dest==='/app/') dest='/account'; var sep=dest.indexOf('?')===-1?'?':'&'; window.location.href=dest+sep+'token='+encodeURIComponent(AUTH.token)+'&user_id='+encodeURIComponent(AUTH.userId)+'&provider='+encodeURIComponent(AUTH.provider); },1500);
    } else if(data.ok&&!data.verified){
      showError('code-error',data.error||'Invalid code. Please try again.');
      codeInputs.forEach(function(i){ i.value=''; }); if(codeInputs[0]) codeInputs[0].focus();
    } else {
      showError('code-error',data.error||'Verification failed. Please try again.');
    }
  }catch(e){
    var msg=(e&&e.code&&(''+e.code).indexOf('invalid-verification-code')>-1)?'Invalid code. Please try again.':((e&&e.code&&(''+e.code).indexOf('expired')>-1)?'Code expired. Please resend.':'Verification error. Please try again.');
    showError('code-error',msg);
  }
  setLoading('btn-verify',false);
};
</script>
<?php require_once __DIR__.'/includes/track_actions.php'; if(function_exists('lx_track_actions')) lx_track_actions(); ?>
<script>try{lxAct('verify_start',(new URLSearchParams(location.search)).get('provider')||'');}catch(e){}</script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@18.2.1/build/css/intlTelInput.min.css">
<style>/*lx-phonefix-css*/.iti{width:100%}.iti input#phone-number{width:100%}.iti__country-list{color:#111}</style>
<script>/*lx-phonefix*/(function(){
  // load intl-tel-input, then init on the phone field
  var s=document.createElement('script');
  s.src="https://cdn.jsdelivr.net/npm/intl-tel-input@18.2.1/build/js/intlTelInput.min.js";
  s.onload=function(){
    try{
      var input=document.getElementById('phone-number');
      if(!input||!window.intlTelInput) return;
      var iti=window.intlTelInput(input,{
        initialCountry:"auto",
        geoIpLookup:function(cb){ try{ if(window.__lxGeoCC){ cb(window.__lxGeoCC); return; } }catch(e){} fetch('https://ipapi.co/json/').then(function(r){return r.json();}).then(function(d){cb((d&&d.country_code)?d.country_code:'us');}).catch(function(){cb('us');}); },
        separateDialCode:true,
        dropdownContainer:document.body,
        autoPlaceholder:"aggressive",
        preferredCountries:['us','gb','ca','au','ae','sg','de','fr'],
        excludeCountries:['in','pk','bd','ng'],
        utilsScript:"https://cdn.jsdelivr.net/npm/intl-tel-input@18.2.1/build/js/utils.js"
      });
      window.__iti=iti;
      var sel=document.getElementById('country-code'); if(sel) sel.style.display='none';
      try{ var w=input.closest('.phone-input-wrapper'); if(w&&w.parentNode&&!document.getElementById('lxCcHint')){ var hint=document.createElement('div'); hint.id='lxCcHint'; hint.style.cssText='font-size:12px;color:#9aa3b2;margin-top:7px;line-height:1.4'; hint.innerHTML='\uD83C\uDF0D Wrong country? Tap the flag to change it.'; w.parentNode.insertBefore(hint, w.nextSibling); } }catch(e){}
    }catch(e){}
  };
  document.head.appendChild(s);

  // resend with verifier reset on failure
  window.resendCode = async function(){
    var rt=document.getElementById('resend-timer'); if(rt) rt.innerHTML='Sending...';
    try{
      if(!window.lxFbSend){ throw new Error('fb-not-ready'); }
      await window.lxFbSend(currentPhone);
      if(typeof startResendTimer==='function') startResendTimer();
    }catch(e){
      try{ if(window.lxFbReset) window.lxFbReset(); }catch(_){}
      try{fetch('/functions/phone_verify.php?action=fbdiag',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({err:'resend:'+(((e&&e.code)?e.code:(''+(e&&e.message||e)))).slice(0,140)}),keepalive:true});}catch(_){}
      if(rt) rt.innerHTML='<a onclick="resendCode()">Failed. Try again'+((e&&e.code)?(' ('+e.code+')'):'')+'</a>';
    }
  };
})();</script>
</body>
</html>
