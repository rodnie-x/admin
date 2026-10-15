<?php
/**
 * Minimal mailer - no Composer needed.
 *   MAIL_MODE 'log'  -> appends the message to storage/mail.log (local testing)
 *   MAIL_MODE 'smtp' -> sends through Gmail SMTP (465 SSL or 587 STARTTLS)
 */
function sendMail(string $to, string $subject, string $text, string $html = ''): bool
{
    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $subject = str_replace(["\r", "\n"], ' ', $subject);
    if ($html === '') {
        $html = '<p>' . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) . '</p>';
    }

    if (MAIL_MODE !== 'smtp') {
        $dir = __DIR__ . '/../storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $entry = '[' . date('Y-m-d H:i:s') . "] TO: $to | SUBJECT: $subject\n$text\n" . str_repeat('-', 60) . "\n";
        return @file_put_contents($dir . '/mail.log', $entry, FILE_APPEND | LOCK_EX) !== false;
    }

    return smtpDeliver($to, $subject, $text, $html);
}

function smtpDeliver(string $to, string $subject, string $text, string $html): bool
{
    $host = MAIL_HOST;
    $port = (int)MAIL_PORT;
    $from = MAIL_FROM_ADDRESS !== '' ? MAIL_FROM_ADDRESS : MAIL_USERNAME;

    if ($from === '' || MAIL_USERNAME === '' || MAIL_PASSWORD === '') {
        error_log('SMTP is not configured: set MAIL_USERNAME and MAIL_PASSWORD in config/config.php.');
        return false;
    }

    $errno = 0;
    $errstr = '';
    $prefix = $port === 465 ? 'ssl://' : 'tcp://';
    $fp = @stream_socket_client($prefix . $host . ':' . $port, $errno, $errstr, 15);
    if (!$fp) {
        error_log("SMTP connect failed: $errstr ($errno)");
        return false;
    }
    stream_set_timeout($fp, 15);

    // Reads one full (possibly multi-line) SMTP reply.
    $read = function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };
    // Sends a command and checks the reply code. Never logs the command (it may hold credentials).
    $send = function (string $cmd, string $expect) use ($fp, $read): bool {
        fwrite($fp, $cmd . "\r\n");
        $reply = $read();
        if (strpos($reply, $expect) !== 0) {
            error_log('SMTP unexpected reply (wanted ' . $expect . '): ' . trim($reply));
            return false;
        }
        return true;
    };
    $fail = function () use ($fp): bool {
        fclose($fp);
        return false;
    };

    if (strpos($read(), '220') !== 0) {
        return $fail();
    }
    if (!$send('EHLO localhost', '250')) {
        return $fail();
    }
    if ($port === 587) {
        if (!$send('STARTTLS', '220')) {
            return $fail();
        }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            return $fail();
        }
        if (!$send('EHLO localhost', '250')) {
            return $fail();
        }
    }
    
    if (!$send('AUTH LOGIN', '334')
        || !$send(base64_encode(MAIL_USERNAME), '334')
        || !$send(base64_encode(MAIL_PASSWORD), '235')) {
        return $fail();
    }
    if (!$send("MAIL FROM:<$from>", '250')
        || !$send("RCPT TO:<$to>", '250')
        || !$send('DATA', '354')) {
        return $fail();
    }

    $boundary = 'b' . bin2hex(random_bytes(12));
    $headers = [
        'Date: ' . date('r'),
        'From: =?UTF-8?B?' . base64_encode(MAIL_FROM_NAME) . "?= <$from>",
        "To: <$to>",
        'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];
    $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
          . chunk_split(base64_encode($text))
          . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
          . chunk_split(base64_encode($html))
          . "--$boundary--";

    fwrite($fp, implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n");
    $reply = $read();
    $ok = strpos($reply, '250') === 0;
    if (!$ok) {
        error_log('SMTP message rejected: ' . trim($reply));
    }
    $send('QUIT', '221');
    fclose($fp);
    return $ok;
}
