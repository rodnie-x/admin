<?php
// >>> PLACE AT: css-system/student_enrollments.php  (replace the old file; if it is new, just add it)
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireRole(['admin', 'registrar']);

$pdo = getDB();

/** Random 10-character password (no look-alike characters), always with a capital, a small letter and a number. */
function enPassword(): string
{
    $all = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    do {
        $pw = '';
        for ($i = 0; $i < 10; $i++) { $pw .= $all[random_int(0, strlen($all) - 1)]; }
    } while (!preg_match('/[A-Z]/', $pw) || !preg_match('/[a-z]/', $pw) || !preg_match('/\d/', $pw));
    return $pw;
}
function enName(array $r): string { return trim(preg_replace('/\s+/', ' ', $r['first_name'] . ' ' . ($r['middle_name'] ?? '') . ' ' . $r['last_name'])); }

$sections = [];
try { $sections = $pdo->query('SELECT DISTINCT name FROM sections ORDER BY name')->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) { $sections = []; }

$validFilters = ['pending', 'approved', 'rejected', 'all'];
$filter = in_array($_GET['status'] ?? 'pending', $validFilters, true) ? ($_GET['status'] ?? 'pending') : 'pending';

/* ---------------- Actions ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'approve') {
            $id  = (int)($_POST['id'] ?? 0);
            $no  = trim((string)($_POST['student_no'] ?? ''));
            $sec = trim((string)($_POST['section'] ?? ''));
            $adv = mb_substr(trim((string)($_POST['adviser'] ?? '')), 0, 120);
            $st = $pdo->prepare("SELECT * FROM stu_applications WHERE id = ? AND status <> 'approved'");
            $st->execute([$id]);
            $app = $st->fetch();

            if (!$app) {
                flash('error', 'That application was already approved or no longer exists.');
            } elseif (!preg_match('/^\d{6,12}$/', $no)) {
                flash('error', 'Student ID must be 6 to 12 digits.');
            } elseif ($sections && !in_array($sec, $sections, true)) {
                flash('error', 'Choose a section for the student.');
            } else {
                $pw = enPassword();   // random; only its hash is stored (batch 4: no longer guessable from the email)
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO stu_students (student_no, first_name, middle_name, last_name, password_hash, course, admission_type, year_level, section, adviser, must_change_password, application_id)
                               VALUES (?,?,?,?,?,?,?,?,?,?,1,?)')
                    ->execute([$no, $app['first_name'], $app['middle_name'], $app['last_name'], password_hash($pw, PASSWORD_DEFAULT), $app['course'],
                               $app['admission_type'], $app['year_level'], $sec ?: null, $adv ?: null, $id]);
                $sid = (int)$pdo->lastInsertId();
                $pdo->prepare("UPDATE stu_applications SET status='approved', review_note=NULL, reviewed_by=?, reviewed_at=NOW(), student_id=? WHERE id=?")
                    ->execute([$_SESSION['user_id'], $sid, $id]);
                $pdo->commit();
                logAudit($_SESSION['user_id'], 'UPDATE', 'stu_applications', $id, 'Approved enrollment of ' . enName($app) . ' as student ' . $no . ', section ' . $sec);
                $_SESSION['new_credentials'] = ['name' => enName($app), 'student_no' => $no, 'password' => $pw, 'section' => $sec, 'course' => $app['course'], 'reset' => false];
                flash('success', 'Enrollment approved. Give the student the Student ID and password shown below.');
            }
        } elseif ($action === 'reject') {
            $id = (int)($_POST['id'] ?? 0);
            $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255);
            if ($note === '') {
                flash('error', 'Write a short reason so the student knows what to fix.');
            } else {
                $pdo->prepare("UPDATE stu_applications SET status='rejected', review_note=?, reviewed_by=?, reviewed_at=NOW() WHERE id=? AND status='pending'")
                    ->execute([$note, $_SESSION['user_id'], $id]);
                logAudit($_SESSION['user_id'], 'UPDATE', 'stu_applications', $id, 'Enrollment not approved');
                flash('success', 'Marked as not approved. The student sees your note when checking their status.');
            }
        } elseif ($action === 'reset_password') {
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare('SELECT * FROM stu_students WHERE id = ?');
            $st->execute([$id]);
            if ($stu = $st->fetch()) {
                $pw = enPassword();
                $pdo->prepare('UPDATE stu_students SET password_hash = ?, must_change_password = 1, failed_attempts = 0, locked_until = NULL WHERE id = ?')
                    ->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
                logAudit($_SESSION['user_id'], 'UPDATE', 'stu_students', $id, 'Student password reset by registrar');
                $_SESSION['new_credentials'] = ['name' => enName($stu), 'student_no' => $stu['student_no'], 'password' => $pw, 'section' => (string)$stu['section'], 'course' => $stu['course'], 'reset' => true];
                flash('success', 'New temporary password created. Give it to the student below.');
            }
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $dup = $ex instanceof PDOException && $ex->getCode() === '23000';
        error_log('student_enrollments: ' . $ex->getMessage());
        flash('error', $dup ? 'That Student ID is already used by another student.' : 'Could not save. Import student-portal/sql/enrollment_schema.sql into the database first.');
    }
    redirect('student_enrollments.php?status=' . urlencode($filter));
}

$cred = $_SESSION['new_credentials'] ?? null;   // shown once, never stored in the database
unset($_SESSION['new_credentials']);

/* ---------------- Load ---------------- */
$setupError = null; $apps = []; $students = [];
$counts = ['all' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
$base = 0;
try {
    foreach ($pdo->query('SELECT status, COUNT(*) c FROM stu_applications GROUP BY status')->fetchAll() as $r) { $counts[$r['status']] = (int)$r['c']; $counts['all'] += (int)$r['c']; }
    $st = $pdo->prepare("SELECT a.*, s.student_no, s.section AS assigned_section FROM stu_applications a LEFT JOIN stu_students s ON s.id = a.student_id WHERE (? = 'all' OR a.status = ?) ORDER BY a.last_name, a.first_name");
    $st->execute([$filter, $filter]);
    $apps = $st->fetchAll();
    $students = $pdo->query('SELECT id, student_no, first_name, middle_name, last_name, course, year_level, section, must_change_password FROM stu_students ORDER BY last_name, first_name')->fetchAll();
    $yy = date('y');   // suggested next Student ID: 2-digit year + running number, e.g. 260000001
    $mq = $pdo->prepare("SELECT COALESCE(MAX(CAST(student_no AS UNSIGNED)), 0) FROM stu_students WHERE student_no REGEXP ?");
    $mq->execute(['^' . $yy . '[0-9]{7}$']);
    $maxNo = (int)$mq->fetchColumn();
    $base = $maxNo > 0 ? $maxNo + 1 : (int)($yy . '0000001');
} catch (PDOException $ex) {
    $setupError = 'The enrollment tables were not found. Import student-portal/sql/enrollment_schema.sql into this database first.';
    error_log('student_enrollments: ' . $ex->getMessage());
}
$statusText = ['pending' => 'Waiting for review', 'approved' => 'Approved', 'rejected' => 'Not approved'];

$pageTitle = 'Student Enrollments';
include __DIR__ . '/includes/header.php';
?>
<h1>🎓 Student Enrollments</h1>
<p>Applications sent from the Student Portal. Approve a student to give them a Student ID, a section and their own password.</p>

<?php if ($setupError): ?><div class="alert alert-error"><?= e($setupError) ?></div><?php else: ?>

<?php if ($cred): ?>
<div class="card cred-slip">
    <h3>🔑 <?= $cred['reset'] ? 'New temporary password' : 'Enrollment approved' ?>: give this to the student now</h3>
    <p><strong>This password is shown only once.</strong> The student must replace it with their own the first time they log in. If it is lost, use <em>Reset password</em> below.</p>
    <table class="cred-table">
        <tr><th>Student</th><td><?= e($cred['name']) ?></td></tr>
        <?php if ($cred['course']): ?><tr><th>Course</th><td><?= e($cred['course']) ?></td></tr><?php endif; ?>
        <?php if ($cred['section'] !== ''): ?><tr><th>Section</th><td><?= e($cred['section']) ?></td></tr><?php endif; ?>
        <tr><th>Student ID</th><td class="cred-code"><?= e($cred['student_no']) ?></td></tr>
        <tr><th>Temporary password</th><td class="cred-code"><?= e($cred['password']) ?></td></tr>
    </table>
    <button type="button" class="btn btn-sm no-print" data-print>Print this slip</button>
</div>
<?php endif; ?>

<div class="en-tools">
    <div class="en-search"><input type="search" id="en-q" placeholder="Search by name, email or course" aria-label="Search" autocomplete="off"></div>
    <div class="filters" style="margin:0">
        <?php foreach ($validFilters as $f): ?>
            <a class="<?= $filter === $f ? 'on' : '' ?>" href="student_enrollments.php?status=<?= e($f) ?>"><?= e($f === 'all' ? 'All' : $statusText[$f]) ?> (<?= (int)$counts[$f] ?>)</a>
        <?php endforeach; ?>
    </div>
</div>

<?php if (!$apps): ?><div class="card"><p>No applications in this view.</p></div><?php endif; ?>
<div id="en-list">
<?php $idx = 0; foreach ($apps as $a):
    $name = enName($a);
    $hay  = mb_strtolower($name . ' ' . $a['personal_email'] . ' ' . $a['course'] . ' ' . $a['year_level']);
?>
<details class="en-item" data-q="<?= e($hay) ?>">
    <summary class="en-sum">
        <span class="en-name"><?= e($name) ?></span>
        <span class="en-meta"><?= e($a['year_level']) ?> &middot; <?= e($a['admission_type']) ?></span>
        <span class="en-pill en-<?= e($a['status']) ?>"><?= e($statusText[$a['status']]) ?></span>
    </summary>
    <div class="en-body">
        <div class="en-grid">
            <div><b>Course</b><?= e($a['course']) ?></div>
            <div><b>Email</b><?= e($a['personal_email']) ?></div>
            <div><b>Mobile</b><?= e($a['mobile']) ?></div>
            <div><b>Birthdate</b><?= e(date('M j, Y', strtotime($a['birthdate']))) ?></div>
            <div><b>Last school</b><?= e($a['last_school'] ?: '—') ?></div>
            <div><b>Reference</b>ENR-<?= e(str_pad((string)$a['id'], 5, '0', STR_PAD_LEFT)) ?> &middot; sent <?= e(date('M j, Y g:i A', strtotime($a['submitted_at']))) ?></div>
        </div>

        <?php if ($a['status'] === 'approved'): ?>
            <p class="en-ok">Approved. Student ID <strong><?= e((string)$a['student_no']) ?></strong>, section <strong><?= e((string)($a['assigned_section'] ?: '—')) ?></strong>.</p>
        <?php else: ?>
            <?php if ($a['status'] === 'rejected' && $a['review_note']): ?><p class="reg-note">Your note to the student: <?= e($a['review_note']) ?></p><?php endif; ?>
            <form method="POST" action="student_enrollments.php?status=<?= e($filter) ?>" class="en-approve">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                <div><label>Student ID</label><input type="text" name="student_no" required pattern="\d{6,12}" inputmode="numeric" value="<?= e((string)($base + $idx)) ?>"></div>
                <div><label>Section</label>
                    <?php if ($sections): ?>
                        <select name="section" required><option value="">Choose section</option>
                            <?php foreach ($sections as $sn): ?><option value="<?= e($sn) ?>"><?= e($sn) ?></option><?php endforeach; ?></select>
                    <?php else: ?><input type="text" name="section" maxlength="60" placeholder="Add sections first"><?php endif; ?></div>
                <div><label>Adviser <span class="hint">(optional)</span></label><input type="text" name="adviser" maxlength="120"></div>
                <div class="en-act"><button class="btn btn-gold btn-sm" type="submit">Approve and create password</button></div>
            </form>
            <?php if ($a['status'] === 'pending'): ?>
            <form method="POST" action="student_enrollments.php?status=<?= e($filter) ?>" class="inline en-reject">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                <input type="text" name="note" maxlength="255" required placeholder="Reason shown to the student">
                <button class="btn btn-danger btn-sm" type="submit">Not approved</button>
            </form>
            <?php endif; ?>
            <?php $idx++; ?>
        <?php endif; ?>
    </div>
</details>
<?php endforeach; ?>
</div>

<div class="card" style="margin-top:26px">
    <h3>Enrolled students (<?= count($students) ?>)</h3>
    <table>
        <tr><th>Student ID</th><th>Name</th><th>Course</th><th>Year</th><th>Section</th><th>Password</th><th>Actions</th></tr>
        <?php if (!$students): ?><tr><td colspan="7">No enrolled students yet.</td></tr><?php endif; ?>
        <?php foreach ($students as $s): ?>
        <tr>
            <td><?= e($s['student_no']) ?></td>
            <td><strong><?= e(enName($s)) ?></strong></td>
            <td><?= e($s['course']) ?></td>
            <td><?= e($s['year_level']) ?></td>
            <td><?= e((string)($s['section'] ?: '—')) ?></td>
            <td><?= $s['must_change_password'] ? '<span class="badge">Temporary, not changed yet</span>' : '<span class="badge badge-active">Set by student</span>' ?></td>
            <td>
                <form class="inline" method="POST" action="student_enrollments.php?status=<?= e($filter) ?>" data-confirm="Create a new temporary password for this student? Their old password will stop working.">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                    <button class="btn btn-sm" type="submit">Reset password</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<?php endif; ?>

<style>
.en-tools{display:flex;flex-wrap:wrap;gap:14px;align-items:center;margin:0 0 16px}
.en-search{flex:1 1 280px;max-width:420px}
.en-item{background:#fff;border:1px solid #e3e6f0;border-radius:12px;margin-bottom:10px}
.en-item[open]{border-color:#b9c6f0;box-shadow:0 6px 22px rgba(20,30,80,.08)}
.en-sum{list-style:none;display:flex;align-items:center;gap:14px;padding:14px 18px;cursor:pointer;border-radius:12px}
.en-sum::-webkit-details-marker{display:none}.en-sum:hover{background:#f6f8ff}
.en-name{font-weight:700;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.en-meta{color:#6b7190;font-size:.85rem}
.en-pill{margin-left:auto;padding:3px 12px;border-radius:99px;font-size:.75rem;font-weight:700;white-space:nowrap}
.en-pending{background:#fff4d6;color:#7a5a00}.en-approved{background:#e3f5ec;color:#16794c}.en-rejected{background:#fdeceb;color:#a5201a}
.en-body{padding:6px 22px 20px;border-top:1px solid #eef0f6}
.en-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px 22px;margin:14px 0}
.en-grid b{display:block;font-size:.75rem;color:#6b7190;font-weight:600}
.en-approve{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;align-items:end;margin-top:6px;padding:14px;background:#f7f9ff;border:1px solid #e3e9fb;border-radius:10px}
.en-reject{margin-top:12px}.en-reject input{width:260px}
.en-ok{margin:10px 0 0;color:#16794c}
.cred-slip{border:2px solid #f5b301;background:#fffbea}
.cred-table{width:auto;margin:10px 0 14px}.cred-table th{width:170px;text-transform:none;font-size:.85rem}
.cred-code{font-family:Consolas,'Courier New',monospace;font-size:1.3rem;font-weight:700;letter-spacing:.06em}
@media print{body *{visibility:hidden}.cred-slip,.cred-slip *{visibility:visible}.cred-slip{position:absolute;left:0;top:0;width:100%;border:2px solid #000}.no-print{display:none!important}}
</style>
<script src="assets/js/page-student-enrollments.js" defer></script>
<?php include __DIR__ . '/includes/footer.php'; ?>