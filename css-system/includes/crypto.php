<?php
// >>> PLACE AT: css-system/includes/crypto.php, teacher-registration/includes/crypto.php and
//     student-portal/includes/crypto.php  (the SAME file in all 3 apps)  [batch 12]
/**
 * Field-level encryption (AES-256-GCM) and masking helpers.
 *
 * KEY: config/app_key.php defines APP_DATA_KEY (64 hex characters). It is created automatically the first
 * time css-system needs it. Then COPY that same file to teacher-registration/config/ and
 * student-portal/config/ (those two apps define CRYPTO_NO_AUTOGEN so a missing key fails loudly
 * instead of silently creating a different key). Keep a backup of the key OUTSIDE the database backup:
 * without it, encrypted values cannot be read again.
 *
 * Stored format: "enc1:" + base64(iv 12 bytes | tag 16 bytes | ciphertext).
 * decField() returns old plain-text values unchanged, so existing rows keep working until migrated.
 */
const CRYPTO_PREFIX = 'enc1:';

function cryptoKey(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    $file = __DIR__ . '/../config/app_key.php';
    if (!is_file($file)) {
        if (defined('CRYPTO_NO_AUTOGEN')) {
            throw new RuntimeException('config/app_key.php is missing. Copy it from css-system/config/.');
        }
        $hex = bin2hex(random_bytes(32));
        if (@file_put_contents($file, "<?php\ndefine('APP_DATA_KEY', '$hex');\n", LOCK_EX) === false) {
            throw new RuntimeException('Cannot write config/app_key.php (give the config/ folder write permission once).');
        }
        @chmod($file, 0644);
        $ht = dirname($file) . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "Require all denied\n");
        }
    }
    require_once $file;
    $bin = defined('APP_DATA_KEY') ? hex2bin((string)APP_DATA_KEY) : false;
    if ($bin === false || strlen($bin) !== 32) {
        throw new RuntimeException('APP_DATA_KEY in config/app_key.php is not 64 hex characters.');
    }
    return $key = $bin;
}

/** Encrypts a value. null and '' stay as they are; an already encrypted value is not encrypted twice. */
function encField(?string $plain): ?string
{
    if ($plain === null || $plain === '' || str_starts_with($plain, CRYPTO_PREFIX)) {
        return $plain;
    }
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', cryptoKey(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ct === false) {
        throw new RuntimeException('Encryption failed.');
    }
    return CRYPTO_PREFIX . base64_encode($iv . $tag . $ct);
}

/** Decrypts a value. Returns '' if it cannot be read (wrong key or tampered data). */
function decField(?string $stored): string
{
    if ($stored === null || $stored === '') {
        return '';
    }
    if (!str_starts_with($stored, CRYPTO_PREFIX)) {
        return $stored;   // old plain-text row, not migrated yet
    }
    $raw = base64_decode(substr($stored, strlen(CRYPTO_PREFIX)), true);
    if ($raw === false || strlen($raw) < 29) {
        return '';
    }
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', cryptoKey(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? '' : $plain;
}

/* ---------------- Masking (for lists, logs and anything shown to people who do not need the full value) ---------------- */

/** 09171234567 -> 0917*****67 */
function maskPhone(string $v): string
{
    $d = preg_replace('/\s+/', '', $v);
    $n = strlen($d);
    return $n < 7 ? str_repeat('*', $n) : substr($d, 0, 4) . str_repeat('*', $n - 6) . substr($d, -2);
}

/** juan.delacruz@gmail.com -> j***@gmail.com  (text without an @ is returned unchanged) */
function maskEmail(string $v): string
{
    if (strpos($v, '@') === false) {
        return $v;
    }
    [$local, $domain] = explode('@', $v, 2);
    return mb_substr($local, 0, 1) . str_repeat('*', max(1, mb_strlen($local) - 1)) . '@' . $domain;
}

/** 260000123 -> ******123 */
function maskId(string $v): string
{
    $n = strlen($v);
    return $n <= 3 ? str_repeat('*', $n) : str_repeat('*', $n - 3) . substr($v, -3);
}
