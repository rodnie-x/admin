<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireRole(['admin','registrar','program_head']);

$pdo = getDB();
// Only the Admin and Registrar can issue Employee / Faculty IDs
$canAssignIds = in_array($_SESSION['role'] ?? '', ['admin', 'registrar'], true);

// --- Handle Create / Update / Delete ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['full_name'] ?? '');
        $dept = trim($_POST['department'] ?? '');
        $maxUnits = (int)($_POST['max_units'] ?? 24);
        $status = ($_POST['status'] ?? '') === 'inactive' ? 'inactive' : 'active';
        $empId = strtoupper(trim($_POST['employee_id'] ?? ''));
        $facId = strtoupper(trim($_POST['faculty_id'] ?? ''));

        if ($name === '') {
            flash('error', 'Teacher name is required.');
        } elseif ($canAssignIds && (!preg_match('/^[A-Z0-9-]{0,30}$/', $empId) || !preg_match('/^[A-Z0-9-]{0,30}$/', $facId))) {
            flash('error', 'Employee ID and Faculty ID may only contain letters, numbers and dashes.');
        } else {
            try {
                if ($id > 0) {
                    if ($canAssignIds) {
                        $pdo->prepare("UPDATE teachers SET full_name=?, department=?, max_units=?, status=?, employee_id=?, faculty_id=? WHERE id=?")
                            ->execute([$name, $dept, $maxUnits, $status, $empId ?: null, $facId ?: null, $id]);
                    } else {
                        $pdo->prepare("UPDATE teachers SET full_name=?, department=?, max_units=?, status=? WHERE id=?")
                            ->execute([$name, $dept, $maxUnits, $status, $id]);
                    }
                    logAudit($_SESSION['user_id'], 'UPDATE', 'teachers', $id, "Updated teacher $name");
                    flash('success', 'Teacher updated.');
                } else {
                    if ($canAssignIds) {
                        $pdo->prepare("INSERT INTO teachers (full_name, department, max_units, status, employee_id, faculty_id) VALUES (?,?,?,?,?,?)")
                            ->execute([$name, $dept, $maxUnits, $status, $empId ?: null, $facId ?: null]);
                    } else {
                        $pdo->prepare("INSERT INTO teachers (full_name, department, max_units, status) VALUES (?,?,?,?)")
                            ->execute([$name, $dept, $maxUnits, $status]);
                    }
                    logAudit($_SESSION['user_id'], 'CREATE', 'teachers', $pdo->lastInsertId(), "Created teacher $name");
                    flash('success', 'Teacher added.');
                }
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    flash('error', 'That Employee ID or Faculty ID is already assigned to another teacher.');
                } else {
                    error_log('teachers.php: ' . $e->getMessage());
                    flash('error', 'Could not save. Import sql/teacher_ids.sql into the database first.');
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        try {
            $pdo->prepare("DELETE FROM teachers WHERE id=?")->execute([$id]);
            logAudit($_SESSION['user_id'], 'DELETE', 'teachers', $id);
            flash('success', 'Teacher removed.');
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                flash('error', 'Cannot delete this teacher because they are still assigned to a schedule.');
            } else {
                flash('error', 'An error occurred while deleting the teacher.');
            }
        }
    }
    redirect('teachers.php');
}

$editRow = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM teachers WHERE id=?");
    $stmt->execute([(int)$_GET['edit']]);
    $editRow = $stmt->fetch();
}

$teachers = $pdo->query("SELECT * FROM teachers ORDER BY full_name")->fetchAll();

$pageTitle = 'Teachers';
include __DIR__ . '/includes/header.php';
?>
<h1>👩‍🏫 Teachers</h1>

<div class="card">
    <h3><?= $editRow ? 'Edit Teacher' : 'Add Teacher' ?></h3>
    <form method="POST" action="teachers.php">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($editRow['id'] ?? 0) ?>">
        <div class="form-grid">
            <div>
                <label>Full Name</label>
                <input type="text" name="full_name" required value="<?= e($editRow['full_name'] ?? '') ?>">
            </div>
            <?php if ($canAssignIds): ?>
            <div>
                <label>Employee ID</label>
                <input type="text" name="employee_id" maxlength="30" placeholder="EMP-2026-0001" value="<?= e($editRow['employee_id'] ?? '') ?>">
            </div>
            <div>
                <label>Faculty ID</label>
                <input type="text" name="faculty_id" maxlength="30" placeholder="FAC-2026-0001" value="<?= e($editRow['faculty_id'] ?? '') ?>">
            </div>
            <?php endif; ?>
            <div>
                <label>Department</label>
                <input type="text" name="department" value="<?= e($editRow['department'] ?? '') ?>">
            </div>
            <div>
                <label>Max Units</label>
                <input type="number" name="max_units" min="1" max="40" value="<?= e((string)($editRow['max_units'] ?? 24)) ?>">
            </div>
            <div>
                <label>Status</label>
                <select name="status">
                    <option value="active" <?= (($editRow['status'] ?? 'active')==='active')?'selected':'' ?>>Active</option>
                    <option value="inactive" <?= (($editRow['status'] ?? '')==='inactive')?'selected':'' ?>>Inactive</option>
                </select>
            </div>
        </div>
        <?php if ($canAssignIds): ?><p class="hint" style="margin-top:10px">The Employee ID and Faculty ID you enter here appear (locked) on the teacher's profile in the Teacher Registration portal.</p><?php endif; ?>
        <br>
        <button class="btn btn-gold" type="submit"><?= $editRow ? 'Update Teacher' : 'Add Teacher' ?></button>
        <?php if ($editRow): ?><a class="btn btn-secondary" href="teachers.php">Cancel</a><?php endif; ?>
    </form>
</div>

<div class="card">
    <h3>All Teachers</h3>
    <table>
        <tr><th>Name</th><th>Employee ID</th><th>Faculty ID</th><th>Department</th><th>Max Units</th><th>Status</th><th>Actions</th></tr>
        <?php foreach ($teachers as $t): ?>
        <tr>
            <td><?= e($t['full_name']) ?></td>
            <td><?= ($t['employee_id'] ?? '') !== '' ? e($t['employee_id']) : '<span class="hint">Not assigned</span>' ?></td>
            <td><?= ($t['faculty_id'] ?? '') !== '' ? e($t['faculty_id']) : '<span class="hint">Not assigned</span>' ?></td>
            <td><?= e($t['department']) ?></td>
            <td><?= e((string)$t['max_units']) ?></td>
            <td><span class="badge badge-<?= $t['status'] ?>"><?= e($t['status']) ?></span></td>
            <td>
                <a class="btn btn-sm" href="teachers.php?edit=<?= $t['id'] ?>">Edit</a>
                <form class="inline" method="POST" action="teachers.php" data-confirm="Delete this teacher?">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $t['id'] ?>">
                    <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

