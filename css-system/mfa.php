<?php
// >>> PLACE AT: css-system/mfa.php (new file)  [batch 12]
/**
 * Second login step.
 *  - Account has two-step login on  -> asks for the 6-digit code (or a recovery code).
 *  - Required role without it yet   -> forced set-up (secret + confirm one code + recovery codes).
 *  - ?setup=1 for anyone logged in  -> voluntary set-up.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/rate_limit.php';
requireLogin();   // lets this page and logout.php through while the second step is pending

$pdo     = getDB();
$uid     = (int)$_SESSION['user_id'];
$u       = mfaUser($uid);
$enabled = $u !== null && !empty($u['totp_enabled']);
$pending = mfaPending();
$volunt  = !$pending && !$enabled && isset($_GET['setup']);
if (!$pending && !$volunt) {
    redirect('dashboard.php');
}

$setupError = $u === null ? 'Two-step login is not set up in the database yet. Import sql/mfa_privacy.sql, then reload.' : null;
$errors = [];
$codes  = null;
$action = $volunt ? 'mfa.php?setup=1' : 'mfa.php';

if (!$setupError && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $code = (isset($_POST['code']) && is_string($_POST['code'])) ? trim($_POST['code']) : '';

    if (!rateLimitAllow('css_mfa', 20, 900)) {
        $errors[] = 'Too many attempts from this network. Wait a few minutes.';
    } elseif ($enabled) {
        /* ---- verify ---- */
        $ok   = false;
        $step = totpVerify(decField((string)$u['totp_secret_enc']), $code, (int)$u['totp_last_step']);
        if ($step !== null) {
            $pdo->prepare('UPDATE users SET totp_last_step = ? WHERE id = ?')->execute([$step, $uid]);
            $ok = true;
        } elseif (mfaUseRecovery($uid, $code, $u)) {
            logAudit($uid, 'MFA_RECOVERY', 'users', $uid, 'Signed in with a recovery code');
            $ok = true;
        }
        if ($ok) {
            session_regenerate_id(true);
            $_SESSION['mfa_ok']   = 1;
            $_SESSION['mfa_fails'] = 0;
            logAudit($uid, 'MFA_PASSED', 'users', $uid, 'Second step passed');
            redirect('dashboard.php');
        }
        $_SESSION['mfa_fails'] = (int)($_SESSION['mfa_fails'] ?? 0) + 1;
        logAudit($uid, 'MFA_FAILED', 'users', $uid, 'Wrong two-step code (' . $_SESSION['mfa_fails'] . '/' . MFA_MAX_FAILS . ')');
        if ($_SESSION['mfa_fails'] >= MFA_MAX_FAILS) {
            $pdo->prepare('UPDATE users SET locked_until = ? WHERE id = ?')->execute([date('Y-m-d H:i:s', time() + LOCKOUT_MINUTES * 60), $uid]);
            doLogout();
            header('Location: login.php');
            exit;
        }
        $errors[] = 'That code is not correct. Check the code in your authenticator app.';
    } else {
        /* ---- enroll: confirm one code from the new secret ---- */
        $secret = (string)($_SESSION['mfa_new_secret'] ?? '');
        $step   = $secret !== '' ? totpVerify($secret, $code, 0) : null;
        if ($step === null) {
            $errors[] = 'That code is not correct. Make sure the account was added to your app and your phone clock is automatic.';
        } else {
            [$codes, $hashes] = mfaNewRecoveryCodes();
            $pdo->prepare('UPDATE users SET totp_secret_enc = ?, totp_enabled = 1, totp_last_step = ?, mfa_recovery = ? WHERE id = ?')
                ->execute([encField($secret), $step, $hashes, $uid]);
            unset($_SESSION['mfa_new_secret']);
            session_regenerate_id(true);
            $_SESSION['mfa_ok']      = 1;
            $_SESSION['mfa_enabled'] = 1;
            logAudit($uid, 'MFA_ENABLED', 'users', $uid, 'Two-step login turned on');
        }
    }
}

if (!$enabled && !$setupError && $codes === null && empty($_SESSION['mfa_new_secret'])) {
    $_SESSION['mfa_new_secret'] = totpGenerateSecret();
}
$secret = (string)($_SESSION['mfa_new_secret'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Two-step login | Class Scheduling System</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-body">
<main class="auth-panel" style="min-height:100vh">
<div class="auth-card" style="max-width:460px">

<?php if ($setupError): ?>
    <h2>Two-step login</h2>
    <div class="alert alert-error"><?= e($setupError) ?></div>

<?php elseif ($codes !== null): ?>
    <h2>Two-step login is on</h2>
    <p class="auth-sub">Save these recovery codes now. Each works once if you lose your phone. They are <strong>not shown again</strong>.</p>
    <div class="card" style="box-shadow:none;border:1px solid var(--line);font-family:Consolas,'Courier New',monospace;font-size:1.1rem;line-height:1.9;text-align:center">
        <?php foreach ($codes as $c): ?><div><?= e($c) ?></div><?php endforeach; ?>
    </div>
    <a class="btn btn-gold btn-block" href="dashboard.php">I saved them, continue</a>

<?php elseif ($enabled): ?>
    <h2>Enter your code</h2>
    <p class="auth-sub">Open your authenticator app and type the 6-digit code for the Class Scheduling System. Lost your phone? Type one of your recovery codes instead.</p>
    <?php if ($errors): ?><div class="alert alert-error" role="alert"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
    <form method="POST" action="<?= e($action) ?>" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <div class="field"><label for="code">6-digit code or recovery code</label>
            <input type="text" id="code" name="code" required autofocus inputmode="numeric" autocomplete="one-time-code" maxlength="12"></div>
        <button class="btn btn-gold btn-block" type="submit">Verify</button>
    </form>

<?php else: ?>
    <h2>Set up two-step login</h2>
    <p class="auth-sub"><?= $pending ? 'Your role must use two-step login before you can continue.' : 'Add a second step to protect your account.' ?></p>
    <ol style="padding-left:20px;line-height:1.7">
        <li>Install an authenticator app (Google Authenticator, Microsoft Authenticator or Authy).</li>
        <li>Add an account with <strong>Enter a setup key</strong> and type this key (or open the link on your phone):
            <div style="font-family:Consolas,'Courier New',monospace;font-size:1.15rem;font-weight:700;letter-spacing:.08em;margin:8px 0;word-break:break-all"><?= e(trim(chunk_split($secret, 4, ' '))) ?></div>
            <a href="<?= e(totpUri($secret, (string)($_SESSION['username'] ?? 'user'), MFA_ISSUER)) ?>">Open in authenticator app</a>
        </li>
        <li>Type the 6-digit code it shows:</li>
    </ol>
    <?php if ($errors): ?><div class="alert alert-error" role="alert"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
    <form method="POST" action="<?= e($action) ?>" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <div class="field"><label for="code">6-digit code</label>
            <input type="text" id="code" name="code" required inputmode="numeric" autocomplete="one-time-code" maxlength="7"></div>
        <button class="btn btn-gold btn-block" type="submit">Turn on two-step login</button>
    </form>
<?php endif; ?>

<?php if ($pending): ?>
    <form method="POST" action="logout.php" style="margin-top:12px">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <button class="btn btn-secondary btn-sm btn-block" type="submit">Log out</button>
    </form>
<?php elseif ($volunt && $codes === null): ?>
    <p class="hint" style="margin-top:12px"><a href="dashboard.php">Cancel</a></p>
<?php endif; ?>

</div>
</main>
</body>
</html>
