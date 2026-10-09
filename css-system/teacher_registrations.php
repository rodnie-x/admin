<?php
// >>> PLACE AT: css-system/teacher_registrations.php  (replace the old file)  [batch 8: no inline handlers]
/**
 * Teacher Registrations  (Class Scheduling System page)
 * Copy to:  css-system/teacher_registrations.php
 *
 * Shows what teachers filled in on the Teacher Registration portal (availability + subjects),
 * lets the Admin / Registrar approve or reject them, and flags classes that fall outside
 * a teacher's declared availability. Approving sets teachers.status = 'active', which is what
 * makes the teacher show up in the Teacher dropdown of schedules.php.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/teacher_registration.php';
requireRole(['admin', 'registrar']);

$pdo = getDB();

// Employee / Faculty ID columns exist only after sql/teacher_ids.sql has been imported
$hasIds = false;
try { $hasIds = (bool)$pdo->query("SHOW COLUMNS FROM teachers LIKE 'employee_id'")->fetch(); } catch (Throwable $e) { $hasIds = false; }

/**
 * Gives a teacher the next free Employee ID (EMP-YYYY-0001) and Faculty ID (FAC-YYYY-0001)
 * if they do not have one yet. Never overwrites an ID the admin already typed.
 */
function assignTeacherIds(PDO $pdo, int $teacherId): array
{
    $year = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y');
    $st = $pdo->prepare('SELECT employee_id, faculty_id FROM teachers WHERE id = ?');
    $st->execute([$teacherId]);
    $row = $st->fetch();
    if (!$row) { return ['employee_id' => null, 'faculty_id' => null, 'new' => false]; }
    $new = false;
    foreach (['employee_id' => 'EMP', 'faculty_id' => 'FAC'] as $col => $prefix) {
        if (!empty($row[$col])) { continue; }
        for ($try = 0; $try < 5; $try++) {
            if ($col === 'employee_id') {   // the two columns each get a fixed query (no variable inside the SQL text)
                $q = $pdo->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING(employee_id, -4) AS UNSIGNED)), 0) FROM teachers WHERE employee_id LIKE ?");
            } else {
                $q = $pdo->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING(faculty_id, -4) AS UNSIGNED)), 0) FROM teachers WHERE faculty_id LIKE ?");
            }
            $q->execute(["$prefix-$year-%"]);
            $candidate = sprintf('%s-%s-%04d', $prefix, $year, (int)$q->fetchColumn() + 1);
            try {
                if ($col === 'employee_id') {
                    $u = $pdo->prepare("UPDATE teachers SET employee_id = ? WHERE id = ? AND (employee_id IS NULL OR employee_id = '')");
                } else {
                    $u = $pdo->prepare("UPDATE teachers SET faculty_id = ? WHERE id = ? AND (faculty_id IS NULL OR faculty_id = '')");
                }
                $u->execute([$candidate, $teacherId]);
                if ($u->rowCount() === 1) { $row[$col] = $candidate; $new = true; }
                break;
            } catch (PDOException $ex) {
                if ($ex->getCode() !== '23000') { throw $ex; }   // number was just taken: try the next one
            }
        }
    }
    return ['employee_id' => $row['employee_id'], 'faculty_id' => $row['faculty_id'], 'new' => $new];
}

/* ---------------- Approve / reject ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $id     = (int)($_POST['id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');

    // One-click: give IDs to teachers who were approved before automatic IDs existed
    if ($action === 'backfill_ids') {
        try {
            if (!$hasIds) { throw new RuntimeException('teacher_ids.sql not imported'); }
            $rowsToFix = $pdo->query("SELECT t.id FROM treg_accounts a JOIN teachers t ON t.id = a.teacher_id
                                       WHERE a.reg_status = 'approved' AND (t.employee_id IS NULL OR t.employee_id = '' OR t.faculty_id IS NULL OR t.faculty_id = '')
                                       ORDER BY a.reviewed_at, t.id")->fetchAll(PDO::FETCH_COLUMN);
            $done = 0;
            foreach ($rowsToFix as $tidFix) {
                if (assignTeacherIds($pdo, (int)$tidFix)['new']) { $done++; }
            }
            logAudit($_SESSION['user_id'], 'UPDATE', 'teachers', 0, "Auto-assigned Employee/Faculty IDs to $done approved teacher(s)");
            flash('success', $done . ' approved teacher' . ($done === 1 ? '' : 's') . ' received Employee and Faculty IDs.');
        } catch (Throwable $ex) {
            error_log('backfill_ids: ' . $ex->getMessage());
            flash('error', 'Could not assign IDs. Import sql/teacher_ids.sql into the database first.');
        }
        redirect('teacher_registrations.php' . (isset($_GET['status']) ? '?status=' . urlencode((string)$_GET['status']) : ''));
    }

    try {
        $st = $pdo->prepare('SELECT * FROM treg_accounts WHERE id = ?');
        $st->execute([$id]);
        $acct = $st->fetch();

        if (!$acct) {
            flash('error', 'That registration no longer exists.');
        } elseif (empty($acct['teacher_id'])) {
            flash('error', 'This teacher has not submitted the registration form yet.');
        } elseif ($action === 'approve') {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE teachers SET status = 'active' WHERE id = ?")->execute([$acct['teacher_id']]);
            $pdo->prepare("UPDATE treg_accounts SET reg_status = 'approved', review_note = NULL, reviewed_at = NOW(), reviewed_by = ? WHERE id = ?")
                ->execute([$_SESSION['user_id'], $id]);
            $pdo->commit();
            logAudit($_SESSION['user_id'], 'UPDATE', 'teachers', (int)$acct['teacher_id'], 'Approved teacher registration for ' . $acct['email']);
            $msg = 'Approved. The teacher can now be selected when building schedules.';
            if ($hasIds) {
                try {
                    $ids = assignTeacherIds($pdo, (int)$acct['teacher_id']);
                    if ($ids['new']) {
                        logAudit($_SESSION['user_id'], 'UPDATE', 'teachers', (int)$acct['teacher_id'], 'Auto-assigned ' . $ids['employee_id'] . ' / ' . $ids['faculty_id']);
                        $msg = 'Approved. Employee ID ' . $ids['employee_id'] . ' and Faculty ID ' . $ids['faculty_id'] . ' were assigned automatically. The teacher can now be selected when building schedules.';
                    }
                } catch (Throwable $ex) {
                    error_log('assignTeacherIds: ' . $ex->getMessage());
                    $msg .= ' (The IDs could not be generated automatically. Set them on the Teachers page.)';
                }
            } else {
                $msg .= ' Import sql/teacher_ids.sql to turn on automatic Employee and Faculty IDs.';
            }
            flash('success', $msg);
        } elseif ($action === 'reject') {
            $note = trim((string)($_POST['note'] ?? ''));
            if ($note === '') {
                flash('error', 'Write a short reason so the teacher knows what to fix.');
            } else {
                $note = mb_substr($note, 0, 255);
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE teachers SET status = 'inactive' WHERE id = ?")->execute([$acct['teacher_id']]);
                $pdo->prepare("UPDATE treg_accounts SET reg_status = 'rejected', review_note = ?, reviewed_at = NOW(), reviewed_by = ? WHERE id = ?")
                    ->execute([$note, $_SESSION['user_id'], $id]);
                $pdo->commit();
                logAudit($_SESSION['user_id'], 'UPDATE', 'teachers', (int)$acct['teacher_id'], 'Sent teacher registration back for changes: ' . $acct['email']);
                flash('success', 'Sent back to the teacher with your note.');
            }
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('teacher_registrations action failed: ' . $ex->getMessage());
        flash('error', 'Could not update that registration. Check the server error log.');
    }
    $q = [];
    if (isset($_GET['status'])) { $q['status'] = (string)$_GET['status']; }
    if ($id > 0) { $q['open'] = $id; }
    redirect('teacher_registrations.php' . ($q ? '?' . http_build_query($q) : '') . ($id > 0 ? '#reg-' . $id : ''));
}

/* ---------------- Load list ---------------- */
$validFilters = ['all', 'submitted', 'approved', 'rejected', 'incomplete'];
$filter = in_array($_GET['status'] ?? 'all', $validFilters, true) ? ($_GET['status'] ?? 'all') : 'all';

$setupError = null;
$accounts   = [];
$counts     = ['all' => 0, 'submitted' => 0, 'approved' => 0, 'rejected' => 0, 'incomplete' => 0];

try {
    foreach ($pdo->query('SELECT reg_status, COUNT(*) AS c FROM treg_accounts GROUP BY reg_status')->fetchAll() as $r) {
        $counts[$r['reg_status']] = (int)$r['c'];
        $counts['all'] += (int)$r['c'];
    }
    $args = [$filter, $filter];   // "(? = 'all' OR ...)" means: no filter when the value is 'all'
    if ($hasIds) {
        $st = $pdo->prepare("SELECT a.*, t.full_name AS teacher_name, t.employee_id, t.faculty_id FROM treg_accounts a LEFT JOIN teachers t ON t.id = a.teacher_id
                              WHERE (? = 'all' OR a.reg_status = ?)
                              ORDER BY FIELD(a.reg_status,'submitted','rejected','incomplete','approved'), a.created_at DESC");
    } else {
        $st = $pdo->prepare("SELECT a.*, t.full_name AS teacher_name FROM treg_accounts a LEFT JOIN teachers t ON t.id = a.teacher_id
                              WHERE (? = 'all' OR a.reg_status = ?)
                              ORDER BY FIELD(a.reg_status,'submitted','rejected','incomplete','approved'), a.created_at DESC");
    }
    $st->execute($args);
    $accounts = $st->fetchAll();
    $displayName = fn($r) => mb_strtolower(trim(($r['teacher_name'] ?? '') ?: trim($r['first_name'] . ' ' . $r['last_name'])));
    usort($accounts, fn($x, $y) => strnatcmp($displayName($x), $displayName($y)));
} catch (PDOException $ex) {
    $setupError = 'The registration tables were not found. Import teacher-registration/sql/registration_schema.sql into this database first.';
    error_log('teacher_registrations: ' . $ex->getMessage());
}

$statusText = ['incomplete' => 'Not submitted', 'submitted' => 'Waiting for review', 'approved' => 'Approved', 'rejected' => 'Sent back'];

$pageTitle = 'Teacher Registrations';
include __DIR__ . '/includes/header.php';
?>
<h1>📝 Teacher Registrations</h1>
<p>Availability and subjects that teachers submitted through the Teacher Registration portal. Approve a teacher to make them available in the schedule builder. When you assign classes, the system checks them against what the teacher declared here.</p>

<?php if ($setupError): ?>
    <div class="alert alert-error"><?= e($setupError) ?></div>
<?php else: ?>

<?php
$missingIds = 0;
if ($hasIds) {
    try {
        $missingIds = (int)$pdo->query("SELECT COUNT(*) FROM treg_accounts a JOIN teachers t ON t.id = a.teacher_id
                                         WHERE a.reg_status = 'approved' AND (t.employee_id IS NULL OR t.employee_id = '' OR t.faculty_id IS NULL OR t.faculty_id = '')")->fetchColumn();
    } catch (Throwable $e) { $missingIds = 0; }
}
if ($missingIds > 0): ?>
<div class="alert alert-info" style="display:flex;gap:14px;align-items:center;justify-content:space-between;flex-wrap:wrap">
    <span><strong><?= $missingIds ?></strong> approved teacher<?= $missingIds === 1 ? ' has' : 's have' ?> no Employee/Faculty ID yet.</span>
    <form class="inline" method="POST" action="teacher_registrations.php?status=<?= e($filter) ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="backfill_ids">
        <button class="btn btn-gold btn-sm" type="submit">Assign IDs automatically</button>
    </form>
</div>
<?php endif; ?>

<div class="reg-tools">
    <div class="reg-search">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.3-4.3"/></svg>
        <input type="search" id="reg-search" placeholder="Search by name, email, program or ID" aria-label="Search teachers" autocomplete="off">
    </div>
    <span class="reg-count" id="reg-count" aria-live="polite"></span>
</div>

<div class="filters">
    <?php foreach ($validFilters as $f): ?>
        <a class="<?= $filter === $f ? 'on' : '' ?>" href="teacher_registrations.php?status=<?= e($f) ?>">
            <?= e($f === 'all' ? 'All' : ($statusText[$f] ?? $f)) ?> (<?= (int)$counts[$f] ?>)
        </a>
    <?php endforeach; ?>
</div>

<?php if (!$accounts): ?>
    <div class="card"><p>No registrations in this view yet.</p></div>
<?php endif; ?>

<div id="reg-list">
<?php foreach ($accounts as $a):
    $tid      = (int)($a['teacher_id'] ?? 0);
    $profile  = $tid ? trGetProfile($tid) : null;
    $problems = ($tid && $a['reg_status'] === 'approved') ? trAvailabilityViolations($tid) : [];
    $edited   = $a['reg_status'] === 'approved' && $a['profile_updated_at'] && $a['reviewed_at']
                && strtotime($a['profile_updated_at']) > strtotime($a['reviewed_at']);
    $fullName = $a['teacher_name'] ?: trim($a['first_name'] . ' ' . $a['last_name']);
?>
<?php
    $words    = preg_split('/\s+/', trim($fullName));
    $initials = mb_strtoupper(mb_substr($words[0], 0, 1) . (count($words) > 1 ? mb_substr(end($words), 0, 1) : ''));
    $hay      = mb_strtolower(implode(' ', [$fullName, $a['email'], $a['program'], $a['year_level'], $a['employee_id'] ?? '', $a['faculty_id'] ?? '']));
?>
<details class="reg-item" id="reg-<?= (int)$a['id'] ?>" data-search="<?= e($hay) ?>"<?= ((int)($_GET['open'] ?? 0) === (int)$a['id']) ? ' open' : '' ?>>
    <summary class="reg-sum">
        <span class="reg-av" aria-hidden="true"><?= e($initials) ?></span>
        <span class="reg-name"><?= e($fullName) ?></span>
        <span class="reg-sum-right">
            <span class="reg-tag <?= e($a['reg_status']) ?>"><?= e($statusText[$a['reg_status']] ?? $a['reg_status']) ?></span>
            <svg class="reg-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
        </span>
    </summary>
    <div class="reg-body">
    <div class="reg-meta">
        <?php if (!empty($a['employee_id'])): ?><span>Employee ID: <strong><?= e($a['employee_id']) ?></strong></span><?php endif; ?>
        <?php if (!empty($a['faculty_id'])): ?><span>Faculty ID: <strong><?= e($a['faculty_id']) ?></strong></span><?php endif; ?>
        <span><?= e($a['email']) ?></span>
        <span>Program: <?= e($a['program']) ?></span>
        <span><?= e($a['year_level']) ?></span>
        <?php if ($a['submitted_at']): ?><span>Submitted <?= e(date('M j, Y g:i A', strtotime($a['submitted_at']))) ?></span><?php endif; ?>
        <?php if ($edited): ?><span class="reg-tag edited">Edited since approval</span><?php endif; ?>
    </div>

    <?php if ($profile): ?>
    <div class="reg-cols">
        <div>
            <h4>Time availability</h4>
            <?php foreach (trDays() as $code => $label): if (empty($profile['availability'][$code])) continue; ?>
                <div class="reg-day"><b><?= e($label) ?></b>
                    <?= e(implode(', ', array_map(fn($w) => trClock($w['start']) . ' to ' . trClock($w['end']), $profile['availability'][$code]))) ?>
                </div>
            <?php endforeach; ?>
            <?php if (!$profile['availability']): ?><div class="reg-meta">None declared.</div><?php endif; ?>
        </div>
        <div>
            <h4>Subjects they can teach (<?= count($profile['subjects']) ?>)</h4>
            <div class="chips">
                <?php foreach ($profile['subjects'] as $s): ?><span title="<?= e($s['title']) ?>"><?= e($s['code']) ?></span><?php endforeach; ?>
            </div>
            <?php if (!$profile['subjects']): ?><div class="reg-meta">None selected.</div><?php endif; ?>
        </div>
    </div>
    <?php else: ?>
        <p class="reg-note">This teacher has an account but has not submitted the form yet.</p>
    <?php endif; ?>

    <?php if ($a['reg_status'] === 'rejected' && $a['review_note']): ?>
        <p class="reg-note">Your note to the teacher: <?= e($a['review_note']) ?></p>
    <?php endif; ?>

    <?php if ($problems): ?>
        <div class="reg-warn">
            <strong><?= count($problems) ?> assigned class<?= count($problems) === 1 ? '' : 'es' ?> now fall<?= count($problems) === 1 ? 's' : '' ?> outside this teacher's declared availability:</strong>
            <ul><?php foreach ($problems as $p): ?>
                <li><?= e($p['subject']) ?> for <?= e($p['section']) ?>, <?= e($p['day']) ?> <?= e($p['start']) ?> to <?= e($p['end']) ?></li>
            <?php endforeach; ?></ul>
            <a href="schedules.php">Open the schedule builder to adjust</a>
        </div>
    <?php endif; ?>

    <?php if ($tid): ?>
    <div class="reg-actions">
        <?php if ($a['reg_status'] !== 'approved'): ?>
            <form class="inline" method="POST" action="teacher_registrations.php?status=<?= e($filter) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                <button class="btn btn-gold btn-sm" type="submit">Approve</button>
            </form>
        <?php endif; ?>
        <form class="inline" method="POST" action="teacher_registrations.php?status=<?= e($filter) ?>"
              <?= $a['reg_status'] === 'approved' ? 'data-confirm="This deactivates the teacher in the schedule builder. Existing classes are kept. Continue?"' : '' ?>>
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
            <input type="text" name="note" maxlength="255" required placeholder="Reason shown to the teacher">
            <button class="btn btn-danger btn-sm" type="submit"><?= $a['reg_status'] === 'approved' ? 'Deactivate' : 'Send back' ?></button>
        </form>
    </div>
    <?php endif; ?>
    </div>
</details>
<?php endforeach; ?>
</div>
<p class="empty" id="reg-nomatch" hidden>No teachers match your search.</p>

<style>
.reg-tools{display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin:6px 0 14px}
.reg-search{position:relative;flex:1 1 320px;max-width:460px}
.reg-search svg{position:absolute;left:13px;top:50%;width:18px;height:18px;transform:translateY(-50%);color:#8a92ad;pointer-events:none}
.reg-search input{width:100%;padding:11px 14px 11px 40px;border:1px solid #d5d9e8;border-radius:99px;background:#fff;font:inherit}
.reg-search input:focus{border-color:#2e4db8;outline:3px solid #dbe3ff}
.reg-count{font-size:.85rem;color:#6b7190}
.reg-item{background:#fff;border:1px solid #e3e6f0;border-radius:12px;margin-bottom:10px;box-shadow:0 1px 2px rgba(20,30,80,.04)}
.reg-item[open]{border-color:#b9c6f0;box-shadow:0 6px 22px rgba(20,30,80,.08)}
.reg-sum{list-style:none;display:flex;align-items:center;gap:14px;padding:14px 18px;cursor:pointer;border-radius:12px}
.reg-sum::-webkit-details-marker{display:none}
.reg-sum:hover{background:#f6f8ff}
.reg-sum:focus-visible{outline:3px solid #9db0ee;outline-offset:-3px}
.reg-av{width:38px;height:38px;border-radius:50%;background:#e6edff;color:#1f3a9d;font-weight:700;font-size:.85rem;display:grid;place-items:center;flex:none}
.reg-name{font-weight:700;font-size:1.02rem;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.reg-sum-right{margin-left:auto;display:flex;align-items:center;gap:12px}
.reg-chev{width:20px;height:20px;color:#6b7190;transition:transform .15s}
.reg-item[open] .reg-chev{transform:rotate(180deg)}
.reg-body{padding:4px 22px 20px 70px;border-top:1px solid #eef0f6}
.reg-body .reg-meta{margin:14px 0 6px}
@media(max-width:640px){.reg-body{padding:4px 16px 18px}.reg-sum{padding:12px 14px}}
</style>
<script src="assets/js/page-teacher-registrations.js" defer></script>

<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>