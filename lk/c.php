<?php
require_once __DIR__.'/../includes/nv_i18n/engine/nv_i18n.php'; /* nv_i18n: site localisation (one line, removable) */
/**
 * /lk/c.php — cabinet variant C of the A/B/C split ("one-tap download" screen).
 *
 * Goal: the fewest possible distractions between signing in and getting the app.
 * A single big Download button on the first screen (OS auto-detected with a Windows/macOS
 * toggle; on phones just one line "open this page on your computer" -- no forms; owner 2026-10-10: no email-link
 * block and no promo field in this variant), and the step-by-step install guide that appears right after the click.
 *
 * Everything is wired to production exactly like A and B (lk_core.php + lk_dlblock.php, lk_dl_block_pure()):
 * Download -> /api/desktop_download.php, readiness -> /api/desktop_status.php,
 * email link -> /api/desktop_email_link.php, promo -> /api/promo_redeem.php.
 * Shares variant A's theme assets (/lk/assets-a/).
 */
require_once __DIR__ . '/lk_core.php';
require_once __DIR__ . '/lk_dlblock.php';
require_once __DIR__ . '/lk_install_steps.php';
if (!defined('LK_ROUTED')) { $_GET['lk'] = 'c'; }   // direct hit on /lk/c.php pins variant C
$C = lk_boot();
?>
<!doctype html><html lang="en"><head>
<?php require_once LK_ROOT . '/includes/gtag.php'; ?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Neuravision — Download</title>
<meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/png" href="/lk/assets-a/img/logo.png?v=neworange1">
<link rel="stylesheet" href="/lk/assets-a/nv.css">
<link rel="stylesheet" href="/lk/assets-a/v1.css">
<?php echo lk_dl_head($C); echo lk_install_assets(); ?>
<?php lk_x_pixel_head(); ?>
<?php lk_fb_pixel_head(); ?>
<style>
.cwrap{max-width:720px;margin:0 auto;padding:40px 20px 72px}
.cdl{text-align:center}
.cdl .eyebrow{display:inline-block;font-size:12.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--mut);margin:0 0 12px}
.cdl h1{font-size:34px;line-height:1.1;letter-spacing:-.03em;margin:0 0 10px}
.cdl h1 .g{background:var(--grad);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
.cdl .lead{color:var(--mut);font-size:15.5px;line-height:1.55;margin:0 auto 26px;max-width:520px}
.cdl .lkdl{max-width:560px;margin:0 auto}
.cdl .lkdl-main{justify-content:center}
.cdl .lkdl-cta{font-size:17px;padding:16px 30px}
.cdl .lkdl-os{justify-content:center}
.cdl .nvins{margin-left:auto;margin-right:auto;text-align:left}
.cbadges{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin:22px 0 0}
.cbadges .chip{max-width:100%}
.clic{margin:14px 0 0;font-size:12.5px;color:var(--mut);text-align:center}
.clic b{color:var(--text);font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-weight:600;letter-spacing:.3px}
.csec{margin:30px auto 0;max-width:560px}
.cshot{margin:34px auto 0;max-width:620px;border:1px solid var(--line2);border-radius:16px;overflow:hidden;box-shadow:0 40px 80px -46px #000;background:#0d0d16}
.cshot img{display:block;width:100%;height:auto}
@media (max-width:640px){.cdl h1{font-size:26px}.cwrap{padding:28px 16px 64px}}
.cdl .lkdl-card{display:none}
.cdl .lkdl-phone{margin:8px auto 0;max-width:460px;font-size:18px;line-height:1.45;font-weight:600;color:var(--text)}
.cresend{margin:10px 0 0;font-size:12.5px;color:var(--mut)}
.cresend-btn{background:none;border:0;padding:0;margin:0;color:var(--text);text-decoration:underline;cursor:pointer;font:inherit}
.cresend-btn:disabled{opacity:.5;cursor:default;text-decoration:none}
.cresend-msg{margin:6px 0 0;font-size:12.5px;min-height:1em}
.cresend-msg.ok{color:#00cc6a}
.cresend-msg.err{color:#ff5a66}
.egate{max-width:420px;margin:0 auto 20px;background:var(--panel);border:1px solid var(--line);border-radius:var(--r);padding:26px 24px;text-align:left}
.egate h2{font-size:17px;margin:0 0 6px;color:var(--text)}
.egate .elead{color:var(--mut);font-size:13.5px;line-height:1.5;margin:0 0 18px}
.egate .ecode{position:relative;display:flex;gap:8px;justify-content:center;margin:0 0 14px}
.egate .ecode .cell{width:42px;height:50px;border:1px solid var(--line2);border-radius:10px;background:var(--well);color:var(--text);font-size:20px;font-weight:700;text-align:center;line-height:48px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
.egate .ecode .cell.on{border-color:var(--acc);box-shadow:0 0 0 3px rgba(255,106,26,.18)}
.egate .ecode.bad .cell{border-color:#ff5a66}
.egate #ecodeInput{position:absolute;inset:0;width:100%;height:100%;opacity:0;font-size:16px;color:transparent;caret-color:transparent;background:transparent;border:0;padding:0;margin:0;letter-spacing:40px}
.egate .emsg{min-height:1.2em;font-size:12.5px;margin:0 0 12px;text-align:center}
.egate .emsg.err{color:#ff5a66}
.egate .emsg.ok{color:#00cc6a}
.egate .erow{display:flex;align-items:center;justify-content:center;gap:10px;margin-top:12px;font-size:12.5px;color:var(--mut)}
.egsuccess{text-align:center;color:#00cc6a;font-size:13.5px;line-height:1.5;margin:0 0 18px;max-width:520px;margin-left:auto;margin-right:auto}
</style>
</head>
<body>
<header class="top">
  <a class="brand" href="/"><img class="logo" src="/lk/assets-a/img/logo.png?v=neworange1" alt="" width="32" height="32"><span class="bt">Neuravision</span></a>
  <div class="right" data-nv-lang-mount>
    <div class="userchip"><span class="ava"><?php echo lk_e($C['initial']); ?></span><span class="lk" id="userEmail"><?php echo lk_e($C['email']); ?></span></div>
    <a class="linkbtn" id="signout" href="#" data-track="signout">Sign out</a>
  </div>
</header>

<main class="cwrap">
  <section class="cdl" id="hero">
    <span class="eyebrow">Your account is ready</span>
    <h1>Download <span class="g">Neuravision</span> and start creating</h1>
    <p class="lead">Install the desktop app and sign in with your email and password — your <?php echo lk_e($C['creditsFmt']); ?> credits are already waiting.</p>

    <?php if (empty($C['emailVerified'])): ?>
    <div class="egate" id="emailGate">
      <h2>Confirm it's you</h2>
      <p class="elead">One quick check before we hand over the installer — enter the 6-digit code we just sent to <b><?php echo lk_e($C['email']); ?></b>.</p>
      <div class="ecode" id="ecode" role="group" aria-label="Code from the email">
        <span class="cell"></span><span class="cell"></span><span class="cell"></span><span class="cell"></span><span class="cell"></span><span class="cell"></span>
        <input id="ecodeInput" type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" enterkeyhint="done" autocapitalize="off" autocorrect="off" spellcheck="false" aria-label="Code from the email">
      </div>
      <p class="emsg" id="egMsg" role="alert"></p>
      <button type="button" class="btn btn-pri btn-block" id="egVerify">Verify &amp; unlock download</button>
      <div class="erow"><span id="egTimer"></span><button type="button" class="linkbtn" id="egResend" hidden>Resend code</button></div>
    </div>
    <?php endif; ?>

    <p class="egsuccess" id="egSuccess" style="display:none"></p>

    <div id="dlReal"<?php if (empty($C['emailVerified'])): ?> style="display:none"<?php endif; ?>>
      <?php echo lk_dl_block_pure($C, 'Download'); ?>

      <?php echo lk_install_steps_html(); ?>
    </div>

    <div class="cbadges">
      <span class="chip chip-ok"><?php echo lk_e($C['creditsFmt']); ?> credits on your account</span>
      <span class="chip chip-acc">Signed in as <?php echo lk_e($C['email']); ?></span>
    </div>
    <p class="cresend">Lost your password? <button type="button" id="resendLogin" class="cresend-btn" data-track="resend_login">Email me a new one</button></p>
    <p class="cresend-msg" id="resendMsg" role="status" aria-live="polite"></p>

    <div class="cshot">
      <img src="/lk/assets-a/img/app.jpg" alt="Neuravision desktop app window with the AI assistant" width="1400" height="820">
    </div>
  </section>
</main>

<footer class="foot">
  <span><b>Neuravision</b></span>
  <a href="mailto:support@neuracreation.com">support@neuracreation.com</a>
  <a href="/pricing">Pricing</a>
  <a href="/contact">Contact</a>
  <span class="co">Vista Bridge Advisors L.L.C-FZ, Dubai</span>
</footer>

<script src="/lk/assets/lk-common.js?v=obf1"></script>
<script>
(function () {
  var so = document.getElementById('signout');
  if (so) so.addEventListener('click', function (e) {
    e.preventDefault();
    try {
      document.cookie = 'lx_auth_token=;Max-Age=0;path=/';
      document.cookie = 'lx_auth=;Max-Age=0;path=/';
      document.cookie = 'lx_user_id=;Max-Age=0;path=/';
      localStorage.removeItem('lx-auth-token'); localStorage.removeItem('lx_auth_token');
    } catch (e2) {}
    location.href = '/';
  });

  var rb = document.getElementById('resendLogin'), rmsg = document.getElementById('resendMsg');
  if (rb) rb.addEventListener('click', function () {
    rb.disabled = true;
    rmsg.className = 'cresend-msg';
    rmsg.textContent = 'Sending…';
    fetch('/api/lk_resend_login.php', { method: 'POST', credentials: 'same-origin' })
      .then(function (r) { return r.text().then(function (t) { var j = null; try { j = JSON.parse(t); } catch (e3) {} return { j: j }; }); })
      .then(function (res) {
        rb.disabled = false;
        if (res.j && res.j.ok) {
          rmsg.className = 'cresend-msg ok';
          rmsg.textContent = res.j.message || 'Sent — check your inbox.';
        } else {
          rmsg.className = 'cresend-msg err';
          rmsg.textContent = (res.j && res.j.message) || 'Could not send. Please try again.';
        }
      }, function () {
        rb.disabled = false;
        rmsg.className = 'cresend-msg err';
        rmsg.textContent = 'Network error — please try again.';
      });
  });

  var gate = document.getElementById('emailGate');
  if (gate) {
    var cells = [].slice.call(gate.querySelectorAll('.ecode .cell'));
    var real = document.getElementById('ecodeInput');
    var wrap = document.getElementById('ecode');
    var msg = document.getElementById('egMsg');
    var verifyBtn = document.getElementById('egVerify');
    var timerEl = document.getElementById('egTimer');
    var resendBtn = document.getElementById('egResend');
    var dlReal = document.getElementById('dlReal');
    var egSuccess = document.getElementById('egSuccess');
    var tick, busy = false;

    function post(data) {
      var fd = new FormData();
      for (var k in data) fd.append(k, data[k]);
      return fetch('/api/lk_verify_email.php', { method: 'POST', credentials: 'same-origin', body: fd })
        .then(function (r) { return r.text(); })
        .then(function (t) { try { return JSON.parse(t); } catch (e) { return { ok: false, message: 'Network error — please try again.' }; } });
    }

    function value() { return (real.value || '').replace(/\D/g, '').slice(0, 6); }
    function paint() {
      var v = value();
      cells.forEach(function (c, i) { c.textContent = v.charAt(i) || ''; c.classList.toggle('on', document.activeElement === real && i === Math.min(v.length, 5)); });
    }
    function clearCode() { real.value = ''; wrap.classList.remove('bad'); paint(); try { real.focus(); } catch (e) {} }
    real.addEventListener('input', function () { wrap.classList.remove('bad'); paint(); if (value().length === 6) doVerify(); });
    real.addEventListener('focus', paint);
    real.addEventListener('blur', paint);
    gate.querySelector('.ecode').addEventListener('click', function () { real.focus(); paint(); });

    function startTimer() {
      var s = 59; clearInterval(tick);
      resendBtn.hidden = true; timerEl.hidden = false;
      function draw() { timerEl.textContent = 'Resend in ' + (s < 10 ? '0' : '') + s + 's'; }
      draw();
      tick = setInterval(function () { s--; if (s <= 0) { clearInterval(tick); timerEl.hidden = true; resendBtn.hidden = false; } else draw(); }, 1000);
    }

    function sendCode() {
      return post({ action: 'send' }).then(function (d) {
        if (d.ok || d.cooldown) { startTimer(); if (!d.ok) msg.className = 'emsg'; }
        else { msg.className = 'emsg err'; msg.textContent = d.message || 'Could not send the code. Please try again.'; }
      }, function () { msg.className = 'emsg err'; msg.textContent = 'Network error — please try again.'; });
    }

    function doVerify() {
      if (busy) return;
      var code = value();
      if (code.length !== 6) { msg.className = 'emsg err'; msg.textContent = 'Enter all 6 digits from the email.'; return; }
      busy = true; verifyBtn.disabled = true; msg.className = 'emsg'; msg.textContent = 'Checking…';
      post({ action: 'verify', code: code }).then(function (d) {
        busy = false; verifyBtn.disabled = false;
        if (!d.ok) {
          wrap.classList.add('bad'); msg.className = 'emsg err'; msg.textContent = d.message || 'That code is not correct.';
          clearCode();
          return;
        }
        gate.style.display = 'none';
        if (dlReal) dlReal.style.display = '';
        if (egSuccess) {
          egSuccess.style.display = '';
          egSuccess.textContent = 'Email confirmed — we’ve also sent a fresh login & password to your inbox. Enjoy creating with the best AI around, courtesy of Neuravision! 🚀';
        }
      }, function () {
        busy = false; verifyBtn.disabled = false;
        msg.className = 'emsg err'; msg.textContent = 'Network error — please try again.';
      });
    }
    verifyBtn.addEventListener('click', doVerify);
    resendBtn.addEventListener('click', function () { clearCode(); sendCode(); });

    sendCode();
  }
})();
</script>
<?php require_once LK_ROOT . '/includes/track_actions.php'; if (function_exists('lx_track_actions')) lx_track_actions(); ?>
<script src="/chat/widget.js?v=sup1010" defer></script>
</body></html>
