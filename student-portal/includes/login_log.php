<?php
// >>> PLACE AT: student-portal/includes/login_log.php (new file)  [batch 6]
/** Records student sign-ins, sign-outs and failed attempts for the Registrar. Never breaks a page if logging fails. Never stores passwords. */
require_once __DIR__ . '/rate_limit.php';   // for rlVisitorKey()

function logStudentSignIn(string $event, ?int $studentId, string $studentNo = ''): void
{
    try {
        $pdo = getDB();
        static $ready = false;
        if (!$ready) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS stu_login_log (
                id               BIGINT AUTO_INCREMENT PRIMARY KEY,
                student_id       INT NULL,
                student_no_tried VARCHAR(20) NULL,
                event            ENUM('login','logout','failed') NOT NULL,
                ip_address       VARCHAR(45) NULL,
                user_agent       VARCHAR(255) NULL,
                created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_stu_log_time (created_at),
                KEY idx_stu_log_student (student_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $ready = true;
        }
        $pdo->prepare('INSERT INTO stu_login_log (student_id, student_no_tried, event, ip_address, user_agent) VALUES (?,?,?,?,?)')
            ->execute([
                $studentId,
                mb_substr($studentNo, 0, 20) ?: null,
                in_array($event, ['login', 'logout', 'failed'], true) ? $event : 'failed',
                rlVisitorKey(),
                mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
            ]);
    } catch (Throwable $ex) {
        error_log('logStudentSignIn: ' . $ex->getMessage());
    }
}