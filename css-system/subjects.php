<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireRole(['admin','registrar','program_head']);

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $code = trim($_POST['code'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $units = (float)($_POST['units'] ?? 3);
        $lec = (float)($_POST['lecture_hours'] ?? 3);
        $lab = (float)($_POST['lab_hours'] ?? 0);

        if ($code === '' || $title === '') {
            flash('error', 'Subject code and title are required.');
        } else if ($id > 0) {
            $pdo->prepare("UPDATE subjects SET code=?, title=?, units=?, lecture_hours=?, lab_hours=? WHERE id=?")
                ->execute([$code, $title, $units, $lec, $lab, $id]);
            logAudit($_SESSION['user_id'], 'UPDATE', 'subjects', $id, "Updated subject $code");
            flash('success', 'Subject updated.');
        } else {
            try {
                $pdo->prepare("INSERT INTO subjects (code, title, units, lecture_hours, lab_hours) VALUES (?,?,?,?,?)")
                    ->execute([$code, $title, $units, $lec, $lab]);
                logAudit($_SESSION['user_id'], 'CREATE', 'subjects', $pdo->lastInsertId(), "Created subject $code");
                flash('success', 'Subject added.');
            } catch (PDOException $e) {
                flash('error', 'Subject code must be unique.');
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        try {
            $pdo->prepare("DELETE FROM subjects WHERE id=?")->execute([$id]);
            logAudit($_SESSION['user_id'], 'DELETE', 'subjects', $id);
            flash('success', 'Subject removed.');
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                flash('error', 'Cannot delete this subject because it is still assigned to a schedule.');
            } else {
                flash('error', 'An error occurred while deleting the subject.');
            }
        }
    }
    redirect('subjects.php');
}

$editRow = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM subjects WHERE id=?");
    $stmt->execute([(int)$_GET['edit']]);
    $editRow = $stmt->fetch();
}
$subjects = $pdo->query("SELECT * FROM subjects ORDER BY code")->fetchAll();

$pageTitle = 'Subjects';
include __DIR__ . '/includes/header.php';
?>
<h1>📘 Subjects</h1>

<div class="card">
    <h3><?= $editRow ? 'Edit Subject' : 'Add Subject' ?></h3>
    <form method="POST" action="subjects.php">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($editRow['id'] ?? 0) ?>">
        <div class="form-grid">
            <div><label>Code</label><input type="text" name="code" required value="<?= e($editRow['code'] ?? '') ?>"></div>
            <div><label>Title</label><input type="text" name="title" required value="<?= e($editRow['title'] ?? '') ?>"></div>
            <div><label>Units</label><input type="number" step="0.5" name="units" value="<?= e((string)($editRow['units'] ?? 3)) ?>"></div>
            <div><label>Lecture Hours</label><input type="number" step="0.5" name="lecture_hours" value="<?= e((string)($editRow['lecture_hours'] ?? 3)) ?>"></div>
            <div><label>Lab Hours</label><input type="number" step="0.5" name="lab_hours" value="<?= e((string)($editRow['lab_hours'] ?? 0)) ?>"></div>
        </div>
        <br>
        <button class="btn btn-gold" type="submit"><?= $editRow ? 'Update Subject' : 'Add Subject' ?></button>
        <?php if ($editRow): ?><a class="btn btn-secondary" href="subjects.php">Cancel</a><?php endif; ?>
    </form>
</div>

<div class="card">
    <h3>All Subjects</h3>
    <table>
        <tr><th>Code</th><th>Title</th><th>Units</th><th>Lec</th><th>Lab</th><th>Actions</th></tr>
        <?php foreach ($subjects as $s): ?>
        <tr>
            <td><?= e($s['code']) ?></td>
            <td><?= e($s['title']) ?></td>
            <td><?= e((string)$s['units']) ?></td>
            <td><?= e((string)$s['lecture_hours']) ?></td>
            <td><?= e((string)$s['lab_hours']) ?></td>
            <td>
                <a class="btn btn-sm" href="subjects.php?edit=<?= $s['id'] ?>">Edit</a>
                <form class="inline" method="POST" action="subjects.php" data-confirm="Delete this subject?">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                    <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

