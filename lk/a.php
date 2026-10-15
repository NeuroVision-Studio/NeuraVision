<?php
require_once __DIR__.'/../includes/nv_i18n/engine/nv_i18n.php'; /* nv_i18n: site localisation (one line, removable) */
/**
 * /lk/a.php — cabinet variant A of the 50/50 split ("download-first" screen).
 *
 * Source design: Cash Top site f6d8e12e (assets copied to /lk/assets-a/).
 * Everything that used to be simulated in that preview is wired to production here:
 * the signed-in email and the credit balance come from data/users.sqlite via lk_core.php,
 * Download goes to /api/desktop_download.php, readiness to /api/desktop_status.php,
 * the mobile email-link form to /api/desktop_email_link.php and the promo field to
 * /api/promo_redeem.php. The preview-only nv-dl.js runtime is intentionally NOT loaded.
 *
 * Asset paths are absolute (/lk/assets-a/...) because this file can be include()d by
 * the router from a different URL path.
 */
require_once __DIR__ . '/lk_core.php';
require_once __DIR__ . '/lk_dlblock.php';
require_once __DIR__ . '/lk_install_steps.php';
if (!defined('LK_ROUTED')) { $_GET['lk'] = 'a'; }   // direct hit on /lk/a.php pins variant A
$C = lk_boot();
?>
<!doctype html><html lang="en"><head>
<?php require_once LK_ROOT . '/includes/gtag.php'; ?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Neuravision — Account</title>
<meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/png" href="/lk/assets-a/img/logo.png?v=neworange1">
<link rel="stylesheet" href="/lk/assets-a/nv.css">
<link rel="stylesheet" href="/lk/assets-a/v1.css">
<?php echo lk_dl_head($C); echo lk_install_assets(); ?>
<?php lk_x_pixel_head(); ?>
<?php lk_fb_pixel_head(); ?>
<style>
.hero-soon{display:flex;gap:10px;align-items:flex-start;margin:0 0 20px;padding:12px 14px;max-width:560px;border:1px solid var(--line);border-left:3px solid rgba(255,106,26,.8);border-radius:12px;background:rgba(255,255,255,.04);font-size:14px;line-height:1.5;color:var(--mut)}
.hero-soon b{color:var(--text);font-weight:600}
.hero-soon-dot{flex:none;width:8px;height:8px;margin-top:7px;border-radius:50%;background:#ff6a1a;box-shadow:0 0 0 4px rgba(255,106,26,.18)}
.dl .acct .chip{max-width:100%}
.lic{margin-top:14px;font-size:12.5px;color:var(--mut)}
.lic b{color:var(--text);font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-weight:600;letter-spacing:.3px}
/* The sticky bar must never sit on top of the hero controls on a phone. */
@media (max-width:640px){ body.has-sticky{padding-bottom:96px} }
</style>
</head>
<body>
<header class="top">
  <a class="brand" href="/"><img class="logo" src="/lk/assets-a/img/logo.png?v=neworange1" alt="" width="32" height="32"><span class="bt">Neuravision</span></a>
  <div class="mid">
    <span class="chip chip-warn pulse" id="webStatus">Web studio · updating</span>
  </div>
  <div class="right" data-nv-lang-mount>
    <div class="userchip"><span class="ava"><?php echo lk_e($C['initial']); ?></span><span class="lk" id="userEmail"><?php echo lk_e($C['email']); ?></span></div>
    <a class="linkbtn" id="signout" href="#" data-track="signout">Sign out</a>
  </div>
</header>

<div id="statusBar" aria-hidden="true"></div>

<main class="wrap">
  <section class="dl" id="hero">
    <div>
      <span class="eyebrow">Web studio · upgrade in progress</span>
      <h1 class="disp">New tools are on their way to the web — and already in the <span class="g">desktop app</span></h1>
      <p class="lead">You asked for them, and we're building them into the web studio too. That takes a little time. Until then, every new tool is live in Neuravision Desktop for Windows and macOS — with your <?php echo lk_e($C['creditsFmt']); ?> credits ready to use.</p>
      <p class="hero-soon"><span class="hero-soon-dot" aria-hidden="true"></span><span><b>Coming soon: the full desktop version of the web studio and a Google Chrome extension.</b> We'll email you the moment they launch. In the meantime, every feature of the platform is already yours in the desktop app.</span></p>

      <?php echo lk_dl_block($C, 'Download'); ?>
      <?php echo lk_install_steps_html(); ?>

      <div class="acct">
        <span class="chip chip-ok"><?php echo lk_e($C['creditsFmt']); ?> credits on your account</span>
        <span class="chip chip-acc">Signed in as <?php echo lk_e($C['email']); ?></span>
      </div>
    </div>
    <div class="shot-wrap">
      <div class="shot"><img src="/lk/assets-a/img/app.jpg" alt="Neuravision desktop app window with the AI assistant" width="1400" height="820"></div>
      <span class="badge-float">8 new tools · live in desktop</span>
    </div>
  </section>

  <section class="sec how" id="how" aria-label="What to do next">
    <div class="card on">
      <div class="num">1</div>
      <h3>Download</h3>
      <p>Click the button above — the file will save to your Downloads folder.</p>
    </div>
    <div class="card">
      <div class="num">2</div>
      <h3>Install</h3>
      <p>Open the downloaded file and follow the installer prompts. Takes about a minute.</p>
    </div>
    <div class="card">
      <div class="num">3</div>
      <h3>Sign in</h3>
      <p>Sign in as <b><?php echo lk_e($C['email']); ?></b> with the password you set. Neira, your AI assistant, will greet you after signing in.</p>
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

<div class="sticky" id="stickyCta" aria-hidden="true">
  <div class="s-txt"><b data-lk-oslabel>Download for <?php echo lk_e($C['os'] === 'macos' ? 'macOS' : 'Windows'); ?></b><?php echo lk_e($C['creditsFmt']); ?> credits are waiting</div>
  <div><a class="btn btn-pri btn-sm" data-lk-dl href="/api/desktop_download.php?os=<?php echo $C['os']; ?>">Download</a></div>
</div>

<script src="/lk/assets/lk-common.js?v=obf1"></script>
<script>
(function () {
  /* status chip: short label on phones */
  var mq = window.matchMedia('(max-width:640px)');
  function statusLabel() { document.getElementById('webStatus').textContent = mq.matches ? 'Updating' : 'Web studio · updating'; }
  statusLabel(); if (mq.addEventListener) mq.addEventListener('change', statusLabel);

  /* Sign out — same cookie clear account.php uses, then home. */
  document.getElementById('signout').addEventListener('click', function (e) {
    e.preventDefault();
    try {
      document.cookie = 'lx_auth_token=;Max-Age=0;path=/';
      document.cookie = 'lx_auth=;Max-Age=0;path=/';
      document.cookie = 'lx_user_id=;Max-Age=0;path=/';
      localStorage.removeItem('lx-auth-token'); localStorage.removeItem('lx_auth_token');
    } catch (e2) {}
    location.href = '/';
  });

  /* Sticky Download bar: only once the hero CTA has scrolled out of view.
     On a phone the email form is the hero control, so the bar stays off there. */
  var sticky = document.getElementById('stickyCta');
  var isMobile = !!(window.LK_CTX && window.LK_CTX.mobile);
  if (isMobile) { sticky.parentNode.removeChild(sticky); }
  else if ('IntersectionObserver' in window) {
    new IntersectionObserver(function (en) {
      var out = !en[0].isIntersecting && en[0].boundingClientRect.top < 0;
      sticky.classList.toggle('on', out);
      sticky.setAttribute('aria-hidden', out ? 'false' : 'true');
      document.body.classList.toggle('has-sticky', out);
    }, { threshold: 0 }).observe(document.getElementById('hero'));
  }
})();
</script>
<?php require_once LK_ROOT . '/includes/track_actions.php'; if (function_exists('lx_track_actions')) lx_track_actions(); ?>
<script src="/chat/widget.js?v=sup1010" defer></script>
</body></html>
