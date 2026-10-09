<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireRole(['faculty','admin','registrar','program_head']);

$pdo = getDB();

// Find the teacher record linked to this logged-in user
$stmt = $pdo->prepare("SELECT * FROM teachers WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$teacher = $stmt->fetch();

$rows = [];
if ($teacher) {
    $stmt = $pdo->prepare("SELECT s.*, sub.code AS subj_code, sub.title AS subj_title, r.name AS room_name, sec.name AS section_name
                            FROM schedules s
                            JOIN subjects sub ON sub.id = s.subject_id
                            JOIN rooms r ON r.id = s.room_id
                            JOIN sections sec ON sec.id = s.section_id
                            WHERE s.teacher_id = ? AND s.status='active'
                            ORDER BY FIELD(s.day_of_week,'Mon','Tue','Wed','Thu','Fri','Sat'), s.start_time");
    $stmt->execute([$teacher['id']]);
    $rows = $stmt->fetchAll();
}

$pageTitle = 'My Schedule';
include __DIR__ . '/includes/header.php';
?>
<h1>🗓️ My Teaching Schedule</h1>

<?php if (!$teacher): ?>
    <div class="card"><p>No teacher profile is linked to your account yet. Please contact the Registrar/Admin to link your account.</p></div>
<?php else: ?>
<div class="card">
    <p>Showing schedule for: <strong><?= e($teacher['full_name']) ?></strong></p>
    <table>
        <tr><th>Day</th><th>Time</th><th>Subject</th><th>Section</th><th>Room</th></tr>
        <?php if (empty($rows)): ?><tr><td colspan="5">No classes assigned yet.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
        <tr>
            <td class="day-badge"><?= e($r['day_of_week']) ?></td>
            <td><?= substr($r['start_time'],0,5) ?> – <?= substr($r['end_time'],0,5) ?></td>
            <td><?= e($r['subj_code']) ?> - <?= e($r['subj_title']) ?></td>
            <td><?= e($r['section_name']) ?></td>
            <td><?= e($r['room_name']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
