<?php
// >>> PLACE AT: css-system/clone_schedule.php  (replace the old file)  [batch 5]
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/teacher_registration.php';   // cloned classes must also fit each teacher's registration
requireRole(['admin','registrar']);

$pdo = getDB();
$sections = $pdo->query("SELECT * FROM sections ORDER BY school_year DESC, name")->fetchAll();
$byId = [];
foreach ($sections as $s) { $byId[(int)$s['id']] = $s; }
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $in = fn(string $k): string => (isset($_POST[$k]) && is_string($_POST[$k])) ? trim($_POST[$k]) : '';
    $sourceId  = (int)$in('source_section_id');
    $targetId  = (int)$in('target_section_id');
    $targetSem = $in('target_semester');
    $targetSY  = $in('target_school_year');

    $error = null;
    if (!isset($byId[$sourceId]) || !isset($byId[$targetId])) {
        $error = 'Choose a source and a target section from the list.';
    } elseif (!in_array($targetSem, ['1st', '2nd', 'summer'], true)) {
        $error = 'Choose a valid target semester.';
    } elseif (!preg_match('/^(\d{4})-(\d{4})$/', $targetSY, $m) || (int)$m[2] !== (int)$m[1] + 1) {
        $error = 'Target school year must look like 2027-2028.';
    } elseif ($sourceId === $targetId && $targetSem === $byId[$sourceId]['semester'] && $targetSY === $byId[$sourceId]['school_year']) {
        $error = 'That would copy the section onto itself. Choose a different target section or a new term.';
    }
    if ($error !== null) {
        flash('error', $error);
        redirect('clone_schedule.php');
    }

    try {
        $result = cloneSectionSchedule($sourceId, $targetId, $targetSem, $targetSY, (int)$_SESSION['user_id']);
    } catch (Throwable $ex) {
        error_log('clone_schedule.php failed: ' . $ex->getMessage());
        flash('error', 'The clone failed and nothing was copied. Please try again.');
        redirect('clone_schedule.php');
    }
}

$pageTitle = 'Clone Schedule';
include __DIR__ . '/includes/header.php';
?>
<h1>📋 Schedule Cloning Tool</h1>
<p>Automatically copy a section's entire weekly timetable to another section or a new term. Any entry that would create a conflict at the destination, or fall outside the teacher's registered availability or subjects, is skipped and reported below.</p>

<div class="card">
    <form method="POST" action="clone_schedule.php">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <div class="form-grid">
            <div>
                <label>Source Section (copy FROM)</label>
                <select name="source_section_id" required>
                    <?php foreach ($sections as $s): ?>
                        <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?> — <?= e($s['semester']) ?> <?= e($s['school_year']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Target Section (copy TO)</label>
                <select name="target_section_id" required>
                    <?php foreach ($sections as $s): ?>
                        <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?> — <?= e($s['semester']) ?> <?= e($s['school_year']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Target Semester</label>
                <select name="target_semester" required>
                    <?php foreach (['1st','2nd','summer'] as $s): ?><option value="<?= $s ?>"><?= $s ?></option><?php endforeach; ?>
                </select>
            </div>
            <div><label>Target School Year</label><input type="text" name="target_school_year" placeholder="2027-2028" pattern="\d{4}-\d{4}" required></div>
        </div>
        <br>
        <button class="btn btn-gold" type="submit">Clone Schedule</button>
    </form>
</div>

<?php if ($result): ?>
<div class="card">
    <h3>Clone Result</h3>
    <p><strong><?= (int)$result['cloned'] ?></strong> entries cloned successfully.</p>
    <?php if (!empty($result['skipped'])): ?>
        <div class="conflict-box">
            <strong>⚠ <?= count($result['skipped']) ?> entries skipped:</strong>
            <ul><?php foreach ($result['skipped'] as $msg): ?><li><?= e($msg) ?></li><?php endforeach; ?></ul>
        </div>
    <?php else: ?>
        <div class="alert alert-success">No conflicts — every entry was cloned cleanly.</div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>