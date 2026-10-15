<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/rate_limit.php';
$result = null; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    // 10 checks per IP per 10 minutes
    if (!rateLimitAllow('stu_status', 10, 600)) {
        $error = 'Too many checks from this network. Please wait a few minutes.';
    } else {
        $email = strtolower(trim(str($_POST, 'personal_email')));
        $ok = preg_match('/^ENR-?(\d{1,10})$/i', trim(str($_POST, 'reference')), $m);
        if ($ok) {
            try {
                $st = getDB()->prepare('SELECT a.status, a.review_note, a.submitted_at, s.student_no, s.section FROM stu_applications a LEFT JOIN stu_students s ON s.id = a.student_id WHERE a.id = ? AND a.personal_email = ?');
                $st->execute([(int)$m[1], $email]);
                $result = $st->fetch() ?: null;
            } catch (PDOException $ex) { $result = null; }
        }
        if (!$result) { $error = 'No application found with that reference number and email.'; }
    }
}
$label = ['pending' => 'Waiting for review', 'approved' => 'Approved', 'rejected' => 'Not approved'];
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Enrollment status | <?= e(APP_NAME) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css">
</head><body class="auth-body">
<main class="auth-card">
  <span class="brand-mark brand-mark-lg">BCP</span>
  <h1>Enrollment status</h1>
  <p class="muted">Enter the reference number you received and the email you applied with.</p>
  <?php if ($error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if ($result): ?>
    <div class="card" style="box-shadow:none;border:1px solid var(--line);padding:18px">
      <span class="pill pill-<?= e($result['status']) ?>"><?= e($label[$result['status']] ?? $result['status']) ?></span>
      <p class="muted small" style="margin-top:10px">Sent <?= e(date('F j, Y g:i A', strtotime($result['submitted_at']))) ?></p>
      <?php if ($result['status'] === 'approved'): ?>
        <p style="margin-top:10px"><strong>Student ID:</strong> <?= e($result['student_no']) ?><br><strong>Section:</strong> <?= e($result['section'] ?: 'To be announced') ?></p>
        <p class="muted small">Get your password from the Registrar, then <a href="login.php">log in</a>.</p>
      <?php elseif ($result['status'] === 'rejected' && $result['review_note']): ?>
        <p style="margin-top:10px"><strong>Note from the Registrar:</strong> <?= e($result['review_note']) ?></p>
      <?php else: ?>
        <p class="muted small" style="margin-top:10px">The Registrar has not reviewed it yet.</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <form method="POST" action="status.php" autocomplete="off">
    <?= csrfField() ?>
    <label for="rf">Reference number</label><input type="text" id="rf" name="reference" required placeholder="ENR-00001" value="<?= e(str($_POST, 'reference')) ?>">
    <label for="em">Email address</label><input type="text" id="em" name="personal_email" required inputmode="email" value="<?= e(str($_POST, 'personal_email')) ?>">
    <button class="btn btn-block" type="submit">Check status</button>
  </form>
  <p class="switch"><a href="enroll.php">Apply for enrollment</a> &middot; <a href="login.php">Log in</a></p>
</main>
</body></html>
