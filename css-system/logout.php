<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Log out only through the POST form (with CSRF token) so a stray link or image can't log anyone out.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isLoggedIn()) {
    verifyCsrf();
    doLogout();
}
header('Location: login.php');
exit;
