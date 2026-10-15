<?php
/**
 * Email verification codes (6-digit, short-lived).
 * Used by the registration flow: verify the email belongs to the
 * person before we ever create an account or email credentials to it.
 */
require_once __DIR__ . '/auth_db.php';

if (!defined('LX_CODE_TTL_MIN'))      define('LX_CODE_TTL_MIN', 10);
if (!defined('LX_CODE_RESEND_SEC'))   define('LX_CODE_RESEND_SEC', 60);
if (!defined('LX_CODE_MAX_ATTEMPTS')) define('LX_CODE_MAX_ATTEMPTS', 5);

function lx_ensure_email_codes_table(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_codes (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        email      TEXT NOT NULL,
        purpose    TEXT NOT NULL,
        code_hash  TEXT NOT NULL,
        attempts   INTEGER NOT NULL DEFAULT 0,
        used       INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        expires_at TEXT NOT NULL
    )");
}

/**
 * Issues a new code for $email/$purpose, invalidating any earlier
 * unused code for the same pair. Enforces a resend cooldown so a
 * double-click (or a bot) can't spam the inbox.
 * Returns ['ok'=>true,'code'=>'123456'] or ['ok'=>false,'message'=>...].
 */
function lx_issue_email_code($email, $purpose) {
    $pdo = lx_auth_db();
    lx_ensure_email_codes_table($pdo);
    $email = strtolower(trim($email));

    $stmt = $pdo->prepare("SELECT created_at FROM email_codes WHERE email=? AND purpose=? AND used=0 ORDER BY id DESC LIMIT 1");
    $stmt->execute([$email, $purpose]);
    $last = $stmt->fetchColumn();
    if ($last && (time() - strtotime($last)) < LX_CODE_RESEND_SEC) {
        return ['ok' => false, 'message' => 'A code was already sent. Please check your inbox (or wait a moment before requesting another).', 'cooldown' => true];
    }

    $pdo->prepare("UPDATE email_codes SET used=1 WHERE email=? AND purpose=? AND used=0")->execute([$email, $purpose]);

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $hash = password_hash($code, PASSWORD_BCRYPT);
    $expires = date('c', time() + LX_CODE_TTL_MIN * 60);
    $pdo->prepare("INSERT INTO email_codes (email, purpose, code_hash, created_at, expires_at) VALUES (?,?,?,?,?)")
        ->execute([$email, $purpose, $hash, date('c'), $expires]);

    return ['ok' => true, 'code' => $code];
}

/**
 * Verifies $code for $email/$purpose. Single-use, rate-limited, expires.
 */
function lx_verify_email_code($email, $purpose, $code) {
    $pdo = lx_auth_db();
    lx_ensure_email_codes_table($pdo);
    $email = strtolower(trim($email));
    // keep digits only: Android keyboards / autofill paste "123 456", NBSP, dashes, zero-width chars (2026-10-10)
    $code  = preg_replace('/\D+/', '', (string)$code);
    if (strlen($code) !== 6) {
        return ['ok' => false, 'message' => 'The code is not complete — enter all 6 digits from the email.'];
    }

    $stmt = $pdo->prepare("SELECT * FROM email_codes WHERE email=? AND purpose=? AND used=0 ORDER BY id DESC LIMIT 1");
    $stmt->execute([$email, $purpose]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'message' => 'No active code for this email. Please request a new one.'];
    }

    if (strtotime($row['expires_at']) < time()) {
        $pdo->prepare("UPDATE email_codes SET used=1 WHERE id=?")->execute([$row['id']]);
        return ['ok' => false, 'message' => 'This code has expired. Please request a new one.'];
    }
    if ((int)$row['attempts'] >= LX_CODE_MAX_ATTEMPTS) {
        $pdo->prepare("UPDATE email_codes SET used=1 WHERE id=?")->execute([$row['id']]);
        return ['ok' => false, 'message' => 'Too many incorrect attempts. Please request a new code.'];
    }

    $pdo->prepare("UPDATE email_codes SET attempts = attempts + 1 WHERE id=?")->execute([$row['id']]);

    if (!password_verify($code, $row['code_hash'])) {
        // Tell the user it was the CODE that was wrong, and how many tries are left -- the
        // old flat message read like a form-validation hint. 2026-10-10
        $left = LX_CODE_MAX_ATTEMPTS - ((int)$row['attempts'] + 1);
        if ($left <= 0) {
            $pdo->prepare("UPDATE email_codes SET used=1 WHERE id=?")->execute([$row['id']]);
            return ['ok' => false, 'message' => 'That code is not correct, and there are no attempts left. Please request a new code.'];
        }
        return ['ok' => false, 'message' => 'That code is not correct. Check the 6 digits in the email and try again (' . $left . ' ' . ($left === 1 ? 'attempt' : 'attempts') . ' left).'];
    }

    $pdo->prepare("UPDATE email_codes SET used=1 WHERE id=?")->execute([$row['id']]);
    return ['ok' => true];
}
