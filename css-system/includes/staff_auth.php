<?php
/**
 * Staff sign up + "Gmail + emailed code + password" log in.
 * Copy to: css-system/includes/staff_auth.php
 *
 * Sits on top of includes/auth.php. It reuses attemptLogin() for the password check, the
 * lockout and the session, so the session looks exactly like a normal login.
 */

/** Roles a person can pick when signing up (key = roles.name in the database). */
const STAFF_SIGNUP_ROLES = [
    'registrar'    => 'Registrar',
    'program_head' => 'Program Head',
    'faculty'      => 'Faculty',
];

const STAFF_CODE_TTL_MINUTES    = 10;   // how long a code works
const STAFF_CODE_RESEND_SECONDS = 60;   // wait before another code can be sent
const STAFF_CODE_MAX_ATTEMPTS   = 5;    // wrong guesses before a code is thrown away

/**
 * true  = new sign-ups are created as "inactive" until the admin presses Activate on the
 *         User Accounts page. Recommended: otherwise anyone could sign up as Registrar.
 * false = a new account can log in straight away.
 */
const STAFF_SIGNUP_NEEDS_APPROVAL = true;

/**
 * How login codes are delivered:
 *   'log'  = local testing only. No email is sent; the code is shown on the login page and written
 *             to the PHP error log.
 *   'smtp'  = sent through Gmail SMTP using the settings in config/mail.php (needed on InfinityFree,
 *             where PHP mail() is switched off).
 * It is chosen automatically: 'log' ONLY when the request comes from the server's own computer
 * (localhost), 'smtp' for everyone else. So a live server can never show codes on screen.
 */
define('STAFF_MAIL_MODE', in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) ? 'log' : 'smtp');
const STAFF_MAIL_FROM = 'Class Scheduling System <no-reply@localhost>';

/* ------------------------------------------------------------------ small helpers */

function staffStr(array $src, string $key): string
{
    return (isset($src[$key]) && is_string($src[$key])) ? trim($src[$key]) : '';
}

function staffIsGmail(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        && preg_match('/@gmail\.com$/i', $email) === 1;
}

/** Same check verifyCsrf() does, but returns true/false so the AJAX endpoint can answer in JSON. */
function staffCsrfOk(): bool
{
    return hash_equals((string)csrfToken(), (string)($_POST['csrf_token'] ?? ''));
}

function staffFindUserByEmail(string $email): ?array
{
    $st = getDB()->prepare('SELECT id, username, email, full_name, password_hash, status FROM users WHERE email = ? LIMIT 1');
    $st->execute([$email]);
    return $st->fetch() ?: null;
}

function staffEnsureCodeTable(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS staff_login_codes (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT NOT NULL,
        code_hash  VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL,
        attempts   TINYINT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        INDEX idx_staff_code_user (user_id)
    )");
    $done = true;
}

function staffMakeUsername(PDO $pdo, string $email): string
{
    $local = strstr($email, '@', true);
    $base  = strtolower(preg_replace('/[^a-z0-9._-]/i', '', $local === false ? '' : $local));
    $base  = substr($base !== '' ? $base : 'user', 0, 30);
    $name  = $base;
    $i     = 1;
    $st    = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
    while (true) {
        $st->execute([$name]);
        if (!$st->fetchColumn()) {
            return $name;
        }
        $i++;
        $name = $base . $i;
    }
}

/* ------------------------------------------------------------------ sign up */

/**
 * A Gmail that already has an account gets the SAME "ok" answer as a new one (no account probing).
 * The sign-up page must show a neutral message, e.g. "Thanks. If this Gmail can be registered,
 * the administrator will review it."
 * @return array{ok:bool, errors:string[], pending:bool}
 */
function staffRegister(array $in): array
{
    $pdo      = getDB();
    $errors   = [];
    $fullName = staffStr($in, 'full_name');
    $roleKey  = staffStr($in, 'role');
    $email    = strtolower(staffStr($in, 'email'));
    $password = isset($in['password']) && is_string($in['password']) ? $in['password'] : '';

    if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 100) {
        $errors[] = 'Enter your full name.';
    }
    if (!isset(STAFF_SIGNUP_ROLES[$roleKey])) {
        $errors[] = 'Choose your role.';
    }
    if (!staffIsGmail($email)) {
        $errors[] = 'Enter a valid Gmail address, like name@gmail.com.';
    }
    if (strlen($password) < 8 || strlen($password) > 72
        || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        $errors[] = 'Password must be 8 to 72 characters with at least one letter and one number.';
    }
    if ($errors) {
        return ['ok' => false, 'errors' => $errors, 'pending' => false];
    }

    try {
        $st = $pdo->prepare('SELECT id FROM roles WHERE name = ? LIMIT 1');
        $st->execute([$roleKey]);
        $roleId = (int)$st->fetchColumn();
        if ($roleId === 0) {
            return ['ok' => false, 'errors' => ['That role is not set up in the system. Contact the administrator.'], 'pending' => false];
        }

        if (staffFindUserByEmail($email)) {
            password_hash($password, PASSWORD_DEFAULT);   // same work as a real sign-up, so timing doesn't reveal the Gmail
            return ['ok' => true, 'errors' => [], 'pending' => STAFF_SIGNUP_NEEDS_APPROVAL];
        }

        $status = STAFF_SIGNUP_NEEDS_APPROVAL ? 'inactive' : 'active';
        $pdo->prepare('INSERT INTO users (username, email, password_hash, full_name, role_id, status) VALUES (?,?,?,?,?,?)')
            ->execute([
                staffMakeUsername($pdo, $email),
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                $fullName,
                $roleId,
                $status,
            ]);
        $newId = (int)$pdo->lastInsertId();
        logAudit($newId, 'CREATE', 'users', $newId, 'Self-registered as ' . $roleKey . ($status === 'inactive' ? ' (waiting for admin approval)' : ''));

        return ['ok' => true, 'errors' => [], 'pending' => STAFF_SIGNUP_NEEDS_APPROVAL];
    } catch (PDOException $ex) {
        if (($ex->errorInfo[1] ?? 0) === 1062) {
            return ['ok' => true, 'errors' => [], 'pending' => STAFF_SIGNUP_NEEDS_APPROVAL];
        }
        error_log('staffRegister failed: ' . $ex->getMessage());
        return ['ok' => false, 'errors' => ['We could not create your account. Try again in a moment.'], 'pending' => false];
    }
}

/* ------------------------------------------------------------------ login codes */

function staffSendMail(string $to, string $subject, string $body): bool
{
    if (STAFF_MAIL_MODE === 'log') {
        error_log('[CSS login code] to=' . $to . ' | ' . $subject . ' | ' . str_replace(["\r", "\n"], ' ', $body));
        return true;
    }
    require_once __DIR__ . '/mailer.php';
    return cssSmtpDeliver($to, $subject, $body);
}

/** Seconds the user still has to wait before a new code may be sent (0 = can send now). */
function staffCodeCooldownLeft(int $userId): int
{
    $pdo = getDB();
    staffEnsureCodeTable($pdo);
    $st = $pdo->prepare('SELECT created_at FROM staff_login_codes WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$userId]);
    $created = $st->fetchColumn();
    if (!$created) {
        return 0;
    }
    return max(0, STAFF_CODE_RESEND_SECONDS - (time() - strtotime((string)$created)));
}

/** @return array{sent:bool, debug_code:?string} */
function staffIssueCode(array $user): array
{
    $pdo = getDB();
    staffEnsureCodeTable($pdo);

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $pdo->prepare('DELETE FROM staff_login_codes WHERE user_id = ?')->execute([$user['id']]);
    $pdo->prepare('INSERT INTO staff_login_codes (user_id, code_hash, expires_at, created_at) VALUES (?,?,?,?)')
        ->execute([
            $user['id'],
            password_hash($code, PASSWORD_DEFAULT),
            date('Y-m-d H:i:s', time() + STAFF_CODE_TTL_MINUTES * 60),
            date('Y-m-d H:i:s'),
        ]);

    $sent = staffSendMail(
        $user['email'],
        'Your Class Scheduling System login code',
        "Hello " . $user['full_name'] . ",\n\nYour login code is: " . $code
        . "\n\nIt expires in " . STAFF_CODE_TTL_MINUTES . " minutes. If you did not ask for it, ignore this email.\n"
    );
    if (!$sent) {
        error_log('staffIssueCode: could not send email to ' . $user['email']);
    }
    return ['sent' => $sent, 'debug_code' => STAFF_MAIL_MODE === 'log' ? $code : null];
}

function staffVerifyCode(int $userId, string $code): bool
{
    $pdo = getDB();
    staffEnsureCodeTable($pdo);
    $st = $pdo->prepare('SELECT * FROM staff_login_codes WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$userId]);
    $row = $st->fetch();
    if (!$row || strtotime($row['expires_at']) < time() || (int)$row['attempts'] >= STAFF_CODE_MAX_ATTEMPTS) {
        return false;
    }
    if (!preg_match('/^\d{6}$/', $code) || !password_verify($code, $row['code_hash'])) {
        $pdo->prepare('UPDATE staff_login_codes SET attempts = attempts + 1 WHERE id = ?')->execute([$row['id']]);
        return false;
    }
    return true;
}

/* ------------------------------------------------------------------ log in */

/** Staff log in: Gmail + emailed code + password. @return array{success:bool, message:string} */
function staffAttemptLogin(string $email, string $code, string $password): array
{
    $generic = 'Invalid Gmail, code or password. Codes expire after ' . STAFF_CODE_TTL_MINUTES . ' minutes.';
    $fail    = static fn(string $m): array => ['success' => false, 'message' => $m];

    $user = $email !== '' ? staffFindUserByEmail(strtolower($email)) : null;
    if (!$user) {
        return $fail($generic);
    }

    if ($user['status'] !== 'active') {
        // Only say so when the password is right, so this can't be used to probe for accounts.
        return password_verify($password, $user['password_hash'])
            ? $fail('Your account is waiting for the administrator to activate it.')
            : $fail($generic);
    }

    if (!staffVerifyCode((int)$user['id'], $code)) {
        return $fail($generic);
    }

    $res = attemptLogin($user['username'], $password);   // password check, lockout, session, audit
    if (!empty($res['success'])) {
        getDB()->prepare('DELETE FROM staff_login_codes WHERE user_id = ?')->execute([$user['id']]);
        return ['success' => true, 'message' => ''];
    }
    $msg = (string)($res['message'] ?? '');
    return $fail(($msg === '' || preg_match('/invalid|incorrect|wrong|username/i', $msg)) ? $generic : $msg);
}

/** Admin log in (username + password). Only the admin role may use it. */
function staffAttemptAdminLogin(string $username, string $password): array
{
    $st = getDB()->prepare('SELECT r.name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.username = ? LIMIT 1');
    $st->execute([$username]);
    $role = $st->fetchColumn();
    if ($role !== false && $role !== 'admin') {
        return ['success' => false, 'message' => 'This sign-in is for the administrator. Staff, use Log in with your Gmail and code.'];
    }
    return attemptLogin($username, $password);
}