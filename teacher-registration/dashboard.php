<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/portal.php';
requireLogin();

$account   = currentAccount();
$subjects  = getDB()->query('SELECT id, code, title, units FROM subjects ORDER BY code')->fetchAll();
$teacher   = getLinkedTeacher($account);
$teacherId = $teacher ? (int)$teacher['id'] : 0;

// What is saved right now (empty on a brand-new account)
$windows     = $teacherId ? getSavedAvailability($teacherId) : [];
$selectedIds = $teacherId ? getSavedSubjectIds($teacherId) : [];
$errors      = [];

/** One availability row (also used for the JS <template>). */
function availRowHtml(string $day, string $start, string $end): string
{
    $opts = '<option value="">Day</option>';
    foreach (DAYS as $code => $label) {
        $opts .= '<option value="' . e($code) . '"' . ($day === $code ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    $bounds = ' min="' . e(AVAIL_MIN) . '" max="' . e(AVAIL_MAX) . '"';
    return '<div class="avail-row">'
        . '<select name="avail_day[]" aria-label="Day">' . $opts . '</select>'
        . '<input type="time" name="avail_start[]" aria-label="Available from"' . $bounds . ' value="' . e($start) . '">'
        . '<span class="avail-to">to</span>'
        . '<input type="time" name="avail_end[]" aria-label="Available until"' . $bounds . ' value="' . e($end) . '">'
        . '<button type="button" class="icon-btn" data-remove aria-label="Remove this time">&times;</button>'
        . '</div>';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    [$postedWindows, $errors] = parseAvailability($_POST);
    $postedIds = parseSubjectIds($_POST);
    if (!$postedIds) {
        $errors[] = 'Select at least one subject you can teach.';
    }

    if (!$errors) {
        $firstSubmit = in_array($account['reg_status'], ['incomplete', 'rejected'], true);
        try {
            saveRegistration($account, $postedWindows, $postedIds);
            flash('success', $firstSubmit
                ? 'Registration submitted. The admin will review it and add you to the class schedule.'
                : 'Your changes were saved.');
            redirect('dashboard.php');
        } catch (Throwable $ex) {
            error_log('Saving registration failed for account #' . $account['id'] . ': ' . $ex->getMessage());
            $errors[] = 'We could not save your registration. Try again, and contact the Registrar if it keeps happening.'
                      . (APP_DEBUG ? ' [' . $ex->getMessage() . ']' : '');
        }
    }

    // Keep exactly what the teacher typed so nothing is lost on an error.
    $pick = fn($arr, $i) => (is_array($arr) && isset($arr[$i]) && is_string($arr[$i])) ? substr($arr[$i], 0, 5) : '';
    $windows = [];
    $postedDays = $_POST['avail_day'] ?? [];
    if (is_array($postedDays)) {
        foreach ($postedDays as $i => $d) {
            $windows[] = [
                'day'   => is_string($d) ? $d : '',
                'start' => $pick($_POST['avail_start'] ?? [], $i),
                'end'   => $pick($_POST['avail_end'] ?? [], $i),
            ];
        }
    }
    $selectedIds = $postedIds;
}

$rows   = $windows ?: [['day' => '', 'start' => '', 'end' => '']];
$status = $account['reg_status'];
$notes  = [
    'incomplete' => 'You have not submitted yet. Add your available times and the subjects you can teach, then submit.',
    'submitted'  => 'Your registration is with the admin for review. You can still update it until it is approved.',
    'approved'   => 'You are approved. The admin can now assign you classes. They will show up under My schedules.',
    'rejected'   => 'The admin asked for changes' . (!empty($account['review_note']) ? ': ' . $account['review_note'] : '.') . ' Update the form and submit again.',
];
$submitLabel = in_array($status, ['incomplete', 'rejected'], true) ? 'Submit registration' : 'Save changes';
$daysJson    = json_encode(array_map(fn($k, $v) => [$k, $v], array_keys(DAYS), DAYS));

$pageTitle = 'Registration form';
$active    = 'form';
include __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <h1>Registration form</h1>
        <p class="lede">Your name is filled in from your account. Add when you can teach and which subjects you can handle.</p>
    </div>
    <span class="pill pill-<?= e($status) ?>"><?= e(statusLabel($status)) ?></span>
</div>

<div class="status-note status-<?= e($status) ?>"><?= e($notes[$status] ?? '') ?></div>

<form method="POST" action="dashboard.php" id="reg-form">
    <?= csrfField() ?>

    <?php if ($errors): ?>
        <div class="alert alert-error" role="alert">
            <strong>Check the form:</strong>
            <ul><?php foreach ($errors as $msg): ?><li><?= e($msg) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <!-- ===== Your details (read-only, pulled from the account) ===== -->
    <section class="sheet-row">
        <div class="sheet-label">
            <h2>Your details</h2>
            <p>Taken from your account. If something is misspelled, ask the Registrar to correct it.</p>
        </div>
        <div class="sheet-body">
            <div class="field">
                <label for="d-full">Name on the timetable</label>
                <input type="text" id="d-full" value="<?= e(accountFullName($account)) ?>" readonly>
            </div>
            <div class="field-row field-row-3">
                <div class="field"><label for="d-first">First name</label><input type="text" id="d-first" value="<?= e($account['first_name']) ?>" readonly></div>
                <div class="field"><label for="d-middle">Middle name</label><input type="text" id="d-middle" value="<?= e($account['middle_name']) ?>" readonly></div>
                <div class="field"><label for="d-last">Last name</label><input type="text" id="d-last" value="<?= e($account['last_name']) ?>" readonly></div>
            </div>
            <div class="field-row field-row-3">
                <div class="field"><label for="d-email">Gmail account</label><input type="text" id="d-email" value="<?= e($account['email']) ?>" readonly></div>
                <div class="field"><label for="d-program">Program</label><input type="text" id="d-program" value="<?= e(PROGRAMS[$account['program']] ?? $account['program']) ?>" readonly></div>
                <div class="field"><label for="d-year">Year level</label><input type="text" id="d-year" value="<?= e($account['year_level']) ?>" readonly></div>
            </div>
        </div>
    </section>

    <!-- ===== Time availability ===== -->
    <section class="sheet-row">
        <div class="sheet-label">
            <h2>Time availability</h2>
            <p>Add each stretch of time you can teach. For a lunch break, add two rows on the same day, such as 8:00 AM to 12:00 PM and 1:00 PM to 5:00 PM.</p>
        </div>
        <div class="sheet-body">
            <div id="avail-rows" data-min="<?= e(AVAIL_MIN) ?>" data-max="<?= e(AVAIL_MAX) ?>" data-days="<?= e($daysJson) ?>">
                <?php foreach ($rows as $w): ?>
                    <?= availRowHtml((string)$w['day'], (string)$w['start'], (string)$w['end']) ?>
                <?php endforeach; ?>
            </div>
            <template id="avail-row-tpl"><?= availRowHtml('', '', '') ?></template>

            <div class="btn-row">
                <button type="button" class="btn btn-outline btn-sm" id="add-window">Add another time</button>
                <button type="button" class="btn btn-ghost btn-sm" id="preset-weekdays">Fill Monday to Friday, 8:00 AM to 5:00 PM</button>
            </div>

            <div class="strip-wrap">
                <h3>Your week</h3>
                <div id="week-strip" class="strip" aria-hidden="true"></div>
                <p class="hint">Gold bars are the times the admin can schedule you.</p>
            </div>
        </div>
    </section>

    <!-- ===== Subjects ===== -->
    <section class="sheet-row">
        <div class="sheet-label">
            <h2>Subjects you can teach</h2>
            <p>Tick every subject you are able to handle. The admin can only assign you to these.</p>
        </div>
        <div class="sheet-body">
            <?php if (!$subjects): ?>
                <div class="empty">No subjects have been added to the system yet. Ask the Registrar to add them, then come back.</div>
            <?php else: ?>
                <div class="subject-tools">
                    <input type="search" id="subject-search" placeholder="Search by code or title" aria-label="Search subjects" autocomplete="off">
                    <span class="count" id="subject-count" aria-live="polite">0 selected</span>
                </div>
                <div class="subject-grid" id="subject-grid">
                    <?php foreach ($subjects as $s): ?>
                        <label class="subject-item" data-search="<?= e(mb_strtolower($s['code'] . ' ' . $s['title'])) ?>">
                            <input type="checkbox" name="subject_ids[]" value="<?= (int)$s['id'] ?>" <?= in_array((int)$s['id'], $selectedIds, true) ? 'checked' : '' ?>>
                            <span class="subject-code"><?= e($s['code']) ?></span>
                            <span class="subject-title"><?= e($s['title']) ?></span>
                            <span class="subject-units"><?= e(formatUnits($s['units'])) ?> units</span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="hint" id="subject-empty" hidden>No subjects match your search.</p>
            <?php endif; ?>
        </div>
    </section>

    <div class="actionbar">
        <div class="actionbar-inner">
            <p class="actionbar-meta"><span id="bar-windows">0 times</span> and <span id="bar-subjects">0 subjects</span> selected</p>
            <button type="submit" class="btn btn-primary"><?= e($submitLabel) ?></button>
        </div>
    </div>
</form>
<?php include __DIR__ . '/includes/footer.php'; ?>
