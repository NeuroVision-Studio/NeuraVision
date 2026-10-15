<?php
/**
 * Set-password gate — shown once, right after an email-code signup
 * (or a reissued temp password), before the user can enter /account.
 * Auth is the same `token` used by account.php's email fallback, not a
 * PHP session, so the link keeps working even in a fresh browser tab.
 */
$token = isset($_GET['token']) ? trim($_GET['token']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Set Your Password — Neuravision</title>
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

        .page-wrapper { position: relative; z-index: 1; width: 100%; max-width: 440px; padding: 24px; }

        .brand { text-align: center; margin-bottom: 32px; }
        .brand a { display: inline-flex; align-items: center; gap: 10px; text-decoration: none; color: #fff; font-size: 20px; font-weight: 700; }
        .brand img { width: 32px; height: 32px; border-radius: 8px; }

        .card {
            background: rgba(17, 17, 27, 0.92);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 20px;
            padding: 40px 32px;
            backdrop-filter: blur(24px);
            box-shadow: 0 32px 64px -16px rgba(0, 0, 0, 0.6);
        }

        .card-title { font-size: 24px; font-weight: 700; text-align: center; margin-bottom: 6px; }
        .card-subtitle { text-align: center; color: rgba(255, 255, 255, 0.5); font-size: 14px; margin-bottom: 22px; line-height: 1.5; }
        .card-subtitle .credits { color: #00ff88; font-weight: 600; }

        .notice {
            background: rgba(255,173,51,0.06);
            border: 1px solid rgba(255,173,51,0.18);
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 24px;
            color: rgba(255,255,255,0.65);
            font-size: 12.5px;
            line-height: 1.6;
        }
        .notice strong { color: #ffad33; }

        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 13px; font-weight: 500; color: rgba(255, 255, 255, 0.6); margin-bottom: 6px; }

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
        .form-input:focus { border-color: rgba(0, 255, 136, 0.4); box-shadow: 0 0 0 3px rgba(0, 255, 136, 0.08); }
        .form-input::placeholder { color: rgba(255, 255, 255, 0.25); }

        .password-wrapper { position: relative; }
        .password-toggle {
            position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
            background: none; border: none; color: rgba(255, 255, 255, 0.4); cursor: pointer; padding: 4px;
        }
        .password-toggle:hover { color: rgba(255, 255, 255, 0.7); }

        .btn-submit {
            width: 100%; padding: 14px 24px; border: none; border-radius: 12px;
            font-size: 15px; font-weight: 600; cursor: pointer; transition: all 0.2s; margin-top: 8px;
            background: linear-gradient(135deg, #00cc6a 0%, #00e68a 50%, #00b8d4 100%);
            color: #000; display: flex; align-items: center; justify-content: center; gap: 8px;
        }
        .btn-submit:hover { transform: translateY(-1px); box-shadow: 0 8px 24px rgba(0, 204, 106, 0.3); }
        .btn-submit:active { transform: translateY(0); }
        .btn-submit:disabled { opacity: 0.5; cursor: not-allowed; transform: none; box-shadow: none; }

        .msg { padding: 10px 14px; border-radius: 10px; font-size: 13px; margin-bottom: 16px; display: none; line-height: 1.4; }
        .msg.error { background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); color: #fca5a5; }
        .msg.success { background: rgba(0, 255, 136, 0.08); border: 1px solid rgba(0, 255, 136, 0.2); color: #86efac; }
        .msg.visible { display: block; }

        .spinner { width: 18px; height: 18px; border: 2px solid rgba(0,0,0,0.2); border-top-color: #000; border-radius: 50%; animation: spin 0.6s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        @media (max-width: 480px) { .page-wrapper { padding: 16px; } .card { padding: 32px 20px; } .card-title { font-size: 20px; } }
    </style>
</head>
<body>
    <div class="page-wrapper">
        <div class="brand">
            <a href="/"><img src="/assets/logo.png?v=neworange1" alt="Neuravision">Neuravision</a>
        </div>

        <div class="card">
            <h1 class="card-title">Set Your Password</h1>
            <p class="card-subtitle">Your <span class="credits">250 free credits</span> are ready — just set a password you'll remember to finish unlocking your studio.</p>

            <div class="notice">
                &#x23F1; You signed in with a temporary password. It stays valid for 24 hours, so there's no rush — but setting your own now means you won't need a new one later.
            </div>

            <div class="msg error" id="error-msg"></div>
            <div class="msg success" id="success-msg"></div>

            <form id="set-pw-form" onsubmit="handleSubmit(event)">
                <input type="hidden" id="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                <div class="form-group">
                    <label for="password">New password</label>
                    <div class="password-wrapper">
                        <input type="password" id="password" class="form-input" placeholder="Create a strong password" required autocomplete="new-password" minlength="8">
                        <button type="button" class="password-toggle" onclick="togglePassword()">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-submit" id="btn-submit">Set Password &amp; Continue</button>
            </form>
        </div>
    </div>

    <script>
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

        async function handleSubmit(e) {
            e.preventDefault();
            document.getElementById('error-msg').classList.remove('visible');
            document.getElementById('success-msg').classList.remove('visible');

            const token = document.getElementById('token').value;
            const password = document.getElementById('password').value;
            const btn = document.getElementById('btn-submit');

            if (password.length < 8) { showError('Password must be at least 8 characters'); return; }
            if (!/[A-Za-z]/.test(password) || !/[0-9]/.test(password)) { showError('Password must include letters and a number'); return; }

            btn.disabled = true;
            btn.innerHTML = '<div class="spinner"></div>';

            try {
                const formData = new FormData();
                formData.append('token', token);
                formData.append('password', password);

                const resp = await fetch('/functions/auth_set_password.php', { method: 'POST', body: formData });
                const data = await resp.json();

                if (data.ok) {
                    showSuccess('Password set! Taking you to your studio...');
                    setTimeout(() => { window.location.href = data.redirect || '/account'; }, 700);
                } else {
                    showError(data.message || 'Something went wrong. Please try again.');
                    btn.disabled = false;
                    btn.textContent = 'Set Password & Continue';
                }
            } catch (err) {
                showError('Network error. Please check your connection and try again.');
                btn.disabled = false;
                btn.textContent = 'Set Password & Continue';
            }
        }
    </script>
</body>
</html>
