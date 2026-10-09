<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$pdo = getDB();
$counts = [
    'sections'  => $pdo->query("SELECT COUNT(*) FROM sections")->fetchColumn(),
    'teachers'  => $pdo->query("SELECT COUNT(*) FROM teachers WHERE status='active'")->fetchColumn(),
    'subjects'  => $pdo->query("SELECT COUNT(*) FROM subjects")->fetchColumn(),
    'rooms'     => $pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn(),
    'schedules' => $pdo->query("SELECT COUNT(*) FROM schedules WHERE status='active'")->fetchColumn(),
];

$isAdmin  = in_array($_SESSION['role'], ['admin', 'registrar'], true);
$pending  = [];
if ($isAdmin) {
    try {
        $pending = $pdo->query(
            "SELECT a.id, a.first_name, a.last_name, a.program, a.submitted_at
               FROM treg_accounts a WHERE a.reg_status = 'submitted'
              ORDER BY a.submitted_at ASC LIMIT 6"
        )->fetchAll();
    } catch (Throwable $e) {
        $pending = [];
    }
}

// Faculty: quick status of their own registration, if the portal tables exist and they are linked.
$myReg = null;
if ($_SESSION['role'] === 'faculty') {
    try {
        $stmt = $pdo->prepare(
            "SELECT a.reg_status FROM treg_accounts a
               JOIN teachers t ON t.id = a.teacher_id
              WHERE t.user_id = ? LIMIT 1"
        );
        $stmt->execute([$_SESSION['user_id']]);
        $myReg = $stmt->fetchColumn() ?: null;
    } catch (Throwable $e) {
        $myReg = null;
    }
}

$pageTitle = 'Dashboard';
include __DIR__ . '/includes/header.php';
?>
<h1>Dashboard</h1>
<p>Welcome back, <strong><?= e($_SESSION['full_name']) ?></strong>. Role: <span class="badge badge-role"><?= e($_SESSION['role']) ?></span></p>

<div class="stat-grid">
    <div class="stat-card"><div class="num"><?= $counts['sections'] ?></div><div class="label">Sections</div></div>
    <div class="stat-card"><div class="num"><?= $counts['teachers'] ?></div><div class="label">Active Teachers</div></div>
    <div class="stat-card"><div class="num"><?= $counts['subjects'] ?></div><div class="label">Subjects</div></div>
    <div class="stat-card"><div class="num"><?= $counts['rooms'] ?></div><div class="label">Rooms</div></div>
    <div class="stat-card"><div class="num"><?= $counts['schedules'] ?></div><div class="label">Active Schedule Entries</div></div>
</div>

<?php if ($isAdmin): ?>
<div class="two-col">
    <div class="card">
        <div class="reg-head" style="margin-bottom:6px">
            <h3>📝 Teacher Registrations waiting for review</h3>
            <a class="btn btn-sm btn-secondary" href="teacher_registrations.php">Open</a>
        </div>
        <?php if (!$pending): ?>
            <div class="empty">Nothing waiting for review right now.</div>
        <?php else: foreach ($pending as $p): ?>
            <div class="attn-row">
                <div>
                    <div class="attn-name"><?= e($p['first_name'] . ' ' . $p['last_name']) ?></div>
                    <div class="attn-meta"><?= e(teacherRegId($p['id'])) ?> &middot; <?= e($p['program']) ?> &middot; submitted <?= e(date('M j, g:i A', strtotime($p['submitted_at']))) ?></div>
                </div>
                <a class="btn btn-sm" href="teacher_registrations.php?status=submitted">Review</a>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <div class="card">
        <h3>How a teacher gets scheduled</h3>
        <ol style="padding-left:20px;color:var(--muted);line-height:1.9">
            <li>Teacher signs up on the <strong>Teacher Registration</strong> portal and submits their availability and subjects.</li>
            <li>You review it under <a href="teacher_registrations.php">Teacher Registrations</a> and <strong>Approve</strong>.</li>
            <li>The teacher now appears in the <strong>Schedules</strong> builder. Their availability and subjects show above the form and out-of-range classes are blocked automatically.</li>
            <li>The teacher sees the assigned sections, subjects, rooms and times under their own <strong>My Schedule</strong>.</li>
        </ol>
    </div>
</div>
<?php endif; ?>

<?php if ($_SESSION['role'] === 'faculty' && $myReg !== null && $myReg !== 'approved'): ?>
<div class="alert alert-info">
    Your teacher registration status is <strong><?= e(ucfirst(str_replace('_', ' ', $myReg))) ?></strong>.
    Classes will appear on <a href="my_schedule.php">My Schedule</a> once an admin approves your registration.
</div>
<?php endif; ?>

<h2 style="margin:26px 0 4px">System modules</h2>
<p style="color:var(--muted);margin-bottom:14px">Automated per the project charter.</p>
<ul class="flow">
    <li><div class="flow-title">Section Assignment</div><div class="flow-sub">Teacher-Subject Mapping</div></li>
    <li><div class="flow-title">Conflict Checker</div><div class="flow-sub">Teacher, room and section double-booking prevention</div></li>
    <li><div class="flow-title">Room Availability</div><div class="flow-sub">Find a free room instantly</div></li>
    <li><div class="flow-title">Schedule Cloning</div><div class="flow-sub">Copy a section's timetable to a new term</div></li>
</ul>

<div class="card">
    <h3>Role-based access</h3>
    <p style="margin-bottom:0">
        <strong>Admin</strong> &ndash; full access, manages accounts and roles, views the audit log &middot;
        <strong>Registrar</strong> &ndash; schedules, sections, rooms, cloning, conflict resolution, and Teacher Registration approvals &middot;
        <strong>Program Head</strong> &ndash; manages teachers and subjects &middot;
        <strong>Faculty</strong> &ndash; views their own teaching schedule
    </p>
</div>

<?php if ($_SESSION['role'] === 'faculty'): ?>
<div class="card">
    <a class="btn" href="my_schedule.php">View My Teaching Schedule →</a>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
