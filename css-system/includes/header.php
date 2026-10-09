<?php /* PLACE AT: css-system/includes/header.php  (replace the old file) */ /* Expects $pageTitle. Opens the app shell; footer.php closes it. */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle ?? 'Dashboard') ?> | Class Scheduling System</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="app">
<?php include __DIR__ . '/sidebar.php'; ?>
<div class="main">
  <header class="topbar">
    <button class="burger" id="burger" aria-label="Toggle menu"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
    <span class="crumb">Dashboard<?= ($pageTitle ?? 'Dashboard') !== 'Dashboard' ? ' / ' . e($pageTitle) : '' ?></span>
    <span class="clock" id="clock"></span>
  </header>
  <div class="content">
<?php
/* Flash messages (batch 4 fix): functions.php defines getFlashes() and stores the text under 'message'. */
foreach (getFlashes() as $f) {
    echo '<div class="alert alert-' . e($f['type']) . '" role="status">' . e($f['message']) . '</div>';
}