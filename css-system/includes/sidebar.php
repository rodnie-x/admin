<?php
/** Sidebar in the BCP portal style. Uses $_SESSION['role'], ['full_name'], ['email'] (optional). */
$role = $_SESSION['role'] ?? '';
$cur  = basename($_SERVER['SCRIPT_NAME']);
$name = $_SESSION['full_name'] ?? 'User';
$initials = strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($name)), 0, 2))));

function ic(string $n): string {
    $p = [
     'dash'=>'<path d="M21 12A9 9 0 1112 3v9z"/><path d="M15 3.5A9 9 0 0120.5 9H15z"/>',
     'cal'=>'<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
     'grid'=>'<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
     'door'=>'<path d="M4 21V4a1 1 0 011-1h9a1 1 0 011 1v17M2 21h20M11 12h.01"/>',
     'copy'=>'<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a1 1 0 01-1-1V4a1 1 0 011-1h10a1 1 0 011 1v1"/>',
     'user'=>'<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0116 0"/>',
     'book'=>'<path d="M4 19.5A2.5 2.5 0 016.5 17H20V3H6.5A2.5 2.5 0 004 5.5z"/><path d="M4 19.5V22h16"/>',
     'clip'=>'<rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4V3h6v1M9 11h6M9 15h4"/>',
     'key'=>'<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M16 7l3 3"/>',
     'log'=>'<path d="M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
     'out'=>'<path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/>',
     'bell'=>'<path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 01-3.4 0"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$n] ?? '') . '</svg>';
}
$groups = [
 ['Main', 'Class Scheduling Overview', [
   ['dashboard.php','Dashboard','dash',['admin','registrar','program_head','faculty']],
   ['my_schedule.php','My Schedule','cal',['faculty']],
   ['calendar.php','Calendar','cal',['admin','registrar','program_head','faculty']],
 ]],
 ['Scheduling', 'Timetables and rooms', [
   ['schedules.php','Schedules','cal',['admin','registrar']],
   ['sections.php','Sections','grid',['admin','registrar']],
   ['rooms.php','Rooms','door',['admin','registrar']],
   ['clone_schedule.php','Clone Schedule','copy',['admin','registrar']],
 ]],
 ['Academic', 'Faculty and curriculum', [
   ['teachers.php','Teachers','user',['admin','registrar','program_head']],
   ['subjects.php','Subjects','book',['admin','registrar','program_head']],
   ['teacher_registrations.php','Teacher Registrations','clip',['admin','registrar']],
   ['student_enrollments.php','Student Enrollments','user',['admin','registrar']],
   ['student_signins.php','Student Sign-ins','log',['admin','registrar']],
 ]],
 ['Administration', 'Security and access', [
   ['users.php','User Accounts','key',['admin']],
   ['audit_log.php','Audit Log','log',['admin']],
 ]],
];
?>
<aside class="sidebar" id="sidebar">
  <div class="sb-top"><span class="brand-mark">CSS</span><span class="sb-icons"><?= ic('bell') ?><?= ic('user') ?></span></div>
  <div class="sb-user">
    <div class="avatar"><?= e($initials) ?></div>
    <div class="sb-name"><?= e($name) ?></div>
    <div class="sb-mail"><?= e(ucfirst(str_replace('_',' ',$role))) ?></div>
  </div>
  <?php foreach ($groups as [$title, $sub, $items]):
      $items = array_filter($items, fn($i) => in_array($role, $i[3], true));
      if (!$items) continue; ?>
    <nav class="sb-group">
      <div class="sb-head"><b><?= e($title) ?></b><small><?= e($sub) ?></small></div>
      <?php foreach ($items as [$href,$label,$icon]): ?>
        <a class="sb-link <?= $cur === $href ? 'active' : '' ?>" href="<?= e($href) ?>"><?= ic($icon) ?><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
  <?php endforeach; ?>
  <form class="sb-logout" method="POST" action="logout.php">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
    <button type="submit"><?= ic('out') ?>Log out</button>
  </form>
</aside>