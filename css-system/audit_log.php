<?php
// >>> PLACE AT: css-system/audit_log.php  (replace the old file)  [SQL review: fixed queries]
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireRole(['admin']);

$pdo  = getDB();
$view = ($_GET['view'] ?? 'signins') === 'activity' ? 'activity' : 'signins';

/* ---------- helpers ---------- */
function alDuration(?string $from, ?string $to): string
{
    if (!$from || !$to) { return '—'; }
    $s = max(0, strtotime($to) - strtotime($from));
    if ($s < 60)   { return $s . 's'; }
    if ($s < 3600) { return floor($s / 60) . 'm ' . str_pad((string)($s % 60), 2, '0', STR_PAD_LEFT) . 's'; }
    return floor($s / 3600) . 'h ' . str_pad((string)floor(($s % 3600) / 60), 2, '0', STR_PAD_LEFT) . 'm';
}
function alAgo(int $sec): string
{
    if ($sec < 60)    { return 'just now'; }
    if ($sec < 3600)  { return floor($sec / 60) . ' min ago'; }
    if ($sec < 86400) { return floor($sec / 3600) . ' h ago'; }
    return floor($sec / 86400) . ' d ago';
}
function alWhen(?string $t): string { return $t ? date('M j, Y g:i:s A', strtotime($t)) : '—'; }
function alDevice(?string $ua): string
{
    if (!$ua) { return '—'; }
    $b = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/OPR\/|Opera/', $ua) ? 'Opera' : (preg_match('/Chrome\//', $ua) ? 'Chrome'
       : (preg_match('/Firefox\//', $ua) ? 'Firefox' : (preg_match('/Safari\//', $ua) ? 'Safari' : 'Browser'))));
    $o = preg_match('/Android/', $ua) ? 'Android' : (preg_match('/iPhone|iPad/', $ua) ? 'iOS' : (preg_match('/Windows/', $ua) ? 'Windows'
       : (preg_match('/Mac OS X/', $ua) ? 'macOS' : (preg_match('/Linux/', $ua) ? 'Linux' : ''))));
    return $b . ($o ? ' on ' . $o : '');
}

$sessions = []; $stats = ['today' => 0, 'teachers' => 0, 'failed' => 0, 'active' => 0]; $activeNow = []; $nowTs = time(); $activeWindow = 15 * 60; $teacherLogMissing = false;
$whoIn  = (string)($_GET['who'] ?? 'all');
$who    = in_array($whoIn, ['all', 'teacher', 'staff', 'active'], true) ? $whoIn : 'all';
$dateIn = (string)($_GET['date'] ?? '');
$date   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateIn) ? $dateIn : '';
$logs = [];

if ($view === 'signins') {
    $events = []; $n = 0;

    if (!in_array($who, ['teacher', 'active'], true)) {   // staff: Admin / Registrar / Program Head / Faculty accounts of this system
        foreach ($pdo->query("SELECT a.action, a.ip_address, a.created_at, a.user_id, u.username, u.full_name, r.name AS role_name
                                FROM audit_log a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN roles r ON r.id = u.role_id
                               WHERE a.action = 'LOGOUT' OR a.action LIKE '%LOGIN%'
                               ORDER BY a.created_at DESC LIMIT 1500")->fetchAll() as $r) {
            $ev = $r['action'] === 'LOGOUT' ? 'logout' : (stripos($r['action'], 'FAIL') !== false ? 'failed' : 'login');
            $events[] = ['n' => ++$n, 'actor' => 's' . ($r['user_id'] ?? 0), 'kind' => 'staff', 'event' => $ev, 'time' => $r['created_at'],
                         'name' => $r['full_name'] ?: ($r['username'] ?: '(deleted user)'), 'sub' => ucfirst(str_replace('_', ' ', (string)$r['role_name'])),
                         'ip' => $r['ip_address'], 'ua' => null, 'seen' => null];
        }
    }
    if ($who !== 'staff') {     // teachers: Teacher Registration portal accounts
        try {
            $hasSeen = (bool)$pdo->query("SHOW COLUMNS FROM treg_login_log LIKE 'last_seen'")->fetch();
            // two fixed queries (with / without the optional last_seen column): no SQL text is assembled from variables
            if ($hasSeen) {
                $logRows = $pdo->query("SELECT l.event, l.last_seen, l.ip_address, l.user_agent, l.created_at, l.account_id, l.email_tried,
                                               a.first_name, a.middle_name, a.last_name, a.email
                                          FROM treg_login_log l LEFT JOIN treg_accounts a ON a.id = l.account_id
                                         ORDER BY l.created_at DESC LIMIT 1500")->fetchAll();
            } else {
                $logRows = $pdo->query("SELECT l.event, NULL AS last_seen, l.ip_address, l.user_agent, l.created_at, l.account_id, l.email_tried,
                                               a.first_name, a.middle_name, a.last_name, a.email
                                          FROM treg_login_log l LEFT JOIN treg_accounts a ON a.id = l.account_id
                                         ORDER BY l.created_at DESC LIMIT 1500")->fetchAll();
            }
            foreach ($logRows as $r) {
                $name = trim(preg_replace('/\s+/', ' ', $r['first_name'] . ' ' . $r['middle_name'] . ' ' . $r['last_name'])) ?: ($r['email_tried'] ?: '(unknown)');
                $events[] = ['n' => ++$n, 'actor' => 't' . ($r['account_id'] ?? 0), 'kind' => 'teacher', 'event' => $r['event'], 'time' => $r['created_at'],
                             'name' => $name, 'sub' => $r['email'] ?: $r['email_tried'], 'ip' => $r['ip_address'], 'ua' => $r['user_agent'], 'seen' => $r['last_seen']];
            }
        } catch (PDOException $ex) {
            $teacherLogMissing = true;
        }
    }

    // Pair each login with that person's next logout
    usort($events, fn($a, $b) => strcmp($a['time'], $b['time']) ?: ($a['n'] <=> $b['n']));
    $open = [];
    $mk = fn($e) => ['kind' => $e['kind'], 'name' => $e['name'], 'sub' => $e['sub'], 'ip' => $e['ip'], 'ua' => $e['ua'], 'login' => null, 'logout' => null, 'failed' => null, 'seen' => $e['seen'], 'active' => false];
    foreach ($events as $e) {
        $k = $e['actor'];
        if ($e['event'] === 'failed') { $s = $mk($e); $s['failed'] = $e['time']; $sessions[] = $s; continue; }
        if ($e['event'] === 'login') {
            if (isset($open[$k])) { $sessions[] = $open[$k]; }
            $open[$k] = $mk($e); $open[$k]['login'] = $e['time'];
        } else {
            if (isset($open[$k])) { $open[$k]['logout'] = $e['time']; $sessions[] = $open[$k]; unset($open[$k]); }
            else { $s = $mk($e); $s['logout'] = $e['time']; $sessions[] = $s; }
        }
    }
    foreach ($open as $s) { $sessions[] = $s; }
    $stamp = fn($s) => $s['login'] ?? $s['logout'] ?? $s['failed'];
    usort($sessions, fn($a, $b) => strcmp($stamp($b), $stamp($a)));

    // Active = signed in, not signed out, and seen on a page within the last 15 minutes
    $nowTs = strtotime((string)$pdo->query('SELECT NOW()')->fetchColumn());
    foreach ($sessions as $i => $s) {
        $ref = $s['seen'] ?: $s['login'];
        if ($s['kind'] === 'teacher' && $s['login'] && !$s['logout'] && !$s['failed'] && $ref && ($nowTs - strtotime($ref)) <= $activeWindow) {
            $sessions[$i]['active'] = true;
            $activeNow[] = $sessions[$i];
        }
    }
    $stats['active'] = count($activeNow);

    $today = (string)$pdo->query('SELECT CURDATE()')->fetchColumn();
    $teacherSet = [];
    foreach ($sessions as $s) {
        if ($s['login'] && substr($s['login'], 0, 10) === $today) { $stats['today']++; if ($s['kind'] === 'teacher') { $teacherSet[$s['name']] = 1; } }
        if ($s['failed'] && substr($s['failed'], 0, 10) === $today) { $stats['failed']++; }
    }
    $stats['teachers'] = count($teacherSet);

    if ($who === 'active') { $sessions = $activeNow; }
    if ($date !== '') {
        $sessions = array_values(array_filter($sessions, fn($s) => substr((string)$stamp($s), 0, 10) === $date || ($s['logout'] && substr($s['logout'], 0, 10) === $date)));
    }
    $sessions = array_slice($sessions, 0, 300);
} else {
    $logs = $pdo->query("SELECT a.*, u.username FROM audit_log a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.created_at DESC LIMIT 300")->fetchAll();
}

$pageTitle = 'Audit Log';
include __DIR__ . '/includes/header.php';
?>
<h1>📜 Audit Log</h1>
<p>Who signed in and out of the system, and every change made to records.</p>

<div class="filters">
    <a class="<?= $view === 'signins' ? 'on' : '' ?>" href="audit_log.php?view=signins">Sign-ins and sign-outs</a>
    <a class="<?= $view === 'activity' ? 'on' : '' ?>" href="audit_log.php?view=activity">All activity</a>
</div>

<?php if ($view === 'signins'): ?>

<?php if ($teacherLogMissing): ?>
    <div class="alert alert-info">Teacher sign-ins are not being recorded yet. Import <strong>sql/login_log.sql</strong> into the database and update the Teacher Registration portal files (header.php, logout.php, includes/login_log.php).</div>
<?php endif; ?>

<div class="stat-grid">
    <div class="stat-card al-livecard"><div class="num"><?= (int)$stats['active'] ?></div><div class="label"><span class="al-dot"></span>Teachers active now</div></div>
    <div class="stat-card"><div class="num"><?= (int)$stats['today'] ?></div><div class="label">Sign-ins today</div></div>
    <div class="stat-card"><div class="num"><?= (int)$stats['teachers'] ?></div><div class="label">Teachers who signed in today</div></div>
    <div class="stat-card"><div class="num"><?= (int)$stats['failed'] ?></div><div class="label">Failed attempts today</div></div>
</div>

<?php if ($who !== 'staff' && !$teacherLogMissing): ?>
<div class="card al-active">
    <h3><span class="al-dot"></span>Active teachers <small>(signed in and seen in the last 15 minutes)</small></h3>
    <?php if (!$activeNow): ?>
        <p class="hint" style="margin:0">No teachers are active right now.</p>
    <?php else: foreach ($activeNow as $s): ?>
        <div class="al-act-row">
            <div><strong><?= e($s['name']) ?></strong><div class="hint"><?= e((string)$s['sub']) ?></div></div>
            <div class="hint">Signed in <?= e(date('g:i A', strtotime($s['login']))) ?> &middot; last seen <?= e(alAgo($nowTs - strtotime($s['seen'] ?: $s['login']))) ?></div>
            <div class="hint"><?= e(alDevice($s['ua'])) ?></div>
        </div>
    <?php endforeach; endif; ?>
</div>
<?php endif; ?>

<form method="GET" action="audit_log.php" class="al-filter">
    <input type="hidden" name="view" value="signins">
    <div class="al-field al-grow">
        <label for="al-q">Search</label>
        <input type="search" id="al-q" placeholder="Name, email, role or IP" autocomplete="off">
    </div>
    <div class="al-field">
        <label for="al-who">Show</label>
        <select name="who" id="al-who">
            <option value="all" <?= $who === 'all' ? 'selected' : '' ?>>Everyone</option>
            <option value="teacher" <?= $who === 'teacher' ? 'selected' : '' ?>>Teachers only</option>
            <option value="active" <?= $who === 'active' ? 'selected' : '' ?>>Active teachers now</option>
            <option value="staff" <?= $who === 'staff' ? 'selected' : '' ?>>Staff only</option>
        </select>
    </div>
    <div class="al-field">
        <label for="al-date">Date</label>
        <input type="date" name="date" id="al-date" value="<?= e($date) ?>">
    </div>
    <div class="al-actions">
        <button class="btn btn-sm" type="submit">Apply</button>
        <?php if ($who !== 'all' || $date !== ''): ?><a class="btn btn-sm btn-secondary" href="audit_log.php?view=signins">Clear</a><?php endif; ?>
    </div>
</form>

<div class="card">
    <table id="al-table">
        <tr><th>Person</th><th>Type</th><th>Signed in</th><th>Signed out</th><th>Time online</th><th>Status</th><th>IP</th><th>Device</th></tr>
        <?php if (!$sessions): ?><tr><td colspan="8">No sign-ins found for this view.</td></tr><?php endif; ?>
        <?php foreach ($sessions as $s):
            $status = $s['failed'] ? ['al-bad', 'Failed attempt'] : ($s['logout'] && $s['login'] ? ['al-ok', 'Signed out'] : ($s['login'] ? ['al-warn', 'No sign-out recorded'] : ['al-warn', 'Sign-in not recorded']));
            if (!empty($s['active'])) { $status = ['al-live', 'Active now']; }
        ?>
        <tr data-q="<?= e(mb_strtolower($s['name'] . ' ' . $s['sub'] . ' ' . $s['kind'] . ' ' . $s['ip'])) ?>">
            <td><strong><?= e($s['name']) ?></strong><div class="hint"><?= e((string)$s['sub']) ?></div></td>
            <td><span class="badge <?= $s['kind'] === 'teacher' ? 'badge-role' : '' ?>"><?= $s['kind'] === 'teacher' ? 'Teacher' : 'Staff' ?></span></td>
            <td><?= e(alWhen($s['login'] ?? $s['failed'])) ?></td>
            <td><?= e(alWhen($s['logout'])) ?></td>
            <td><?= e(alDuration($s['login'], $s['logout'])) ?></td>
            <td><span class="al-pill <?= $status[0] ?>"><?= e($status[1]) ?></span><?php if ($s['kind'] === 'teacher' && $s['login'] && !$s['logout']): ?><div class="hint">last seen <?= e(alAgo($nowTs - strtotime($s['seen'] ?: $s['login']))) ?></div><?php endif; ?></td>
            <td><?= e((string)$s['ip']) ?></td>
            <td><?= e(alDevice($s['ua'])) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <p class="hint" style="margin:12px 0 0">"No sign-out recorded" means the person closed the browser or the session timed out instead of pressing Log out, or they are still signed in. Showing the latest 300 sessions.</p>
</div>

<?php else: ?>

<div class="al-filter"><div class="al-field al-grow"><label for="al-q">Search</label><input type="search" id="al-q" placeholder="User, action, table or details" autocomplete="off"></div></div>
<div class="card">
    <table id="al-table">
        <tr><th>Date/Time</th><th>User</th><th>Action</th><th>Table</th><th>Record</th><th>Details</th><th>IP</th></tr>
        <?php foreach ($logs as $l): ?>
        <tr data-q="<?= e(mb_strtolower(($l['username'] ?? '') . ' ' . $l['action'] . ' ' . $l['table_name'] . ' ' . $l['details'])) ?>">
            <td><?= e($l['created_at']) ?></td>
            <td><?= e($l['username'] ?? '(deleted user)') ?></td>
            <td><strong><?= e($l['action']) ?></strong></td>
            <td><?= e($l['table_name']) ?></td>
            <td><?= e((string)$l['record_id']) ?></td>
            <td><?= e($l['details']) ?></td>
            <td><?= e($l['ip_address']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <p class="hint" style="margin:12px 0 0">Most recent 300 entries.</p>
</div>

<?php endif; ?>

<style>
.al-filter{display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;background:#fff;border:1px solid #e3e6f0;border-radius:12px;padding:14px 16px;margin:0 0 18px}
.al-field{min-width:150px}.al-grow{flex:1 1 260px}
.al-actions{display:flex;gap:8px}
.al-pill{display:inline-block;padding:3px 11px;border-radius:99px;font-size:.75rem;font-weight:600;white-space:nowrap}
.al-live{background:#d9f5e5;color:#0f6a41}.al-live::before{content:"";display:inline-block;width:7px;height:7px;border-radius:50%;background:#16a163;margin-right:6px;animation:alpulse 1.6s infinite}
.al-dot{display:inline-block;width:9px;height:9px;border-radius:50%;background:#16a163;margin-right:8px;animation:alpulse 1.6s infinite}
@keyframes alpulse{0%{box-shadow:0 0 0 0 rgba(22,161,99,.5)}70%{box-shadow:0 0 0 7px rgba(22,161,99,0)}100%{box-shadow:0 0 0 0 rgba(22,161,99,0)}}
@media(prefers-reduced-motion:reduce){.al-live::before,.al-dot{animation:none}}
.al-livecard{border-left-color:#16a163}.al-livecard .num{color:#0f6a41}
.al-active h3{margin:0 0 10px}.al-active h3 small{font-weight:400;color:#789;font-size:.8rem}
.al-act-row{display:grid;grid-template-columns:1.2fr 1.4fr 1fr;gap:14px;align-items:center;padding:10px 0;border-top:1px solid #eef0f6}
@media(max-width:700px){.al-act-row{grid-template-columns:1fr}}
.al-ok{background:#e3f5ec;color:#16794c}.al-warn{background:#fff4d6;color:#7a5a00}.al-bad{background:#fdeceb;color:#a5201a}
#al-table td .hint{margin:2px 0 0}
</style>
<script src="assets/js/page-audit-log.js" defer></script>

<?php include __DIR__ . '/includes/footer.php'; ?>