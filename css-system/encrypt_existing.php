<?php
// >>> PLACE AT: css-system/encrypt_existing.php (new file)  [batch 12]
/**
 * Admin-only, run once after sql/mfa_privacy.sql: encrypts teacher profile contact/address fields and student
 * mobile numbers that were saved before encryption existed. Safe to run again (already encrypted values are skipped).
 * Delete this file afterwards if you like.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireRole(['admin']);

$pdo = getDB();
$done = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $done = ['profiles' => 0, 'students' => 0];
    try {
        $sel = $pdo->query('SELECT account_id, phone, alt_phone, emergency_name, emergency_relation, emergency_phone, addr_street, barangay, birth_place, prc_license_no FROM treg_profiles');
        $upd = $pdo->prepare('UPDATE treg_profiles SET phone=?, alt_phone=?, emergency_name=?, emergency_relation=?, emergency_phone=?, addr_street=?, barangay=?, birth_place=?, prc_license_no=? WHERE account_id=?');
        foreach ($sel->fetchAll() as $r) {
            $upd->execute([
                encField($r['phone']), encField($r['alt_phone']), encField($r['emergency_name']), encField($r['emergency_relation']),
                encField($r['emergency_phone']), encField($r['addr_street']), encField($r['barangay']), encField($r['birth_place']),
                encField($r['prc_license_no']), $r['account_id'],
            ]);
            $done['profiles']++;
        }
        $sel = $pdo->query('SELECT id, mobile FROM stu_applications');
        $upd = $pdo->prepare('UPDATE stu_applications SET mobile=? WHERE id=?');
        foreach ($sel->fetchAll() as $r) {
            $upd->execute([encField($r['mobile']), $r['id']]);
            $done['students']++;
        }
        logAudit($_SESSION['user_id'], 'UPDATE', 'treg_profiles', 0, 'Encrypted existing personal data');
    } catch (Throwable $ex) {
        error_log('encrypt_existing: ' . $ex->getMessage());
        $error = 'Could not finish. Import sql/mfa_privacy.sql first and check that config/app_key.php exists and can be created.';
        $done = null;
    }
}

$pageTitle = 'Encrypt existing data';
include __DIR__ . '/includes/header.php';
?>
<h1>🔒 Encrypt existing personal data</h1>
<p>Encrypts teacher contact and address details and student mobile numbers that were saved before encryption was added. New entries are encrypted automatically.</p>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<?php if ($done): ?><div class="alert alert-success">Done. <?= (int)$done['profiles'] ?> teacher profile(s) and <?= (int)$done['students'] ?> student application(s) processed.</div><?php endif; ?>
<div class="card">
    <p><strong>Before you run it:</strong> back up the database, and keep a copy of <code>config/app_key.php</code> somewhere safe (not in the database backup). Without that key the data cannot be read again.</p>
    <form method="POST" action="encrypt_existing.php">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <button class="btn btn-gold" type="submit">Encrypt now</button>
    </form>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
