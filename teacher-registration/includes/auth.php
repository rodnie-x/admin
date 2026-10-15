<?php
// >>> PLACE AT: teacher-registration/includes/auth.php  (replace the old file; if it is new, just add it)
/**
 * Bootstrap + authentication for the Teacher Registration portal.
 * Every page requires this file first.
 */
require_once __DIR__ . '/security_headers.php';   // batch 4: CSP + security headers
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

/** True when the visitor reached us over HTTPS (also behind a reverse proxy such as InfinityFree's). */
function isHttpsRequest(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    // Own session name so this portal never collides with the scheduling system's session on the same host.
    session_name('TREG_SESSION');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => isHttpsRequest(),
    ]);
    session_start();
}

/* =====================================================================
 * Small helpers
 * ===================================================================== */
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $message];
}

function takeFlashes(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

/** Returns a POST/GET value only if it is a plain string (blocks array tampering). */
function strIn(array $source, string $key): string
{
    $v = $source[$key] ?? '';
    return is_string($v) ? $v : '';
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

function csrfValid(): bool
{
    $sent = $_POST['csrf_token'] ?? '';
    return is_string($sent) && $sent !== '' && hash_equals($_SESSION['csrf'] ?? '', $sent);
}

function verifyCsrf(): void
{
    if (!csrfValid()) {
        http_response_code(419);
        exit('Your session expired. Go back, refresh the page, and try again.');
    }
}

/* =====================================================================
 * Names, emails
 * ===================================================================== */
function cleanName(string $v): string
{
    return trim(preg_replace('/\s+/u', ' ', $v));
}

function validName(string $v): bool
{
    return (bool)preg_match("/^\p{L}[\p{L} .'\-]{0,58}$/u", $v);
}

/** "Juan D. Dela Cruz" - the format stored in the scheduling system's teachers.full_name. */
function buildFullName(string $first, string $middle, string $last): string
{
    $initial = $middle !== '' ? mb_strtoupper(mb_substr($middle, 0, 1)) . '.' : '';
    return cleanName("$first $initial $last");
}

function accountFullName(array $account): string
{
    return buildFullName($account['first_name'], $account['middle_name'], $account['last_name']);
}

function isGmail(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        && (bool)preg_match('/^[a-z0-9._%+\-]+@gmail\.com$/i', $email);
}

/**
 * Gmail ignores dots and "+tags" (j.doe+x@gmail.com == jdoe@gmail.com).
 * We use this normalised form so one mailbox can't register twice.
 */
function emailKey(string $email): string
{
    $email = strtolower(trim($email));
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    $local = str_replace('.', '', explode('+', $local, 2)[0]);
    return $local . '@' . $domain;
}

function findAccountByEmail(string $email): ?array
{
    $stmt = getDB()->prepare('SELECT * FROM treg_accounts WHERE email_key = ? LIMIT 1');
    $stmt->execute([emailKey($email)]);
    return $stmt->fetch() ?: null;
}

/* =====================================================================
 * Sign up
 * ===================================================================== */
/** @return array{ok:bool, errors:string[], account:?array} */
function registerAccount(array $in): array
{
    $first    = cleanName(strIn($in, 'first_name'));
    $middle   = cleanName(strIn($in, 'middle_name'));
    $last     = cleanName(strIn($in, 'last_name'));
    $email    = strtolower(trim(strIn($in, 'email')));
    $program  = strIn($in, 'program');
    $year     = strIn($in, 'year_level');
    $password = strIn($in, 'password');
    $confirm  = strIn($in, 'confirm_password');

    $errors = [];
    if (!validName($first))                      $errors[] = 'Enter your first name (letters only).';
    if ($middle !== '' && !validName($middle))   $errors[] = 'Middle name can only contain letters.';
    if (!validName($last))                       $errors[] = 'Enter your last name (letters only).';
    if (!isGmail($email))                        $errors[] = 'Enter a valid Gmail address ending in @gmail.com.';
    if (!isset(PROGRAMS[$program]))              $errors[] = 'Choose your program.';
    if (!isset(YEAR_LEVELS[$year]))              $errors[] = 'Choose your year level.';

    require_once __DIR__ . '/password_policy.php';
    foreach (passwordProblems($password, [$first, $last, $email]) as $m) {
        $errors[] = $m;
    }
    if ($password !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    }

    if ($errors) {
        return ['ok' => false, 'errors' => $errors, 'account' => null];
    }
    if (findAccountByEmail($email)) {
        return ['ok' => true, 'errors' => [], 'account' => null];
    }

    try {
        getDB()->prepare(
            'INSERT INTO treg_accounts (first_name, middle_name, last_name, email, email_key, program, year_level, password_hash)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$first, $middle, $last, $email, emailKey($email), $program, $year, password_hash($password, PASSWORD_DEFAULT)]);
    } catch (PDOException $ex) {
        error_log('Sign-up failed: ' . $ex->getMessage());
        return ['ok' => false, 'errors' => ['The account could not be saved. Try again later.'], 'account' => null];
    }

    return ['ok' => true, 'errors' => [], 'account' => findAccountByEmail($email)];
}

/* =====================================================================
 * Emailed login code
 * ===================================================================== */
function hashLoginCode(string $code, int $accountId): string
{
    return hash_hmac('sha256', $accountId . '|' . $code, APP_SECRET);
}

function accountIsLocked(array $account): bool
{
    return !empty($account['locked_until']) && strtotime($account['locked_until']) > time();
}

/**
 * Creates a fresh 6-digit code, stores only its hash, and emails it.
 * @return array{sent:bool, throttled:bool, debug_code:?string}
 */
function issueLoginCode(array $account): array
{
    $now = time();
    if (!empty($account['code_sent_at']) && ($now - strtotime($account['code_sent_at'])) < CODE_RESEND_SECONDS) {
        return ['sent' => false, 'throttled' => true, 'debug_code' => null];
    }

    $code    = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expires = date('Y-m-d H:i:s', $now + CODE_TTL_MINUTES * 60);

    getDB()->prepare('UPDATE treg_accounts SET login_code_hash = ?, login_code_expires = ?, code_sent_at = ? WHERE id = ?')
        ->execute([hashLoginCode($code, (int)$account['id']), $expires, date('Y-m-d H:i:s', $now), $account['id']]);

    $mins = CODE_TTL_MINUTES;
    $text = "Hi {$account['first_name']},\n\nYour login code for " . APP_NAME . " is: $code\n\n"
          . "It expires in $mins minutes and works once. If you didn't ask for it, you can ignore this email.\n";
    $html = '<div style="font-family:Arial,sans-serif;max-width:480px;color:#1B2333">'
          . '<p>Hi ' . e($account['first_name']) . ',</p>'
          . '<p>Your login code for ' . e(APP_NAME) . ' is:</p>'
          . '<p style="font-size:30px;font-weight:bold;letter-spacing:6px;margin:16px 0">' . e($code) . '</p>'
          . "<p>It expires in $mins minutes and works once. If you didn't ask for it, you can ignore this email.</p></div>";

    $sent = sendMail($account['email'], 'Your ' . APP_NAME . ' login code', $text, $html);
    if (!$sent) {
        error_log('Could not send login code to account #' . $account['id']);
    }

    $debug = (APP_DEBUG && MAIL_MODE !== 'smtp') ? $code : null;
    return ['sent' => $sent, 'throttled' => false, 'debug_code' => $debug];
}

/* =====================================================================
 * Login / logout
 * ===================================================================== */
/** @return array{success:bool, message:string} */
function attemptLogin(string $email, string $code, string $password): array
{
    $generic = 'Gmail, code, or password is incorrect.';
    $pdo     = getDB();
    $account = isGmail(strtolower(trim($email))) ? findAccountByEmail($email) : null;

    if (!$account) {
        // Spend the same time as a real check so response timing doesn't reveal which Gmails exist.
        password_verify($password, password_hash('timing-equaliser', PASSWORD_DEFAULT));
        return ['success' => false, 'message' => $generic];
    }

    if (accountIsLocked($account)) {
        $wait = max(1, (int)ceil((strtotime($account['locked_until']) - time()) / 60));
        return ['success' => false, 'message' => "Too many failed attempts. Try again in $wait minute(s)."];
    }

    $passwordOk = password_verify($password, $account['password_hash']);
    $codeOk = $account['login_code_hash'] !== null
        && $account['login_code_expires'] !== null
        && strtotime($account['login_code_expires']) >= time()
        && preg_match('/^\d{6}$/', $code) === 1
        && hash_equals($account['login_code_hash'], hashLoginCode($code, (int)$account['id']));

    if (!$passwordOk || !$codeOk) {
        $attempts = (int)$account['failed_attempts'] + 1;
        $lockedUntil = null;
        if ($attempts >= MAX_FAILED_ATTEMPTS) {
            $lockedUntil = date('Y-m-d H:i:s', time() + LOCK_MINUTES * 60);
            $attempts = 0;
        }
        $pdo->prepare('UPDATE treg_accounts SET failed_attempts = ?, locked_until = ? WHERE id = ?')
            ->execute([$attempts, $lockedUntil, $account['id']]);
        return ['success' => false, 'message' => $generic];
    }

    // Success: the code is single-use.
    $pdo->prepare('UPDATE treg_accounts SET failed_attempts = 0, locked_until = NULL, login_code_hash = NULL,
                   login_code_expires = NULL, is_verified = 1, last_login = ? WHERE id = ?')
        ->execute([date('Y-m-d H:i:s'), $account['id']]);

    session_regenerate_id(true);
    unset($_SESSION['csrf']);                      // fresh CSRF token for the new, logged-in session
    $_SESSION['account_id']    = (int)$account['id'];
    $_SESSION['treg_epoch']    = (int)($account['session_epoch'] ?? 0);
    $_SESSION['last_activity'] = time();
    return ['success' => true, 'message' => ''];
}

function doLogout(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}

/* =====================================================================
 * Current user / page guards
 * ===================================================================== */
function currentAccount(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    if (empty($_SESSION['account_id'])) {
        return $cache = null;
    }
    $stmt = getDB()->prepare('SELECT * FROM treg_accounts WHERE id = ?');
    $stmt->execute([$_SESSION['account_id']]);
    $row = $stmt->fetch() ?: null;
    if (!$row) {
        unset($_SESSION['account_id']);
    } elseif ((int)($row['session_epoch'] ?? 0) !== (int)($_SESSION['treg_epoch'] ?? 0)) {
        unset($_SESSION['account_id'], $_SESSION['treg_epoch']);
        $row = null;
    }
    return $cache = $row;
}

function isLoggedIn(): bool
{
    return currentAccount() !== null;
}

function requireLogin(): void
{
    if (!empty($_SESSION['account_id'])
        && isset($_SESSION['last_activity'])
        && (time() - (int)$_SESSION['last_activity']) > IDLE_TIMEOUT_SECONDS) {
        doLogout();
        flash('info', 'You were logged out after a period of inactivity. Log in again to continue.');
        redirect('index.php');
    }
    if (!isLoggedIn()) {
        flash('error', 'Log in to continue.');
        redirect('index.php');
    }
    $_SESSION['last_activity'] = time();
}
