<?php
// >>> PLACE AT: student-portal/login.php (replace the old file)  [batch 6: sign-in log + per-IP limit]
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/rate_limit.php';
require_once __DIR__ . '/includes/login_log.php';
if (currentStudent()) { redirect('dashboard.php'); }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $no = trim(str($_POST, 'student_no')); $pw = str($_POST, 'password');

    if (!rateLimitAllow('stu_login', 30, 900)) {
        $error = 'Too many log-in attempts from this network. Try again in a few minutes.';
    } else {
        $pdo = getDB();
        $st = $pdo->prepare('SELECT * FROM stu_students WHERE student_no = ?');
        $st->execute([$no]);
        $s = $st->fetch();

        if ($s && $s['locked_until'] && strtotime($s['locked_until']) > time()) {
            logStudentSignIn('failed', (int)$s['id'], $no);
            $error = 'Too many failed attempts. Try again in a few minutes.';
        } elseif ($s && password_verify($pw, $s['password_hash'])) {
            session_regenerate_id(true);                       // fixation protection
            unset($_SESSION['csrf']);                          // fresh CSRF token for the logged-in session
            $_SESSION['last_activity'] = time();
            $_SESSION['stu_epoch'] = (int)($s['session_epoch'] ?? 0);
            $_SESSION['student_id'] = (int)$s['id'];
            $pdo->prepare('UPDATE stu_students SET failed_attempts = 0, locked_until = NULL WHERE id = ?')->execute([$s['id']]);
            logStudentSignIn('login', (int)$s['id'], $no);
            redirect('dashboard.php');
        } else {
            if (!$s) {
                password_verify($pw, password_hash('timing-equaliser', PASSWORD_DEFAULT));   // same time as a real check
            }
            if ($s) {
                $fails = (int)$s['failed_attempts'] + 1;
                $lock  = $fails >= MAX_FAILS ? date('Y-m-d H:i:s', time() + LOCK_MINUTES * 60) : null;
                $pdo->prepare('UPDATE stu_students SET failed_attempts = ?, locked_until = ? WHERE id = ?')->execute([$lock ? 0 : $fails, $lock, $s['id']]);
            }
            logStudentSignIn('failed', $s ? (int)$s['id'] : null, $no);
            $error = 'Incorrect student number or password.';
        }
    }
}
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Log in | <?= e(APP_NAME) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css">
</head>
<style>
/* Login: the campus photo is the whole page; the login form floats on it as frosted glass */
body.auth-body{
  background:
    linear-gradient(180deg, rgba(10,28,100,.62) 0%, rgba(10,28,100,.84) 100%),
    url("/css-system/assets/images/login-bg.png") 62% center / cover no-repeat fixed,
    #1f3a9d;
  margin: 0;
  font-family: 'Figtree', 'Inter', sans-serif;
}
.auth{display:grid;grid-template-columns:1.1fr 1fr;min-height:100vh}
.auth-brand{background:var(--blue);color:#fff;display:flex;align-items:center;padding:48px}
.auth-brand-inner{max-width:440px}.auth-brand h1{color:#fff;font-size:2.3rem;margin-top:20px;margin-bottom:10px;}
.auth-lede{color:#cfd9ff;font-size:1.05rem}
.auth-school{color:var(--blue-tint);font-size:.85rem; margin-top:20px;}
.auth-panel{display:grid;place-items:center;padding:32px;background:transparent}
.auth-brand{background:transparent}
.auth-brand-inner{position:relative;z-index:1;text-shadow:0 2px 14px rgba(0,10,60,.45)}
.auth-brand .brand-mark{text-shadow:none; font-size: 1.5rem; font-weight: 800; background: #fff; color: #1f3a9d; padding: 4px 10px; border-radius: 6px;}

.auth-card{
  background:rgba(255,255,255,.12) !important;
  border:1px solid rgba(255,255,255,.30) !important;
  -webkit-backdrop-filter:blur(16px) saturate(1.2);
          backdrop-filter:blur(16px) saturate(1.2);
  box-shadow:0 20px 60px rgba(0,10,60,.35) !important;
  color:#fff !important;
  border-radius:16px;padding:34px;width:100%;max-width:400px;
}
.auth-card h1,.auth-card h2{font-size:1.5rem;font-weight:800; margin-bottom: 5px;color:#fff !important;}
.auth-card label{color:#fff !important;display: block; margin-bottom: 5px; font-weight: 500; font-size: 0.9rem;}
.auth-card .field { margin-bottom: 15px; }
.auth-card .auth-sub,.auth-card .hint,.auth-card .muted{color:rgba(255,255,255,.85) !important;}
.auth-card input[type=text],.auth-card input[type=email],.auth-card input[type=password]{
  background:rgba(255,255,255,.14) !important;border:1px solid rgba(255,255,255,.42) !important;color:#fff !important;
  width: 100%; padding: 10px; border-radius: 6px; box-sizing: border-box;}
.auth-card input:focus{background:rgba(255,255,255,.22) !important;border-color:#fff !important;outline:3px solid rgba(255,255,255,.28) !important;}
.auth-card .alert-error{background:rgba(255,240,240,.96) !important;color:#8e1c16 !important;border-color:#d64545 !important; padding: 10px; border-radius: 6px; margin-bottom: 15px;}
.auth-card a{color:#ffd54d !important;}
.btn-block { display: block; width: 100%; padding: 12px; border: none; border-radius: 6px; cursor: pointer; text-align: center; }
.btn-gold {background-color:#ffd54d !important; color:#1f3a9d !important; font-weight: bold !important;}
.btn-gold:hover {background-color:#ffca28 !important;}
@media(max-width:900px){
  .auth{grid-template-columns:1fr !important;}.auth-brand{padding:32px !important;}
}
</style>
<body class="auth-body">
<main class="auth">
    <section class="auth-brand">
        <div class="auth-brand-inner">
            <span class="brand-mark brand-mark-lg">BCP</span>
            <h1>Student Portal</h1>
            <p class="auth-lede">Access your enrollment status, schedules, and grades all in one place.</p>
            <p class="auth-school">Bestlink College of the Philippines</p>
        </div>
    </section>
    <section class="auth-panel">
        <div class="auth-card">
            <h2>Welcome back</h2>
            <p class="muted" style="margin-bottom: 20px;">Log in with the Student ID and password the Registrar gave you.</p>
            <?php foreach (takeFlashes() as $f): ?><div class="alert alert-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>
            <?php if ($error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
            <form method="POST" action="login.php" autocomplete="off">
                <?= csrfField() ?>
                <div class="field">
                    <label for="no">Student ID</label>
                    <input type="text" id="no" name="student_no" required inputmode="numeric" autofocus value="<?= e(str($_POST, 'student_no')) ?>">
                </div>
                <div class="field">
                    <label for="pw">Password</label>
                    <input type="password" id="pw" name="password" required>
                </div>
                <button class="btn btn-gold btn-block" type="submit" style="margin-top: 15px;">Log in</button>
            </form>
            <p class="switch" style="margin-top: 20px;">Not enrolled yet? <a href="enroll.php">Apply for enrollment</a><br><a href="status.php">Check my enrollment status</a></p>
        </div>
    </section>
</main>
</body></html>
