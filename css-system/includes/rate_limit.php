<?php
// >>> PLACE AT: css-system/includes/rate_limit.php, teacher-registration/includes/rate_limit.php and
//     student-portal/includes/rate_limit.php  (the SAME file in all 3 apps)  [batch 6]
/**
 * Per-IP rate limiter backed by the database, so it cannot be beaten by deleting cookies
 * (the old per-session counters could). Needs getDB() to be loaded already.
 * The table `rate_limits` is created automatically the first time it is used.
 *
 * Usage:  if (!rateLimitAllow('treg_code', 10, 3600)) { ...answer "too many requests"... }
 *         It records the hit and returns false once the visitor made more than $max hits in $windowSeconds.
 *
 * Visitor IP: REMOTE_ADDR by default. If InfinityFree's proxy makes everybody look like the same IP
 * (check the Audit Log), add to the app's config/db.php (or config.php):
 *     define('RATE_LIMIT_IP_HEADER', 'HTTP_X_FORWARDED_FOR');
 * The LAST address in that header is used because the proxy appends the real visitor address last.
 * Without this fix on a shared-proxy host, all visitors would share one bucket.
 */
if (!defined('RATE_LIMIT_IP_HEADER')) {
    define('RATE_LIMIT_IP_HEADER', '');
}

function rlVisitorKey(): string
{
    $ip  = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $hdr = RATE_LIMIT_IP_HEADER;
    if ($hdr !== '' && !empty($_SERVER[$hdr]) && is_string($_SERVER[$hdr])) {
        $parts = array_map('trim', explode(',', $_SERVER[$hdr]));
        $last  = end($parts);
        if (is_string($last) && filter_var($last, FILTER_VALIDATE_IP)) {
            $ip = $last;
        }
    }
    return substr($ip !== '' ? $ip : 'unknown', 0, 45);
}

/** @return bool true = allowed (hit recorded), false = limit reached */
function rateLimitAllow(string $bucket, int $max, int $windowSeconds): bool
{
    try {
        $pdo = getDB();
        static $ready = false;
        if (!$ready) {
            $pdo->exec('CREATE TABLE IF NOT EXISTS rate_limits (
                id     BIGINT AUTO_INCREMENT PRIMARY KEY,
                bucket VARCHAR(40) NOT NULL,
                ip     VARCHAR(45) NOT NULL,
                hit_at DATETIME NOT NULL,
                KEY idx_rl (bucket, ip, hit_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            $ready = true;
        }
        $ip  = rlVisitorKey();
        $now = time();

        if (random_int(1, 50) === 1) {   // housekeeping: forget hits older than a day
            $pdo->prepare('DELETE FROM rate_limits WHERE hit_at < ?')->execute([date('Y-m-d H:i:s', $now - 86400)]);
        }

        $st = $pdo->prepare('SELECT COUNT(*) FROM rate_limits WHERE bucket = ? AND ip = ? AND hit_at > ?');
        $st->execute([$bucket, $ip, date('Y-m-d H:i:s', $now - $windowSeconds)]);
        if ((int)$st->fetchColumn() >= $max) {
            return false;
        }
        $pdo->prepare('INSERT INTO rate_limits (bucket, ip, hit_at) VALUES (?,?,?)')->execute([$bucket, $ip, date('Y-m-d H:i:s', $now)]);
        return true;
    } catch (Throwable $ex) {
        error_log('rateLimitAllow failed: ' . $ex->getMessage());
        return true;   // never lock people out because the limiter itself broke
    }
}
