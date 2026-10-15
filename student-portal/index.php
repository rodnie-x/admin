<?php
require_once __DIR__ . '/includes/bootstrap.php';
redirect(currentStudent() ? 'dashboard.php' : 'login.php');
