<?php
// >>> PLACE AT: css-system/student_signins.php (new file)  [batch 6]
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireRole(['admin', 'registrar']);

$pdo = getDB();
$ev  = (isset($_GET['event']) && is_string($_GET['event']) && in_array($_GET['event'], ['login', 'logout', 'failed'], true)) ? $_GET['event'] : '';
$q   = (isset($_GET['q']) && is_string($_GET['q'])) ? mb_substr(trim($_GET['q']), 0, 20) : '';

$rows = []; $stats = ['login' => 0, 'failed' => 0]; $missing = false;
try {
    foreach ($pdo->query("SELECT event, COUNT(*) c FROM stu_login_log WHERE created_at >= CURDATE() GROUP BY event")->fetchAll() as $r) {
        $stats[$r['event']] = (int)$r['c'];
    }
    // One fixed query: an empty filter value ('') means "no filter", so no SQL text is built from variables.
    $like = $q !== '' ? '%' . addcslashes($q, '%_\\') . '%' : '';
    $st = $pdo->prepare("SELECT l.event, l.ip_address, l.created_at, l.student_no_tried, s.student_no, s.first_name, s.last_name
               FROM stu_login_log l LEFT JOIN stu_students s ON s.id = l.student_id
              WHERE (? = '' OR l.event = ?)
                AND (? = '' OR l.student_no_tried LIKE ? OR s.student_no LIKE ?)
              ORDER BY l.created_at DESC, l.id DESC LIMIT 300");
    $st->execute([$ev, $ev, $like, $like, $like]);
    $rows = $st->fetchAll();
} catch (PDOException $ex) {
    $missing = true;
    error_log('student_signins: ' . $ex->getMessage());
}
$label = ['login' => 'Signed in', 'logout' => 'Signed out', 'failed' => 'Failed attempt'];
$cls   = ['login' => 'badge-active', 'logout' => '', 'failed' => 'badge-inactive'];

$pageTitle = 'Student Sign-ins';
include __DIR__ . '/includes/header.php';
?>
<h1>🎓 Student Sign-ins</h1>
<p>Who signed in to the Student Portal, and failed attempts. Passwords are never recorded.</p>

<?php if ($missing): ?>
    <div class="alert alert-info">No student sign-ins have been recorded yet. The log starts after the updated Student Portal files are uploaded and a student logs in.</div>
<?php else: ?>
<div class="stat-grid">
    <div class="stat-card"><div class="num"><?= (int)$stats['login'] ?></div><div class="label">Student sign-ins today</div></div>
    <div class="stat-card"><div class="num"><?= (int)$stats['failed'] ?></div><div class="label">Failed attempts today</div></div>
</div>

<form method="GET" action="student_signins.php" class="form-grid" style="margin-bottom:16px">
    <div><label for="q">Student ID</label><input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="e.g. 260000001"></div>
    <div><label for="event">Show</label>
        <select id="event" name="event">
            <option value="">All events</option>
            <?php foreach ($label as $k => $lb): ?><option value="<?= e($k) ?>" <?= $ev === $k ? 'selected' : '' ?>><?= e($lb) ?></option><?php endforeach; ?>
        </select></div>
    <div style="align-self:end"><button class="btn btn-sm" type="submit">Apply</button> <a class="btn btn-sm btn-secondary" href="student_signins.php">Clear</a></div>
</form>

<div class="card">
    <table>
        <tr><th>When</th><th>Student</th><th>Event</th><th>IP</th></tr>
        <?php if (!$rows): ?><tr><td colspan="4">Nothing found.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r):
            $name = $r['student_no'] ? trim($r['first_name'] . ' ' . $r['last_name']) : '(unknown Student ID)'; ?>
        <tr>
            <td><?= e($r['created_at']) ?></td>
            <td><strong><?= e($name) ?></strong><div class="hint"><?= e((string)($r['student_no'] ?: $r['student_no_tried'])) ?></div></td>
            <td><span class="badge <?= e($cls[$r['event']] ?? '') ?>"><?= e($label[$r['event']] ?? $r['event']) ?></span></td>
            <td><?= e((string)$r['ip_address']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <p class="hint" style="margin:12px 0 0">Latest 300 entries.</p>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>