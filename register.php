<?php
require_once __DIR__.'/./includes/nv_i18n/engine/nv_i18n.php'; /* nv_i18n: site localisation (one line, removable) */
/**
 * Registration Page — Neuravision
 * Beautiful standalone registration with email + password
 * Also handles login mode via ?mode=login
 */
$mode = $_GET['mode'] ?? 'register';
$isLogin = ($mode === 'login');
if (!$isLogin) { header("Location: /flow/signin.html", true, 302); exit; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isLogin ? 'Sign In' : 'Create Account' ?> — Neuravision</title>
    <link rel="icon" href="/favicon/favicon.ico">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Inter', sans-serif;
            background: #0a0a0f;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            overflow-x: hidden;
        }
        
        body::before {
            content: '';
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: 
                radial-gradient(ellipse at 20% 0%, rgba(0, 255, 136, 0.06) 0%, transparent 50%),
                radial-gradient(ellipse at 80% 100%, rgba(0, 200, 255, 0.04) 0%, transparent 50%),
                radial-gradient(ellipse at 50% 50%, rgba(99, 102, 241, 0.03) 0%, transparent 60%);
            z-index: 0;
        }
        
        .page-wrapper {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 440px;
            padding: 24px;
        }
        
        .brand {
            text-align: center;
            margin-bottom: 32px;
        }
        
        .brand a {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            color: #fff;
            font-size: 20px;
            font-weight: 700;
        }
        
        .brand img {
            width: 32px;
            height: 32px;
            border-radius: 8px;
        }
        
        .card {
            background: rgba(17, 17, 27, 0.92);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 20px;
            padding: 40px 32px;
            backdrop-filter: blur(24px);
            box-shadow: 0 32px 64px -16px rgba(0, 0, 0, 0.6);
        }
        
        .card-title {
            font-size: 24px;
            font-weight: 700;
            text-align: center;
            margin-bottom: 6px;
        }
        
        .card-subtitle {
            text-align: center;
            color: rgba(255, 255, 255, 0.5);
            font-size: 14px;
            margin-bottom: 28px;
            line-height: 1.5;
        }
        
        .card-subtitle .credits {
            color: #00ff88;
            font-weight: 600;
        }
        
        /* Social buttons */
        .social-btns {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 24px;
        }
        
        .social-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            padding: 13px 20px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(255, 255, 255, 0.03);
            color: #fff;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s;
            cursor: pointer;
        }
        
        .social-btn:hover {
            background: rgba(255, 255, 255, 0.07);
            border-color: rgba(255, 255, 255, 0.15);
            transform: translateY(-1px);
        }
        
        .social-btn svg {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
        }
        
        /* Divider */
        .divider {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: rgba(255, 255, 255, 0.08);
        }
        
        .divider span {
            color: rgba(255, 255, 255, 0.35);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Form */
        .form-group {
            margin-bottom: 16px;
        }
        
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: rgba(255, 255, 255, 0.6);
            margin-bottom: 6px;
        }
        
        .form-input {
            width: 100%;
            padding: 13px 16px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            color: #fff;
            font-size: 15px;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        
        .form-input:focus {
            border-color: rgba(0, 255, 136, 0.4);
            box-shadow: 0 0 0 3px rgba(0, 255, 136, 0.08);
        }
        
        .form-input::placeholder {
            color: rgba(255, 255, 255, 0.25);
        }
        
        .password-wrapper {
            position: relative;
        }
        
        .password-toggle {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: rgba(255, 255, 255, 0.4);
            cursor: pointer;
            padding: 4px;
        }
        
        .password-toggle:hover {
            color: rgba(255, 255, 255, 0.7);
        }
        
        /* Submit button */
        .btn-submit {
            width: 100%;
            padding: 14px 24px;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 8px;
            background: linear-gradient(135deg, #00cc6a 0%, #00e68a 50%, #00b8d4 100%);
            color: #000;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .btn-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 24px rgba(0, 204, 106, 0.3);
        }
        
        .btn-submit:active {
            transform: translateY(0);
        }
        
        .btn-submit:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        /* Error/Success messages */
        .msg {
            padding: 10px 14px;
            border-radius: 10px;
            font-size: 13px;
            margin-bottom: 16px;
            display: none;
            line-height: 1.4;
        }
        
        .msg.error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #fca5a5;
        }
        
        .msg.success {
            background: rgba(0, 255, 136, 0.08);
            border: 1px solid rgba(0, 255, 136, 0.2);
            color: #86efac;
        }
        
        .msg.visible { display: block; }
        
        /* Footer */
        .card-footer {
            text-align: center;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
        }
        
        .card-footer p {
            color: rgba(255, 255, 255, 0.4);
            font-size: 13px;
        }
        
        .card-footer a {
            color: #00ff88;
            text-decoration: none;
            font-weight: 500;
        }
        
        .card-footer a:hover {
            text-decoration: underline;
        }
        
        .terms {
            text-align: center;
            margin-top: 16px;
            font-size: 11px;
            color: rgba(255, 255, 255, 0.3);
            line-height: 1.5;
        }
        
        .terms a {
            color: rgba(255, 255, 255, 0.5);
            text-decoration: none;
        }
        
        .terms a:hover {
            text-decoration: underline;
        }
        
        /* Spinner */
        .spinner {
            width: 18px;
            height: 18px;
            border: 2px solid rgba(0,0,0,0.2);
            border-top-color: #000;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }
        
        @keyframes spin { to { transform: rotate(360deg); } }
        
        /* Responsive */
        @media (max-width: 480px) {
            .page-wrapper { padding: 16px; }
            .card { padding: 32px 20px; }
            .card-title { font-size: 20px; }
        }
    </style>
</head>
<body>
    <div class="page-wrapper">
        <div class="brand">
            <a href="/">
                <img src="/assets/logo.png?v=neworange1" alt="Neuravision">
                Neuravision
            </a>
        </div>
        
        <div class="card">
            <h1 class="card-title" id="card-title" data-i18n-text="<?= $isLogin ? 'Welcome Back' : 'Create Your Account' ?>">
                <?= $isLogin ? 'Welcome Back' : 'Create Your Account' ?>
            </h1>
            <p class="card-subtitle" id="card-subtitle">
                <?php if ($isLogin): ?>
                <span data-i18n-text="Sign in to continue creating amazing content">Sign in to continue creating amazing content</span>
                <?php else: ?>
                <span data-i18n-text="Verify your email and get">Verify your email and get</span> <span class="credits">250 <span data-i18n-text="free credits">free credits</span></span> <span data-i18n-text="instantly.">instantly.</span>
                <?php endif; ?>
            </p>

            <!-- Social Login -->
            <div class="social-btns">
                <a href="/auth/google/redirect.php" class="social-btn">
                    <svg viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
                    <span data-i18n-text="Continue with Google">Continue with Google</span>
                </a>
                <a href="/auth/twitter/redirect.php" class="social-btn">
                    <svg viewBox="0 0 24 24" fill="white"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                    <span data-i18n-text="Continue with X">Continue with X</span>
                </a>
            </div>

            <div class="divider"><span data-i18n-text="or continue with email">or continue with email</span></div>
            
            <!-- Email Form -->
            <div class="msg error" id="error-msg"></div>
            <div class="msg success" id="success-msg"></div>
            
            <?php if ($isLogin): ?>
            <form id="auth-form" onsubmit="handleLoginSubmit(event)">
                <div class="form-group">
                    <label for="email" data-i18n-text="Email address">Email address</label>
                    <input type="email" id="email" class="form-input" placeholder="you@example.com" required autocomplete="email">
                </div>

                <div class="form-group">
                    <label for="password" data-i18n-text="Password">Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="password" class="form-input" placeholder="Enter your password" required autocomplete="current-password" minlength="1" data-i18n-attr="placeholder:Enter your password">
                        <button type="button" class="password-toggle" onclick="togglePassword()">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-submit" id="btn-submit" data-i18n-text="Sign In">Sign In</button>
            </form>
            <?php else: ?>
            <!-- Step 1: email -->
            <form id="step-email-form" onsubmit="handleSendCode(event)">
                <div class="form-group">
                    <label for="reg-email" data-i18n-text="Email address">Email address</label>
                    <input type="email" id="reg-email" class="form-input" placeholder="you@example.com" required autocomplete="email">
                </div>
                <button type="submit" class="btn-submit" id="btn-send-code" data-i18n-text="Send me a code">Send me a code</button>
            </form>

            <!-- Step 2: 6-digit code (hidden until step 1 succeeds) -->
            <form id="step-code-form" onsubmit="handleVerifyCode(event)" style="display:none;">
                <p class="card-subtitle" style="text-align:left;margin:0 0 18px;"><span data-i18n-text="We sent a 6-digit code to">We sent a 6-digit code to</span> <strong id="code-email-display" style="color:#fff;"></strong>. <span data-i18n-text="Enter it below — your login and temporary password will follow by email right after, with your 250 credits ready to go.">Enter it below — your login and temporary password will follow by email right after, with your 250 credits ready to go.</span></p>
                <div class="form-group">
                    <label for="reg-code" data-i18n-text="Verification code">Verification code</label>
                    <input type="text" id="reg-code" class="form-input" placeholder="000000" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" required>
                </div>
                <button type="submit" class="btn-submit" id="btn-verify-code" data-i18n-text="Verify &amp; Create Account">Verify &amp; Create Account</button>
                <p style="text-align:center;margin-top:14px;">
                    <a href="#" id="resend-link" onclick="handleResendCode(event)" style="color:#00ff88;font-size:13px;text-decoration:none;" data-i18n-text="Didn't get it? Resend code">Didn't get it? Resend code</a>
                </p>
            </form>
            <?php endif; ?>

            <div class="card-footer">
                <?php if ($isLogin): ?>
                    <p><span data-i18n-text="Don't have an account?">Don't have an account?</span> <a href="/register.php" data-i18n-text="Sign up free">Sign up free</a></p>
                <?php else: ?>
                    <p><span data-i18n-text="Already have an account?">Already have an account?</span> <a href="/register.php?mode=login" data-i18n-text="Sign in">Sign in</a></p>
                <?php endif; ?>
            </div>
            
            <?php if (!$isLogin): ?>
            <div class="terms">
                By creating an account, you agree to our <a href="/terms">Terms of Service</a> and <a href="/privacy">Privacy Policy</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        const isLogin = <?= $isLogin ? 'true' : 'false' ?>;
        let pendingEmail = '';

        function togglePassword() {
            const input = document.getElementById('password');
            input.type = input.type === 'password' ? 'text' : 'password';
        }

        function showError(msg) {
            const el = document.getElementById('error-msg');
            el.textContent = msg;
            el.classList.add('visible');
            document.getElementById('success-msg').classList.remove('visible');
        }

        function showSuccess(msg) {
            const el = document.getElementById('success-msg');
            el.textContent = msg;
            el.classList.add('visible');
            document.getElementById('error-msg').classList.remove('visible');
        }

        function hideMessages() {
            document.getElementById('error-msg').classList.remove('visible');
            document.getElementById('success-msg').classList.remove('visible');
        }

        async function handleLoginSubmit(e) {
            e.preventDefault();
            hideMessages();

            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;
            const btn = document.getElementById('btn-submit');

            if (!email || !password) {
                showError('Please fill in all fields');
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<div class="spinner"></div>';

            try {
                const formData = new FormData();
                formData.append('email', email);
                formData.append('password', password);
                formData.append('action', 'login');

                const resp = await fetch('/functions/auth_register.php', { method: 'POST', body: formData });
                const data = await resp.json();

                if (data.ok) {
                    showSuccess('Signing in...');
                    setTimeout(() => { window.location.href = data.redirect || '/account'; }, 800);
                } else {
                    showError(data.message || 'Something went wrong. Please try again.');
                    btn.disabled = false;
                    btn.textContent = 'Sign In';
                }
            } catch (err) {
                showError('Network error. Please check your connection and try again.');
                btn.disabled = false;
                btn.textContent = 'Sign In';
            }
        }

        async function handleSendCode(e) {
            e.preventDefault();
            hideMessages();

            const email = document.getElementById('reg-email').value.trim();
            const btn = document.getElementById('btn-send-code');
            if (!email) { showError('Please enter your email address'); return; }

            btn.disabled = true;
            btn.innerHTML = '<div class="spinner"></div>';

            try {
                const formData = new FormData();
                formData.append('email', email);

                const resp = await fetch('/functions/auth_send_code.php', { method: 'POST', body: formData });
                const data = await resp.json();

                if (data.ok) {
                    pendingEmail = email;
                    document.getElementById('code-email-display').textContent = email;
                    document.getElementById('step-email-form').style.display = 'none';
                    document.getElementById('step-code-form').style.display = 'block';
                    showSuccess('Code sent! Check your inbox.');
                } else if (data.already_registered) {
                    showError(data.message + ' ');
                    const link = document.createElement('a');
                    link.href = '/register.php?mode=login';
                    link.textContent = 'Sign in';
                    link.style.color = '#00ff88';
                    document.getElementById('error-msg').appendChild(link);
                    btn.disabled = false;
                    btn.textContent = 'Send me a code';
                } else {
                    showError(data.message || 'Something went wrong. Please try again.');
                    btn.disabled = false;
                    btn.textContent = 'Send me a code';
                }
            } catch (err) {
                showError('Network error. Please check your connection and try again.');
                btn.disabled = false;
                btn.textContent = 'Send me a code';
            }
        }

        async function handleVerifyCode(e) {
            e.preventDefault();
            hideMessages();

            const code = document.getElementById('reg-code').value.trim();
            const btn = document.getElementById('btn-verify-code');
            if (!code) { showError('Please enter the code from your email'); return; }

            btn.disabled = true;
            btn.innerHTML = '<div class="spinner"></div>';

            try {
                const formData = new FormData();
                formData.append('email', pendingEmail);
                formData.append('code', code);

                const resp = await fetch('/functions/auth_verify_code.php', { method: 'POST', body: formData });
                const data = await resp.json();

                if (data.ok) {
                    showSuccess('Email verified! Check your inbox for your login — redirecting...');
                    setTimeout(() => { window.location.href = data.redirect || '/account'; }, 900);
                } else {
                    showError(data.message || 'Incorrect code. Please try again.');
                    btn.disabled = false;
                    btn.textContent = 'Verify & Create Account';
                }
            } catch (err) {
                showError('Network error. Please check your connection and try again.');
                btn.disabled = false;
                btn.textContent = 'Verify & Create Account';
            }
        }

        async function handleResendCode(e) {
            e.preventDefault();
            hideMessages();
            if (!pendingEmail) return;

            try {
                const formData = new FormData();
                formData.append('email', pendingEmail);
                const resp = await fetch('/functions/auth_send_code.php', { method: 'POST', body: formData });
                const data = await resp.json();
                showSuccess(data.ok ? 'A new code is on its way.' : (data.message || 'Please wait a moment before requesting another code.'));
            } catch (err) {
                showError('Network error. Please check your connection and try again.');
            }
        }
    </script>
    <script>
        /* Browser-language auto-detect — same mechanism as the main site (assets/index.js):
           navigator.languages -> normalize -> match against the supported list -> apply.
           Persisted under the same localStorage key the main site uses, so a language
           picked on neuracreation.com carries over here automatically. */
        (function(){
            var LANG_KEY = 'lx-language', SUPPORTED = ['en','de','fr','ja','ko','zh','pt','es','it'];
            function normalizeLang(v){
                if (typeof v !== 'string') return null;
                var n = v.trim().toLowerCase().replace(/_/g,'-');
                if (!n) return null;
                if (n.indexOf('zh') === 0) return 'zh';
                if (n.indexOf('pt') === 0) return 'pt';
                return n.split('-')[0] || null;
            }
            function detectLang(){
                try {
                    var stored = window.localStorage.getItem(LANG_KEY);
                    if (stored && SUPPORTED.indexOf(stored) > -1) return stored;
                } catch (e) {}
                var langs = (Array.isArray(navigator.languages) && navigator.languages.length) ? navigator.languages : [navigator.language || navigator.userLanguage || ''];
                for (var i = 0; i < langs.length; i++) {
                    var c = normalizeLang(langs[i]);
                    if (c && SUPPORTED.indexOf(c) > -1) return c;
                }
                return 'en';
            }
            var lang = detectLang();
            document.documentElement.lang = lang;
            if (lang === 'en') return;
            fetch('/assets/i18n/register.json').then(function(r){ return r.json(); }).then(function(d){
                var dict = (d && d.text) || {};
                function t(key){ return (dict[key] && dict[key][lang]) || key; }
                document.querySelectorAll('[data-i18n-text]').forEach(function(el){
                    var key = el.getAttribute('data-i18n-text');
                    if (key) el.textContent = t(key);
                });
                document.querySelectorAll('[data-i18n-attr]').forEach(function(el){
                    var spec = el.getAttribute('data-i18n-attr') || '';
                    var parts = spec.split(':');
                    if (parts.length >= 2) {
                        var attr = parts.shift();
                        var key = parts.join(':');
                        el.setAttribute(attr, t(key));
                    }
                });
            }).catch(function(){});
        })();
    </script>
</body>
</html>
