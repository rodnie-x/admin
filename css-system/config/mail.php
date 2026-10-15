<?php
/**
 * Email settings for staff login codes. Secrets live in config/mail.local.php (never shared/committed).
 * CSS_MAIL_PASSWORD must be a Google "App Password" (16 characters), not the normal Gmail password.
 */
define('CSS_MAIL_MODE', 'smtp');
define('CSS_MAIL_HOST', 'smtp.gmail.com');
define('CSS_MAIL_PORT', 587);
define('CSS_MAIL_FROM_NAME', 'Class Scheduling System');

$mailLocal = __DIR__ . '/mail.local.php';
if (!is_file($mailLocal)) {
    http_response_code(500);
    exit('Mail is not configured.');
}
require $mailLocal;
