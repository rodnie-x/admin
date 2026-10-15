<?php
/**
 * Shared password policy (same file in all 3 apps).
 * Place at: css-system/includes/password_policy.php,
 *           teacher-registration/includes/password_policy.php,
 *           student-portal/includes/password_policy.php
 */
function passwordProblems(string $pw, array $avoid = []): array
{
    $p = [];
    if (strlen($pw) < 10 || strlen($pw) > 72) {
        $p[] = 'Use 10 to 72 characters.';
    }
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
        $p[] = 'Include a letter and a number.';
    }
    $common = ['password', 'password1', 'password123', 'admin123', 'qwerty123', '12345678', '123456789', 'welcome1', 'iloveyou', 'bcp12345', 'admin@123'];
    if (in_array(strtolower($pw), $common, true)) {
        $p[] = 'That password is too common.';
    }
    foreach ($avoid as $a) {
        $a = is_string($a) ? trim($a) : '';
        // Also compare the email local-part (before @) so "juan@gmail.com" blocks "juan".
        $parts = explode('@', $a);
        $candidates = [$a];
        if (count($parts) === 2 && strlen($parts[0]) >= 3) {
            $candidates[] = $parts[0];
        }
        foreach ($candidates as $c) {
            if ($c !== '' && strlen($c) >= 3 && stripos($pw, $c) !== false) {
                $p[] = 'Do not include your name, username or email.';
                break 2;
            }
        }
    }
    return $p;
}
