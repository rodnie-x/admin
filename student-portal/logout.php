<?php
// >>> PLACE AT: student-portal/logout.php (replace the old file)  [batch 6: logs the sign-out]
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/login_log.php';
// POST only (with CSRF token) so a stray link or image can't log anyone out
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfValid()) {
    $stu = currentStudent();                       // record who is signing out before the session is destroyed
    if ($stu) { logStudentSignIn('logout', (int)$stu['id'], (string)$stu['student_no']); }
    $_SESSION = [];
    session_destroy();
    session_start();
    flash('success', 'You have been logged out.');
}
redirect('login.php');