<?php
/**
 * /lk/lk_install_steps.php — shared post-"Download" install guide (Windows + macOS).
 * Markup is plain English so the nv_i18n engine localises it server-side on the LK pages
 * and client-side on flow/download.html. Behaviour + styles live in /lk/assets/lk-install.{js,css}.
 * The guide is hidden until a Download control is clicked (see lk-install.js).
 */
function lk_install_assets() {
    return '<link rel="stylesheet" href="/lk/assets/lk-install.css?v=inst1011">' . "\n"
         . '<script defer src="/lk/assets/lk-install.js?v=inst1011"></script>' . "\n";
}
function lk_install_steps_html($opts = []) {
    $html = <<<'HTML'
<div class="nvins" id="nvins" aria-hidden="true" data-os="win">
  <div class="nvins-head"><span class="nvins-dot" aria-hidden="true"></span><h3>Your download has started — here's how to install</h3></div>
  <p class="nvins-sub">Follow the steps for your system. It takes about a minute.</p>
  <div class="nvins-tabs" role="tablist" aria-label="Choose your system">
    <button type="button" class="nvins-tab on" data-nvins-tab="win" role="tab" aria-selected="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/></svg>Windows</button>
    <button type="button" class="nvins-tab" data-nvins-tab="mac" role="tab" aria-selected="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16.5 3c-1.5.1-3.2 1-4.1 2.3M12 7.5c-1-.6-2.4-.9-3.6-.6-2.6.6-4.4 3.2-4.2 6.3.2 3.4 2.5 7.8 5 7.8 1.2 0 1.7-.8 3.2-.8s2 .8 3.2.8c2.4 0 4.7-4.4 4.8-6.2-2.1-.9-3.2-2.8-3-5-1.9-1.9-4-2.3-5.4-2.3Z"/></svg>macOS</button>
  </div>
  <div class="nvins-pane on" data-nvins-pane="win" role="tabpanel">
    <ol class="nvins-steps">
      <li><b>Open the downloaded file</b><p>Open <b>NeuroVision.exe</b> from your Downloads folder (or click it in the browser's download bar).</p></li>
      <li><b>If Windows shows "Windows protected your PC"</b><p>Click <span class="nvins-key">More info</span>, then <span class="nvins-key">Run anyway</span>. This notice is normal for a newly released app and is safe to continue.</p></li>
      <li><b>Install the app</b><p>Follow the installer prompts and wait for it to finish — it takes about a minute.</p></li>
      <li><b>Sign in</b><p>Open Neuravision and sign in with the email and password you set.</p></li>
    </ol>
  </div>
  <div class="nvins-pane" data-nvins-pane="mac" role="tabpanel">
    <ol class="nvins-steps">
      <li><b>Open the .dmg file</b><p>Open <b>NeuraVision-Studio.dmg</b> from your Downloads folder.</p></li>
      <li><b>Drag the app into Applications</b><p>In the window that opens, drag the Neuravision icon onto the Applications folder.</p></li>
      <li><b>On first launch, if macOS says "cannot be opened"</b><p>Open <span class="nvins-key">System Settings</span> → <span class="nvins-key">Privacy &amp; Security</span>, then click <span class="nvins-key">Open Anyway</span> (or right-click the app and choose <span class="nvins-key">Open</span>). This is normal for a newly released app.</p></li>
      <li><b>Sign in</b><p>Open Neuravision and sign in with the email and password you set.</p></li>
    </ol>
  </div>
  <p class="nvins-foot">Download didn't start? <a href="#" data-nvins-retry>Download again</a>. Need help? Write to <a href="mailto:support@neuracreation.com">support@neuracreation.com</a>.</p>
</div>
HTML;
    if (!empty($opts['p3'])) {
        // landing path 3 (download without registration, 2026-10-10): there is no account yet, so the
        // last step is "start creating" instead of "sign in with the email and password you set".
        $signin = '<li><b>Sign in</b><p>Open Neuravision and sign in with the email and password you set.</p></li>';
        $parts = explode($signin, $html);
        if (count($parts) === 3) {
            $html = $parts[0] . '<li><b>Start creating</b><p>Open Neuravision from the Start menu or the desktop shortcut and start creating.</p></li>'
                  . $parts[1] . '<li><b>Start creating</b><p>Open Neuravision from your Applications folder and start creating.</p></li>' . $parts[2];
        }
    }
    return $html;
}
