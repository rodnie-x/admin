<?php
// >>> PLACE AT: teacher-registration/includes/login_log.php (replace the old file)  [batch 6: failed sign-ins]
/** Records teacher sign-ins, sign-outs, failed attempts and "last active" time for the admin's Audit Log. Never breaks a page if logging fails. */

function logTeacherSignIn(string $event, ?int $accountId, string $email = ''): ?int
{
    try {
        $pdo = getDB();
        $pdo->prepare('INSERT INTO treg_login_log (account_id, email_tried, event, ip_address, user_agent) VALUES (?,?,?,?,?)')
            ->execute([
                $accountId,
                mb_substr($email, 0, 150) ?: null,
                in_array($event, ['login', 'logout', 'failed'], true) ? $event : 'login',
                mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
                mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
            ]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $ex) {
        // If "event" is still an ENUM without 'failed', run: ALTER TABLE treg_login_log MODIFY event VARCHAR(10) NOT NULL;
        error_log('logTeacherSignIn: ' . $ex->getMessage());
        return null;
    }
}

function touchTeacherSignIn(int $rowId): void
{
    try {
        getDB()->prepare('UPDATE treg_login_log SET last_seen = NOW() WHERE id = ?')->execute([$rowId]);
    } catch (Throwable $ex) {
        // last_seen column not added yet: ignore, sign-ins are still recorded
    }
}

/** Called from header.php on every page: logs one "login" per new session, then refreshes "last active" at most once a minute. */
function trackTeacherSignIn(int $accountId, string $email): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) { return; }
    $sid = session_id();

    if (($_SESSION['treg_login_logged'] ?? '') !== $sid) {
        $_SESSION['treg_login_logged'] = $sid;
        $id = logTeacherSignIn('login', $accountId, $email);
        $_SESSION['treg_login_row'] = $id ?: 0;
        $_SESSION['treg_seen_at']   = time();
        if ($id) { touchTeacherSignIn($id); }
        return;
    }

    $row = (int)($_SESSION['treg_login_row'] ?? 0);
    if ($row > 0 && time() - (int)($_SESSION['treg_seen_at'] ?? 0) >= 60) {
        $_SESSION['treg_seen_at'] = time();
        touchTeacherSignIn($row);
    }
}