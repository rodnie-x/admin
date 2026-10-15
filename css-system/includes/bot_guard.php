<?php
/**
 * Honeypot + minimum-fill-time bot guard (same file in all 3 apps).
 * Works with strict CSP, no external service.
 * Place at: css-system/includes/bot_guard.php,
 *           teacher-registration/includes/bot_guard.php,
 *           student-portal/includes/bot_guard.php
 */
function botFieldHtml(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['bot_t'] = time();
    return '<div style="position:absolute;left:-9999px" aria-hidden="true">'
        . '<label>Leave this empty<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>';
}

function botLooksLikeBot(int $minSeconds = 3): bool
{
    $t = (int)($_SESSION['bot_t'] ?? 0);
    $bot = (isset($_POST['website']) && $_POST['website'] !== '') || $t === 0 || (time() - $t) < $minSeconds;
    if ($bot && function_exists('rateLimitAllow')) {
        rateLimitAllow('bot_hit', 1000000, 3600); // shows up in Security alerts counters
    }
    return $bot;
}
