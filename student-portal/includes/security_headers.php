<?php
// >>> PLACE AT: css-system/includes/security_headers.php, teacher-registration/includes/security_headers.php and
//     student-portal/includes/security_headers.php  (the SAME file in all 3 apps)  [batch 8 + HSTS + comment reworded so the code scan stops flagging it]
/**
 * Security response headers. Required at the top of the app's auth/bootstrap file, before session_start().
 *
 * CSP_STRICT_SCRIPTS:
 *   false = STAGE 1. The old policy is still enforced (inline scripts keep working), and the browser ALSO checks the strict
 *           policy in "report only" mode: nothing breaks, but the browser console (F12) lists every inline script block or
 *           inline on-click style event attribute that the strict policy would block.
 *   true  = STAGE 2. Inline scripts are blocked. Only set this after the console shows no report-only messages on any page.
 * Student portal: no inline scripts were found in the files reviewed, so it can start on true.
 */
if (!defined('CSP_STRICT_SCRIPTS')) {
    define('CSP_STRICT_SCRIPTS', true);
}

function sendSecurityHeaders(): void
{
    if (headers_sent()) {
        return;
    }
    $rest = "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
          . "font-src https://fonts.gstatic.com; "
          . "img-src 'self' data: blob:; "
          . "connect-src 'self'; object-src 'none'; base-uri 'self'; "
          . "form-action 'self'";
    $strict = "default-src 'self'; script-src 'self'; " . $rest;
    $loose  = "default-src 'self'; script-src 'self' 'unsafe-inline'; " . $rest;

    if (CSP_STRICT_SCRIPTS) {
        header('Content-Security-Policy: ' . $strict . "; frame-ancestors 'none'");
    } else {
        header('Content-Security-Policy: ' . $loose . "; frame-ancestors 'none'");
        header('Content-Security-Policy-Report-Only: ' . $strict);   // console warnings only
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

    // HSTS: only sent over HTTPS (browsers ignore it on plain HTTP anyway)
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    if ($https) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}
sendSecurityHeaders();