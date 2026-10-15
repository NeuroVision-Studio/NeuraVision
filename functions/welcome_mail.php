<?php
/**
 * Email sending functions for Neuravision
 * Uses Resend API for transactional emails
 * Domain: neuracreation.com
 */
require_once __DIR__ . "/../includes/x_ads_config.php"; // loads .env

define('RESEND_API_KEY', getenv('RESEND_API_KEY') ?: (isset($_ENV['RESEND_API_KEY']) ? $_ENV['RESEND_API_KEY'] : ''));
define('FROM_EMAIL', 'Neuravision <hello@neuracreation.com>');
define('FROM_NOREPLY', 'Neuravision <noreply@neuracreation.com>');

/**
 * Send welcome email after registration
 */
function lx_send_welcome_email($toEmail) {
    $subject = "Welcome to Neuravision — Your 250 Free Credits Are Ready";
    
    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background:#0a0a0f;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0a0f;padding:40px 20px;">
        <tr>
            <td align="center">
                <table width="560" cellpadding="0" cellspacing="0" style="background:#13131f;border-radius:16px;border:1px solid rgba(255,255,255,0.06);overflow:hidden;">
                    <!-- Header -->
                    <tr>
                        <td style="padding:32px 40px 24px;text-align:center;border-bottom:1px solid rgba(255,255,255,0.06);">
                            <img src="https://neuracreation.com/assets/logo.png?v=neworange1" width="36" height="36" alt="Neuravision" style="border-radius:8px;">
                            <p style="color:#fff;font-size:18px;font-weight:700;margin:12px 0 0;">Neuravision</p>
                        </td>
                    </tr>
                    <!-- Body -->
                    <tr>
                        <td style="padding:40px;">
                            <h1 style="color:#fff;font-size:22px;font-weight:700;margin:0 0 16px;line-height:1.3;">
                                Welcome aboard! Your creative studio is ready.
                            </h1>
                            <p style="color:rgba(255,255,255,0.7);font-size:15px;line-height:1.6;margin:0 0 24px;">
                                You've just unlocked access to the most powerful AI content creation platform. 
                                Your account comes loaded with <strong style="color:#00ff88;">250 free credits</strong> — 
                                enough for your first videos, images, and posts.
                            </p>
                            
                            <table width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 28px;">
                                <tr>
                                    <td style="background:rgba(0,255,136,0.06);border:1px solid rgba(0,255,136,0.15);border-radius:12px;padding:20px;">
                                        <p style="color:#00ff88;font-size:13px;font-weight:600;margin:0 0 8px;text-transform:uppercase;letter-spacing:0.5px;">What you can do right now:</p>
                                        <p style="color:rgba(255,255,255,0.8);font-size:14px;line-height:1.7;margin:0;">
                                            &#x2022; Generate studio-quality videos from text<br>
                                            &#x2022; Create images, banners & profile pictures<br>
                                            &#x2022; Animate any image into cinematic motion<br>
                                            &#x2022; Auto-publish to X, TikTok, YouTube & Instagram
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- CTA Button -->
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td align="center">
                                        <a href="https://neuracreation.com/app" style="display:inline-block;padding:14px 36px;background:linear-gradient(135deg,#00cc6a,#00e68a,#00b8d4);color:#000;font-size:15px;font-weight:700;text-decoration:none;border-radius:12px;">
                                            Start Creating Now &rarr;
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            
                            <p style="color:rgba(255,255,255,0.4);font-size:13px;line-height:1.6;margin:28px 0 0;text-align:center;">
                                No credit card required. Upgrade anytime for more credits and premium features.
                            </p>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="padding:24px 40px;border-top:1px solid rgba(255,255,255,0.06);text-align:center;">
                            <p style="color:rgba(255,255,255,0.3);font-size:12px;margin:0;line-height:1.6;">
                                Neuravision — AI Content Creation Platform<br>
                                <a href="https://neuracreation.com" style="color:rgba(255,255,255,0.4);text-decoration:none;">neuracreation.com</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

    return resend_send_email($toEmail, $subject, $html, FROM_EMAIL);
}

/**
 * Send phone verification complete email
 */
function lx_send_phone_verified_email($toEmail) {
    $subject = "Phone Verified — Your Neuravision Account is Fully Secured";
    
    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background:#0a0a0f;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0a0f;padding:40px 20px;">
        <tr>
            <td align="center">
                <table width="560" cellpadding="0" cellspacing="0" style="background:#13131f;border-radius:16px;border:1px solid rgba(255,255,255,0.06);overflow:hidden;">
                    <!-- Header -->
                    <tr>
                        <td style="padding:32px 40px 24px;text-align:center;border-bottom:1px solid rgba(255,255,255,0.06);">
                            <img src="https://neuracreation.com/assets/logo.png?v=neworange1" width="36" height="36" alt="Neuravision" style="border-radius:8px;">
                            <p style="color:#fff;font-size:18px;font-weight:700;margin:12px 0 0;">Neuravision</p>
                        </td>
                    </tr>
                    <!-- Body -->
                    <tr>
                        <td style="padding:40px;">
                            <div style="text-align:center;margin-bottom:24px;">
                                <div style="display:inline-block;width:56px;height:56px;background:rgba(0,255,136,0.1);border-radius:50%;line-height:56px;font-size:28px;">&#x2713;</div>
                            </div>
                            <h1 style="color:#fff;font-size:22px;font-weight:700;margin:0 0 16px;line-height:1.3;text-align:center;">
                                Phone Verified Successfully
                            </h1>
                            <p style="color:rgba(255,255,255,0.7);font-size:15px;line-height:1.6;margin:0 0 24px;text-align:center;">
                                Your account is now fully secured with phone verification. 
                                You're all set to start creating incredible content with Neuravision.
                            </p>
                            
                            <table width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 28px;">
                                <tr>
                                    <td style="background:rgba(255,90,26,0.06);border:1px solid rgba(255,90,26,0.15);border-radius:12px;padding:20px;">
                                        <p style="color:rgba(255,255,255,0.6);font-size:13px;line-height:1.7;margin:0;">
                                            <strong style="color:#fff;">Security tip:</strong> Your phone number adds an extra layer of protection to your account. 
                                            If you ever need to recover access, we can verify your identity through SMS.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- CTA Button -->
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td align="center">
                                        <a href="https://neuracreation.com/app" style="display:inline-block;padding:14px 36px;background:linear-gradient(135deg,#00cc6a,#00e68a,#00b8d4);color:#000;font-size:15px;font-weight:700;text-decoration:none;border-radius:12px;">
                                            Go to Dashboard &rarr;
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="padding:24px 40px;border-top:1px solid rgba(255,255,255,0.06);text-align:center;">
                            <p style="color:rgba(255,255,255,0.3);font-size:12px;margin:0;line-height:1.6;">
                                Neuravision — AI Content Creation Platform<br>
                                <a href="https://neuracreation.com" style="color:rgba(255,255,255,0.4);text-decoration:none;">neuracreation.com</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

    return resend_send_email($toEmail, $subject, $html, FROM_NOREPLY);
}

/**
 * Send the 6-digit email verification code used at the start of signup.
 */
function lx_send_verification_code_email($toEmail, $code) {
    $subject = "{$code} is your Neuravision verification code";

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background:#0a0a0f;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0a0f;padding:40px 20px;">
        <tr>
            <td align="center">
                <table width="480" cellpadding="0" cellspacing="0" style="background:#13131f;border-radius:16px;border:1px solid rgba(255,255,255,0.06);overflow:hidden;">
                    <tr>
                        <td style="padding:32px 40px 24px;text-align:center;border-bottom:1px solid rgba(255,255,255,0.06);">
                            <img src="https://neuracreation.com/assets/logo.png?v=neworange1" width="36" height="36" alt="Neuravision" style="border-radius:8px;">
                            <p style="color:#fff;font-size:18px;font-weight:700;margin:12px 0 0;">Neuravision</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:40px;text-align:center;">
                            <h1 style="color:#fff;font-size:20px;font-weight:700;margin:0 0 12px;">Confirm your email</h1>
                            <p style="color:rgba(255,255,255,0.6);font-size:14px;line-height:1.6;margin:0 0 28px;">
                                Enter this code to continue creating your Neuravision account.
                            </p>
                            <div style="display:inline-block;background:rgba(0,255,136,0.06);border:1px solid rgba(0,255,136,0.2);border-radius:12px;padding:18px 32px;margin:0 0 24px;">
                                <span style="color:#00ff88;font-size:32px;font-weight:800;letter-spacing:8px;font-family:monospace;">{$code}</span>
                            </div>
                            <p style="color:rgba(255,255,255,0.35);font-size:12.5px;line-height:1.6;margin:0;">
                                This code expires in 10 minutes. If you didn't request it, you can safely ignore this email.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 40px;border-top:1px solid rgba(255,255,255,0.06);text-align:center;">
                            <p style="color:rgba(255,255,255,0.3);font-size:12px;margin:0;line-height:1.6;">
                                Neuravision — AI Content Creation Platform<br>
                                <a href="https://neuracreation.com" style="color:rgba(255,255,255,0.4);text-decoration:none;">neuracreation.com</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

    return resend_send_email($toEmail, $subject, $html, FROM_NOREPLY);
}

/**
 * Send the 6-digit code used to confirm the inbox on /lk/c.php before the Download button
 * unlocks. Same table/helpers as the signup code (purpose 'lk_verify' keeps it a separate,
 * independent code), just worded around "unlock your download" instead of "create your
 * account" since this user is already signed in. Owner 2026-10-10.
 */
function lx_send_lk_unlock_code_email($toEmail, $code) {
    $subject = "{$code} — confirm your email to unlock your download";

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background:#0a0a0f;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0a0f;padding:40px 20px;">
        <tr>
            <td align="center">
                <table width="480" cellpadding="0" cellspacing="0" style="background:#13131f;border-radius:16px;border:1px solid rgba(255,255,255,0.06);overflow:hidden;">
                    <tr>
                        <td style="padding:32px 40px 24px;text-align:center;border-bottom:1px solid rgba(255,255,255,0.06);">
                            <img src="https://neuracreation.com/assets/logo.png?v=neworange1" width="36" height="36" alt="Neuravision" style="border-radius:8px;">
                            <p style="color:#fff;font-size:18px;font-weight:700;margin:12px 0 0;">Neuravision</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:40px;text-align:center;">
                            <h1 style="color:#fff;font-size:20px;font-weight:700;margin:0 0 12px;">Confirm it's you</h1>
                            <p style="color:rgba(255,255,255,0.6);font-size:14px;line-height:1.6;margin:0 0 28px;">
                                Enter this code on the download page to unlock your Neuravision app.
                            </p>
                            <div style="display:inline-block;background:rgba(0,255,136,0.06);border:1px solid rgba(0,255,136,0.2);border-radius:12px;padding:18px 32px;margin:0 0 24px;">
                                <span style="color:#00ff88;font-size:32px;font-weight:800;letter-spacing:8px;font-family:monospace;">{$code}</span>
                            </div>
                            <p style="color:rgba(255,255,255,0.35);font-size:12.5px;line-height:1.6;margin:0;">
                                This code expires in 10 minutes. If you didn't request it, you can safely ignore this email.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 40px;border-top:1px solid rgba(255,255,255,0.06);text-align:center;">
                            <p style="color:rgba(255,255,255,0.3);font-size:12px;margin:0;line-height:1.6;">
                                Neuravision — AI Content Creation Platform<br>
                                <a href="https://neuracreation.com" style="color:rgba(255,255,255,0.4);text-decoration:none;">neuracreation.com</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

    return resend_send_email($toEmail, $subject, $html, FROM_NOREPLY);
}

/**
 * Send the 6-digit "trial activation" code for the path-3 landing (download without
 * registration). Framed as unlocking the free 1-month Pro trial, not as a login/account
 * check — there is no account at all on this path. Purpose 'p3_trial' keeps it independent
 * from the registration and lk_verify codes. Owner 2026-10-14 (soft anti-bot gate on the
 * direct-download variant: the file download itself is never blocked by this code).
 */
function lx_send_p3_trial_code_email($toEmail, $code) {
    $subject = "{$code} is your Neuravision trial activation code";

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background:#0a0a0f;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0a0f;padding:40px 20px;">
        <tr>
            <td align="center">
                <table width="480" cellpadding="0" cellspacing="0" style="background:#13131f;border-radius:16px;border:1px solid rgba(255,255,255,0.06);overflow:hidden;">
                    <tr>
                        <td style="padding:32px 40px 24px;text-align:center;border-bottom:1px solid rgba(255,255,255,0.06);">
                            <img src="https://neuracreation.com/assets/logo.png?v=neworange1" width="36" height="36" alt="Neuravision" style="border-radius:8px;">
                            <p style="color:#fff;font-size:18px;font-weight:700;margin:12px 0 0;">Neuravision</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:40px;text-align:center;">
                            <h1 style="color:#fff;font-size:20px;font-weight:700;margin:0 0 12px;">Activate your free trial</h1>
                            <p style="color:rgba(255,255,255,0.6);font-size:14px;line-height:1.6;margin:0 0 28px;">
                                Enter this code on the Neuravision page to activate your free 1-month Pro trial.
                            </p>
                            <div style="display:inline-block;background:rgba(0,255,136,0.06);border:1px solid rgba(0,255,136,0.2);border-radius:12px;padding:18px 32px;margin:0 0 24px;">
                                <span style="color:#00ff88;font-size:32px;font-weight:800;letter-spacing:8px;font-family:monospace;">{$code}</span>
                            </div>
                            <p style="color:rgba(255,255,255,0.35);font-size:12.5px;line-height:1.6;margin:0;">
                                This code expires in 10 minutes. If you didn't request it, you can safely ignore this email.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 40px;border-top:1px solid rgba(255,255,255,0.06);text-align:center;">
                            <p style="color:rgba(255,255,255,0.3);font-size:12px;margin:0;line-height:1.6;">
                                Neuravision — AI Content Creation Platform<br>
                                <a href="https://neuracreation.com" style="color:rgba(255,255,255,0.4);text-decoration:none;">neuracreation.com</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

    return resend_send_email($toEmail, $subject, $html, FROM_NOREPLY);
}

/**
 * Send login + temporary password after the email code is confirmed.
 * The user never chose this password themselves, so the email leads
 * with "change it" rather than burying that instruction.
 */
function lx_send_credentials_email($toEmail, $tempPassword, $bonus = 0, $balance = null) {
    $bonus = (int)$bonus;
    // $balance is the account's real credit balance (free signup bonus + any promo). It is
    // passed in by auth_verify_code.php so the email can never promise credits the account
    // does not actually have (e.g. a re-issued temp password on an existing account). 2026-10-09
    $balance = $balance === null ? null : (int)$balance;
    $shown   = $balance !== null ? $balance : $bonus;
    $subject = $shown > 0
        ? "Your Neuravision login is ready \xe2\x80\x94 " . number_format($shown) . " credits inside"
        : "Your Neuravision login is ready";
    if ($shown <= 0) {
        $bonusLine = 'Your account is ready.';
    } elseif ($bonus > 0) {
        $bonusLine = 'Your account is ready, loaded with <strong style="color:#00ff88;">' . number_format($shown)
            . ' credits</strong> \xe2\x80\x94 including ' . number_format($bonus) . ' from your promo code.';
    } else {
        $bonusLine = 'Your account is ready, loaded with <strong style="color:#00ff88;">' . number_format($shown) . ' free credits</strong>.';
    }
    $safeEmail = htmlspecialchars($toEmail, ENT_QUOTES, 'UTF-8');
    $safePass  = htmlspecialchars($tempPassword, ENT_QUOTES, 'UTF-8');

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background:#0a0a0f;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0a0f;padding:40px 20px;">
        <tr>
            <td align="center">
                <table width="560" cellpadding="0" cellspacing="0" style="background:#13131f;border-radius:16px;border:1px solid rgba(255,255,255,0.06);overflow:hidden;">
                    <tr>
                        <td style="padding:32px 40px 24px;text-align:center;border-bottom:1px solid rgba(255,255,255,0.06);">
                            <img src="https://neuracreation.com/assets/logo.png?v=neworange1" width="36" height="36" alt="Neuravision" style="border-radius:8px;">
                            <p style="color:#fff;font-size:18px;font-weight:700;margin:12px 0 0;">Neuravision</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:40px;">
                            <h1 style="color:#fff;font-size:22px;font-weight:700;margin:0 0 16px;line-height:1.3;">
                                Email verified \xe2\x80\x94 here's your login
                            </h1>
                            <p style="color:rgba(255,255,255,0.7);font-size:15px;line-height:1.6;margin:0 0 24px;">
                                {$bonusLine}
                                Enjoy creativity without limits \xe2\x80\x94 here's how to sign in for the first time.
                            </p>

                            <table width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;">
                                <tr>
                                    <td style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.1);border-radius:12px;padding:20px 24px;">
                                        <p style="color:rgba(255,255,255,0.45);font-size:12px;font-weight:600;margin:0 0 4px;text-transform:uppercase;letter-spacing:0.5px;">Email</p>
                                        <p style="color:#fff;font-size:15px;font-family:monospace;margin:0 0 16px;">{$safeEmail}</p>
                                        <p style="color:rgba(255,255,255,0.45);font-size:12px;font-weight:600;margin:0 0 4px;text-transform:uppercase;letter-spacing:0.5px;">Temporary password</p>
                                        <p style="color:#00ff88;font-size:18px;font-family:monospace;font-weight:700;letter-spacing:1px;margin:0;">{$safePass}</p>
                                    </td>
                                </tr>
                            </table>

                            <p style="color:rgba(255,255,255,0.6);font-size:13.5px;line-height:1.6;margin:0 0 28px;">
                                This password is temporary \xe2\x80\x94 it only works until you set a password of your own.
                                <strong style="color:#fff;">If you already chose your own password on the site right after signing up, sign in with that one</strong> \xe2\x80\x94 this temporary one stopped working the moment you saved it.
                            </p>

                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td align="center">
                                        <a href="https://neuracreation.com/register.php?mode=login" style="display:inline-block;padding:14px 36px;background:linear-gradient(135deg,#00cc6a,#00e68a,#00b8d4);color:#000;font-size:15px;font-weight:700;text-decoration:none;border-radius:12px;">
                                            Sign In &amp; Set Your Password &rarr;
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <table width="100%" cellpadding="0" cellspacing="0" style="margin:28px 0 0;">
                                <tr>
                                    <td style="background:rgba(255,173,51,0.06);border:1px solid rgba(255,173,51,0.18);border-radius:12px;padding:16px 20px;">
                                        <p style="color:rgba(255,255,255,0.65);font-size:13px;line-height:1.6;margin:0;">
                                            &#x23F1; This temporary password works for the next <strong style="color:#ffad33;">24 hours</strong>, or until you set your own password on the site \xe2\x80\x94 whichever comes first.
                                            After that it stops working for security \xe2\x80\x94 you'd just need to sign up again with this same email to get a fresh one, no credits are lost either way.
                                            Setting your own password now takes 10 seconds and skips that step entirely.
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <p style="color:rgba(255,255,255,0.5);font-size:13px;line-height:1.6;margin:22px 0 0;text-align:center;">
                                Wishing you a great time creating with Neuravision \xe2\x80\x94 the best AI studio around. &#x1F680;
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 40px;border-top:1px solid rgba(255,255,255,0.06);text-align:center;">
                            <p style="color:rgba(255,255,255,0.3);font-size:12px;margin:0;line-height:1.6;">
                                Neuravision — AI Content Creation Platform<br>
                                <a href="https://neuracreation.com" style="color:rgba(255,255,255,0.4);text-decoration:none;">neuracreation.com</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

    return resend_send_email($toEmail, $subject, $html, FROM_NOREPLY);
}

/**
 * Confirms promo credits added on the setup step. The password is never emailed here.
 */
function lx_send_promo_credits_email($toEmail, $bonus) {
    $n = number_format((int)$bonus);
    $safeEmail = htmlspecialchars($toEmail, ENT_QUOTES, 'UTF-8');
    $subject = $n . " credits added to your Neuravision account";
    $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#0a0a0f;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0a0f;padding:40px 20px;">
        <tr><td align="center">
            <table width="560" cellpadding="0" cellspacing="0" style="background:#13131f;border-radius:16px;border:1px solid rgba(255,255,255,0.06);overflow:hidden;">
                <tr><td style="padding:32px 40px 24px;text-align:center;border-bottom:1px solid rgba(255,255,255,0.06);">
                    <img src="https://neuracreation.com/assets/logo.png?v=neworange1" width="36" height="36" alt="Neuravision" style="border-radius:8px;">
                    <p style="color:#fff;font-size:18px;font-weight:700;margin:12px 0 0;">Neuravision</p>
                </td></tr>
                <tr><td style="padding:40px;">
                    <h1 style="color:#fff;font-size:22px;font-weight:700;margin:0 0 16px;line-height:1.3;">Your promo code is activated</h1>
                    <p style="color:rgba(255,255,255,0.7);font-size:15px;line-height:1.6;margin:0 0 24px;">
                        <strong style="color:#00ff88;">{$n} credits</strong> have been added to your balance.
                    </p>
                    <table width="100%" cellpadding="0" cellspacing="0"><tr>
                        <td style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.1);border-radius:12px;padding:20px 24px;">
                            <p style="color:rgba(255,255,255,0.45);font-size:12px;font-weight:600;margin:0 0 4px;text-transform:uppercase;letter-spacing:0.5px;">Login</p>
                            <p style="color:#fff;font-size:15px;font-family:monospace;margin:0 0 16px;">{$safeEmail}</p>
                            <p style="color:rgba(255,255,255,0.45);font-size:12px;font-weight:600;margin:0 0 4px;text-transform:uppercase;letter-spacing:0.5px;">Password</p>
                            <p style="color:rgba(255,255,255,0.7);font-size:14px;margin:0;">The one you just set. Use it to sign in to the app.</p>
                        </td>
                    </tr></table>
                </td></tr>
                <tr><td style="padding:24px 40px;border-top:1px solid rgba(255,255,255,0.06);text-align:center;">
                    <p style="color:rgba(255,255,255,0.3);font-size:12px;margin:0;line-height:1.6;">
                        Neuravision — AI Content Creation Platform<br>
                        <a href="https://neuracreation.com" style="color:rgba(255,255,255,0.4);text-decoration:none;">neuracreation.com</a>
                    </p>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
HTML;

    return resend_send_email($toEmail, $subject, $html, FROM_NOREPLY);
}

/**
 * Core function to send email via Resend API
 */
function resend_send_email($to, $subject, $html, $from = null) {
    /* nv_i18n: e-mails go out in the visitor's language (cookie lx-language / Accept-Language of the request that triggered them) */
    if (!defined('NV_I18N_LOADED')) { if (!defined('NV_I18N_NO_AUTOSTART')) define('NV_I18N_NO_AUTOSTART', 1); require_once __DIR__ . '/../includes/nv_i18n/engine/nv_i18n.php'; }
    if (function_exists('nv_i18n_lang') && nv_i18n_lang() !== 'en') { $subject = nv_t($subject); $html = nv_i18n_translate_html($html, nv_i18n_lang()); }
    if ($from === null) $from = FROM_EMAIL;
    
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'from' => $from,
            'to' => [$to],
            'subject' => $subject,
            'html' => $html
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . RESEND_API_KEY
        ],
        CURLOPT_TIMEOUT => 15
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $data = json_decode($response, true);
    
    if ($httpCode === 200) {
        return ['ok' => true, 'id' => $data['id'] ?? null];
    }
    
    return ['ok' => false, 'error' => $data['message'] ?? 'Email send failed', 'code' => $httpCode];
}
