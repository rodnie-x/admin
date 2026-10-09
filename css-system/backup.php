<?php
// >>> PLACE AT: css-system/backup.php (new file)  [batch 13]
/**
 * Admin-only encrypted database backup. Builds a full SQL dump, compresses it, encrypts it with AES-256-GCM
 * (key derived from config/app_key.php) and sends it to the browser. Nothing is stored on the web server.
 * Restore: decrypt on your own computer with tools/decrypt_backup.php, then import the .sql in phpMyAdmin.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/rate_limit.php';
requireRole(['admin']);

@set_time_limit(180);
$pdo = getDB();

function bkValue(PDO $pdo, $v): string
{
    return $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string)$v : $pdo->quote((string)$v));
}

/** Full SQL dump (structure + data). Skips throw-away tables. Throws if it grows past $maxBytes. */
function bkDump(PDO $pdo, int $maxBytes): string
{
    $skip = ['rate_limits', 'staff_login_codes'];
    $out  = '-- BCP Class Scheduling System backup, ' . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $t) {
        if (in_array($t[0], $skip, true)) {
            continue;
        }
        $q      = '`' . str_replace('`', '``', (string)$t[0]) . '`';   // name comes from MySQL itself, not from the visitor
        $create = $pdo->query('SHOW CREATE TABLE ' . $q)->fetch(PDO::FETCH_NUM);
        $out   .= 'DROP TABLE IF EXISTS ' . $q . ";\n" . $create[1] . ";\n\n";

        $stmt  = $pdo->query('SELECT * FROM ' . $q);
        $batch = [];
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $batch[] = '(' . implode(',', array_map(fn($v) => bkValue($pdo, $v), $row)) . ')';
            if (count($batch) >= 100) {
                $out  .= 'INSERT INTO ' . $q . " VALUES\n" . implode(",\n", $batch) . ";\n";
                $batch = [];
            }
            if (strlen($out) > $maxBytes) {
                throw new RuntimeException('Database is larger than the in-browser backup limit.');
            }
        }
        if ($batch) {
            $out .= 'INSERT INTO ' . $q . " VALUES\n" . implode(",\n", $batch) . ";\n";
        }
        $out .= "\n";
    }
    return $out . "SET FOREIGN_KEY_CHECKS=1;\n";
}

/** BCPBK1 = compressed, BCPBK0 = not compressed (no zlib on the host). Then iv(12) tag(16) ciphertext. */
function bkEncrypt(string $plain): string
{
    $magic = function_exists('gzencode') ? 'BCPBK1' : 'BCPBK0';
    if ($magic === 'BCPBK1') {
        $plain = gzencode($plain, 9);
    }
    $key = hash_hmac('sha256', 'css-backup-v1', cryptoKey(), true);
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ct === false) {
        throw new RuntimeException('Encryption failed.');
    }
    return $magic . $iv . $tag . $ct;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (!rateLimitAllow('css_backup', 5, 3600)) {
        flash('error', 'Too many backups in the last hour. Try again later.');
        redirect('backup.php');
    }
    try {
        $blob = bkEncrypt(bkDump($pdo, 60 * 1048576));
        logAudit($_SESSION['user_id'], 'BACKUP', 'database', 0, 'Encrypted backup downloaded, ' . strlen($blob) . ' bytes, sha256 ' . substr(hash('sha256', $blob), 0, 16));
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="css-backup-' . date('Ymd-His') . '.sql.enc"');
        header('Content-Length: ' . strlen($blob));
        header('Cache-Control: no-store');
        echo $blob;
        exit;
    } catch (Throwable $ex) {
        error_log('backup.php: ' . $ex->getMessage());
        flash('error', 'The backup could not be created. Check the server error log. (Very large databases need phpMyAdmin Export instead.)');
        redirect('backup.php');
    }
}

$last = null;
try {
    $ts = $pdo->query("SELECT UNIX_TIMESTAMP(MAX(created_at)) FROM audit_log WHERE action = 'BACKUP'")->fetchColumn();
    $last = $ts ? (int)$ts : null;
} catch (Throwable $ex) { /* ignore */ }

$pageTitle = 'Backup';
include __DIR__ . '/includes/header.php';
?>
<h1>💾 Backup</h1>
<p>Downloads the whole database as one <strong>encrypted</strong> file. Even if the file is lost or stolen, it cannot be read without your <code>config/app_key.php</code>.</p>

<div class="card">
    <p><strong>Last backup:</strong> <?= $last ? e(date('M j, Y g:i A', $last)) . ' (' . (int)floor((time() - $last) / 86400) . ' day(s) ago)' : 'never recorded' ?></p>
    <form method="POST" action="backup.php">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <button class="btn btn-gold" type="submit">Download encrypted backup</button>
    </form>
</div>

<div class="card">
    <h3>Backup routine (recommended)</h3>
    <ol style="padding-left:20px;line-height:1.9">
        <li>Download a backup at least <strong>once a week</strong>, and before every semester change or big import. The dashboard warns you after 7 days.</li>
        <li>Keep copies <strong>off the web server</strong> (your computer plus a cloud drive or USB). Never leave a backup inside the website folder.</li>
        <li>Back up these separately by FTP, because the database file does not contain them: <code>config/app_key.php</code> (without it every encrypted backup and encrypted field is unreadable), <code>teacher-registration/uploads/</code> (teacher documents and photos) and the three <code>config/</code> files.</li>
        <li>Test a restore once a semester: decrypt a backup with <code>tools/decrypt_backup.php</code> on your computer and import the result into a <em>spare</em> database in phpMyAdmin.</li>
    </ol>
    <p class="hint">Honest limit: this is a manual full backup, not point-in-time recovery. Shared hosting does not give access to the database change log, and InfinityFree has no scheduled jobs, so a recovery returns to the time of your last backup. If you move to a VPS later, use daily <code>mysqldump</code> via cron plus MySQL binary logs for point-in-time recovery.</p>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
