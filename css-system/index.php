<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
redirect(isLoggedIn() ? 'dashboard.php' : 'login.php');
