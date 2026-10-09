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
        $type = in_array($_POST['type'] ?? '', ['lecture','laboratory','other']) ? $_POST['type'] : 'lecture';
        $capacity = (int)($_POST['capacity'] ?? 40);
        $status = $_POST['status'] === 'under_maintenance' ? 'under_maintenance' : 'available';

        if ($name === '') {
            flash('error', 'Room name is required.');
        } else if ($id > 0) {
            $pdo->prepare("UPDATE rooms SET name=?, type=?, capacity=?, status=? WHERE id=?")
                ->execute([$name, $type, $capacity, $status, $id]);
            logAudit($_SESSION['user_id'], 'UPDATE', 'rooms', $id, "Updated room $name");
            flash('success', 'Room updated.');
        } else {
            try {
                $pdo->prepare("INSERT INTO rooms (name, type, capacity, status) VALUES (?,?,?,?)")
                    ->execute([$name, $type, $capacity, $status]);
                logAudit($_SESSION['user_id'], 'CREATE', 'rooms', $pdo->lastInsertId(), "Created room $name");
                flash('success', 'Room added.');
            } catch (PDOException $e) {
                flash('error', 'Room name must be unique.');
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        try {
            $pdo->prepare("DELETE FROM rooms WHERE id=?")->execute([$id]);
            logAudit($_SESSION['user_id'], 'DELETE', 'rooms', $id);
            flash('success', 'Room removed.');
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                flash('error', 'Cannot delete this room because it is still assigned to a schedule.');
            } else {
                flash('error', 'An error occurred while deleting the room.');
            }
        }
    }
    redirect('rooms.php');
}

$editRow = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id=?");
    $stmt->execute([(int)$_GET['edit']]);
    $editRow = $stmt->fetch();
}
$rooms = $pdo->query("SELECT * FROM rooms ORDER BY name")->fetchAll();

$pageTitle = 'Rooms';
include __DIR__ . '/includes/header.php';
?>
<h1>🚪 Rooms</h1>

<div class="card">
    <h3><?= $editRow ? 'Edit Room' : 'Add Room' ?></h3>
    <form method="POST" action="rooms.php">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($editRow['id'] ?? 0) ?>">
        <div class="form-grid">
            <div><label>Room Name</label><input type="text" name="name" required value="<?= e($editRow['name'] ?? '') ?>"></div>
            <div><label>Type</label>
                <select name="type">
                    <?php foreach (['lecture','laboratory','other'] as $t): ?>
                        <option value="<?= $t ?>" <?= (($editRow['type'] ?? 'lecture')===$t)?'selected':'' ?>><?= ucfirst($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label>Capacity</label><input type="number" name="capacity" value="<?= e((string)($editRow['capacity'] ?? 40)) ?>"></div>
            <div><label>Status</label>
                <select name="status">
                    <option value="available" <?= (($editRow['status'] ?? 'available')==='available')?'selected':'' ?>>Available</option>
                    <option value="under_maintenance" <?= (($editRow['status'] ?? '')==='under_maintenance')?'selected':'' ?>>Under Maintenance</option>
                </select>
            </div>
        </div>
        <br>
        <button class="btn btn-gold" type="submit"><?= $editRow ? 'Update Room' : 'Add Room' ?></button>
        <?php if ($editRow): ?><a class="btn btn-secondary" href="rooms.php">Cancel</a><?php endif; ?>
    </form>
</div>

<div class="card">
    <h3>All Rooms</h3>
    <table>
        <tr><th>Name</th><th>Type</th><th>Capacity</th><th>Status</th><th>Actions</th></tr>
        <?php foreach ($rooms as $r): ?>
        <tr>
            <td><?= e($r['name']) ?></td>
            <td><?= e(ucfirst($r['type'])) ?></td>
            <td><?= e((string)$r['capacity']) ?></td>
            <td><span class="badge <?= $r['status']==='available'?'badge-active':'badge-inactive' ?>"><?= e($r['status']) ?></span></td>
            <td>
                <a class="btn btn-sm" href="rooms.php?edit=<?= $r['id'] ?>">Edit</a>
                <form class="inline" method="POST" action="rooms.php" data-confirm="Delete this room?">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                    <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<div class="card">
    <h3>🔎 Room Availability Checker</h3>
    <p>Find rooms that are free for a given day &amp; time.</p>
    <form method="GET" action="rooms.php" class="form-grid">
        <div><label>Day</label>
            <select name="check_day">
                <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat'] as $d): ?>
                <option value="<?= $d ?>" <?= (($_GET['check_day'] ?? '')===$d)?'selected':'' ?>><?= $d ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div><label>Start Time</label><input type="time" name="check_start" value="<?= e($_GET['check_start'] ?? '') ?>"></div>
        <div><label>End Time</label><input type="time" name="check_end" value="<?= e($_GET['check_end'] ?? '') ?>"></div>
        <div><label>Semester</label>
            <select name="check_sem">
                <?php foreach (['1st','2nd','summer'] as $s): ?>
                <option value="<?= $s ?>" <?= (($_GET['check_sem'] ?? '')===$s)?'selected':'' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div><label>School Year</label><input type="text" name="check_sy" placeholder="2026-2027" value="<?= e($_GET['check_sy'] ?? '') ?>"></div>
        <div style="align-self:end"><button class="btn" type="submit">Check Availability</button></div>
    </form>

    <?php if (!empty($_GET['check_day']) && !empty($_GET['check_start']) && !empty($_GET['check_end']) && !empty($_GET['check_sy'])):
        $free = findAvailableRooms($_GET['check_day'], $_GET['check_start'], $_GET['check_end'], $_GET['check_sem'], $_GET['check_sy']);
    ?>
        <h4 style="margin-top:18px">Available rooms:</h4>
        <?php if (empty($free)): ?>
            <p>No rooms are free at that time.</p>
        <?php else: ?>
            <ul><?php foreach ($free as $f): ?><li><?= e($f['name']) ?> (<?= e(ucfirst($f['type'])) ?>, capacity <?= e((string)$f['capacity']) ?>)</li><?php endforeach; ?></ul>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

