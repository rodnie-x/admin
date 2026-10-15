<?php
// >>> PLACE AT: student-portal/enroll.php  (replace the old file; if it is new, just add it)
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/rate_limit.php';
require_once __DIR__ . '/includes/bot_guard.php';
if (currentStudent()) { redirect('dashboard.php'); }

$errors = []; $done = null;
if (isset($_GET['done']) && !empty($_SESSION['enroll_ref'])) { $done = $_SESSION['enroll_ref']; unset($_SESSION['enroll_ref']); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    // At most 5 applications per IP per hour (stops form spam)
    if (!rateLimitAllow('stu_enroll', 5, 3600)) { $errors[] = 'Too many applications from this network. Please try again later.'; }
    if (botLooksLikeBot()) { $errors[] = 'Please wait a few seconds, then send the form again.'; }

    $first = trim(str($_POST, 'first_name')); $mid = trim(str($_POST, 'middle_name')); $last = trim(str($_POST, 'last_name'));
    $email = strtolower(trim(str($_POST, 'personal_email'))); $mobile = preg_replace('/[\s-]/', '', str($_POST, 'mobile'));
    $birth = trim(str($_POST, 'birthdate')); $course = str($_POST, 'course'); $adm = str($_POST, 'admission_type'); $year = str($_POST, 'year_level');
    $school = mb_substr(trim(str($_POST, 'last_school')), 0, 150);

    if ($first === '' || $last === '') { $errors[] = 'First name and last name are required.'; }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) { $errors[] = 'Enter a valid email address.'; }
    if (!preg_match('/^(09\d{9}|\+639\d{9})$/', $mobile)) { $errors[] = 'Mobile number should look like 09171234567.'; }
    $bd = DateTime::createFromFormat('!Y-m-d', $birth);
    if (!$bd || $bd->format('Y-m-d') !== $birth || $birth > date('Y-m-d', strtotime('-14 years')) || $birth < '1940-01-01') { $errors[] = 'Enter a valid birthdate.'; }
    if (!in_array($course, STU_COURSES, true)) { $errors[] = 'Choose the course you are enrolling in.'; }
    if (!in_array($adm, STU_ADMISSION, true)) { $errors[] = 'Choose your admission type.'; }
    if (!in_array($year, STU_YEARS, true)) { $errors[] = 'Choose your year level.'; }

    if (!$errors) {
        try {
            $pdo = getDB();
            $dup = $pdo->prepare("SELECT COUNT(*) FROM stu_applications WHERE personal_email = ? AND status = 'pending'");
            $dup->execute([$email]);
            if ((int)$dup->fetchColumn() > 0) {
                $errors[] = 'You already have an application waiting for review. Use "Check my enrollment status" to follow it.';
            } else {
                $pdo->prepare('INSERT INTO stu_applications (first_name, middle_name, last_name, personal_email, mobile, birthdate, course, admission_type, year_level, last_school) VALUES (?,?,?,?,?,?,?,?,?,?)')
                    ->execute([mb_substr($first, 0, 60), mb_substr($mid, 0, 60) ?: null, mb_substr($last, 0, 60), $email, $mobile, $birth, $course, $adm, $year, $school ?: null]);
                $_SESSION['enroll_ref'] = 'ENR-' . str_pad((string)$pdo->lastInsertId(), 5, '0', STR_PAD_LEFT);
                redirect('enroll.php?done=1');
            }
        } catch (PDOException $ex) {
            // Batch 4: the real reason goes to the server log only, never to the visitor.
            error_log('enroll.php: ' . $ex->getMessage());
            $errors[] = 'We could not send your application. Please try again later or see the Registrar.';
        }
    }
}
$o = fn(string $k) => e(str($_POST, $k));
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Enrollment | <?= e(APP_NAME) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css">
</head><body class="auth-body">
<main class="auth-card auth-wide">
  <span class="brand-mark brand-mark-lg">BCP</span>
<?php if ($done): ?>
  <h1>Enrollment sent</h1>
  <p class="muted">The Registrar will review your application.</p>
  <div class="ref"><?= e($done) ?></div>
  <p class="muted small">Keep this reference number. You need it with your email to check your status.</p>
  <ol class="steps">
    <li>The Registrar reviews your application.</li>
    <li>If approved, you get a <strong>Student ID</strong>, your <strong>section</strong> and a <strong>personal password</strong> from the Registrar.</li>
    <li>Log in, then choose your own new password.</li>
  </ol>
  <a class="btn btn-block" href="status.php">Check my enrollment status</a>
  <p class="switch"><a href="login.php">Back to log in</a></p>
<?php else: ?>
  <h1>Enrollment form</h1>
  <p class="muted">Fill in your details and send them to the Registrar. You do not need a password yet.</p>
  <?php if ($errors): ?><div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <form method="POST" action="enroll.php" autocomplete="off">
    <?= csrfField() ?>
    <?= botFieldHtml() ?>
    <div class="row3">
      <div><label for="fn">First name</label><input type="text" id="fn" name="first_name" required maxlength="60" value="<?= $o('first_name') ?>"></div>
      <div><label for="mn">Middle name</label><input type="text" id="mn" name="middle_name" maxlength="60" value="<?= $o('middle_name') ?>"></div>
      <div><label for="ln">Last name</label><input type="text" id="ln" name="last_name" required maxlength="60" value="<?= $o('last_name') ?>"></div>
    </div>
    <div class="row2">
      <div><label for="em">Email address</label><input type="text" id="em" name="personal_email" required maxlength="150" inputmode="email" placeholder="name@gmail.com" value="<?= $o('personal_email') ?>"></div>
      <div><label for="mo">Mobile number</label><input type="text" id="mo" name="mobile" required maxlength="20" inputmode="tel" placeholder="09171234567" value="<?= $o('mobile') ?>"></div>
    </div>
    <label for="bd">Birthdate</label><input type="date" id="bd" name="birthdate" required max="<?= e(date('Y-m-d')) ?>" value="<?= $o('birthdate') ?>">
    <label for="co">Course</label>
    <select id="co" name="course" required><option value="">Choose course</option>
      <?php foreach (STU_COURSES as $c): ?><option value="<?= e($c) ?>" <?= str($_POST, 'course') === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select>
    <div class="row2">
      <div><label for="ad">Admission type</label><select id="ad" name="admission_type" required><option value="">Choose</option>
        <?php foreach (STU_ADMISSION as $c): ?><option <?= str($_POST, 'admission_type') === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
      <div><label for="yl">Year level</label><select id="yl" name="year_level" required><option value="">Choose</option>
        <?php foreach (STU_YEARS as $c): ?><option <?= str($_POST, 'year_level') === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
    </div>
    <label for="ls">Last school attended <span class="muted small">(optional)</span></label><input type="text" id="ls" name="last_school" maxlength="150" value="<?= $o('last_school') ?>">
    <p class="hint">Your Student ID, section and password are given by the Registrar after your enrollment is approved.</p>
    <button class="btn btn-block" type="submit">Enroll</button>
  </form>
  <p class="switch">Already enrolled? <a href="login.php">Log in</a> &middot; <a href="status.php">Check my status</a></p>
<?php endif; ?>
</main>
</body></html>