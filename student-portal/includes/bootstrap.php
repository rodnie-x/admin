<?php
// >>> PLACE AT: student-portal/includes/bootstrap.php  (replace the old file; if it is new, just add it)
require_once __DIR__ . '/security_headers.php';   // batch 4: CSP + security headers
require_once __DIR__ . '/../config/db.php';

const APP_NAME     = 'Student Portal';
const EMAIL_DOMAIN = 'bcp.edu.ph';
const MAX_FAILS    = 5;
const LOCK_MINUTES = 15;
const STU_IDLE_SECONDS = 7200;   // log out after 2 hours with no activity
const STU_COURSES  = ['Bachelor of Science in Information Technology', 'Bachelor of Science in Computer Science', 'Bachelor of Science in Accountancy',
                      'Bachelor of Science in Business Administration', 'Bachelor of Science in Hospitality Management', 'Bachelor of Elementary Education', 'Bachelor of Secondary Education'];
const STU_YEARS    = ['1st Year College', '2nd Year College', '3rd Year College', '4th Year College'];
const STU_ADMISSION = ['New', 'Transferee', 'Continuing', 'Returnee'];

/* Sidebar: group => [subtitle, items]. Item: [slug, label, icon, badge|null, hasArrow] */
const STU_NAV = [
    ['Student Dashboard', 'SMS Account Fundamentals', [
        ['dashboard', 'Dashboard', 'pie', null, false], ['sms-profile', 'SMS Profile', 'user', null, false], ['module-grant', 'Module Grant', 'clip', null, false],
        ['permit', 'Permit', 'file', null, false], ['semestral-grade', 'Semestral Grade', 'cap', null, false]]],
    ['Enrollment', 'Enrollment Information', [
        ['enrollment', 'Enrollment', 'clip', null, true], ['registrar-forms', 'Registrar Forms', 'clip', null, false]]],
    ['Wallet and Payments', 'Billing and Wallet', [
        ['account-statement', 'Account Statement', 'clip', 'BETA', false]]],
    ['Scholarship', 'Scholarship', [
        ['scholarship', 'Scholarship', 'clip', null, false], ['scholarship-application', 'Scholarship Application', 'clip', null, true]]],
    ['Gradebook', 'Academic Progress', [
        ['lms-subjects', 'LMS Subjects', 'clip', null, false]]],
    ['Student Services', 'Non-Academic Affairs', [
        ['guidance', 'Guidance', 'scale', 'REQUIRED', true], ['sps-hotline', 'SPS Hotline', 'clip', null, false],
        ['appointment', 'Appointment', 'cal', null, false], ['events', 'Events', 'cal', null, false]]],
];

date_default_timezone_set('Asia/Manila');
/** True when the visitor reached us over HTTPS (also behind a reverse proxy such as InfinityFree's). */
function isHttpsRequest(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('bcp_student');   // own cookie, so it never mixes with the other systems on localhost
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => isHttpsRequest()]);
    session_start();
}

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function redirect(string $to): never { header('Location: ' . $to); exit; }
function csrfToken(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrfField(): string { return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">'; }
function csrfValid(): bool { return isset($_POST['csrf_token'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['csrf_token']); }
function verifyCsrf(): void { if (!csrfValid()) { http_response_code(419); exit('Your session expired. Go back, refresh the page and try again.'); } }
function flash(string $type, string $msg): void { $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg]; }
function takeFlashes(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }
function str(array $a, string $k): string { return isset($a[$k]) && is_string($a[$k]) ? $a[$k] : ''; }

function currentStudent(): ?array
{
    static $cache = false;
    if ($cache !== false) { return $cache; }
    $cache = null;
    if (!empty($_SESSION['student_id'])) {
        $now = time();
        if (($now - (int)($_SESSION['last_activity'] ?? $now)) > STU_IDLE_SECONDS) {
            $_SESSION = [];                      // idle too long: sign the student out
            session_regenerate_id(true);
        } else {
            $_SESSION['last_activity'] = $now;
            $st = getDB()->prepare('SELECT * FROM stu_students WHERE id = ?');
            $st->execute([(int)$_SESSION['student_id']]);
            $row = $st->fetch() ?: null;
            // Kill-switch: password changed/reset elsewhere signs old sessions out.
            if ($row && (int)($row['session_epoch'] ?? 0) !== (int)($_SESSION['stu_epoch'] ?? 0)) {
                $_SESSION = [];
                session_regenerate_id(true);
                $row = null;
            }
            $cache = $row;
        }
    }
    return $cache;
}
function requireStudent(): array
{
    $s = currentStudent();
    if (!$s) { redirect('login.php'); }
    // The Registrar issues a temporary password: the student must replace it with their own first
    if (!empty($s['must_change_password']) && basename($_SERVER['SCRIPT_NAME']) !== 'change_password.php') { redirect('change_password.php'); }
    return $s;
}
function studentName(array $s): string { return trim(preg_replace('/\s+/', ' ', $s['first_name'] . ' ' . $s['middle_name'] . ' ' . $s['last_name'])); }
function studentInitials(array $s): string { return mb_strtoupper(mb_substr($s['first_name'], 0, 1) . mb_substr($s['last_name'], 0, 1)); }
function studentEmail(array $s): string { return $s['student_no'] . '@' . EMAIL_DOMAIN; }

function icon(string $n): string
{
    $p = [
        'pie'   => '<path d="M21 12A9 9 0 1112 3v9z"/><path d="M15 3.5A9 9 0 0120.5 9H15z"/>',
        'user'  => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0116 0"/>',
        'clip'  => '<rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4V3h6v1M9 11h6M9 15h4"/>',
        'file'  => '<rect x="3" y="4" width="16" height="16" rx="2"/><path d="M7 9h8M7 13h8M7 17h4M21 8v10a2 2 0 01-2 2"/>',
        'cap'   => '<path d="M22 10L12 5 2 10l10 5 10-5z"/><path d="M6 12v5c3 2.5 9 2.5 12 0v-5"/>',
        'scale' => '<path d="M12 3v18M5 21h14M7 7h10M7 7l-3 7a3 3 0 006 0zM17 7l-3 7a3 3 0 006 0z"/>',
        'cal'   => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'chev'  => '<path d="M9 6l6 6-6 6"/>',
        'bell'  => '<path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 01-3.4 0"/>',
        'mega'  => '<path d="M3 11v2a1 1 0 001 1h3l7 4V6L7 10H4a1 1 0 00-1 1zM18 8a5 5 0 010 8"/>',
        'menu'  => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'out'   => '<path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$n] ?? '') . '</svg>';
}

function navHref(string $slug): string { return $slug === 'dashboard' ? 'dashboard.php' : 'page.php?p=' . urlencode($slug); }

function layoutStart(array $stu, string $title, string $crumb, string $active): void
{
    ?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?> | <?= e(APP_NAME) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css">
</head><body>
<div class="app">
<aside class="sidebar" id="sidebar">
  <div class="sb-top"><span class="brand-mark">BCP</span><span class="sb-icons"><?= icon('bell') ?><?= icon('user') ?></span></div>
  <div class="sb-user">
    <div class="avatar"><?= e(studentInitials($stu)) ?></div>
    <div class="sb-name"><?= e(studentName($stu)) ?></div>
    <div class="sb-mail"><?= e(studentEmail($stu)) ?></div>
  </div>
  <?php foreach (STU_NAV as [$group, $sub, $items]): ?>
    <nav class="sb-group" aria-label="<?= e($group) ?>">
      <div class="sb-head"><b><?= e(mb_strtoupper($group)) ?></b><small><?= e($sub) ?></small></div>
      <?php foreach ($items as [$slug, $label, $ic, $badge, $arrow]): ?>
        <a class="sb-link <?= $active === $slug ? 'active' : '' ?>" href="<?= e(navHref($slug)) ?>">
          <?= icon($ic) ?><span><?= e($label) ?></span>
          <?php if ($badge): ?><span class="sb-badge <?= $badge === 'BETA' ? 'beta' : 'req' ?>"><?= e($badge) ?></span><?php endif; ?>
          <?php if ($arrow): ?><span class="sb-arrow"><?= icon('chev') ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
  <?php endforeach; ?>
  <form class="sb-logout" method="POST" action="logout.php"><?= csrfField() ?><button type="submit"><?= icon('out') ?>Log out</button></form>
</aside>
<div class="main">
  <header class="topbar">
    <button class="burger" id="burger" type="button" aria-label="Toggle menu"><?= icon('menu') ?></button>
    <span class="clock" id="clock"></span>
  </header>
  <div class="page-head"><a class="crumb" href="dashboard.php"><?= e($crumb) ?></a><h1><?= e($title) ?></h1></div>
  <div class="content">
  <?php foreach (takeFlashes() as $f): ?><div class="alert alert-<?= e($f['type']) ?>" role="status"><?= e($f['msg']) ?></div><?php endforeach; ?>
<?php }

function layoutEnd(): void
{
    ?>
  </div>
</div>
</div>
<script src="assets/js/app.js" defer></script>
</body></html><?php
}