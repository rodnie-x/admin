<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/password_policy.php';
$stu = currentStudent();
if (!$stu) { redirect('login.php'); }

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $cur = str($_POST, 'current_password'); $new = str($_POST, 'new_password');
    if (!password_verify($cur, $stu['password_hash'])) { $errors[] = 'Your current password is not correct.'; }
    foreach (passwordProblems($new, [$stu['student_no'] ?? '', $stu['first_name'] ?? '', $stu['last_name'] ?? '']) as $m) { $errors[] = $m; }
    if ($new !== str($_POST, 'confirm_password')) { $errors[] = 'The two new passwords do not match.'; }
    if (!$errors && password_verify($new, $stu['password_hash'])) { $errors[] = 'Choose a password different from the one the Registrar gave you.'; }
    if (!$errors) {
        try {
            getDB()->prepare('UPDATE stu_students SET password_hash = ?, must_change_password = 0, session_epoch=session_epoch+1 WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $stu['id']]);
        } catch (PDOException $ex) {
            getDB()->prepare('UPDATE stu_students SET password_hash = ?, must_change_password = 0 WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $stu['id']]);
        }
        try {
            $st = getDB()->prepare('SELECT session_epoch FROM stu_students WHERE id=?');
            $st->execute([$stu['id']]);
            $_SESSION['stu_epoch'] = (int)$st->fetchColumn();
        } catch (PDOException $ex) { /* column missing */ }
        session_regenerate_id(true);
        flash('success', 'Your new password is saved. Only you know it now.');
        redirect('dashboard.php');
    }
}
$forced = !empty($stu['must_change_password']);
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Change password | <?= e(APP_NAME) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css">
</head><body class="auth-body">
<main class="auth-card">
  <span class="brand-mark brand-mark-lg">BCP</span>
  <h1>Choose your password</h1>
  <p class="muted"><?= $forced ? 'The Registrar gave you a temporary password. Replace it with your own so that only you can log in.' : 'Change your password.' ?></p>
  <?php if ($errors): ?><div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <form method="POST" action="change_password.php" autocomplete="off">
    <?= csrfField() ?>
    <label for="cp">Current (temporary) password</label><input type="password" id="cp" name="current_password" required>
    <label for="np">New password</label><input type="password" id="np" name="new_password" required minlength="10" maxlength="72">
    <label for="cf">Confirm new password</label><input type="password" id="cf" name="confirm_password" required minlength="10" maxlength="72">
    <p class="hint">At least 10 characters, with a letter and a number.</p>
    <button class="btn btn-block" type="submit">Save password</button>
  </form>
  <form method="POST" action="logout.php" style="margin-top:6px"><?= csrfField() ?><button class="btn btn-block btn-ghost" type="submit">Log out</button></form>
</main>
</body></html>
