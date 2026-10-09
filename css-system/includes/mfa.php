<?php
// >>> PLACE AT: css-system/includes/mfa.php (new)  [batch 12]
/**
 * Two-step login (TOTP) helpers. Loaded by includes/auth.php.
 * Password (+ emailed code for staff) first, then the 6-digit code from the authenticator app.
 */

/** Roles that MUST use two-step login. Everyone else can turn it on voluntarily (sidebar link). */
const MFA_REQUIRED_ROLES = ['admin', 'registrar'];
const MFA_ISSUER         = 'BCP Class Scheduling';
const MFA_MAX_FAILS      = 5;   // wrong codes in one session before the account is locked

/** Logged in with a correct password but the second step is not done yet. */
function mfaPending(): bool
{
    return isLoggedIn()
        && empty($_SESSION['mfa_ok'])
        && (!empty($_SESSION['mfa_enabled']) || in_array($_SESSION['role'] ?? '', MFA_REQUIRED_ROLES, true));
}

/** @return array|null null when the MFA columns do not exist yet (sql/mfa_privacy.sql not imported) */
function mfaUser(int $userId): ?array
{
    try {
        $st = getDB()->prepare('SELECT totp_secret_enc, totp_enabled, totp_last_step, mfa_recovery FROM users WHERE id = ?');
        $st->execute([$userId]);
        return $st->fetch() ?: null;
    } catch (Throwable $ex) {
        error_log('mfaUser: ' . $ex->getMessage());
        return null;
    }
}

function mfaRecoveryHash(string $clean): string
{
    return hash_hmac('sha256', $clean, cryptoKey());
}

/** @return array{0:string[],1:string} [plain codes to show ONCE, JSON of their hashes to store] */
function mfaNewRecoveryCodes(int $count = 8): array
{
    $plain = [];
    $hashes = [];
    for ($i = 0; $i < $count; $i++) {
        $raw = bin2hex(random_bytes(5));            // 10 characters
        $plain[]  = substr($raw, 0, 5) . '-' . substr($raw, 5);
        $hashes[] = mfaRecoveryHash($raw);
    }
    return [$plain, json_encode($hashes)];
}

/** One-time recovery code (for a lost phone). Used codes are removed. */
function mfaUseRecovery(int $userId, string $input, ?array $user): bool
{
    $list = json_decode((string)($user['mfa_recovery'] ?? ''), true);
    $clean = strtolower(preg_replace('/[^a-z0-9]/i', '', $input));
    if (!is_array($list) || !$list || strlen($clean) !== 10) {
        return false;
    }
    $h = mfaRecoveryHash($clean);
    foreach ($list as $i => $stored) {
        if (is_string($stored) && hash_equals($stored, $h)) {
            unset($list[$i]);
            getDB()->prepare('UPDATE users SET mfa_recovery = ? WHERE id = ?')->execute([json_encode(array_values($list)), $userId]);
            return true;
        }
    }
    return false;
}
