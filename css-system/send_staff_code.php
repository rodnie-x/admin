<?php
// >>> PLACE AT: css-system/send_staff_code.php (replace the old file)  [batch 6: per-IP rate limit]
/**
 * "Send code" button on the staff log in form.
 * Always answers with the same message whether or not the Gmail has an account.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/staff_auth.php';
require_once __DIR__ . '/includes/rate_limit.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function staffJson(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    staffJson(405, ['ok' => false, 'message' => 'Use the Send code button on the login page.']);
}
if (!staffCsrfOk()) {
    staffJson(419, ['ok' => false, 'message' => 'Your session expired. Refresh the page and try again.']);
}

// Per-IP cap (database): cannot be beaten by deleting cookies.
if (!rateLimitAllow('css_code', 10, 3600)) {
    staffJson(429, ['ok' => false, 'message' => 'Too many code requests from this network. Try again later.']);
}

// Per-browser-session cap: at most 8 requests in 10 minutes.
$now    = time();
$recent = array_filter($_SESSION['staff_code_requests'] ?? [], fn($t) => $now - $t < 600);
if (count($recent) >= 8) {
    $_SESSION['staff_code_requests'] = $recent;
    staffJson(429, ['ok' => false, 'message' => 'Too many code requests. Wait a few minutes and try again.']);
}
$recent[] = $now;
$_SESSION['staff_code_requests'] = array_values($recent);

$email = strtolower(staffStr($_POST, 'email'));
if (!staffIsGmail($email)) {
    staffJson(422, ['ok' => false, 'message' => 'Enter your Gmail address (ending in @gmail.com) first.']);
}

$response = [
    'ok'       => true,
    'message'  => 'If that Gmail has an active account, a login code is on its way. It expires in ' . STAFF_CODE_TTL_MINUTES . ' minutes.',
    'cooldown' => STAFF_CODE_RESEND_SECONDS,
];

$user = staffFindUserByEmail($email);
if ($user && $user['status'] === 'active' && staffCodeCooldownLeft((int)$user['id']) === 0) {
    $result = staffIssueCode($user);
    if ($result['debug_code'] !== null) {
        $response['debug_code'] = $result['debug_code'];   // local testing only (STAFF_MAIL_MODE = 'log')
    }
}

staffJson(200, $response);