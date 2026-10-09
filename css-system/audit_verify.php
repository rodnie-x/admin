<?php
// >>> PLACE AT: css-system/audit_verify.php (new file)  [batch 13]
/**
 * Checks the tamper-evident audit trail: every record carries a signed fingerprint (HMAC) that also covers the
 * previous record. Editing, deleting or re-ordering a protected record breaks the chain from that point on.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireRole(['admin']);

@set_time_limit(60);
$pdo = getDB();
$res = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    try {
        $res = ['checked' => 0, 'unchained' => 0, 'problems' => [], 'start' => null, 'head' => '', 'total' => 0];
        $stmt = $pdo->prepare('SELECT id, user_id, action, table_name, record_id, details, ip_address, UNIX_TIMESTAMP(created_at) AS ts, prev_hash, row_hash
                                 FROM audit_log WHERE id > ? ORDER BY id LIMIT 1000');
        $after = 0;
        $expectPrev = '';
        while (true) {
            $stmt->execute([$after]);
            $rows = $stmt->fetchAll();
            if (!$rows) {
                break;
            }
            foreach ($rows as $r) {
                $after = (int)$r['id'];
                $res['total']++;
                if ($r['row_hash'] === null || $r['row_hash'] === '') {
                    if ($res['start'] !== null) { $res['unchained']++; }   // written after the chain began but without a fingerprint
                    continue;
                }
                if ($res['start'] === null) {
                    $res['start'] = $after;                                  // first protected record: older ones are not covered
                } elseif (!hash_equals($expectPrev, (string)$r['prev_hash']) && count($res['problems']) < 30) {
                    $res['problems'][] = 'Record #' . $after . ': the record before it was changed, removed or unsigned.';
                }
                if (!hash_equals(auditRowHash($r, (string)$r['prev_hash']), (string)$r['row_hash']) && count($res['problems']) < 30) {
                    $res['problems'][] = 'Record #' . $after . ': its content does not match its fingerprint (edited).';
                }
                $expectPrev = (string)$r['row_hash'];
                $res['head'] = $expectPrev;
                $res['checked']++;
            }
        }
        logAudit($_SESSION['user_id'], 'VERIFY', 'audit_log', 0, 'Audit chain check: ' . $res['checked'] . ' records, ' . count($res['problems']) . ' problem(s)');
    } catch (Throwable $ex) {
        error_log('audit_verify: ' . $ex->getMessage());
        $error = 'Could not check the chain. Import sql/ops_security.sql first and make sure config/app_key.php exists.';
        $res = null;
    }
}

$pageTitle = 'Verify audit trail';
include __DIR__ . '/includes/header.php';
?>
<h1>🔗 Verify audit trail</h1>
<p>Every new audit record is signed and linked to the one before it. This check recalculates the signatures. Records written before this feature was installed are not covered.</p>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<?php if ($res): ?>
    <?php if (!$res['problems']): ?>
        <div class="alert alert-success"><strong>The chain is intact.</strong> <?= (int)$res['checked'] ?> protected record(s) checked<?= $res['start'] ? ', starting at record #' . (int)$res['start'] : '' ?>.</div>
    <?php else: ?>
        <div class="alert alert-error"><strong>Tampering or data loss detected.</strong>
            <ul><?php foreach ($res['problems'] as $p): ?><li><?= e($p) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>
    <?php if ($res['unchained'] > 0): ?>
        <div class="alert alert-info"><?= (int)$res['unchained'] ?> record(s) written after the chain began have no fingerprint (usually the signing step was skipped under heavy load or the key file was missing at that moment).</div>
    <?php endif; ?>
    <?php if ($res['head'] !== ''): ?>
    <div class="card">
        <h3>Latest fingerprint</h3>
        <p style="font-family:Consolas,'Courier New',monospace;word-break:break-all"><?= e($res['head']) ?></p>
        <p class="hint">Write this down or email it to yourself now and then. A chain cannot show that its newest records were cut off, but a saved fingerprint can: if the chain no longer contains it later, records were removed.</p>
    </div>
    <?php endif; ?>
<?php endif; ?>

<div class="card">
    <form method="POST" action="audit_verify.php">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <button class="btn btn-gold" type="submit">Check the audit trail now</button>
    </form>
    <p class="hint" style="margin:12px 0 0">Do not delete user accounts: the database sets the user of their audit records to empty, which changes those records and the check will flag them. Deactivate accounts instead (the system already does this).</p>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
