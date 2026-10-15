<?php
/**
 * Teacher Registration portal - settings. Secrets live in config/config.local.php.
 */
date_default_timezone_set('Asia/Manila');

$local = __DIR__ . '/config.local.php';
if (!is_file($local)) {
    http_response_code(500);
    exit('Server is not configured.');
}
require $local;

/* ------------------------------------------------------------------
 * App
 * ------------------------------------------------------------------ */
define('APP_NAME', 'BCP Teacher Registration');

/* ------------------------------------------------------------------
 * APP_SECRET - signs every emailed login code.
 * ------------------------------------------------------------------ */
$_secretFile = __DIR__ . '/app_secret.php';
if (is_file($_secretFile)) {
    require $_secretFile;
} else {
    $secret = bin2hex(random_bytes(32));
    $code   = "<?php\ndefine('APP_SECRET', '$secret');\n";
    if (file_put_contents($_secretFile, $code) === false) {
        http_response_code(500);
        exit(
            'Cannot write config/app_secret.php. '
            . 'Give the web server write permission to the config/ folder, '
            . 'then reload this page once. After the file is created you can '
            . 'remove write permission again.'
        );
    }
    define('APP_SECRET', $secret);
    unset($secret, $code);
}
unset($_secretFile);

define('APP_DEBUG', false);

/* ------------------------------------------------------------------
 * Email (sends the login code to the teacher's Gmail)
 * ------------------------------------------------------------------ */
define('MAIL_MODE', 'smtp');
define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_PORT', 587);
define('MAIL_FROM_ADDRESS', 'bcp.css.system@gmail.com');
define('MAIL_FROM_NAME', 'BCP Teacher Registration');

/* ------------------------------------------------------------------
 * Login code + lockout rules
 * ------------------------------------------------------------------ */
define('CODE_TTL_MINUTES', 10);
define('CODE_RESEND_SECONDS', 60);
define('MAX_FAILED_ATTEMPTS', 5);
define('LOCK_MINUTES', 15);
define('IDLE_TIMEOUT_SECONDS', 7200);

/* ------------------------------------------------------------------
 * Dropdown lists
 * ------------------------------------------------------------------ */
define('PROGRAMS', [
    'BSIT'   => 'BSIT - Information Technology',
    'BSCS'   => 'BSCS - Computer Science',
    'BSBA'   => 'BSBA - Business Administration',
    'BSA'    => 'BSA - Accountancy',
    'BSOA'   => 'BSOA - Office Administration',
    'BSHM'   => 'BSHM - Hospitality Management',
    'BSTM'   => 'BSTM - Tourism Management',
    'BSCRIM' => 'BSCrim - Criminology',
    'BSED'   => 'BSEd - Secondary Education',
    'BEED'   => 'BEEd - Elementary Education',
]);

define('YEAR_LEVELS', [
    '1st Year' => '1st Year',
    '2nd Year' => '2nd Year',
    '3rd Year' => '3rd Year',
    '4th Year' => '4th Year',
]);

define('DAYS', [
    'Mon' => 'Monday',
    'Tue' => 'Tuesday',
    'Wed' => 'Wednesday',
    'Thu' => 'Thursday',
    'Fri' => 'Friday',
    'Sat' => 'Saturday',
]);

define('AVAIL_MIN', '06:00');
define('AVAIL_MAX', '21:00');
