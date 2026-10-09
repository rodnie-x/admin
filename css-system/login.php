<?php
// >>> PLACE AT: css-system/login.php (replace the old file)  [batch 9: staff sign in = Gmail + emailed code + password (item 39)]
// Staff tab (default): Gmail + emailed code + password. Admin tab: username + password (the break-glass sign-in).
// Needs: includes/staff_auth.php (smtp mode), config/mail.php filled in on the live server, assets/js/staff-login.js.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/rate_limit.php';
require_once __DIR__ . '/includes/staff_auth.php';

if (isLoggedIn()) {
    redirect('dashboard.php');
}

$mode     = staffStr($_GET, 'mode') === 'admin' ? 'admin' : 'staff';
$error    = '';
$email    = '';
$username = '';
if (($_GET['expired'] ?? '') === '1') {
    $error = 'Your session ended. Please log in again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf_token'] ?? '')) {   // verifyCsrf() cannot handle array values
        http_response_code(400);
        exit('Bad request.');
    }
    verifyCsrf();
    $mode     = staffStr($_POST, 'mode') === 'admin' ? 'admin' : 'staff';
    $password = (isset($_POST['password']) && is_string($_POST['password'])) ? $_POST['password'] : '';

    if (!rateLimitAllow('css_login', 30, 900)) {
        // Per-IP: the 5-try lockout is per account, so without this one IP could try every account.
        $error = 'Too many log-in attempts from this network. Try again in a few minutes.';
    } elseif ($mode === 'admin') {
        $username = staffStr($_POST, 'username');
        if ($username === '' || $password === '') {
            $error = 'Please enter both username and password.';
        } else {
            $res = staffAttemptAdminLogin($username, $password);
            if ($res['success']) {
                redirect('dashboard.php');
            }
            $error = $res['message'];
        }
    } else {
        $email = strtolower(staffStr($_POST, 'email'));
        $code  = staffStr($_POST, 'code');
        if ($email === '' || $code === '' || $password === '') {
            $error = 'Enter your Gmail, the login code and your password.';
        } else {
            $res = staffAttemptLogin($email, $code, $password);
            if ($res['success']) {
                redirect('dashboard.php');
            }
            $error = $res['message'];
        }
    }
}

// Link to the staff sign-up page if one of these files exists
$signupPage = '';
foreach (['staff_signup.php', 'signup.php', 'register.php'] as $cand) {
    if (is_file(__DIR__ . '/' . $cand)) { $signupPage = $cand; break; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Log in | Class Scheduling System</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;800&family=Figtree:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<style>
/* Login: the campus photo is the whole page; the login form floats on it as frosted glass */
body.auth-body{
  background:
    linear-gradient(180deg, rgba(10,28,100,.62) 0%, rgba(10,28,100,.84) 100%),
    url("assets/images/login-bg.png") 62% center / cover no-repeat fixed,
    #1f3a9d;
}
.auth-brand,.auth-panel{background:transparent}
.auth-brand-inner{position:relative;z-index:1;text-shadow:0 2px 14px rgba(0,10,60,.45)}
.auth-brand .brand-mark{text-shadow:none}

.auth-card{
  background:rgba(255,255,255,.12);
  border:1px solid rgba(255,255,255,.30);
  -webkit-backdrop-filter:blur(16px) saturate(1.2);
          backdrop-filter:blur(16px) saturate(1.2);
  box-shadow:0 20px 60px rgba(0,10,60,.35);
  color:#fff;
}
.auth-card h2,.auth-card label{color:#fff}
.auth-card .auth-sub,.auth-card .hint{color:rgba(255,255,255,.85)}
.auth-card input[type=text],.auth-card input[type=email],.auth-card input[type=password]{
  background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.42);color:#fff}
.auth-card input:focus{background:rgba(255,255,255,.22);border-color:#fff;outline:3px solid rgba(255,255,255,.28)}
.auth-card input:-webkit-autofill{-webkit-text-fill-color:#fff;transition:background-color 9999s ease-out 0s}
.auth-card .alert-error{background:rgba(255,240,240,.96);color:#8e1c16;border-color:#d64545}
.auth-card a{color:#ffd54d}
.login-tabs{display:flex;gap:8px;margin:0 0 18px}
.login-tabs a{flex:1;text-align:center;padding:9px 8px;border-radius:9px;border:1px solid rgba(255,255,255,.3);color:#fff;text-decoration:none;font-weight:600;font-size:.9rem}
.login-tabs a.on{background:rgba(255,255,255,.28)}
.code-row{display:flex;gap:8px}
.code-row input{flex:1;min-width:0;letter-spacing:.12em}
.code-row .btn{white-space:nowrap}
.auth-card .btn-outline{background:rgba(255,255,255,.9);color:#1f3a9d}
</style>
</head>
<body class="auth-body">
<main class="auth">

    <section class="auth-brand">
        <div class="auth-brand-inner">
            <span class="brand-mark brand-mark-lg">CSS</span>
            <h1>Class Scheduling System</h1>
            <p class="auth-lede">Section assignment, real-time conflict checking, room availability and schedule cloning, in one place.</p>

            <p class="auth-school">Bestlink College of the Philippines</p>
        </div>
    </section>

    <section class="auth-panel">
        <div class="auth-card">
            <h2>Welcome back</h2>
            <p class="auth-sub"><?= $mode === 'admin' ? 'Administrator sign-in.' : 'Staff sign-in. We email a code to your Gmail.' ?></p>

            <div class="login-tabs">
                <a class="<?= $mode === 'staff' ? 'on' : '' ?>" href="login.php">Staff</a>
                <a class="<?= $mode === 'admin' ? 'on' : '' ?>" href="login.php?mode=admin">Administrator</a>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <?php if ($mode === 'admin'): ?>
            <form method="POST" action="login.php?mode=admin" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="mode" value="admin">
                <div class="field">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required autofocus value="<?= e($username) ?>">
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit" class="btn btn-gold btn-block">Log in</button>
            </form>
            <?php else: ?>
            <form method="POST" action="login.php" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="mode" value="staff">
                <div class="field">
                    <label for="email">Gmail account</label>
                    <input type="email" id="email" name="email" required autofocus placeholder="name@gmail.com" value="<?= e($email) ?>">
                </div>
                <div class="field">
                    <label for="code">Login code</label>
                    <div class="code-row">
                        <input type="text" id="code" name="code" required inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" placeholder="6 digits" aria-describedby="code-status">
                        <button type="button" class="btn btn-outline" id="send-code">Send code</button>
                    </div>
                    <p class="hint" id="code-status" role="status" aria-live="polite">Select "Send code", then check your Gmail. The code works for <?= (int)STAFF_CODE_TTL_MINUTES ?> minutes.</p>
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit" class="btn btn-gold btn-block">Log in</button>
                <?php if ($signupPage !== ''): ?>
                    <p class="hint" style="margin-top:14px;text-align:center">No account yet? <a href="<?= e($signupPage) ?>">Sign up</a></p>
                <?php endif; ?>
            </form>
            <?php endif; ?>
        </div>
    </section>
</main>
<script src="assets/js/staff-login.js" defer></script>
</body>
</html>