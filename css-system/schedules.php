<?php
// >>> PLACE AT: css-system/schedules.php  (replace the old file)  [batch 5: validation + transaction]
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/teacher_registration.php';   // Teacher Registration bridge
requireRole(['admin','registrar']);

$pdo = getDB();

// --- Handle create / update / delete ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);

        // Every field is checked on the server (allowed day/semester, real times, school year format)
        [$slot, $errors] = validateScheduleInput($_POST);
        $back = 'schedules.php' . ($slot['section_id'] > 0 ? '?section_id=' . $slot['section_id'] : '');
        if ($errors) {
            flash('error', implode(' ', $errors));
            redirect($back);
        }

        // One transaction: lock teacher/room/section, re-check conflicts, then write.
        // Two admins saving at the same moment can no longer both pass the check and double-book.
        $savedId = 0;
        try {
            beginScheduleTransaction($pdo);

            $conflicts = [];
            $problem = lockScheduleResources($pdo, $slot);
            if ($problem !== null) {
                $conflicts[] = $problem;
            } elseif ($id > 0) {
                $st = $pdo->prepare('SELECT id FROM schedules WHERE id = ? FOR UPDATE');
                $st->execute([$id]);
                if (!$st->fetchColumn()) {
                    $conflicts[] = 'That schedule entry no longer exists.';
                }
            }
            if (!$conflicts) {
                // AUTOMATION: server-side authoritative conflict check (never trust client-side JS alone)
                $conflicts = detectScheduleConflicts($slot, $id > 0 ? $id : null);
                // Teacher Registration: must fit the availability + subjects the teacher declared
                $conflicts = array_merge($conflicts, checkTeacherRegistrationFit($slot));
            }
            if ($conflicts) {
                $pdo->rollBack();
                flash('error', 'Cannot save — conflict(s) found: ' . implode(' | ', $conflicts));
                redirect($back);
            }

            if ($id > 0) {
                $pdo->prepare("UPDATE schedules SET section_id=?, subject_id=?, teacher_id=?, room_id=?, day_of_week=?, start_time=?, end_time=?, semester=?, school_year=? WHERE id=?")
                    ->execute([...array_values($slot), $id]);
                $savedId = $id;
            } else {
                $pdo->prepare("INSERT INTO schedules (section_id, subject_id, teacher_id, room_id, day_of_week, start_time, end_time, semester, school_year, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
                    ->execute([...array_values($slot), $_SESSION['user_id']]);
                $savedId = (int)$pdo->lastInsertId();
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('schedules.php save failed: ' . $ex->getMessage());
            flash('error', 'Could not save the schedule. Nothing was changed. Please try again.');
            redirect($back);
        }

        if ($id > 0) {
            logAudit($_SESSION['user_id'], 'UPDATE', 'schedules', $savedId, 'Updated schedule entry');
            flash('success', 'Schedule updated with no conflicts.');
        } else {
            logAudit($_SESSION['user_id'], 'CREATE', 'schedules', $savedId, 'Created schedule entry');
            flash('success', 'Schedule created — no conflicts detected.');
        }
        redirect('schedules.php?section_id=' . $slot['section_id']);
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $stmt = $pdo->prepare("SELECT section_id FROM schedules WHERE id=?");
        $stmt->execute([$id]);
        $sec = (int)$stmt->fetchColumn();
        $pdo->prepare("DELETE FROM schedules WHERE id=?")->execute([$id]);
        logAudit($_SESSION['user_id'], 'DELETE', 'schedules', $id);
        flash('success', 'Schedule entry removed.');
        redirect('schedules.php' . ($sec > 0 ? '?section_id=' . $sec : ''));
    }
}

$sections = $pdo->query("SELECT * FROM sections ORDER BY school_year DESC, name")->fetchAll();
$teachers = $pdo->query("SELECT * FROM teachers WHERE status='active' ORDER BY full_name")->fetchAll();
$subjects = $pdo->query("SELECT * FROM subjects ORDER BY code")->fetchAll();
$rooms    = $pdo->query("SELECT * FROM rooms WHERE status='available' ORDER BY name")->fetchAll();

$sectionId = (int)($_GET['section_id'] ?? ($sections[0]['id'] ?? 0));

$editRow = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM schedules WHERE id=?");
    $stmt->execute([(int)$_GET['edit']]);
    $editRow = $stmt->fetch();
}

$scheduleRows = [];
if ($sectionId) {
    $stmt = $pdo->prepare("SELECT s.*, sub.code AS subj_code, sub.title AS subj_title, t.full_name AS teacher_name, r.name AS room_name
                            FROM schedules s
                            JOIN subjects sub ON sub.id = s.subject_id
                            JOIN teachers t ON t.id = s.teacher_id
                            JOIN rooms r ON r.id = s.room_id
                            WHERE s.section_id = ? AND s.status='active'
                            ORDER BY FIELD(s.day_of_week,'Mon','Tue','Wed','Thu','Fri','Sat'), s.start_time");
    $stmt->execute([$sectionId]);
    $scheduleRows = $stmt->fetchAll();
}

$pageTitle = 'Schedules';
include __DIR__ . '/includes/header.php';
?>
<h1>🗓️ Schedules</h1>

<div class="card">
    <label>Select Section</label>
    <select data-goto="schedules.php?section_id=">
        <?php foreach ($sections as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= $s['id']==$sectionId?'selected':'' ?>>
                <?= e($s['name']) ?> — <?= e($s['semester']) ?> <?= e($s['school_year']) ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

<div class="card">
    <h3><?= $editRow ? 'Edit Schedule Entry' : 'Add Schedule Entry' ?> <small style="font-weight:400;color:#789">(auto conflict-checked)</small></h3>
    <form method="POST" action="schedules.php" id="schedule-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($editRow['id'] ?? 0) ?>">
        <div class="form-grid">
            <div>
                <label>Section</label>
                <select name="section_id" required>
                    <?php foreach ($sections as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= (($editRow['section_id'] ?? $sectionId)==$s['id'])?'selected':'' ?>><?= e($s['name']) ?> (<?= e($s['semester']) ?> <?= e($s['school_year']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Subject</label>
                <select name="subject_id" required>
                    <?php foreach ($subjects as $sub): ?>
                        <option value="<?= (int)$sub['id'] ?>" <?= (($editRow['subject_id'] ?? '')==$sub['id'])?'selected':'' ?>><?= e($sub['code']) ?> - <?= e($sub['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Teacher</label>
                <select name="teacher_id" id="teacher-select" required>
                    <?php foreach ($teachers as $t): ?>
                        <option value="<?= (int)$t['id'] ?>" <?= (($editRow['teacher_id'] ?? '')==$t['id'])?'selected':'' ?>><?= e($t['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Room</label>
                <select name="room_id" required>
                    <?php foreach ($rooms as $r): ?>
                        <option value="<?= (int)$r['id'] ?>" <?= (($editRow['room_id'] ?? '')==$r['id'])?'selected':'' ?>><?= e($r['name']) ?> (<?= e(ucfirst($r['type'])) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Day</label>
                <select name="day_of_week" required>
                    <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat'] as $d): ?>
                        <option value="<?= $d ?>" <?= (($editRow['day_of_week'] ?? '')===$d)?'selected':'' ?>><?= $d ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label>Start Time</label><input type="time" name="start_time" required value="<?= e($editRow['start_time'] ?? '') ?>"></div>
            <div><label>End Time</label><input type="time" name="end_time" required value="<?= e($editRow['end_time'] ?? '') ?>"></div>
            <div>
                <label>Semester</label>
                <select name="semester" required>
                    <?php foreach (['1st','2nd','summer'] as $s): ?>
                        <option value="<?= $s ?>" <?= (($editRow['semester'] ?? '1st')===$s)?'selected':'' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label>School Year</label><input type="text" name="school_year" required placeholder="2026-2027" pattern="\d{4}-\d{4}" value="<?= e($editRow['school_year'] ?? '2026-2027') ?>"></div>
        </div>

        <div id="teacher-profile-box" class="profile-panel" hidden></div>

        <div id="conflict-result" style="margin-top:14px"></div>

        <br>
        <button class="btn btn-gold" id="submit-btn" type="submit"><?= $editRow ? 'Update Entry' : 'Add Entry' ?></button>
        <?php if ($editRow): ?><a class="btn btn-secondary" href="schedules.php?section_id=<?= $sectionId ?>">Cancel</a><?php endif; ?>
    </form>
</div>

<div class="card">
    <h3>Weekly Timetable</h3>
    <table>
        <tr><th>Day</th><th>Time</th><th>Subject</th><th>Teacher</th><th>Room</th><th>Actions</th></tr>
        <?php if (empty($scheduleRows)): ?>
            <tr><td colspan="6">No schedule entries yet for this section.</td></tr>
        <?php endif; ?>
        <?php foreach ($scheduleRows as $r): ?>
        <tr>
            <td class="day-badge"><?= e($r['day_of_week']) ?></td>
            <td><?= substr($r['start_time'],0,5) ?> – <?= substr($r['end_time'],0,5) ?></td>
            <td><?= e($r['subj_code']) ?> - <?= e($r['subj_title']) ?></td>
            <td><?= e($r['teacher_name']) ?></td>
            <td><?= e($r['room_name']) ?></td>
            <td>
                <a class="btn btn-sm" href="schedules.php?section_id=<?= $sectionId ?>&edit=<?= (int)$r['id'] ?>">Edit</a>
                <form class="inline" method="POST" action="schedules.php" data-confirm="Remove this class from the timetable?">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-sm btn-danger" type="submit">Remove</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<script type="application/json" id="teacher-profiles">
<?= json_encode((object)trAllProfiles(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>
</script>
<script src="assets/js/page-schedule.js" defer></script>
<?php include __DIR__ . '/includes/footer.php'; ?>