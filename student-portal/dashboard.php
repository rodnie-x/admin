<?php
require_once __DIR__ . '/includes/bootstrap.php';
$stu = requireStudent();

try {
    $announcements = getDB()->query('SELECT title, body, created_at FROM stu_announcements ORDER BY created_at DESC LIMIT 20')->fetchAll();
} catch (PDOException $ex) {
    $announcements = [];
}

layoutStart($stu, 'Announcements', 'Dashboard', 'dashboard');
?>
<section class="card">
  <h2 class="card-title"><?= icon('user') ?><?= e(studentName($stu)) ?></h2>
  <dl class="facts">
    <div><dt>Course:</dt><dd><?= e($stu['course']) ?></dd></div>
    <div><dt>Admission Type:</dt><dd><?= e($stu['admission_type']) ?></dd></div>
    <div><dt>Student ID:</dt><dd><?= e($stu['student_no']) ?></dd></div>
    <div><dt class="sr">Year level</dt><dd><?= e($stu['year_level']) ?></dd></div>
  </dl>
</section>

<section class="card">
  <h2 class="card-title"><?= icon('user') ?>ADVISER</h2>
  <dl class="facts">
    <div><dt>Name:</dt><dd><?= e($stu['adviser'] ?: 'Not assigned yet') ?></dd></div>
    <div><dt>My Section:</dt><dd><?= e($stu['section'] ?: 'Not assigned yet') ?></dd></div>
  </dl>
</section>

<section class="card">
  <h2 class="card-title"><?= icon('mega') ?>Announcements</h2>
  <?php if (!$announcements): ?>
    <p class="muted">No announcements yet.</p>
  <?php else: foreach ($announcements as $a): ?>
    <article class="ann">
      <h3><?= e($a['title']) ?></h3>
      <p><?= nl2br(e($a['body'])) ?></p>
      <time><?= e(date('F j, Y g:i:s A', strtotime($a['created_at']))) ?></time>
    </article>
  <?php endforeach; endif; ?>
</section>
<?php layoutEnd();
