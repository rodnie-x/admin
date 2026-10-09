<?php
// >>> PLACE AT: css-system/includes/anomaly.php (new)  [batch 13]
/**
 * Suspicious-activity detection. Reads the audit log (and the teacher/student sign-in logs) and raises alerts.
 * There is no cron on InfinityFree, so the scan runs when the admin opens the dashboard or security_alerts.php.
 * High-severity alerts are also emailed to the active admin accounts (only on the live server).
 */

function anomalyEnsureTable(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS security_alerts (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        fingerprint     VARCHAR(120) NOT NULL,
        rule            VARCHAR(40)  NOT NULL,
        severity        ENUM('high','medium','low') NOT NULL,
        message         VARCHAR(255) NOT NULL,
        created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        acknowledged_by INT NULL,
        acknowledged_at DATETIME NULL,
        UNIQUE KEY uq_fp (fingerprint),
        KEY idx_open (acknowledged_at, severity)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/**
 * Runs all rules. The fingerprint makes sure the same event raises only one alert.
 * @return array<int, array{0:string,1:string}> NEW alerts as [severity, message]
 */
function anomalyScan(): array
{
    $new = [];
    try {
        $pdo = getDB();
        anomalyEnsureTable($pdo);
        $add = function (string $fp, string $rule, string $sev, string $msg) use ($pdo, &$new): void {
            $st = $pdo->prepare('INSERT IGNORE INTO security_alerts (fingerprint, rule, severity, message) VALUES (?,?,?,?)');
            $st->execute([substr($fp, 0, 120), $rule, $sev, mb_substr($msg, 0, 255)]);
            if ($st->rowCount() === 1) {
                $new[] = [$sev, $msg];
            }
        };

        $now    = time();
        $win    = $now - 600;                 // last 10 minutes
        $bucket = intdiv($now, 600);
        $manila = new DateTimeZone('Asia/Manila');
        $change = ['CREATE', 'UPDATE', 'DELETE', 'CLONE'];

        $rows = $pdo->query("SELECT a.id, a.user_id, a.action, a.details, a.ip_address, UNIX_TIMESTAMP(a.created_at) AS ts, u.username, r.name AS role_name
                               FROM audit_log a
                               LEFT JOIN users u ON u.id = a.user_id
                               LEFT JOIN roles r ON r.id = u.role_id
                              WHERE a.created_at >= (NOW() - INTERVAL 1 DAY)
                              ORDER BY a.id DESC LIMIT 3000")->fetchAll();

        $seen = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE user_id = ? AND action = 'LOGIN' AND ip_address = ? AND id < ? AND created_at >= (NOW() - INTERVAL 90 DAY)");
        $any  = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE user_id = ? AND action = 'LOGIN' AND id < ?");

        $failed = 0; $failIp = []; $changes = []; $deletes = []; $names = [];
        foreach ($rows as $r) {
            $ts  = (int)$r['ts'];
            $act = (string)$r['action'];
            $uid = (int)$r['user_id'];
            $who = (string)($r['username'] ?? 'unknown user');
            $names[$uid] = $who;

            if ($ts >= $win) {
                if ($act === 'LOGIN_FAILED' || $act === 'MFA_FAILED') {
                    $failed++;
                    $ip = (string)$r['ip_address'];
                    $failIp[$ip] = ($failIp[$ip] ?? 0) + 1;
                }
                if (in_array($act, $change, true)) { $changes[$uid] = ($changes[$uid] ?? 0) + 1; }
                if ($act === 'DELETE')             { $deletes[$uid] = ($deletes[$uid] ?? 0) + 1; }
            }

            if ($ts >= $now - 3600) {
                $when = (new DateTimeImmutable('@' . $ts))->setTimezone($manila);
                if (in_array($act, $change, true)) {
                    $h = (int)$when->format('G');
                    if ($h >= 22 || $h < 5) {
                        $add('off:' . $uid . ':' . intdiv($ts, 3600), 'off_hours', 'medium',
                            $who . ' changed data at ' . $when->format('g:i A') . ' Philippine time (' . $act . '). Changes between 10 PM and 5 AM are unusual.');
                    }
                }
                if ($act === 'MFA_RECOVERY') {
                    $add('mfarec:' . $r['id'], 'mfa_recovery', 'medium', $who . ' signed in with a recovery code (their authenticator app was not used).');
                }
                if ($act === 'UPDATE' && stripos((string)$r['details'], 'Two-step login reset') !== false) {
                    $add('mfareset:' . $r['id'], 'mfa_reset', 'medium', 'Two-step login was reset for an account. Confirm it was a real request.');
                }
                if ($act === 'LOGIN' && in_array((string)$r['role_name'], ['admin', 'registrar'], true) && !empty($r['ip_address'])) {
                    $seen->execute([$uid, $r['ip_address'], $r['id']]);
                    $any->execute([$uid, $r['id']]);
                    if ((int)$seen->fetchColumn() === 0 && (int)$any->fetchColumn() > 0) {
                        $add('newip:' . $r['id'], 'new_ip', 'medium', $who . ' (' . $r['role_name'] . ') signed in from a new IP address: ' . $r['ip_address'] . '.');
                    }
                }
            }
        }

        if ($failed >= 15) {
            $add('failed:' . $bucket, 'failed_logins', 'high', $failed . ' failed staff sign-ins or two-step codes in the last 10 minutes. Someone may be guessing passwords.');
        }
        foreach ($failIp as $ip => $n) {
            if ($n >= 10) {
                $add('ipfail:' . $ip . ':' . $bucket, 'ip_burst', 'high', 'IP ' . $ip . ' caused ' . $n . ' failed sign-ins in 10 minutes.');
            }
        }
        foreach ($deletes as $uid => $n) {
            if ($n >= 5) {
                $add('del:' . $uid . ':' . $bucket, 'mass_delete', 'high', ($names[$uid] ?? 'A user') . ' deleted ' . $n . ' records in 10 minutes.');
            }
        }
        foreach ($changes as $uid => $n) {
            if ($n >= 40) {
                $add('chg:' . $uid . ':' . $bucket, 'mass_changes', 'medium', ($names[$uid] ?? 'A user') . ' made ' . $n . ' changes in 10 minutes.');
            }
        }

        // Teacher portal and student portal sign-in logs
        foreach ([
            ['Teacher portal', 'treg_login_log', "SELECT COUNT(*) FROM treg_login_log WHERE event = 'failed' AND created_at >= (NOW() - INTERVAL 10 MINUTE)"],
            ['Student portal', 'stu_login_log',  "SELECT COUNT(*) FROM stu_login_log WHERE event = 'failed' AND created_at >= (NOW() - INTERVAL 10 MINUTE)"],
        ] as [$label, $key, $sql]) {
            try {
                $n = (int)$pdo->query($sql)->fetchColumn();
                if ($n >= 15) {
                    $add($key . ':' . $bucket, 'portal_failed', 'high', $label . ': ' . $n . ' failed sign-ins in the last 10 minutes.');
                }
            } catch (Throwable $ex) { /* that log table does not exist yet */ }
        }

        // Requests the built-in request filter (includes/waf.php) blocked
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM rate_limits WHERE bucket = 'waf_hit' AND hit_at >= ?");
            $st->execute([date('Y-m-d H:i:s', $now - 3600)]);
            $n = (int)$st->fetchColumn();
            if ($n >= 20) {
                $add('waf:' . intdiv($now, 3600), 'waf_blocks', 'medium', 'The request filter blocked ' . $n . ' malicious-looking requests in the last hour.');
            }
        } catch (Throwable $ex) { /* table not created yet */ }

        $high = array_values(array_map(fn($n) => $n[1], array_filter($new, fn($n) => $n[0] === 'high')));
        if ($high) {
            anomalyNotify($pdo, $high);
        }
    } catch (Throwable $ex) {
        error_log('anomalyScan: ' . $ex->getMessage());
    }
    return $new;
}

/** Emails the active admins. Does nothing on localhost or when config/mail.php is not filled in. */
function anomalyNotify(PDO $pdo, array $messages): void
{
    try {
        require_once __DIR__ . '/local_request.php';
        if (isLocalRequest()) {
            return;
        }
        require_once __DIR__ . '/mailer.php';
        $to = $pdo->query("SELECT u.email FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'admin' AND u.status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
        $text = "The Class Scheduling System raised high-severity security alerts:\n\n- " . implode("\n- ", $messages)
              . "\n\nOpen Security Alerts in the system to review and acknowledge them.\n";
        foreach ($to as $addr) {
            cssSmtpDeliver((string)$addr, 'Security alert: Class Scheduling System', $text);
        }
    } catch (Throwable $ex) {
        error_log('anomalyNotify: ' . $ex->getMessage());
    }
}

/** For the dashboard: unacknowledged alerts by severity, and days since the last backup (null = never). */
function opsStatus(): array
{
    $out = ['alerts' => ['high' => 0, 'medium' => 0, 'low' => 0], 'backup_days' => null];
    try {
        $pdo = getDB();
        anomalyEnsureTable($pdo);
        foreach ($pdo->query('SELECT severity, COUNT(*) c FROM security_alerts WHERE acknowledged_at IS NULL GROUP BY severity')->fetchAll() as $r) {
            $out['alerts'][$r['severity']] = (int)$r['c'];
        }
        $ts = $pdo->query("SELECT UNIX_TIMESTAMP(MAX(created_at)) FROM audit_log WHERE action = 'BACKUP'")->fetchColumn();
        $out['backup_days'] = $ts ? (int)floor((time() - (int)$ts) / 86400) : null;
    } catch (Throwable $ex) {
        error_log('opsStatus: ' . $ex->getMessage());
    }
    return $out;
}
