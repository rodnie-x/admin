<?php
require_once __DIR__ . '/includes/bootstrap.php';
$stu  = requireStudent();
$slug = str($_GET, 'p');

$found = null; $groupName = 'Dashboard';
foreach (STU_NAV as [$group, $sub, $items]) {
    foreach ($items as $it) { if ($it[0] === $slug) { $found = $it; $groupName = $group; } }
}
if (!$found || $slug === 'dashboard') { redirect('dashboard.php'); }

layoutStart($stu, $found[1], $groupName, $slug);

if ($slug === 'sms-profile'): ?>
<section class="card">
  <h2 class="card-title"><?= icon('user') ?>Student information</h2>
  <dl class="facts facts-wide">
    <div><dt>Full name</dt><dd><?= e(studentName($stu)) ?></dd></div>
    <div><dt>Student ID</dt><dd><?= e($stu['student_no']) ?></dd></div>
    <div><dt>School email</dt><dd><?= e(studentEmail($stu)) ?></dd></div>
    <div><dt>Course</dt><dd><?= e($stu['course']) ?></dd></div>
    <div><dt>Admission type</dt><dd><?= e($stu['admission_type']) ?></dd></div>
    <div><dt>Year level</dt><dd><?= e($stu['year_level']) ?></dd></div>
    <div><dt>Section</dt><dd><?= e($stu['section'] ?: 'Not assigned yet') ?></dd></div>
    <div><dt>Adviser</dt><dd><?= e($stu['adviser'] ?: 'Not assigned yet') ?></dd></div>
  </dl>
  <p class="muted">If something is misspelled, ask the Registrar to correct it.</p>
</section>
<?php else: ?>
<section class="card empty">
  <h2 class="card-title"><?= icon($found[2]) ?><?= e($found[1]) ?></h2>
  <p class="muted">This part of the portal is not available yet. It will appear here once the school turns it on.</p>
  <a class="btn" href="dashboard.php">Back to Dashboard</a>
</section>
<?php endif;
layoutEnd();
