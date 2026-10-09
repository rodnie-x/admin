<?php
// >>> PLACE AT: css-system/includes/retention.php (new)  [batch 12]
/**
 * Data retention (Data Privacy Act: keep personal data only as long as needed).
 * InfinityFree has no cron, so dashboard.php calls this and it runs on about 1 in 50 page loads.
 * The Audit Log (audit_log table) is NOT touched here: keep it for your school's required period.
 */
function retentionMaybePurge(): void
{
    if (random_int(1, 50) !== 1) {
        return;
    }
    $pdo = getDB();
    try { $pdo->exec("DELETE FROM staff_login_codes WHERE expires_at < (NOW() - INTERVAL 1 DAY)"); } catch (Throwable $e) { /* table not created yet */ }
    try { $pdo->exec("DELETE FROM treg_login_log WHERE created_at < (NOW() - INTERVAL 180 DAY)"); } catch (Throwable $e) { /* table not created yet */ }
    try { $pdo->exec("DELETE FROM stu_login_log WHERE created_at < (NOW() - INTERVAL 180 DAY)"); } catch (Throwable $e) { /* table not created yet */ }
    try { $pdo->exec("DELETE FROM stu_applications WHERE status = 'rejected' AND submitted_at < (NOW() - INTERVAL 365 DAY)"); } catch (Throwable $e) { /* table not created yet */ }
}
