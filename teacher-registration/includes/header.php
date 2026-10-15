<?php
/** Expects: $pageTitle (string), $active ('form' | 'profile' | 'schedule') */
$__account = currentAccount();
require_once __DIR__ . '/login_log.php';
if ($__account) { trackTeacherSignIn((int)$__account['id'], (string)$__account['email']); }
$__active  = $active ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle ?? 'Teacher Registration') ?> | <?= e(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;800&family=Figtree:wght@400;500;600&display=swap" rel="stylesheet">
   <link rel="stylesheet" href="assets/css/style.css">
   <link rel="stylesheet" href="assets/css/registration-form.css">
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<header class="topbar">
    <div class="topbar-inner">
        <a class="brand" href="dashboard.php">
            <span class="brand-mark">BCP</span>
            <span class="brand-name">Teacher Registration</span>
        </a>
        <nav class="topnav" aria-label="Main">
            <a href="dashboard.php" class="<?= $__active === 'form' ? 'is-active' : '' ?>">Registration form</a>
            <a href="profile.php" class="<?= $__active === 'profile' ? 'is-active' : '' ?>">My profile</a>
            <a href="my_schedule.php" class="<?= $__active === 'schedule' ? 'is-active' : '' ?>">My schedules</a>
        </nav>
        <div class="who">
            <span class="who-name"><?= e($__account ? accountFullName($__account) : '') ?></span>
            <form method="POST" action="logout.php">
                <?= csrfField() ?>
                <button type="submit" class="btn btn-ghost btn-sm">Log out</button>
            </form>
        </div>
    </div>
</header>
<main id="main" class="page">
<?php foreach (takeFlashes() as $__f): ?>
    <div class="alert alert-<?= e($__f['type']) ?>" role="status"><?= e($__f['msg']) ?></div>
<?php endforeach; ?>