<?php
// >>> PLACE AT: css-system/includes/totp.php (new)  [batch 12]
/** TOTP (RFC 6238, 6 digits, 30 seconds, SHA-1): works with Google Authenticator, Microsoft Authenticator, Authy, etc. No Composer needed. */

const TOTP_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function totpB32Encode(string $bin): string
{
    $bits = '';
    foreach (str_split($bin) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= TOTP_ALPHABET[bindec(str_pad($chunk, 5, '0'))];
    }
    return $out;
}

function totpB32Decode(string $s): string
{
    $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s));
    if ($s === '') {
        return '';
    }
    $bits = '';
    foreach (str_split($s) as $c) {
        $bits .= str_pad(decbin(strpos(TOTP_ALPHABET, $c)), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}

/** New random secret (160 bits) as base32 text. */
function totpGenerateSecret(): string
{
    return totpB32Encode(random_bytes(20));
}

function totpAt(string $key, int $step): string
{
    $hash   = hash_hmac('sha1', pack('J', $step), $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $num    = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
    return str_pad((string)($num % 1000000), 6, '0', STR_PAD_LEFT);
}

/**
 * Checks a 6-digit code (one step of clock drift allowed each way).
 * $lastStep blocks replay: a code from a step that was already used is refused.
 * @return int|null the matched time step (store it as the new "last step"), or null if wrong
 */
function totpVerify(string $secretB32, string $code, int $lastStep = 0): ?int
{
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{6}$/', $code)) {
        return null;
    }
    $key = totpB32Decode($secretB32);
    if ($key === '') {
        return null;
    }
    $now = intdiv(time(), 30);
    for ($s = $now - 1; $s <= $now + 1; $s++) {
        if ($s > $lastStep && hash_equals(totpAt($key, $s), $code)) {
            return $s;
        }
    }
    return null;
}

/** otpauth:// link (phones open it in the authenticator app). */
function totpUri(string $secretB32, string $account, string $issuer): string
{
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
         . '?secret=' . $secretB32 . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}
