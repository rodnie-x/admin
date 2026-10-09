<?php
// >>> PLACE AT: css-system/change_password.php (new file)  [batch 10]
/**
 * Choose your own password. Accounts with a temporary password (the default admin account, or any account
 * flagged must_change_password) are sent here after logging in and cannot open anything else until done.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();   // lets this page and logout.php through even while a change is required

$pdo    = getDB();
$forced = mustChangePassword();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $cur  = (isset($_POST['current_password'])  && is_string($_POST['current_password']))  ? $_POST['current_password']  : '';
    $new  = (isset($_POST['new_password'])      && is_string($_POST['new_password']))      ? $_POST['new_password']      : '';
    $conf = (isset($_POST['confirm_password'])  && is_string($_POST['confirm_password']))  ? $_POST['confirm_password']  : '';

    $st = $pdo->prepare('SELECT username, password_hash FROM users WHERE id = ?');
    $st->execute([(int)$_SESSION['user_id']]);
    $u = $st->fetch();

    // Someone at an unlocked computer must not be able to guess the current password forever
    $tries = array_filter($_SESSION['pw_change_fails'] ?? [], fn($t) => time() - $t < 900);
    if (count($tries) >= 5) {
        $errors[] = 'Too many wrong attempts. Try again in a few minutes.';
    } elseif (!$u || !password_verify($cur, (string)$u['password_hash'])) {
        $tries[] = time();
        $_SESSION['pw_change_fails'] = array_values($tries);
        $errors[] = 'Your current password is not correct.';
    } else {
        if (strlen($new) < 10 || strlen($new) > 72 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
            $errors[] = 'The new password needs 10 to 72 characters, with at least one letter and one number.';
        }
        if ($new !== $conf) {
            $errors[] = 'The two new passwords do not match.';
        }
        if (hash_equals($cur, $new) || strcasecmp($new, 'Admin@123') === 0) {
            $errors[] = 'Choose a password different from the current one and from the default.';
        }
        if (stripos($new, (string)$u['username']) !== false) {
            $errors[] = 'The password must not contain your username.';
        }
    }

    if (!$errors) {
        try {
            $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 0, failed_attempts = 0, locked_until = NULL WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), (int)$_SESSION['user_id']]);
        } catch (PDOException $ex) {
            // The column is missing (sql/admin_must_change.sql was not run yet): still save the password
            $pdo->prepare('UPDATE users SET password_hash = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), (int)$_SESSION['user_id']]);
        }
        logAudit((int)$_SESSION['user_id'], 'UPDATE', 'users', (int)$_SESSION['user_id'], 'Password changed by the user');
        session_regenerate_id(true);
        unset($_SESSION['pw_change_fails'], $_SESSION['must_change']);
        flash('success', 'Your new password is saved. Only you know it now.');
        redirect('dashboard.php');
    }
}

$pageTitle = 'Change password';
include __DIR__ . '/includes/header.php';
?>
<h1>🔑 Choose your password</h1>
<p><?= $forced
    ? 'You are using a temporary password. Choose your own now: you cannot use the system until you do.'
    : 'Change your password.' ?></p>

<div class="card" style="max-width:520px">
    <?php if ($errors): ?>
        <div class="alert alert-error" role="alert"><ul style="margin:0;padding-left:18px"><?php foreach ($errors as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <form method="POST" action="change_password.php" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <div class="field"><label for="cp">Current (temporary) password</label><input type="password" id="cp" name="current_password" required autofocus></div>
        <div class="field"><label for="np">New password</label><input type="password" id="np" name="new_password" required minlength="10" maxlength="72"></div>
        <div class="field"><label for="cf">Confirm new password</label><input type="password" id="cf" name="confirm_password" required minlength="10" maxlength="72"></div>
        <p class="hint">At least 10 characters, with a letter and a number. Not your username, not the default password.</p>
        <button class="btn btn-gold" type="submit">Save password</button>
    </form>
    <?php if ($forced): ?>
    <form method="POST" action="logout.php" style="margin-top:12px">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <button class="btn btn-secondary btn-sm" type="submit">Log out instead</button>
    </form>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>