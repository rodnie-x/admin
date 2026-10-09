<?php
// >>> PLACE AT: css-system/config/mail.php  (replace the old file ON THE LIVE SERVER only)
/**
 * Email settings for staff login codes (used only when the server is NOT localhost).
 * InfinityFree switches off PHP mail(), so codes go out through Gmail SMTP.
 * Fill these in on the live server only. Never share or commit real values.
 *
 * CSS_MAIL_PASSWORD must be a Google "App Password" (16 characters), not the normal Gmail password.
 * Create it at: Google Account > Security > 2-Step Verification > App passwords.
 */
define('CSS_MAIL_MODE',      'smtp');
define('CSS_MAIL_HOST',      'smtp.gmail.com');
define('CSS_MAIL_PORT',      587);                          // 465 = SSL, 587 = STARTTLS
define('CSS_MAIL_USERNAME',  'bcp.css.system@gmail.com');
define('CSS_MAIL_PASSWORD',  'vdumeimrnlohxqhk'); // <-- paste the NEW Google App Password here, ON THE LIVE SERVER ONLY. Never put it in chat, email, a zip or git.
define('CSS_MAIL_FROM_NAME', 'Class Scheduling System');