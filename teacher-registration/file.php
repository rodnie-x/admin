<?php
/** Serves a teacher's own photo or document, only to that logged-in teacher. */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/portal.php';
requireLogin();

$account = currentAccount();
$pdo     = getDB();
$file = null; $mime = ''; $name = 'file';

if (isset($_GET['photo'])) {
    $st = $pdo->prepare('SELECT photo_file FROM treg_profiles WHERE account_id = ?');
    $st->execute([(int)$account['id']]);
    $file = $st->fetchColumn() ?: null;
    $name = 'photo';
} elseif (isset($_GET['doc'])) {
    $st = $pdo->prepare('SELECT stored_name, original_name FROM treg_documents WHERE id = ? AND account_id = ?');
    $st->execute([(int)$_GET['doc'], (int)$account['id']]);
    if ($r = $st->fetch()) { $file = $r['stored_name']; $name = $r['original_name']; }
}

$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
$path  = $file ? __DIR__ . '/uploads/' . basename($file) : '';
$mime  = $file ? ($types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? '') : '';

if (!$file || $mime === '' || !is_file($path)) {
    http_response_code(404);
    exit('File not found.');
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name) . '"');
readfile($path);
