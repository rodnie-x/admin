<?php
// >>> PLACE AT: css-system/includes/auth.php  (replace the old file)  [batch 10: forced password change; lockout 5 min + counter reset fix]
/**
 * Authentication + authorization + CSRF + audit logging entry point.
 */
require_once __DIR__ . '/security_headers.php';   // batch 4: CSP + security headers
require_once __DIR__ . '/../config/db.php';

/** True when the visitor reached us over HTTPS (also behind a reverse proxy such as InfinityFree's). */
function isHttpsRequest(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

if (session_status() === PHP_SESSION_NONE) {
    // Own session name so it never collides with the other apps on the same host
    session_name('CSS_SESSION');
    ini_set('session.use_strict_mode', '1');   // reject session IDs the server did not create
    ini_set('session.use_only_cookies', '1');  // never accept a session ID from the URL
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isHttpsRequest(),        // HTTPS only on the live site, still works on localhost
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

const MAX_FAILED_ATTEMPTS = 5;
const LOCKOUT_MINUTES      = 5;

const SESSION_IDLE_SECONDS = 3600;    // log out after 1 hour with no activity
const SESSION_MAX_SECONDS  = 43200;   // and always after 12 hours, even if active

/** Why the session was just ended ('expired' or 'ended'), or null. Used to show a message on the login page. */
function sessionEndReason(?string $set = null): ?string
{
    static $reason = null;
    if ($set !== null) { $reason = $set; }
    return $reason;
}

/**
 * Is there a valid logged-in user in this session?
 * On every request this re-checks the database, so a deactivated account or a changed role takes
 * effect immediately instead of staying valid until the person logs out. It also enforces the
 * idle and maximum session times.
 */
function isLoggedIn(): bool
{
    static $valid = false;
    if (empty($_SESSION['user_id'])) {
        $valid = false;
        return false;
    }
    if ($valid) {
        return true;
    }

    $now   = time();
    $last  = (int)($_SESSION['last_activity'] ?? $now);   // sessions from before this update count as active now
    $start = (int)($_SESSION['login_at'] ?? $now);
    $stale = ($now - $last) > SESSION_IDLE_SECONDS || ($now - $start) > SESSION_MAX_SECONDS;

    $row = false;
    if (!$stale) {
        // u.* so this still works before the must_change_password column has been added to the database
        $st = getDB()->prepare('SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?');
        $st->execute([(int)$_SESSION['user_id']]);
        $row = $st->fetch();
    }

    if ($stale || !$row || $row['status'] !== 'active') {
        sessionEndReason($stale ? 'expired' : 'ended');
        $_SESSION = [];
        session_regenerate_id(true);
        return false;
    }

    $_SESSION['role']          = $row['role_name'];   // always the role stored in the database right now
    $_SESSION['must_change']   = !empty($row['must_change_password']) ? 1 : 0;   // temporary password: must be replaced first
    $_SESSION['last_activity'] = $now;
    $_SESSION['login_at']      = $start;
    $valid = true;
    return true;
}

/** True when this account still uses a temporary password and has to choose its own. */
function mustChangePassword(): bool
{
    return isLoggedIn() && !empty($_SESSION['must_change']);
}

/** Bounce to login.php if not authenticated. A temporary password must be changed before anything else. */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: login.php' . (sessionEndReason() ? '?expired=1' : ''));
        exit;
    }
    if (mustChangePassword() && !in_array(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), ['change_password.php', 'logout.php'], true)) {
        header('Location: change_password.php');
        exit;
    }
}

/**
 * Bounce to login.php if not authenticated, or show 403 if the
 * logged-in user's role isn't in the allowed list.
 * @param string[] $roles
 */
function requireRole(array $roles): void
{
    requireLogin();
    if (!in_array($_SESSION['role'], $roles, true)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body style="font-family:sans-serif;text-align:center;padding:60px">';
        echo '<h1>403 — Access Denied</h1><p>Your role ("' . htmlspecialchars($_SESSION['role']) . '") does not have permission to view this page.</p>';
        echo '<p><a href="dashboard.php">&larr; Back to Dashboard</a></p></body></html>';
        exit;
    }
}

/**
 * Attempt to log a user in. Handles bcrypt verification, brute-force
 * lockout, session fixation protection, and audit logging.
 * @return array{success:bool, message:string}
 */
function attemptLogin(string $username, string $password): array
{
    $pdo = getDB();

    $stmt = $pdo->prepare("SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user) {
        // Spend the same time as a real check so response timing doesn't reveal which usernames exist.
        password_verify($password, password_hash('timing-equaliser', PASSWORD_DEFAULT));
        return ['success' => false, 'message' => 'Invalid username or password.'];
    }

    if (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
        $mins = ceil((strtotime($user['locked_until']) - time()) / 60);
        return ['success' => false, 'message' => "Account locked due to too many failed attempts. Try again in $mins minute(s)."];
    }

    if (!password_verify($password, $user['password_hash'])) {
        $attempts  = (int)$user['failed_attempts'] + 1;
        $shown     = $attempts;                 // number written to the audit log
        $lockUntil = null;
        if ($attempts >= MAX_FAILED_ATTEMPTS) {
            $lockUntil = date('Y-m-d H:i:s', time() + LOCKOUT_MINUTES * 60);
            $attempts  = 0;                     // start fresh when the lock ends, so it cannot re-lock on the first wrong try
        }
        $pdo->prepare("UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?")
            ->execute([$attempts, $lockUntil, $user['id']]);

        logAudit($user['id'], 'LOGIN_FAILED', 'users', $user['id'], "Failed login attempt ($shown/" . MAX_FAILED_ATTEMPTS . ")");

        if ($lockUntil) {
            return ['success' => false, 'message' => "Too many failed attempts. Account locked for " . LOCKOUT_MINUTES . " minutes."];
        }
        return ['success' => false, 'message' => 'Invalid username or password.'];
    }

    // Only someone who knows the right password is told the account is deactivated (no account probing).
    if ($user['status'] !== 'active') {
        return ['success' => false, 'message' => 'This account has been deactivated. Contact your administrator.'];
    }

    // Successful login: reset failed attempts, regenerate session id (fixation protection).
    $pdo->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = ?")
        ->execute([$user['id']]);

    session_regenerate_id(true);
    unset($_SESSION['csrf_token']);                 // fresh CSRF token for the new, logged-in session
    $_SESSION['last_activity'] = time();
    $_SESSION['login_at']      = time();
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['username']  = $user['username'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role']      = $user['role_name'];
    $_SESSION['must_change'] = !empty($user['must_change_password']) ? 1 : 0;

    logAudit($user['id'], 'LOGIN', 'users', $user['id'], 'Successful login');

    return ['success' => true, 'message' => 'OK'];
}

/** Log the current user out and destroy the session. */
function doLogout(): void
{
    if (isLoggedIn()) {
        logAudit($_SESSION['user_id'], 'LOGOUT', 'users', $_SESSION['user_id'], 'User logged out');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

/** Get (or lazily create) the CSRF token for this session. */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Verify the csrf_token POST field, or die with a 400. */
function verifyCsrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || $token === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(400);
        die('Invalid or expired form submission (CSRF check failed). Please go back and try again.');
    }
}