<?php
// >>> PLACE AT: css-system/includes/local_request.php (new file)  [batch 7]
/** True only for a request that really comes from the developer's own computer (XAMPP/Laragon on localhost). */
function isLocalRequest(): bool
{
    if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
        return false;
    }
    // A reverse proxy always adds one of these; a visitor cannot remove them.
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_FORWARDED', 'HTTP_X_FORWARDED_HOST'] as $h) {
        if (!empty($_SERVER[$h])) {
            return false;
        }
    }
    $host = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    return in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);   // add your own local host name here if you use one
}