<?php
// >>> PLACE AT: css-system/security_check.php (replace the old file)  [batch 11: copy button for unfinished problems]
/**
 * Admin-only security self-check. Open it on the LIVE site while logged in as admin.
 *
 * Every row has a RISK level (what happens if it is left unfixed):
 *   High   = fix now. Someone could take over accounts or read private data.
 *   Medium = fix this week. It weakens the system or makes attacks easier.
 *   Low    = fix when you have time.
 * and a RESULT: OK, Fix (a problem was found), or Check (the page cannot be sure: look by hand).
 *
 * It never prints a password, secret or App Password. The code scan is a helper, not a full audit:
 * a "Review" row can be a false alarm, a clean scan does not prove the site is safe.
 * Delete it, or leave it: only the admin role can open it.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireRole(['admin']);

@set_time_limit(60);
$started = microtime(true);
$pdo     = getDB();

if (is_file(__DIR__ . '/includes/rate_limit.php')) {
    require_once __DIR__ . '/includes/rate_limit.php';   // defines RATE_LIMIT_IP_HEADER
}

/* ================================================================== helpers */

/** Short readable list: "a, b, c and 4 more". */
function scList(array $items, int $max = 8): string
{
    $items = array_values(array_unique(array_map('strval', $items)));
    return implode(', ', array_slice($items, 0, $max)) . (count($items) > $max ? ' and ' . (count($items) - $max) . ' more' : '');
}

function scRead(string $file): string
{
    return (is_file($file) && filesize($file) < 2000000) ? (string)file_get_contents($file) : '';
}

/** HTTP status of a URL as seen from this server, or null if the test could not run. Never follows redirects. */
function scProbeStatus(string $url): ?int
{
    $ctx = stream_context_create([
        'http' => ['method' => 'GET', 'timeout' => 3, 'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 0,
                   'header' => "Range: bytes=0-0\r\nConnection: close\r\nUser-Agent: css-security-check\r\n"],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $h = @get_headers($url, false, $ctx);
    if (!is_array($h) || !isset($h[0])) {
        return null;
    }
    return preg_match('#^HTTP/\S+\s+(\d{3})#', (string)$h[0], $m) ? (int)$m[1] : null;
}

/**
 * Reads one PHP file the way the PHP engine does (comments and HTML removed) and looks for risky calls.
 * @return array{code:string, danger:array, sqlUser:array, sqlDyn:array}
 */
function scAnalyze(string $src): array
{
    $out = ['code' => $src, 'danger' => [], 'sqlUser' => [], 'sqlDyn' => []];
    if (!function_exists('token_get_all')) {
        return $out;
    }
    try {
        $tokens = token_get_all($src);
    } catch (Throwable $ex) {
        return $out;
    }

    $code = '';
    $sig  = [];   // meaningful tokens: [id|null, text, line]
    $line = 1;
    foreach ($tokens as $t) {
        if (is_array($t)) {
            if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) {
                continue;
            }
            $line  = $t[2];
            $code .= $t[1];
            if ($t[0] !== T_WHITESPACE) {
                $sig[] = [$t[0], $t[1], $line];
            }
        } else {
            $code .= $t;
            $sig[]  = [null, $t, $line];
        }
    }
    $out['code'] = $code;

    $bad      = ['system', 'exec', 'shell_exec', 'passthru', 'popen', 'proc_open', 'pcntl_exec', 'assert', 'create_function', 'unserialize', 'md5', 'sha1'];
    $skipPrev = [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW];
    if (defined('T_NULLSAFE_OBJECT_OPERATOR')) {
        $skipPrev[] = T_NULLSAFE_OBJECT_OPERATOR;
    }
    $superglobals = ['$_GET', '$_POST', '$_REQUEST', '$_COOKIE'];
    $n = count($sig);

    for ($i = 0; $i < $n; $i++) {
        [$id, $text, $ln] = $sig[$i];
        $prevId = $i > 0 ? $sig[$i - 1][0] : null;
        $next   = $sig[$i + 1][1] ?? '';

        if ($id === T_EVAL) {
            $out['danger'][] = ['eval', $ln];
            continue;
        }
        if ($id !== T_STRING) {
            continue;
        }
        $name = strtolower($text);

        if (in_array($name, $bad, true) && $next === '(' && !in_array($prevId, $skipPrev, true)) {
            $out['danger'][] = [$name, $ln];
        }

        // $pdo->query(...) / ->exec(...) / ->prepare(...): look only at the FIRST argument (the SQL text)
        if (in_array($name, ['query', 'exec', 'prepare'], true) && $prevId === T_OBJECT_OPERATOR && $next === '(') {
            $depth = 0;
            $dyn   = false;
            $user  = false;
            for ($j = $i + 2; $j < $n; $j++) {
                $tk = $sig[$j][1];
                if ($tk === '(') {
                    $depth++;
                } elseif ($tk === ')') {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                } elseif ($tk === ',' && $depth === 0) {
                    break;
                }
                if ($sig[$j][0] === T_VARIABLE) {
                    $dyn = true;
                    $castBefore = $j > 0 && in_array($sig[$j - 1][0], [T_INT_CAST, T_DOUBLE_CAST], true);
                    if (in_array($tk, $superglobals, true) && !$castBefore) {
                        $user = true;
                    }
                }
            }
            if ($user) {
                $out['sqlUser'][] = [$name, $ln];
            } elseif ($dyn) {
                $out['sqlDyn'][] = [$name, $ln];
            }
        }
    }
    return $out;
}

/* ================================================================== where things are */

$root = dirname(__DIR__);
$apps = ['css-system' => __DIR__];
foreach (['teacher-registration', 'student-portal'] as $sibling) {
    if (is_dir($root . '/' . $sibling)) {
        $apps[$sibling] = $root . '/' . $sibling;
    }
}
$self = realpath(__FILE__);

$rows = [];   // [area, item, risk, ok (true|false|null), what]
$add  = function (string $area, string $item, string $risk, ?bool $ok, string $what) use (&$rows): void {
    $rows[] = ['area' => $area, 'item' => $item, 'risk' => $risk, 'ok' => $ok, 'what' => $what];
};

/* ================================================================== 1. Transport and hosting */

$https = isHttpsRequest();
$add('Hosting', '1  HTTPS is on', 'High', $https,
    $https ? 'This page was opened over HTTPS.' : 'This page was opened over plain HTTP. Issue the SSL certificate in InfinityFree, then open the site with https://.');

$host   = (string)($_SERVER['HTTP_HOST'] ?? '');
$hostOk = preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/', $host) === 1;
$base   = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
$probeFails = 0;
$probe = function (string $url) use (&$probeFails): ?int {
    if ($probeFails >= 2) {
        return null;   // the server cannot call itself: stop wasting time
    }
    $s = scProbeStatus($url);
    if ($s === null) {
        $probeFails++;
    }
    return $s;
};
$untested = [];

if ($https && $hostOk) {
    $s = $probe('http://' . $host . $base . '/login.php');
    if ($s === null) {
        $untested[] = 'http:// address of the site (should redirect to https://)';
    } else {
        $add('Hosting', '2  Plain HTTP is redirected to HTTPS', 'High', in_array($s, [301, 302, 303, 307, 308], true),
            in_array($s, [301, 302, 303, 307, 308], true) ? 'The http:// address redirects.' : 'The http:// address answered with code ' . $s . ' instead of redirecting. Check the HTTPS redirect rule in .htaccess.');
    }
}

/* Is the web server blocked from private files? (a real request, the same way a stranger would ask) */
if ($hostOk) {
    $scheme = $https ? 'https' : 'http';
    $parent = rtrim(str_replace('\\', '/', dirname($base)), '/');
    $targets = [
        ['css-system/sql/schema.sql', $base . '/sql/schema.sql', 'High', 'database structure'],
        ['css-system/README.md',      $base . '/README.md',      'High', 'project notes'],
        ['css-system/config/db.php',  $base . '/config/db.php',  'Medium', 'config folder'],
        ['css-system/includes/functions.php', $base . '/includes/functions.php', 'Medium', 'includes folder'],
    ];
    if (isset($apps['teacher-registration'])) {
        $t = $apps['teacher-registration'];
        if (is_file($t . '/storage/mail.log')) {
            $targets[] = ['teacher-registration/storage/mail.log', $parent . '/teacher-registration/storage/mail.log', 'High', 'old login codes'];
        }
        $targets[] = ['teacher-registration/config/config.php', $parent . '/teacher-registration/config/config.php', 'Medium', 'config folder'];
        foreach (glob($t . '/sql/*.sql') ?: [] as $f) {
            $targets[] = ['teacher-registration/sql/' . basename($f), $parent . '/teacher-registration/sql/' . basename($f), 'High', 'database structure'];
            break;
        }
    }
    if (isset($apps['student-portal'])) {
        foreach (glob($apps['student-portal'] . '/sql/*.sql') ?: [] as $f) {
            $targets[] = ['student-portal/sql/' . basename($f), $parent . '/student-portal/sql/' . basename($f), 'High', 'database structure'];
            break;
        }
    }
    foreach ($targets as [$label, $path, $risk, $desc]) {
        $s = $probe($scheme . '://' . $host . $path);
        if ($s === null) {
            $untested[] = $path;
            continue;
        }
        $open = ($s === 200 || $s === 206);
        $add('Hosting', '5/6  Web access blocked: ' . $label, $risk, !$open,
            $open ? 'Anyone can open this address (' . $desc . '). Fix the .htaccess rules for that folder or file type.' : 'Blocked or missing (server answered ' . $s . ').');
    }
}
if ($untested) {
    $add('Hosting', '5/6  Web access test could not run', 'High', null,
        'This server could not call itself, so these were not tested: ' . scList($untested, 10) . '. Open each address in a private browser window: you must see "403", "404" or a blank error, never the file.');
}

/* .htaccess present for the private folders */
$noRules = [];
foreach (['config', 'sql', 'includes'] as $dirName) {
    $d = __DIR__ . '/' . $dirName;
    if (is_dir($d) && !is_file($d . '/.htaccess') && !is_file(__DIR__ . '/.htaccess')) {
        $noRules[] = $dirName . '/';
    }
}
$add('Hosting', '5  .htaccess rules exist for config/, sql/, includes/', 'High', !$noRules,
    $noRules ? 'No .htaccess found for: ' . implode(', ', $noRules) . '. Without it the web server may hand these files out.' : 'A .htaccess file was found. (The web test above shows if it really works.)');

/* Security headers sent by this very page */
$sentHeaders = headers_list();
$hasHeader = function (string $name) use ($sentHeaders): bool {
    foreach ($sentHeaders as $h) {
        if (stripos($h, $name . ':') === 0) {
            return true;
        }
    }
    return false;
};
$cspLine = '';
foreach ($sentHeaders as $h) {
    if (stripos($h, 'Content-Security-Policy:') === 0) {
        $cspLine = $h;
    }
}
$missingHdr = [];
if ($cspLine === '')                                         { $missingHdr[] = 'Content-Security-Policy'; }
if (!$hasHeader('X-Content-Type-Options'))                   { $missingHdr[] = 'X-Content-Type-Options'; }
if (!$hasHeader('X-Frame-Options') && stripos($cspLine, 'frame-ancestors') === false) { $missingHdr[] = 'clickjacking protection'; }
if (!$hasHeader('Referrer-Policy'))                          { $missingHdr[] = 'Referrer-Policy'; }
$add('Hosting', '24  Security headers are sent', 'Medium', !$missingHdr,
    $missingHdr ? 'Missing: ' . implode(', ', $missingHdr) . '. Check that includes/security_headers.php is loaded first.' : 'CSP, nosniff, frame and referrer headers are present.');

$scriptInline = preg_match("/script-src[^;]*'unsafe-inline'/i", $cspLine) === 1;
$add('Hosting', '49  Strict script policy (no inline scripts)', 'Medium', !$scriptInline,
    $scriptInline ? 'The policy still allows inline scripts, so an injected script would run. Finish moving inline scripts out (see the Code scan below), then set CSP_STRICT_SCRIPTS to true.' : 'Inline scripts are blocked by the policy.');

/* HSTS */
$hsts = null;
foreach ($sentHeaders as $h) {
    if (preg_match('/^Strict-Transport-Security:.*max-age=(\d+)/i', $h, $m)) {
        $hsts = (int)$m[1];
    }
}
if ($hsts === null) {
    foreach ([__DIR__ . '/.htaccess', $root . '/.htaccess'] as $f) {
        if (preg_match('/Strict-Transport-Security[^\n]*max-age=(\d+)/i', scRead($f), $m)) {
            $hsts = (int)$m[1];
            break;
        }
    }
}
$add('Hosting', '3  HSTS lasts long enough', 'Low', $hsts !== null && $hsts >= 31536000,
    $hsts === null ? 'No HSTS header found. Add it after HTTPS works.' : ($hsts >= 31536000 ? 'max-age is a year or more.' : 'max-age is only ' . $hsts . ' seconds. Raise it to 31536000 once HTTPS is stable.'));

/* PHP settings */
$ver = PHP_VERSION;
$verRisk = PHP_VERSION_ID < 80100 ? 'High' : 'Medium';
$add('Hosting', 'PHP version', $verRisk, PHP_VERSION_ID >= 80200,
    PHP_VERSION_ID >= 80200 ? 'PHP ' . $ver . ' is recent.' : 'PHP ' . $ver . ' is old and may no longer get security fixes. Pick a newer PHP version in the InfinityFree control panel (test the site after).');
$de = strtolower(trim((string)ini_get('display_errors')));
$errorsShown = in_array($de, ['1', 'on', 'true', 'yes', 'stdout', 'stderr'], true);
$add('Hosting', '7  PHP errors are hidden from visitors', 'Medium', !$errorsShown,
    $errorsShown ? 'display_errors is ON: an error page can show file paths and database names. Turn it off (php.ini or .htaccess).' : 'display_errors is off.');
$urlInc = in_array(strtolower(trim((string)ini_get('allow_url_include'))), ['1', 'on', 'true', 'yes'], true);
$add('Hosting', 'allow_url_include is off', 'High', !$urlInc, $urlInc ? 'allow_url_include is ON: turn it off.' : 'Off.');

/* Session cookie */
$cp = session_get_cookie_params();
$cookieBad = [];
if ($https && empty($cp['secure'])) { $cookieBad[] = 'Secure flag missing'; }
if (empty($cp['httponly']))         { $cookieBad[] = 'HttpOnly flag missing'; }
if (trim((string)($cp['samesite'] ?? '')) === '') { $cookieBad[] = 'SameSite missing'; }
$add('Accounts', '4  Session cookie flags', 'Medium', !$cookieBad, $cookieBad ? 'Problem: ' . implode(', ', $cookieBad) . '.' : 'Secure (on HTTPS), HttpOnly and SameSite are set.');

/* ================================================================== 2. Settings files */

$dbBad = DB_USER === 'root' || DB_PASS === '' || DB_PASS === '1234' || str_contains(DB_PASS, 'ChangeThis');
$add('Settings', '8  Database settings (this app)', 'High', !$dbBad,
    $dbBad ? 'config/db.php still has root, an empty password or the placeholder. Use the values from the InfinityFree panel.' : 'Not root and not the placeholder.');

$treg = $apps['teacher-registration'] ?? null;
$stu  = $apps['student-portal'] ?? null;

if ($treg !== null && is_file($treg . '/config/config.php')) {
    $t = scRead($treg . '/config/config.php');
    $secretBad = preg_match("/define\\(\\s*'APP_SECRET'\\s*,\\s*'CHANGE-ME/", $t) === 1;
    $add('Settings', '9/36  Teacher portal APP_SECRET', 'High', !$secretBad, $secretBad ? 'Still the placeholder: login codes can be forged. Set a long random value.' : 'Changed from the placeholder.');
    $badDb = preg_match("/define\\(\\s*'DB_USER'\\s*,\\s*'root'/", $t) || preg_match("/define\\(\\s*'DB_PASS'\\s*,\\s*(''|'1234'|'ChangeThis[^']*')/", $t);
    $add('Settings', '8  Database settings (teacher portal)', 'High', !$badDb, $badDb ? 'config/config.php still has root, an empty password or the placeholder.' : 'Not root and not the placeholder.');
    $debugOn = preg_match("/define\\(\\s*'APP_DEBUG'\\s*,\\s*true\\s*\\)/i", $t) === 1;
    $add('Settings', 'Teacher portal APP_DEBUG is false', 'High', !$debugOn, $debugOn ? 'APP_DEBUG is true: login codes can be shown on screen.' : 'APP_DEBUG is false.');
    $mailMode = preg_match("/define\\(\\s*'MAIL_MODE'\\s*,\\s*'(\\w+)'/", $t, $mm) ? $mm[1] : '';
    $add('Settings', 'Teacher portal MAIL_MODE is smtp', 'High', $mailMode === 'smtp', $mailMode === 'smtp' ? 'Codes are emailed.' : 'MAIL_MODE is "' . $mailMode . '": codes go to a file on the server (storage/mail.log).');
    if (preg_match("/define\\(\\s*'MAIL_PASSWORD'\\s*,\\s*'[^']+'/", $t)) {
        $add('Settings', '9  Gmail App Password stored in config.php', 'High', null,
            'A mail password is written in this file (value not shown). If it was ever sent in chat, email, a zip or a screenshot, revoke it in your Google account and create a new one.');
    }
}
if ($treg !== null && is_file($treg . '/storage/mail.log')) {
    $add('Settings', '9  Teacher portal storage/mail.log', 'High', false, 'Still exists (old login codes). Delete it.');
}
if ($treg !== null && is_dir($treg . '/storage')) {
    $has = is_file($treg . '/storage/.htaccess');
    $add('Settings', '54  storage/.htaccess in the teacher portal', 'Medium', $has, $has ? 'Present.' : 'Missing: add a deny-all .htaccess.');
}
if ($stu !== null && is_file($stu . '/config/db.php')) {
    $s = scRead($stu . '/config/db.php');
    $badStu = preg_match("/define\\(\\s*'DB_USER'\\s*,\\s*'root'/", $s) || preg_match("/define\\(\\s*'DB_PASS'\\s*,\\s*(''|'1234')/", $s);
    $add('Settings', '8  Database settings (student portal)', 'High', !$badStu, $badStu ? 'config/db.php still has root or an easy password.' : 'Not root and not an easy password.');
}

if (is_file(__DIR__ . '/includes/staff_auth.php')) {
    require_once __DIR__ . '/includes/staff_auth.php';
    $smtp = defined('STAFF_MAIL_MODE') && STAFF_MAIL_MODE === 'smtp';
    $add('Settings', '39  Staff code delivery mode', 'High', $smtp, $smtp ? 'Codes are sent by email (smtp).' : 'Mode is "log": codes would be shown on screen. This must be smtp on the live site.');
}
$mailConf = __DIR__ . '/config/mail.php';
if (is_file($mailConf)) {
    require_once $mailConf;
}
$mailOk = defined('CSS_MAIL_USERNAME') && CSS_MAIL_USERNAME !== '' && defined('CSS_MAIL_PASSWORD') && CSS_MAIL_PASSWORD !== '';
$add('Settings', '39  config/mail.php filled in', 'High', $mailOk, $mailOk ? 'A Gmail address and App Password are set (values not shown).' : 'CSS_MAIL_USERNAME or CSS_MAIL_PASSWORD is empty: staff cannot receive codes. Use the admin sign-in until this is fixed.');

/* Files that should not be on the live server */
$add('Files', '13  reset_admin_password.php', 'Medium', !is_file(__DIR__ . '/reset_admin_password.php'), is_file(__DIR__ . '/reset_admin_password.php') ? 'Still on the server. Delete it.' : 'Not on the server.');
$add('Files', '40  Stray css-system/staff_auth.php', 'Medium', !is_file(__DIR__ . '/staff_auth.php'), is_file(__DIR__ . '/staff_auth.php') ? 'Still on the server (its mail mode shows codes on screen). Delete it.' : 'Not on the server.');
$add('Files', '41  Unused assets/js/ui.js', 'Low', !is_file(__DIR__ . '/assets/js/ui.js'), is_file(__DIR__ . '/assets/js/ui.js') ? 'Still on the server. Delete it.' : 'Not on the server.');

/* Walk the files once (depth 3). Skips folders that only hold images, scripts or libraries. */
$skipDirs = ['.git', 'node_modules', 'vendor', 'uploads', 'assets'];
$allFiles = [];
$fileCount = 0;
foreach ($apps as $name => $dir) {
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                function ($cur, $key, $iter) use ($skipDirs) {
                    return $iter->hasChildren() ? !in_array($cur->getFilename(), $skipDirs, true) : true;
                }
            ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        $it->setMaxDepth(3);
        foreach ($it as $f) {
            if ($f->isFile() && ++$fileCount <= 800) {
                $allFiles[$name][] = $f->getPathname();
            }
        }
    } catch (Throwable $ex) {
        error_log('security_check walk: ' . $ex->getMessage());
    }
}

/* Leftover and dangerous files */
$stray = [];
$strayRx = '/(\.(bak|old|orig|save|swp|tmp|zip|tar|gz|7z|rar|log)$|~$|^\.env|^(phpinfo|info|test|debug|shell|adminer|phpmyadmin)\.php$|^error_log$)/i';
foreach ($allFiles as $name => $files) {
    foreach ($files as $path) {
        $bn = basename($path);
        $rel = $name . '/' . ltrim(str_replace('\\', '/', substr($path, strlen($apps[$name]))), '/');
        if ($bn === 'mail.log') { continue; }   // has its own row
        $sqlOutside = preg_match('/\.sql$/i', $bn) && strtolower(basename(dirname($path))) !== 'sql';
        if (preg_match($strayRx, $bn) || $sqlOutside) {
            $stray[] = $rel;
        }
    }
}
$add('Files', 'Leftover backup, log, test or database files', 'High', !$stray,
    $stray ? 'Found: ' . scList($stray) . '. Delete them: backups and logs often hold passwords or private data.' : 'None found.');

/* Uploads folder of the teacher portal */
if ($treg !== null && is_dir($treg . '/uploads')) {
    $exec = [];
    try {
        foreach (new DirectoryIterator($treg . '/uploads') as $f) {
            if ($f->isFile() && preg_match('/\.(php\d?|phtml|phar|cgi|pl|py|sh|htaccess)$/i', $f->getFilename()) && $f->getFilename() !== '.htaccess') {
                $exec[] = $f->getFilename();
            }
        }
    } catch (Throwable $ex) {
        $exec = [];
    }
    $add('Files', '12/28  Script files inside teacher uploads/', 'High', !$exec, $exec ? 'Script files found in uploads/: ' . scList($exec) . '. Delete them now and investigate how they got there.' : 'No script files in uploads/.');
    $hasHt = is_file($treg . '/uploads/.htaccess');
    $add('Files', '12  uploads/.htaccess blocks direct access', 'High', $hasHt, $hasHt ? 'Present.' : 'Missing. It is created when a teacher first saves a profile; add it by hand now.');
}

/* World-writable config files (only meaningful on Linux hosting) */
if (DIRECTORY_SEPARATOR === '/') {
    $writable = [];
    foreach ($apps as $name => $dir) {
        foreach (glob($dir . '/config/*.php') ?: [] as $f) {
            if ((fileperms($f) & 0002) !== 0) {
                $writable[] = $name . '/config/' . basename($f);
            }
        }
    }
    $add('Files', 'Config files are not world-writable', 'Medium', !$writable, $writable ? 'Anyone on the server can change: ' . scList($writable) . '. Set permissions to 644.' : 'Permissions look fine.');
}

/* ================================================================== 3. Accounts and live activity */

$weakDoc = [];
$weakCommon = [];
$checkedAcc = 0;
$partial = false;
$common = ['Admin@123', 'admin', 'admin123', 'password', 'Password1', 'Password123', '12345678', 'Welcome1', 'qwerty123'];
try {
    foreach ($pdo->query('SELECT u.username, u.password_hash, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id LIMIT 100')->fetchAll() as $u) {
        if (microtime(true) - $started > 15) {
            $partial = true;
            break;
        }
        $priv = in_array($u['role_name'], ['admin', 'registrar'], true);
        $try  = $priv ? array_unique(array_merge($common, [$u['username'], $u['username'] . '123'])) : ['Admin@123'];
        $checkedAcc++;
        foreach ($try as $pw) {
            if ($pw !== '' && password_verify($pw, (string)$u['password_hash'])) {
                if ($pw === 'Admin@123') { $weakDoc[] = $u['username']; } else { $weakCommon[] = $u['username'] . ' (' . $u['role_name'] . ')'; }
                break;
            }
        }
    }
} catch (Throwable $ex) {
    error_log('security_check accounts: ' . $ex->getMessage());
}
$add('Accounts', '10  Documented password Admin@123', 'High', !$weakDoc, $weakDoc ? 'These accounts still use it: ' . implode(', ', array_map('strval', $weakDoc)) . '. Reset them in User Accounts.' : 'No account uses it.' . ($partial ? ' (Stopped early to save time: ' . $checkedAcc . ' accounts checked.)' : ''));
$add('Accounts', 'Easy-to-guess passwords for Admin and Registrar', 'High', !$weakCommon, $weakCommon ? 'These use a very common password or their own username: ' . implode(', ', array_map('strval', $weakCommon)) . '. Reset them now.' : 'Admin and Registrar accounts do not use the common passwords tested here.');

try {
    $admins = (int)$pdo->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'admin' AND u.status = 'active'")->fetchColumn();
    $add('Accounts', 'Number of active Admin accounts', 'Low', $admins <= 2, $admins <= 2 ? $admins . ' active admin account(s).' : $admins . ' active admin accounts. Keep as few as possible.');
    $pending = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'inactive'")->fetchColumn();
    $add('Accounts', 'Staff sign-ups waiting for approval', 'Low', null, $pending . ' inactive account(s). Review them in User Accounts: delete or activate.');
} catch (Throwable $ex) {
    error_log('security_check admins: ' . $ex->getMessage());
}
try {
    $tempPw = (int)$pdo->query('SELECT COUNT(*) FROM stu_students WHERE must_change_password = 1')->fetchColumn();
    $add('Accounts', 'Students still on a temporary password', 'Low', null, $tempPw . ' student(s) have not replaced the temporary password yet.');
} catch (Throwable $ex) { /* student tables not in this database */ }

/* Failed sign-ins in the last 24 hours */
$sources = [
    ['Staff',    "SELECT COUNT(*) FROM audit_log WHERE action = 'LOGIN_FAILED' AND created_at >= (NOW() - INTERVAL 1 DAY)",
                 "SELECT ip_address AS ip, COUNT(*) AS c FROM audit_log WHERE action = 'LOGIN_FAILED' AND created_at >= (NOW() - INTERVAL 1 DAY) GROUP BY ip_address ORDER BY c DESC LIMIT 1"],
    ['Teacher',  "SELECT COUNT(*) FROM treg_login_log WHERE event = 'failed' AND created_at >= (NOW() - INTERVAL 1 DAY)",
                 "SELECT ip_address AS ip, COUNT(*) AS c FROM treg_login_log WHERE event = 'failed' AND created_at >= (NOW() - INTERVAL 1 DAY) GROUP BY ip_address ORDER BY c DESC LIMIT 1"],
    ['Student',  "SELECT COUNT(*) FROM stu_login_log WHERE event = 'failed' AND created_at >= (NOW() - INTERVAL 1 DAY)",
                 "SELECT ip_address AS ip, COUNT(*) AS c FROM stu_login_log WHERE event = 'failed' AND created_at >= (NOW() - INTERVAL 1 DAY) GROUP BY ip_address ORDER BY c DESC LIMIT 1"],
];
foreach ($sources as [$who, $sqlCount, $sqlTop]) {
    try {
        $n = (int)$pdo->query($sqlCount)->fetchColumn();
        $top = $pdo->query($sqlTop)->fetch();
        $topTxt = ($top && $n > 0) ? ' Most from IP ' . $top['ip'] . ' (' . (int)$top['c'] . ').' : '';
        $risk = $n >= 100 ? 'High' : 'Medium';
        $add('Live activity', $who . ' failed sign-ins in the last 24 hours', $risk, $n < 30,
            $n < 30 ? $n . ' failed attempt(s). Normal.' : $n . ' failed attempts: someone may be guessing passwords.' . $topTxt . ' Check the Audit Log.');
    } catch (Throwable $ex) { /* that log table does not exist (yet) */ }
}

try {
    $locked = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE locked_until IS NOT NULL AND locked_until > NOW()')->fetchColumn();
    $add('Live activity', 'Staff accounts locked right now', 'Low', null, $locked . ' account(s) locked after too many wrong passwords.');
} catch (Throwable $ex) { /* ignore */ }

try {
    $st = $pdo->prepare("SELECT bucket, ip, COUNT(*) AS c FROM rate_limits WHERE bucket LIKE 'css\\_%' AND hit_at >= ? GROUP BY bucket, ip HAVING c >= 10 ORDER BY c DESC LIMIT 3");
    $st->execute([date('Y-m-d H:i:s', time() - 3600)]);
    $busy = $st->fetchAll();
    $add('Live activity', 'Visitors hitting the rate limits (last hour)', 'Medium', !$busy,
        $busy ? 'Heavy use from: ' . implode(', ', array_map(fn($b) => $b['ip'] . ' on ' . $b['bucket'] . ' (' . (int)$b['c'] . ')', $busy)) . '.' : 'Nobody is near the limits.');
} catch (Throwable $ex) { /* table not created yet */ }

/* Does PHP see real visitor addresses? (items 34 and 42) */
$xff   = (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && is_string($_SERVER['HTTP_X_FORWARDED_FOR'])) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : '';
$xparts = $xff !== '' ? array_map('trim', explode(',', $xff)) : [];
$remote = (string)($_SERVER['REMOTE_ADDR'] ?? '?');
$behindProxy = $xparts && end($xparts) !== $remote;
$hdrSet = defined('RATE_LIMIT_IP_HEADER') && RATE_LIMIT_IP_HEADER !== '';
$add('Live activity', '34/42  Rate limits see real visitor IPs', 'Medium', !($behindProxy && !$hdrSet),
    'REMOTE_ADDR = ' . $remote . '; X-Forwarded-For = ' . ($xff !== '' ? $xff : '(none)') . '. '
    . (($behindProxy && !$hdrSet) ? 'The host sits behind a proxy and RATE_LIMIT_IP_HEADER is empty, so every visitor may share one limit. Add define(\'RATE_LIMIT_IP_HEADER\', \'HTTP_X_FORWARDED_FOR\'); to config/db.php (and TRUST_PROXY_IP_HEADER for the Audit Log).' : 'Looks fine.'));

/* ================================================================== 4. Code scan */

$hits = ['danger' => [], 'eval' => [], 'unserialize' => [], 'weakhash' => [], 'sqlUser' => [], 'sqlDyn' => [], 'echoUser' => [], 'phpself' => [], 'incUser' => [], 'extractUser' => [], 'redirUser' => [],
         'noCsrf' => [], 'noGuard' => [], 'inlineScript' => [], 'inlineHandler' => []];
$scanned = 0;
$publicPages = ['login.php', 'index.php', 'logout.php', 'register.php', 'signup.php', 'staff_signup.php', 'send_staff_code.php', 'send_code.php', 'enroll.php', 'status.php'];

foreach ($allFiles as $name => $files) {
    foreach ($files as $path) {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php' || realpath($path) === $self) {
            continue;
        }
        $src = scRead($path);
        if ($src === '') {
            continue;
        }
        $scanned++;
        $relIn = ltrim(str_replace('\\', '/', substr($path, strlen($apps[$name]))), '/');
        $rel   = $name . '/' . $relIn;
        $isPage = strpos($relIn, '/') === false || strpos($relIn, 'ajax/') === 0;   // top-level pages and ajax endpoints (not includes/)

        $a    = scAnalyze($src);
        $code = $a['code'];

        foreach ($a['danger'] as [$fn, $ln]) {
            if ($fn === 'eval' || in_array($fn, ['system', 'exec', 'shell_exec', 'passthru', 'popen', 'proc_open', 'pcntl_exec', 'assert', 'create_function'], true)) {
                $hits['danger'][] = $rel . ':' . $ln . ' ' . $fn . '()';
            } elseif ($fn === 'unserialize') {
                $hits['unserialize'][] = $rel . ':' . $ln;
            } else {
                $hits['weakhash'][] = $rel . ':' . $ln . ' ' . $fn . '()';
            }
        }
        foreach ($a['sqlUser'] as [$fn, $ln]) { $hits['sqlUser'][] = $rel . ':' . $ln; }
        foreach ($a['sqlDyn'] as [$fn, $ln])  { $hits['sqlDyn'][]  = $rel . ':' . $ln; }

        if (preg_match('/(<\?=|\becho\b|\bprint\b)\s*\$_(GET|POST|REQUEST|COOKIE)\b/', $code))                                                  { $hits['echoUser'][] = $rel; }
        if (preg_match('/(<\?=|\becho\b)\s*\$_SERVER\s*\[\s*[\'"](PHP_SELF|REQUEST_URI|QUERY_STRING)[\'"]\s*\]/', $code))                          { $hits['phpself'][] = $rel; }
        if (preg_match('/\b(include|require)(_once)?\b[^;]*\$_(GET|POST|REQUEST|COOKIE)\b/', $code))                                              { $hits['incUser'][] = $rel; }
        if (preg_match('/\bextract\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/', $code))                                                                  { $hits['extractUser'][] = $rel; }
        if (preg_match('/header\s*\(\s*[\'"]Location:\s*[\'"]\s*\.\s*\$_(GET|POST|REQUEST)/i', $code))                                              { $hits['redirUser'][] = $rel; }

        // Inline scripts and onclick="" style handlers: they stop the strict script policy from being turned on
        $inl = preg_match_all('/<script\b(?![^>]*\bsrc\s*=)(?![^>]*type\s*=\s*["\']application\/json)[^>]*>/i', $src);
        $hnd = preg_match_all('/\son(?:click|change|submit|load|error|input|focus|blur|keyup|keydown|mouseover)\s*=\s*["\']/i', $src);
        if ($inl) { $hits['inlineScript'][]  = $rel . ' (' . $inl . ')'; }
        if ($hnd) { $hits['inlineHandler'][] = $rel . ' (' . $hnd . ')'; }

        if ($isPage) {
            $handlesPost = strpos($code, 'REQUEST_METHOD') !== false && strpos($code, "'POST'") !== false;
            if ($handlesPost && !preg_match('/verifyCsrf|csrfValid|staffCsrfOk|hash_equals\s*\(/', $code)) {
                $hits['noCsrf'][] = $rel;
            }
            $doesSomething = preg_match('/\b(echo|print|exit|die|readfile)\b|<\?=|\bheader\s*\(|\bredirect\s*\(|\binclude\b|header\.php|layoutStart/', $code) === 1;
            $guarded = preg_match('/requireLogin|requireRole|requireStudent|currentStudent\s*\(|isLoggedIn\s*\(|isLocalRequest|currentAccount\s*\(/', $code) === 1;
            if ($doesSomething && !$guarded && !in_array(basename($path), $publicPages, true)) {
                $hits['noGuard'][] = $rel;
            }
        }
    }
}

$fix = fn(string $k): bool => empty($hits[$k]);
$add('Code scan', 'Dangerous PHP functions (eval, exec, system...)', 'High', $fix('danger'), $fix('danger') ? 'None used.' : 'Found: ' . scList($hits['danger']) . '. These can run commands on the server: remove them.');
$add('Code scan', 'SQL text built from visitor input (SQL injection)', 'High', $fix('sqlUser'), $fix('sqlUser') ? 'No query is built from $_GET/$_POST/$_REQUEST/$_COOKIE.' : 'Found: ' . scList($hits['sqlUser']) . '. Use a prepared statement with ? placeholders.');
$add('Code scan', 'Visitor input printed without e() (XSS)', 'High', $fix('echoUser'), $fix('echoUser') ? 'None found.' : 'Found in: ' . scList($hits['echoUser']) . '. Wrap the value in e().');
$add('Code scan', 'include/require or extract() using visitor input', 'High', $fix('incUser') && $fix('extractUser'), ($fix('incUser') && $fix('extractUser')) ? 'None found.' : 'Found in: ' . scList(array_merge($hits['incUser'], $hits['extractUser'])) . '. A visitor could load or overwrite things they should not.');
$add('Code scan', 'POST pages without a CSRF check', 'High', $fix('noCsrf'), $fix('noCsrf') ? 'Every page that handles a POST checks a CSRF token.' : 'Found: ' . scList($hits['noCsrf']) . '. Call verifyCsrf() (or csrfValid()) before changing anything.');
$add('Code scan', 'Pages with no login check', 'High', $fix('noGuard'), $fix('noGuard') ? 'Every non-public page calls a login or role check.' : 'Found: ' . scList($hits['noGuard']) . '. Add requireLogin()/requireRole() at the top, unless the page is meant to be public.');
$add('Code scan', 'Redirect to an address taken from the visitor', 'Medium', $fix('redirUser'), $fix('redirUser') ? 'None found.' : 'Found in: ' . scList($hits['redirUser']) . '. Only redirect to fixed pages.');
$add('Code scan', 'Raw $_SERVER[PHP_SELF] / REQUEST_URI printed', 'Medium', $fix('phpself'), $fix('phpself') ? 'None found.' : 'Found in: ' . scList($hits['phpself']) . '. Use a fixed action="page.php" instead.');
$add('Code scan', 'unserialize() used', 'Medium', $fix('unserialize'), $fix('unserialize') ? 'Not used.' : 'Found: ' . scList($hits['unserialize']) . '. Never unserialize data that a visitor can change.');
$inlineAll = array_merge($hits['inlineScript'], $hits['inlineHandler']);
$add('Code scan', '49/57  Inline scripts and onclick="" handlers left', 'Medium', empty($inlineAll),
    empty($inlineAll) ? 'No inline scripts found, so the strict script policy can be turned on.' : 'Still inline (file and count): ' . scList($inlineAll, 12) . '. Move them to files in assets/js/ (batch 8), then set CSP_STRICT_SCRIPTS to true. Until then the strict policy would break these pages.');
$add('Code scan', 'REVIEW: SQL text that contains a variable', 'Low', $fix('sqlDyn') ? true : null,
    $fix('sqlDyn') ? 'None found.' : 'Check by hand (often safe): ' . scList($hits['sqlDyn'], 12) . '. Safe only if the variable holds fixed text written by you, never visitor input.');
$add('Code scan', 'REVIEW: md5() / sha1() used', 'Low', $fix('weakhash') ? true : null,
    $fix('weakhash') ? 'Not used.' : 'Found: ' . scList($hits['weakhash']) . '. Fine for file names or checksums, never for passwords (use password_hash).');
$add('Code scan', 'Files scanned', 'Low', null, $scanned . ' PHP file(s) in: ' . implode(', ', array_keys($apps)) . '. This scan finds common mistakes only. It cannot prove the site is safe.');

/* ================================================================== 5. Are the earlier patches really applied? */

$mark = function (string $file, string $needle): ?bool {
    return is_file($file) ? (strpos(scRead($file), $needle) !== false) : null;
};
$patchChecks = [
    ['43  Teacher sign-up and login are rate limited', 'Medium', $treg ? $mark($treg . '/index.php', 'rateLimitAllow') : null, 'teacher-registration/index.php has no rateLimitAllow(): upload the batch 6b index.php.'],
    ['45  Staff login is rate limited', 'Medium', $mark(__DIR__ . '/login.php', 'rateLimitAllow'), 'css-system/login.php has no rateLimitAllow(): upload the batch 9 login.php.'],
    ['35  Student enroll form is rate limited', 'Medium', $stu ? $mark($stu . '/enroll.php', 'rateLimitAllow') : null, 'student-portal/enroll.php still uses the session counter only.'],
    ['35  Student status check is rate limited', 'Medium', $stu ? $mark($stu . '/status.php', 'rateLimitAllow') : null, 'student-portal/status.php still uses the session counter only.'],
    ['47  Teacher sign-up hides which Gmails exist', 'Low', $treg ? ($mark($treg . '/includes/auth.php', 'is already registered') === true ? false : $mark($treg . '/includes/auth.php', 'function registerAccount')) : null, 'teacher-registration/includes/auth.php still says "already registered" (PATCHES_6b A).'],
    ['44/47  Staff sign-up hides which Gmails exist', 'Low', is_file(__DIR__ . '/includes/staff_auth.php') ? (strpos(scRead(__DIR__ . '/includes/staff_auth.php'), 'is already registered') === false) : null, 'includes/staff_auth.php still says "already registered" (PATCHES_6b B).'],
    ['26  verifyCsrf() accepts only text values', 'Low', (preg_match('/function verifyCsrf\(\)[^{]*\{[^}]*is_string/s', scRead(__DIR__ . '/includes/auth.php')) === 1), 'includes/auth.php verifyCsrf() does not check is_string() (batch 6 PATCHES.md #5).'],
    ['38  Student sign-ins link in the sidebar', 'Low', $mark(__DIR__ . '/includes/sidebar.php', 'student_signins.php'), 'includes/sidebar.php has no link to student_signins.php.'],
    ['51  app.js loads on every CSS page', 'Low', $mark(__DIR__ . '/includes/footer.php', 'app.js'), 'includes/footer.php does not load assets/js/app.js (batch 8).'],
];
if ($treg !== null && is_file($treg . '/dashboard.php')) {
    $dashIsLogin = strpos(scRead($treg . '/dashboard.php'), 'attemptLogin(') !== false;
    $patchChecks[] = ['55  teacher-registration/dashboard.php is the dashboard', 'Medium', !$dashIsLogin, 'dashboard.php contains the LOGIN page code, so logged-in teachers loop back and the registration form is missing (PATCHES_8 section 0).'];
}
foreach (array_keys($apps) as $appName) {
    $patchChecks[] = ['rate_limit.php exists in ' . $appName, 'Medium', is_file($apps[$appName] . '/includes/rate_limit.php'), 'includes/rate_limit.php is missing in ' . $appName . '.'];
}
foreach ($patchChecks as [$item, $risk, $res, $problem]) {
    if ($res === null) {
        continue;   // the file was not found next to this folder
    }
    $add('Patches', $item, $risk, $res, $res ? 'Applied.' : $problem);
}
try {
    $col = $pdo->query("SHOW COLUMNS FROM treg_login_log LIKE 'event'")->fetch();
    if ($col) {
        $okEnum = stripos((string)$col['Type'], 'enum') === false || stripos((string)$col['Type'], 'failed') !== false;
        $add('Patches', '37  Failed teacher sign-ins can be logged', 'Low', $okEnum, $okEnum ? 'The event column accepts "failed".' : 'Run: ALTER TABLE treg_login_log MODIFY event VARCHAR(10) NOT NULL;');
    }
} catch (Throwable $ex) { /* table not there */ }

/* ================================================================== 6. Show it */

$rank  = ['High' => 0, 'Medium' => 1, 'Low' => 2];
$state = fn(array $r): int => $r['ok'] === false ? 0 : ($r['ok'] === null ? 1 : 2);
usort($rows, fn($x, $y) => [$state($x), $rank[$x['risk']] ?? 9] <=> [$state($y), $rank[$y['risk']] ?? 9]);

$count = ['High' => 0, 'Medium' => 0, 'Low' => 0];
$passed = 0;
$hand = 0;
foreach ($rows as $r) {
    if ($r['ok'] === false) { $count[$r['risk']]++; } elseif ($r['ok'] === true) { $passed++; } else { $hand++; }
}
$problems = array_sum($count);
$onlyProblems = (isset($_GET['show']) && $_GET['show'] === 'problems');
if ($count['High'] > 0)       { $verdict = ['sc-high', 'Needs attention now: ' . $count['High'] . ' high-risk problem(s) found.']; }
elseif ($count['Medium'] > 0) { $verdict = ['sc-med', 'No high-risk problems. ' . $count['Medium'] . ' medium-risk problem(s) to fix this week.']; }
elseif ($count['Low'] > 0)    { $verdict = ['sc-low', 'Only low-risk problems left (' . $count['Low'] . ').']; }
else                          { $verdict = ['sc-ok', 'No problems found by these checks. Keep the "Check by hand" rows in mind.']; }

/* Text for the "Copy unfinished problems" button: every Fix and Check-by-hand row, worst first (rows are already sorted).
   Contains no passwords or secrets: it only repeats the messages shown in the table. */
$copyLines = [];
foreach ($rows as $r) {
    if ($r['ok'] === true) { continue; }
    $copyLines[] = '[' . ($r['ok'] === false ? 'FIX' : 'CHECK BY HAND') . ' | ' . $r['risk'] . '] '
                 . $r['area'] . ' - ' . $r['item'] . "\n    " . $r['what'];
}
$copyText = 'Security check ' . date('Y-m-d H:i') . ': '
          . $count['High'] . ' high, ' . $count['Medium'] . ' medium, ' . $count['Low'] . ' low, ' . $hand . " to check by hand.\n\n"
          . ($copyLines ? implode("\n\n", $copyLines) : 'No unfinished problems.');

$pageTitle = 'Security check';
include __DIR__ . '/includes/header.php';
?>
<style>
.sc-pill{display:inline-block;padding:3px 11px;border-radius:99px;font-size:.75rem;font-weight:700;white-space:nowrap}
.sc-high{background:#fdeceb;color:#a5201a}.sc-med{background:#fff4d6;color:#7a5a00}.sc-low{background:#e6edff;color:#1f3a9d}.sc-ok{background:#e3f5ec;color:#16794c}.sc-hand{background:#eceef3;color:#4b5573}
.sc-verdict{padding:14px 18px;border-radius:12px;font-weight:700;margin:0 0 18px}
.sc-n-high{color:#a5201a!important}.sc-n-med{color:#a36d00!important}.sc-n-low{color:#1f3a9d!important}.sc-n-ok{color:#16794c!important}
#sc-table td{vertical-align:top}.sc-area{color:#6b7190;font-size:.8rem;white-space:nowrap}
.sc-copybar{display:flex;align-items:center;gap:12px;margin:0 0 14px}
.sc-copy-src{position:absolute;left:-9999px;top:0;width:1px;height:1px;opacity:0}
</style>

<h1>🛡️ Security check</h1>
<p>Checks the server settings, the files and the PHP code for security problems. Every row shows how risky it is if left unfixed. Open it on the live site. Nothing secret is shown.</p>

<div class="sc-verdict <?= e($verdict[0]) ?>"><?= e($verdict[1]) ?></div>

<div class="stat-grid">
    <div class="stat-card"><div class="num sc-n-high"><?= (int)$count['High'] ?></div><div class="label">High risk problems</div></div>
    <div class="stat-card"><div class="num sc-n-med"><?= (int)$count['Medium'] ?></div><div class="label">Medium risk problems</div></div>
    <div class="stat-card"><div class="num sc-n-low"><?= (int)$count['Low'] ?></div><div class="label">Low risk problems</div></div>
    <div class="stat-card"><div class="num sc-n-ok"><?= (int)$passed ?></div><div class="label">Checks passed</div></div>
    <div class="stat-card"><div class="num"><?= (int)$hand ?></div><div class="label">Check by hand</div></div>
</div>

<div class="filters">
    <a class="<?= $onlyProblems ? '' : 'on' ?>" href="security_check.php">Show everything</a>
    <a class="<?= $onlyProblems ? 'on' : '' ?>" href="security_check.php?show=problems">Only problems and "check by hand"</a>
</div>

<div class="sc-copybar">
    <button type="button" class="btn btn-sm" id="sc-copy">Copy unfinished problems</button>
    <span class="hint" id="sc-copy-status" role="status" aria-live="polite"></span>
</div>
<textarea id="sc-copy-text" class="sc-copy-src" readonly tabindex="-1" aria-hidden="true"><?= e($copyText) ?></textarea>

<div class="card">
    <table id="sc-table">
        <tr><th>Risk</th><th>Result</th><th>Area</th><th>Item</th><th>What it means</th></tr>
        <?php foreach ($rows as $r):
            if ($onlyProblems && $r['ok'] === true) { continue; }
            $riskCls = ['High' => 'sc-high', 'Medium' => 'sc-med', 'Low' => 'sc-low'][$r['risk']] ?? 'sc-low';
        ?>
        <tr>
            <td><span class="sc-pill <?= e($riskCls) ?>"><?= e($r['risk']) ?></span></td>
            <td><?= $r['ok'] === null ? '<span class="sc-pill sc-hand">Check</span>' : ($r['ok'] ? '<span class="sc-pill sc-ok">OK</span>' : '<span class="sc-pill ' . e($riskCls) . '">Fix</span>') ?></td>
            <td class="sc-area"><?= e($r['area']) ?></td>
            <td><strong><?= e($r['item']) ?></strong></td>
            <td><?= e($r['what']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <p class="hint" style="margin:12px 0 0">
        <strong>High</strong> = fix today (accounts or private data could be taken). <strong>Medium</strong> = fix this week (weakens the system). <strong>Low</strong> = fix when you have time.
        The risk shown on an OK row is how serious it would be if it failed.
        The code scan only finds common mistakes and can raise false alarms: a clean scan does not prove the site is safe.
        Item 9 (revoking the old Gmail App Password) cannot be checked from here: do it in your Google account.
    </p>
</div>
<script src="assets/js/page-security-check.js" defer></script>
<?php include __DIR__ . '/includes/footer.php'; ?>