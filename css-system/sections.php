<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireRole(['admin','registrar']);

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $program = trim($_POST['program'] ?? '');
        $year = trim($_POST['year_level'] ?? '');
        $sem = in_array($_POST['semester'] ?? '', ['1st','2nd','summer']) ? $_POST['semester'] : '1st';
        $sy = trim($_POST['school_year'] ?? '');
        $count = (int)($_POST['student_count'] ?? 0);

        if ($name === '' || $sy === '') {
            flash('error', 'Section name and school year are required.');
        } else if ($id > 0) {
            $pdo->prepare("UPDATE sections SET name=?, program=?, year_level=?, semester=?, school_year=?, student_count=? WHERE id=?")
                ->execute([$name, $program, $year, $sem, $sy, $count, $id]);
            logAudit($_SESSION['user_id'], 'UPDATE', 'sections', $id, "Updated section $name");
            flash('success', 'Section updated.');
        } else {
            try {
                $pdo->prepare("INSERT INTO sections (name, program, year_level, semester, school_year, student_count) VALUES (?,?,?,?,?,?)")
                    ->execute([$name, $program, $year, $sem, $sy, $count]);
                logAudit($_SESSION['user_id'], 'CREATE', 'sections', $pdo->lastInsertId(), "Created section $name");
                flash('success', 'Section added.');
            } catch (PDOException $e) {
                flash('error', 'That section already exists for this semester/school year.');
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        try {
            $pdo->prepare("DELETE FROM sections WHERE id=?")->execute([$id]);
            logAudit($_SESSION['user_id'], 'DELETE', 'sections', $id);
            flash('success', 'Section removed.');
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                flash('error', 'Cannot delete this section because it is still assigned to a schedule.');
            } else {
                flash('error', 'An error occurred while deleting the section.');
            }
        }
    }
    redirect('sections.php');
}

$editRow = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM sections WHERE id=?");
    $stmt->execute([(int)$_GET['edit']]);
    $editRow = $stmt->fetch();
}
$sections = $pdo->query("SELECT * FROM sections ORDER BY school_year DESC, semester, name")->fetchAll();

$pageTitle = 'Sections';
include __DIR__ . '/includes/header.php';
?>
<h1>🏫 Sections</h1>

<div class="card">
    <h3><?= $editRow ? 'Edit Section' : 'Add Section' ?></h3>
    <form method="POST" action="sections.php">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($editRow['id'] ?? 0) ?>">
        <div class="form-grid">
            <div><label>Section Name</label><input type="text" name="name" required placeholder="BSIT 3-1" value="<?= e($editRow['name'] ?? '') ?>"></div>
            <div><label>Program</label><input type="text" name="program" placeholder="BSIT" value="<?= e($editRow['program'] ?? '') ?>"></div>
            <div><label>Year Level</label><input type="text" name="year_level" placeholder="3rd Year" value="<?= e($editRow['year_level'] ?? '') ?>"></div>
            <div><label>Semester</label>
                <select name="semester">
                    <?php foreach (['1st','2nd','summer'] as $s): ?>
                    <option value="<?= $s ?>" <?= (($editRow['semester'] ?? '1st')===$s)?'selected':'' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label>School Year</label><input type="text" name="school_year" placeholder="2026-2027" required value="<?= e($editRow['school_year'] ?? '') ?>"></div>
            <div><label>Student Count</label><input type="number" name="student_count" value="<?= e((string)($editRow['student_count'] ?? 0)) ?>"></div>
        </div>
        <br>
        <button class="btn btn-gold" type="submit"><?= $editRow ? 'Update Section' : 'Add Section' ?></button>
        <?php if ($editRow): ?><a class="btn btn-secondary" href="sections.php">Cancel</a><?php endif; ?>
    </form>
</div>

<div class="card">
    <h3>All Sections</h3>
    <table>
        <tr><th>Name</th><th>Program</th><th>Year</th><th>Semester</th><th>School Year</th><th>Students</th><th>Actions</th></tr>
        <?php foreach ($sections as $s): ?>
        <tr>
            <td><?= e($s['name']) ?></td>
            <td><?= e($s['program']) ?></td>
            <td><?= e($s['year_level']) ?></td>
            <td><?= e($s['semester']) ?></td>
            <td><?= e($s['school_year']) ?></td>
            <td><?= e((string)$s['student_count']) ?></td>
            <td>
                <a class="btn btn-sm" href="schedules.php?section_id=<?= $s['id'] ?>">View Schedule</a>
                <a class="btn btn-sm" href="sections.php?edit=<?= $s['id'] ?>">Edit</a>
                <form class="inline" method="POST" action="sections.php" data-confirm="Delete this section and its schedules?">
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

