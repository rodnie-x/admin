<?php
// >>> PLACE AT: css-system/includes/waf.php, teacher-registration/includes/waf.php and
//     student-portal/includes/waf.php  (the SAME file in all 3 apps)  [batch 13]
/**
 * Small request filter ("mini WAF") that runs inside the app. It is NOT a replacement for a real WAF such as
 * Cloudflare, but it stops the most common automated probes before any page code runs.
 * It looks only at the URL, the query string, the HTTP method and the User-Agent. It never reads POST bodies,
 * so passwords and form text can never be blocked by mistake.
 * Blocked requests get 403 and are counted (bucket "waf_hit") so Security Alerts can warn about floods.
 * Call wafInspect() once, right after the database file is loaded.
 */
function wafInspect(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $uri    = (string)($_SERVER['REQUEST_URI'] ?? '');
    $qs     = rawurldecode((string)($_SERVER['QUERY_STRING'] ?? ''));
    $ua     = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $path   = rawurldecode(rawurldecode($uri));   // decoded twice: catches %252e%252e

    $reason = null;
    if (!in_array($method, ['GET', 'POST', 'HEAD', 'OPTIONS'], true)) {
        $reason = 'method ' . $method;
    } elseif (strlen($uri) > 2000) {
        $reason = 'very long URL';
    } elseif (strpos($path, "\0") !== false || preg_match('#(\.\./|\.\.\\\\)#', $path)) {
        $reason = 'path traversal';
    } elseif (preg_match('#\b(php|data|phar|expect|zip|glob)://#i', $path)) {
        $reason = 'stream wrapper';
    } elseif (preg_match('/<\s*script|javascript:|\bon(error|load)\s*=/i', $qs)) {
        $reason = 'script in query string';
    } elseif (preg_match('/union(\s|\+|\/\*.*?\*\/)+select|information_schema|\bsleep\s*\(\s*\d|\bbenchmark\s*\(|load_file\s*\(|into\s+outfile/i', $qs)) {
        $reason = 'SQL injection pattern';
    } elseif ($ua !== '' && preg_match('/sqlmap|nikto|acunetix|nessus|masscan|havij|wpscan|dirbuster|zgrab|nmap/i', $ua)) {
        $reason = 'scanner tool';
    }
    if ($reason === null) {
        return;
    }

    error_log('WAF blocked (' . $reason . ') from ' . ($_SERVER['REMOTE_ADDR'] ?? '?') . ': ' . substr($uri, 0, 200));
    try {
        if (function_exists('getDB') && is_file(__DIR__ . '/rate_limit.php')) {
            require_once __DIR__ . '/rate_limit.php';
            rateLimitAllow('waf_hit', 1000000, 3600);   // only used as a counter
        }
    } catch (Throwable $ex) { /* counting must never break the block */ }

    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Request blocked.');
}
