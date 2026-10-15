<?php
/**
 * /lk/lk_dlblock.php — the shared, REAL download + promo + email-link markup used by
 * both cabinet variants. One definition, two skins (the CSS in assets/lk-common.css is
 * theme-neutral). Nothing simulated: every control hits a production endpoint.
 *   Download      -> /api/desktop_download.php?os=windows|macos
 *   Readiness     -> /api/desktop_status.php (live, via assets/lk-common.js)
 *   Email a link  -> /api/desktop_email_link.php  (mobile-first alternative)
 *   Promo code    -> /api/promo_redeem.php
 */
require_once __DIR__ . '/lk_core.php';

/**
 * Honest "what your balance buys you" line, derived from the site's own published rate
 * ($20 per 1,000 credits ≈ 11–20 videos or ≈ 200 images, /pricing + lp/v13). Never a flat
 * claim: a range, and it scales with the real balance instead of a hardcoded number.
 */
function lk_credit_hint($credits) {
    $credits = max(0, (int)$credits);
    if ($credits < 50) { return 'top up to start rendering'; }
    $vLow  = (int)floor($credits * 11 / 1000);
    $vHigh = (int)floor($credits * 20 / 1000);
    $imgs  = (int)round($credits / 5);
    if ($vHigh < 1) { return '≈ ' . $imgs . ' images'; }
    $vid = ($vLow >= 1 && $vLow !== $vHigh) ? ($vLow . '–' . $vHigh) : (string)max(1, $vHigh);
    return '≈ ' . $vid . ' videos or ' . $imgs . ' images';
}

function lk_os_icon($os) {
    if ($os === 'macos') {
        return '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16.5 3c-1.5.1-3.2 1-4.1 2.3M12 7.5c-1-.6-2.4-.9-3.6-.6-2.6.6-4.4 3.2-4.2 6.3.2 3.4 2.5 7.8 5 7.8 1.2 0 1.7-.8 3.2-.8s2 .8 3.2.8c2.4 0 4.7-4.4 4.8-6.2-2.1-.9-3.2-2.8-3-5-1.9-1.9-4-2.3-5.4-2.3Z"/></svg>';
    }
    return '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/></svg>';
}

/** macOS / Windows switcher. Every Download link on the page follows it (lk-common.js). */
function lk_os_switch($ctx) {
    $out = '<div class="lkdl-os" role="group" aria-label="Choose your operating system">';
    foreach ($ctx['osOrder'] as $os) {
        $label = $os === 'macos' ? 'macOS' : 'Windows';
        $on = $os === $ctx['os'] ? ' on' : '';
        $dis = $ctx['rel'][$os]['available'] ? '' : ' disabled';
        $out .= '<button type="button" class="lkdl-osbtn' . $on . '" data-lk-ospick="' . $os . '"'
              . ' aria-pressed="' . ($on ? 'true' : 'false') . '"' . $dis . '>'
              . lk_os_icon($os) . lk_e($label) . '</button>';
    }
    return $out . '</div>';
}

/** Primary Download control + the honest micro-copy under it. */
function lk_download_cta($ctx, $label = 'Download') {
    $os = $ctx['os'];
    $ready = $ctx['rel'][$os]['available'];
    $h = '<div class="lkdl-main"><a class="lkdl-cta' . ($ready ? '' : ' is-off') . '" data-lk-dl data-lk-dltext="' . lk_e($label) . '"'
       . ' href="/api/desktop_download.php?os=' . $os . '">'
       . '<svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12M6 11l6 6 6-6M4 21h16"/></svg>'
       . lk_e($ready ? $label : 'Build is being signed') . '</a></div>';
    $h .= '<p class="lkdl-micro">Free &middot; <b>' . lk_e($ctx['creditsFmt']) . ' credits</b> on this account &middot; '
        . 'sign in to the app with the same email and password.</p>';
    return $h;
}

/** Mobile-first alternative: have the installer link emailed. Real send, real honeypot. */
function lk_email_link_form($ctx, $primary = false) {
    $h = '<div class="lkdl-card" data-lk-maillink-wrap>';
    $h .= '<h4>' . ($primary ? 'Send me the link by email' : 'Not at your computer? Email yourself the link') . '</h4>';
    $h .= '<p class="lkdl-hint">Neuravision Desktop runs on Windows and macOS. We will email the installer link so you can open it on your computer.</p>';
    $h .= '<form data-lk-maillink novalidate>';
    $h .= '<div class="lkdl-row">';
    $h .= '<input class="lkdl-inp" type="email" name="email" autocomplete="email" inputmode="email" placeholder="you@example.com" value="' . lk_e($ctx['email']) . '" aria-label="Your email">';
    $h .= '<select class="lkdl-inp" name="os" data-lk-ossel aria-label="Operating system">';
    foreach ($ctx['osOrder'] as $os) {
        if (!$ctx['rel'][$os]['available']) continue;
        $h .= '<option value="' . $os . '"' . ($os === $ctx['os'] ? ' selected' : '') . '>' . ($os === 'macos' ? 'macOS (.dmg)' : 'Windows (.exe)') . '</option>';
    }
    $h .= '</select>';
    $h .= '<button class="lkdl-go" type="submit">Send the link</button>';
    $h .= '</div>';
    // honeypot: real field, hidden from humans, must stay empty
    $h .= '<input class="lkdl-hp" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" value="">';
    $h .= '<p class="lkdl-msg" role="status"></p>';
    $h .= '</form></div>';
    return $h;
}

/** Promo-code field. Posts to the production redeem endpoint. */
function lk_promo_form($ctx) {
    $h = '<div class="lkdl-card">';
    $h .= '<h4>Have a promo code?</h4>';
    $h .= '<p class="lkdl-hint">Promo code <b>' . lk_e($ctx['promo']) . '</b> &mdash; get ' . number_format($ctx['promoCredits']) . ' credits. '
        . 'Every new account also starts with ' . number_format($ctx['bonus']) . ' free credits.</p>';
    $h .= '<form data-lk-promo novalidate>';
    $h .= '<div class="lkdl-row">';
    $h .= '<input class="lkdl-inp" type="text" name="promo" autocapitalize="characters" autocomplete="off" spellcheck="false" placeholder="' . lk_e($ctx['promo']) . '" aria-label="Promo code">';
    $h .= '<button class="lkdl-go" type="submit">Apply code</button>';
    $h .= '</div>';
    $h .= '<p class="lkdl-msg" role="status"></p>';
    $h .= '</form></div>';
    return $h;
}

/**
 * The whole block. On a phone/tablet the email form is the primary control and the direct
 * installer link drops to a small secondary line; on desktop it is the usual OS switch + button.
 */
function lk_dl_block($ctx, $label = 'Download') {
    // Owner 2026-10-10: no "email yourself the link" and no promo field in ANY cabinet (promo is applied at sign-up).
    return lk_dl_block_pure($ctx, $label);
}

/**
 * Cabinet C ("pure download" variant) -- lk_dl_block() below stays the A/B block, unchanged.
 * Owner 2026-10-10 07:30: NO "email yourself the link" block and NO promo-code field here. Desktop = OS
 * switch + Download (+ the install guide after the click, rendered by c.php). Phone = one line, no forms.
 * lk_email_link_form() / lk_promo_form() stay defined for any other caller.
 */
function lk_dl_block_pure($ctx, $label = 'Download') {
    $h = '<div class="lkdl">';
    if ($ctx['mobile']) {
        $h .= '<p class="lkdl-phone">Neuravision is an app for Windows and macOS — open this page on your computer.</p>';
        return $h . '</div>';
    }
    if (!$ctx['anyBuild']) {
        $h .= '<p class="lkdl-micro">Windows and macOS installers are being code-signed right now. '
            . 'Nothing to download yet &mdash; this block turns into a real button the moment a build is published.</p>';
        return $h . '</div>';
    }
    $h .= lk_os_switch($ctx);
    $h .= lk_download_cta($ctx, $label);
    $h .= '</div>';
    return $h;
}

/** <head> assets + the JS context. Call once per page. */
function lk_dl_head($ctx) {
    $cfg = [
        'os' => $ctx['os'],
        'variant' => $ctx['variant'],
        'avail' => ['windows' => (bool)$ctx['rel']['windows']['available'], 'macos' => (bool)$ctx['rel']['macos']['available']],
        'mobile' => (bool)$ctx['mobile'],
        'email' => $ctx['email'],
        'credits' => (int)$ctx['credits'],
        'creditsFmt' => $ctx['creditsFmt'],
        'bonus' => (int)$ctx['bonus'],
        'promo' => $ctx['promo'],
        'promoCredits' => (int)$ctx['promoCredits'],
        'token' => (string)($ctx['user']['token'] ?? ''),
        'name' => $ctx['name'],
        'dl1' => !empty($ctx['dl1']),
        'dlAuto' => !empty($ctx['dlAuto']),   // dl=1 ad visit: lk-common.js clicks Download once
    ];
    return '<link rel="stylesheet" href="/lk/assets/lk-common.css">' . "\n"
         . '<script>window.LK_CTX=' . json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';</script>' . "\n";
}
