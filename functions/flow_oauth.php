<?php
/**
 * Flow glue for Google/X sign-ins: an account without its own password finishes the
 * same steps as an email signup (email code -> login/password + promo -> download).
 */
require_once __DIR__ . '/auth_db.php';
require_once __DIR__ . '/email_codes.php';
require_once __DIR__ . '/welcome_mail.php';

/** X sign-ins get a placeholder address until the user confirms a real one. */
function lx_flow_is_synthetic_email($email) {
    return substr((string)$email, -13) === '@x.leronx.org';
}

/** True while the account still has to choose its own password. */
function lx_flow_row_pending(array $row) {
    return (string)$row['password_hash'] === '' || !empty($row['must_change_password']);
}

/** Session user's row if setup is unfinished, else null. */
function lx_flow_pending_user() {
    if (empty($_SESSION['user_id'])) return null;
    $stmt = lx_auth_db()->prepare('SELECT id, email, password_hash, must_change_password, google_name, twitter_name FROM users WHERE id = ?');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return ($row && lx_flow_row_pending($row)) ? $row : null;
}

/** Google sign-in whose address Google itself reported as verified (set in auth/google/callback.php). */
function lx_flow_google_verified(array $row) {
    $v = (string)($_SESSION['lx_google_verified'] ?? '');
    return $v !== '' && $v === strtolower((string)$row['email']) && !lx_flow_is_synthetic_email($row['email']);
}

/** Where an OAuth callback sends the browser; emails the code when the address is known. */
function lx_flow_after_oauth($provider) {
    $row = lx_flow_pending_user();
    if (!$row) return '/app';   // (dl=1 -> /lk/dl.php branch removed 2026-10-10: that flow was cancelled)
    $_SESSION['lx_flow_provider'] = $provider;
    if ($provider === 'google' && lx_flow_google_verified($row)) {
        return '/flow/verify.html?p=google';   // no emailed code: verify.html finishes via auth_google_finish.php
    }
    if (!lx_flow_is_synthetic_email($row['email'])) {
        $res = lx_issue_email_code($row['email'], 'register');
        if (!empty($res['ok'])) {
            lx_send_verification_code_email($row['email'], $res['code']);
        }
    }
    return '/flow/verify.html?p=' . $provider;
}

/**
 * The code proved the session's X user owns $email: replace the placeholder address.
 * Returns an error message when the address belongs to a different account.
 */
function lx_flow_adopt_email($email) {
    $row = lx_flow_pending_user();
    if (!$row || !lx_flow_is_synthetic_email($row['email'])) return '';
    $pdo = lx_auth_db();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetchColumn()) {
        return 'This email already belongs to another account. Please use a different email.';
    }
    $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$email, $row['id']]);
    $_SESSION['user_email'] = $email;
    return '';
}

/** Credits a promo code would add to this account right now (0 = unknown code or not eligible). */
function lx_flow_promo_eligible(array $row, $promo) {
    $bonus = lx_promo_credits($promo);
    if ($bonus <= 0) return 0;
    // Gated on promo_code only: the free signup bonus means a fresh account is no longer
    // at zero credits, and the promo is additive on top of whatever balance there is. 2026-10-09
    if (!lx_flow_row_pending($row) || (string)$row['promo_code'] !== '') return 0;
    return $bonus;
}
