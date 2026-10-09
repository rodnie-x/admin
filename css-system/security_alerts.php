<?php
// >>> PLACE AT: css-system/security_alerts.php (new file)  [batch 13]
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/anomaly.php';
requireRole(['admin']);

$pdo = getDB();
$pdo->exec("SET time_zone = '+08:00'");   // show Philippine time, like the Audit Log
anomalyEnsureTable($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'ack') {
        $pdo->prepare('UPDATE security_alerts SET acknowledged_by = ?, acknowledged_at = NOW() WHERE id = ? AND acknowledged_at IS NULL')
            ->execute([$_SESSION['user_id'], (int)($_POST['id'] ?? 0)]);
        flash('success', 'Alert acknowledged.');
    } elseif ($action === 'ack_all') {
        $pdo->prepare('UPDATE security_alerts SET acknowledged_by = ?, acknowledged_at = NOW() WHERE acknowledged_at IS NULL')->execute([$_SESSION['user_id']]);
        logAudit($_SESSION['user_id'], 'UPDATE', 'security_alerts', 0, 'Acknowledged all security alerts');
        flash('success', 'All alerts acknowledged.');
    }
    redirect('security_alerts.php');
}

$fresh  = anomalyScan();
$alerts = $pdo->query('SELECT * FROM security_alerts ORDER BY (acknowledged_at IS NULL) DESC, id DESC LIMIT 100')->fetchAll();
$open   = opsStatus();

$pageTitle = 'Security alerts';
include __DIR__ . '/includes/header.php';
?>
<h1>🚨 Security alerts</h1>
<p>Unusual activity found in the audit log and sign-in logs: bursts of failed sign-ins, mass changes or deletions, changes at night, sign-ins from a new address and blocked attacks. The scan runs each time you open this page or the dashboard. High alerts are also emailed to the admins.</p>

<div class="stat-grid">
    <div class="stat-card"><div class="num" style="color:#a5201a"><?= (int)$open['alerts']['high'] ?></div><div class="label">High, not acknowledged</div></div>
    <div class="stat-card"><div class="num" style="color:#a36d00"><?= (int)$open['alerts']['medium'] ?></div><div class="label">Medium, not acknowledged</div></div>
    <div class="stat-card"><div class="num"><?= count($fresh) ?></div><div class="label">New in this scan</div></div>
</div>

<div class="card">
    <?php if ($open['alerts']['high'] + $open['alerts']['medium'] + $open['alerts']['low'] > 0): ?>
    <form method="POST" action="security_alerts.php" style="margin-bottom:12px">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="ack_all">
        <button class="btn btn-sm btn-secondary" type="submit">Acknowledge all</button>
    </form>
    <?php endif; ?>
    <table>
        <tr><th>When</th><th>Severity</th><th>What happened</th><th>Status</th></tr>
        <?php if (!$alerts): ?><tr><td colspan="4">No alerts yet. Nothing unusual has been detected.</td></tr><?php endif; ?>
        <?php foreach ($alerts as $a): $cls = ['high' => 'badge-inactive', 'medium' => '', 'low' => 'badge-active'][$a['severity']] ?? ''; ?>
        <tr>
            <td><?= e(date('M j, g:i A', strtotime($a['created_at']))) ?></td>
            <td><span class="badge <?= e($cls) ?>"><?= e(ucfirst($a['severity'])) ?></span></td>
            <td><?= e($a['message']) ?></td>
            <td>
                <?php if ($a['acknowledged_at']): ?>
                    <span class="hint">Acknowledged</span>
                <?php else: ?>
                    <form class="inline" method="POST" action="security_alerts.php">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <input type="hidden" name="action" value="ack">
                        <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                        <button class="btn btn-sm" type="submit">Acknowledge</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <p class="hint" style="margin:12px 0 0">Alerts are leads, not proof: a night-time change may be a late-working registrar. Check the Audit Log for the full story. For scans while nobody is logged in, a free uptime pinger can open the dashboard on a schedule only if it can log in, so the realistic option on this hosting is to open this page daily.</p>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
