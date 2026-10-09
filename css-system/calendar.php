<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/calendar_lib.php';
requireLogin();

$pdo     = getDB();
$canEdit = in_array($_SESSION['role'] ?? '', ['admin', 'registrar'], true);   // everyone else is view-only
$types   = ['holiday' => 'Holiday', 'event' => 'School event', 'exam' => 'Exam', 'suspension' => 'Class suspension', 'other' => 'Notice'];

$validDate = function ($s) { $d = is_string($s) ? DateTime::createFromFormat('!Y-m-d', $s) : false; return $d && $d->format('Y-m-d') === $s; };
$validTime = fn($s) => $s === '' || (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $s);

/* ---------- Save / delete (admin + registrar only, enforced server-side) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) {
        http_response_code(403);
        exit('You are not allowed to change the calendar.');
    }
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save') {
            $id    = (int)($_POST['id'] ?? 0);
            $title = mb_substr(trim($_POST['title'] ?? ''), 0, 150);
            $type  = array_key_exists($_POST['event_type'] ?? '', $types) ? $_POST['event_type'] : 'event';
            $start = trim($_POST['start_date'] ?? '');
            $end   = trim($_POST['end_date'] ?? '') ?: $start;
            $st    = trim($_POST['start_time'] ?? '');
            $et    = trim($_POST['end_time'] ?? '');
            $desc  = mb_substr(trim($_POST['description'] ?? ''), 0, 1000);
            $nc    = isset($_POST['no_classes']) ? 1 : 0;

            if ($title === '' || !$validDate($start) || !$validDate($end)) {
                flash('error', 'Title and a valid date are required.');
            } elseif ($end < $start) {
                flash('error', 'The end date cannot be before the start date.');
            } elseif (!$validTime($st) || !$validTime($et) || ($st !== '' && $et !== '' && $et <= $st)) {
                flash('error', 'Check the times: the end time must be after the start time.');
            } else {
                $args = [$title, $desc ?: null, $type, $start, $end, $st ?: null, $et ?: null, $nc];
                if ($id > 0) {
                    $pdo->prepare('UPDATE calendar_events SET title=?, description=?, event_type=?, start_date=?, end_date=?, start_time=?, end_time=?, no_classes=? WHERE id=?')
                        ->execute([...$args, $id]);
                    logAudit($_SESSION['user_id'], 'UPDATE', 'calendar_events', $id, "Updated calendar event $title");
                    flash('success', 'Event updated. Teachers can see the change now.');
                } else {
                    $pdo->prepare('INSERT INTO calendar_events (title, description, event_type, start_date, end_date, start_time, end_time, no_classes, created_by) VALUES (?,?,?,?,?,?,?,?,?)')
                        ->execute([...$args, $_SESSION['user_id']]);
                    logAudit($_SESSION['user_id'], 'CREATE', 'calendar_events', $pdo->lastInsertId(), "Created calendar event $title");
                    flash('success', 'Event added. Teachers can see it on their calendar.');
                }
            }
        } elseif ($action === 'save_term') {
            $sem = $_POST['semester'] ?? '';
            $sy  = trim($_POST['school_year'] ?? '');
            $ts  = trim($_POST['term_start'] ?? '');
            $te  = trim($_POST['term_end'] ?? '');
            if (!in_array($sem, ['1st', '2nd', 'summer'], true) || !preg_match('/^\d{4}-\d{4}$/', $sy) || !$validDate($ts) || !$validDate($te)) {
                flash('error', 'Choose a semester, a school year like 2026-2027, and valid start and end dates.');
            } elseif ($te <= $ts) {
                flash('error', 'The semester must end after it starts.');
            } else {
                $pdo->prepare('INSERT INTO academic_terms (semester, school_year, start_date, end_date) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE start_date=VALUES(start_date), end_date=VALUES(end_date)')
                    ->execute([$sem, $sy, $ts, $te]);
                logAudit($_SESSION['user_id'], 'UPDATE', 'academic_terms', 0, "Set $sem $sy: $ts to $te");
                flash('success', 'Semester dates saved. Teachers now see classes only inside these dates.');
            }
        } elseif ($action === 'delete_term') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare('DELETE FROM academic_terms WHERE id=?')->execute([$id]);
            logAudit($_SESSION['user_id'], 'DELETE', 'academic_terms', $id);
            flash('success', 'Semester dates removed.');
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare('DELETE FROM calendar_events WHERE id=?')->execute([$id]);
            logAudit($_SESSION['user_id'], 'DELETE', 'calendar_events', $id);
            flash('success', 'Event removed.');
        }
    } catch (PDOException $ex) {
        error_log('calendar.php: ' . $ex->getMessage());
        flash('error', 'Could not save. Import sql/calendar_events.sql and sql/academic_terms.sql into the database first.');
    }
    redirect('calendar.php');
}

/* ---------- Load ---------- */
$setupError = null;
$editRow = null;
$events = [];
$terms = [];
$editTerm = null;
try {
    if ($canEdit && isset($_GET['edit'])) {
        $st = $pdo->prepare('SELECT * FROM calendar_events WHERE id=?');
        $st->execute([(int)$_GET['edit']]);
        $editRow = $st->fetch() ?: null;
    }
    $terms = calLoadTerms();
    if ($canEdit && isset($_GET['term'])) {
        foreach ($terms as $t) { if ((int)$t['id'] === (int)$_GET['term']) { $editTerm = $t; } }
    }
    if ($canEdit) {
        $events = $pdo->query('SELECT * FROM calendar_events ORDER BY start_date DESC, start_time LIMIT 200')->fetchAll();
    } else {
        $st = $pdo->prepare('SELECT * FROM calendar_events WHERE end_date >= ? ORDER BY start_date, start_time LIMIT 30');
        $st->execute([calManilaToday()]);
        $events = $st->fetchAll();
    }
} catch (PDOException $ex) {
    $setupError = 'The calendar table was not found. Import sql/calendar_events.sql into your database first.';
}

$pageTitle = 'Calendar';
include __DIR__ . '/includes/header.php';
?>
<h1>📅 Calendar</h1>
<p>Philippine holidays are built in. <?= $canEdit
    ? 'Add school events, exams, suspensions or any special day below. Every teacher sees them on their calendar, but they cannot edit them.'
    : 'Special days set by the Registrar appear here. This calendar is view only.' ?></p>

<?php if ($setupError): ?><div class="alert alert-error"><?= e($setupError) ?></div><?php endif; ?>

<?php calRenderWidget(['canEdit' => $canEdit]); ?>

<?php if ($canEdit): ?>
<div class="card cal-form" id="event-form-card">
    <h3><?= $editRow ? 'Edit event' : 'Add event' ?> <small style="font-weight:400;color:#789">(tip: click a day above to fill in the date)</small></h3>
    <form method="POST" action="calendar.php" id="event-form" <?= $editRow ? 'data-editing="1"' : '' ?>>
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($editRow['id'] ?? 0) ?>">
        <div class="form-grid">
            <div><label>Title</label><input type="text" name="title" required maxlength="150" placeholder="e.g. Eid'l Adha, Foundation Day" value="<?= e($editRow['title'] ?? '') ?>"></div>
            <div><label>Type</label>
                <select name="event_type">
                    <?php foreach ($types as $k => $label): ?>
                        <option value="<?= e($k) ?>" <?= (($editRow['event_type'] ?? 'event') === $k) ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label>Start date</label><input type="date" name="start_date" id="f-start" required value="<?= e($editRow['start_date'] ?? '') ?>"></div>
            <div><label>End date <span class="hint">(same day if empty)</span></label><input type="date" name="end_date" id="f-end" value="<?= e($editRow['end_date'] ?? '') ?>"></div>
            <div><label>Start time <span class="hint">(optional)</span></label><input type="time" name="start_time" value="<?= e(substr((string)($editRow['start_time'] ?? ''), 0, 5)) ?>"></div>
            <div><label>End time <span class="hint">(optional)</span></label><input type="time" name="end_time" value="<?= e(substr((string)($editRow['end_time'] ?? ''), 0, 5)) ?>"></div>
        </div>
        <div style="margin-top:16px"><label>Details <span class="hint">(optional, teachers can read this)</span></label>
            <textarea name="description" maxlength="1000"><?= e($editRow['description'] ?? '') ?></textarea></div>
        <label class="cal-check" style="margin-top:14px"><input type="checkbox" name="no_classes" value="1" <?= !empty($editRow['no_classes']) ? 'checked' : '' ?>> No classes on these days (teachers' classes are shown as suspended)</label>
        <br>
        <button class="btn btn-gold" type="submit"><?= $editRow ? 'Update event' : 'Add event' ?></button>
        <?php if ($editRow): ?><a class="btn btn-secondary" href="calendar.php">Cancel</a><?php endif; ?>
    </form>
</div>
<script src="assets/js/page-calendar-admin.js" defer></script>
<?php endif; ?>

<div class="card cal-form" id="terms">
    <h3>Semester dates <small style="font-weight:400;color:#789">(classes on the teachers' calendar only appear between these dates)</small></h3>
    <?php if ($canEdit): ?>
    <form method="POST" action="calendar.php#terms">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="save_term">
        <div class="form-grid">
            <div><label>Semester</label>
                <select name="semester">
                    <?php foreach (['1st' => '1st semester', '2nd' => '2nd semester', 'summer' => 'Summer'] as $k => $lb): ?>
                        <option value="<?= e($k) ?>" <?= (($editTerm['semester'] ?? '1st') === $k) ? 'selected' : '' ?>><?= e($lb) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label>School year</label><input type="text" name="school_year" required placeholder="2026-2027" pattern="\d{4}-\d{4}" value="<?= e($editTerm['school_year'] ?? '2026-2027') ?>"></div>
            <div><label>First day of classes</label><input type="date" name="term_start" required value="<?= e($editTerm['start_date'] ?? '') ?>"></div>
            <div><label>Last day of classes</label><input type="date" name="term_end" required value="<?= e($editTerm['end_date'] ?? '') ?>"></div>
        </div>
        <br>
        <button class="btn btn-gold" type="submit">Save semester dates</button>
        <?php if ($editTerm): ?><a class="btn btn-secondary" href="calendar.php#terms">Cancel</a><?php endif; ?>
        <p class="hint" style="margin-top:10px">Saving the same semester and school year again updates its dates.</p>
    </form>
    <?php endif; ?>
    <table style="margin-top:14px">
        <tr><th>Semester</th><th>School year</th><th>First day</th><th>Last day</th><?php if ($canEdit): ?><th>Actions</th><?php endif; ?></tr>
        <?php if (!$terms): ?><tr><td colspan="<?= $canEdit ? 5 : 4 ?>">No semester dates set yet. Until you add them, classes show on every matching weekday.</td></tr><?php endif; ?>
        <?php foreach ($terms as $t): ?>
        <tr>
            <td><?= e(calSemLabel($t['semester'])) ?></td>
            <td><?= e($t['school_year']) ?></td>
            <td><?= e(date('M j, Y', strtotime($t['start_date']))) ?></td>
            <td><?= e(date('M j, Y', strtotime($t['end_date']))) ?></td>
            <?php if ($canEdit): ?>
            <td>
                <a class="btn btn-sm" href="calendar.php?term=<?= (int)$t['id'] ?>#terms">Edit</a>
                <form class="inline" method="POST" action="calendar.php" data-confirm="Remove these semester dates?">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="delete_term">
                    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                    <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                </form>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<div class="card">
    <h3><?= $canEdit ? 'All events' : 'Upcoming events' ?></h3>
    <table>
        <tr><th>Date</th><th>Title</th><th>Type</th><th>Classes</th><?php if ($canEdit): ?><th>Actions</th><?php endif; ?></tr>
        <?php if (!$events): ?><tr><td colspan="<?= $canEdit ? 5 : 4 ?>">No events yet.</td></tr><?php endif; ?>
        <?php foreach ($events as $ev): ?>
        <tr>
            <td><?= e(date('M j, Y', strtotime($ev['start_date']))) ?><?= $ev['end_date'] !== $ev['start_date'] ? ' – ' . e(date('M j, Y', strtotime($ev['end_date']))) : '' ?></td>
            <td><strong><?= e($ev['title']) ?></strong><?php if ($ev['description']): ?><div class="hint"><?= e(mb_strimwidth($ev['description'], 0, 90, '…')) ?></div><?php endif; ?></td>
            <td><span class="badge"><?= e($types[$ev['event_type']] ?? $ev['event_type']) ?></span></td>
            <td><?= $ev['no_classes'] ? '<span class="badge badge-inactive">No classes</span>' : 'Normal' ?></td>
            <?php if ($canEdit): ?>
            <td>
                <a class="btn btn-sm" href="calendar.php?edit=<?= (int)$ev['id'] ?>#event-form-card">Edit</a>
                <form class="inline" method="POST" action="calendar.php" data-confirm="Remove this event?">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$ev['id'] ?>">
                    <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                </form>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>


