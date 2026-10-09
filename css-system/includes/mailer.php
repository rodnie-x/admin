<?php
/**
 * Minimal SMTP sender for the Class Scheduling System (no Composer needed).
 * Plain-text mail through Gmail on port 465 (SSL) or 587 (STARTTLS).
 * Settings live in config/mail.php. Nothing secret is ever written to the logs.
 */
function cssSmtpDeliver(string $to, string $subject, string $text): bool
{
    $conf = __DIR__ . '/../config/mail.php';
    if (!is_file($conf)) {
        error_log('CSS mail: config/mail.php is missing.');
        return false;
    }
    require_once $conf;

    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $subject = str_replace(["\r", "\n"], ' ', $subject);
    $host = CSS_MAIL_HOST;
    $port = (int)CSS_MAIL_PORT;
    $user = CSS_MAIL_USERNAME;
    if ($user === '' || CSS_MAIL_PASSWORD === '') {
        error_log('CSS mail: set CSS_MAIL_USERNAME and CSS_MAIL_PASSWORD in config/mail.php.');
        return false;
    }

    $fp = @stream_socket_client(($port === 465 ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 15);
    if (!$fp) {
        error_log("CSS mail: connect failed: $errstr ($errno)");
        return false;
    }
    stream_set_timeout($fp, 15);

    $read = function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') { break; }
        }
        return $data;
    };
    $send = function (string $cmd, string $expect) use ($fp, $read): bool {
        fwrite($fp, $cmd . "\r\n");
        $reply = $read();
        if (strpos($reply, $expect) !== 0) {
            error_log('CSS mail: unexpected reply (wanted ' . $expect . '): ' . trim($reply));   // the command itself is never logged
            return false;
        }
        return true;
    };
    $fail = function () use ($fp): bool { fclose($fp); return false; };

    if (strpos($read(), '220') !== 0 || !$send('EHLO localhost', '250')) { return $fail(); }
    if ($port === 587) {
        if (!$send('STARTTLS', '220') || !stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) || !$send('EHLO localhost', '250')) { return $fail(); }
    }
    if (!$send('AUTH LOGIN', '334') || !$send(base64_encode($user), '334') || !$send(base64_encode(CSS_MAIL_PASSWORD), '235')) { return $fail(); }
    if (!$send("MAIL FROM:<$user>", '250') || !$send("RCPT TO:<$to>", '250') || !$send('DATA', '354')) { return $fail(); }

    $headers = [
        'Date: ' . date('r'),
        'From: =?UTF-8?B?' . base64_encode(CSS_MAIL_FROM_NAME) . "?= <$user>",
        "To: <$to>",
        'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
    ];
    fwrite($fp, implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($text)) . "\r\n.\r\n");
    $ok = strpos($read(), '250') === 0;
    if (!$ok) { error_log('CSS mail: message rejected by the SMTP server.'); }
    $send('QUIT', '221');
    fclose($fp);
    return $ok;
}
