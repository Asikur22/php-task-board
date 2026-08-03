<?php
/**
 * Minimal SMTP mailer (no Composer deps).
 * Supports AUTH LOGIN with TLS (STARTTLS) or SSL.
 */

declare(strict_types=1);

/**
 * Send an email via the SMTP settings in auth.php.
 * Returns true on success; throws RuntimeException on failure.
 */
function smtp_send(string $to, string $subject, string $htmlBody, string $textBody = ''): bool
{
    if (!smtp_is_configured()) {
        throw new RuntimeException('SMTP is not configured. Set MAIL_* in the project .env file.');
    }

    $host = SMTP_HOST;
    $port = (int) SMTP_PORT;
    $enc  = strtolower(SMTP_ENCRYPTION);
    $user = SMTP_USER;
    $pass = SMTP_PASS;
    $fromEmail = SMTP_FROM_EMAIL;
    $fromName  = SMTP_FROM_NAME;

    if ($textBody === '') {
        $textBody = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    $remote = ($enc === 'ssl' ? 'ssl://' : '') . $host;
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client(
        $remote . ':' . $port,
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT
    );
    if (!$fp) {
        throw new RuntimeException("SMTP connect failed: {$errstr} ({$errno})");
    }
    stream_set_timeout($fp, 20);

    try {
        smtp_expect($fp, [220]);
        $ehloHost = preg_replace('/:\d+$/', '', ($_SERVER['SERVER_NAME'] ?? 'localhost')) ?: 'localhost';
        smtp_cmd($fp, 'EHLO ' . $ehloHost, [250]);

        if ($enc === 'tls') {
            smtp_cmd($fp, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP STARTTLS failed.');
            }
            smtp_cmd($fp, 'EHLO ' . $ehloHost, [250]);
        }

        if ($user !== '') {
            smtp_cmd($fp, 'AUTH LOGIN', [334]);
            smtp_cmd($fp, base64_encode($user), [334]);
            smtp_cmd($fp, base64_encode($pass), [235]);
        }

        smtp_cmd($fp, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        smtp_cmd($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
        smtp_cmd($fp, 'DATA', [354]);

        $boundary = 'b_' . bin2hex(random_bytes(8));
        $headers = [
            'Date: ' . date('r'),
            'From: ' . smtp_encode_address($fromName, $fromEmail),
            'To: <' . $to . '>',
            'Subject: ' . smtp_encode_header($subject),
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer: TaskBoard-SMTP',
        ];

        $body  = implode("\r\n", $headers) . "\r\n\r\n";
        $body .= '--' . $boundary . "\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($textBody)) . "\r\n";
        $body .= '--' . $boundary . "\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";
        $body .= '--' . $boundary . "--\r\n";

        // Dot-stuff lines that start with '.'
        $body = preg_replace('/^\./m', '..', $body) ?? $body;
        fwrite($fp, $body . "\r\n.\r\n");
        smtp_expect($fp, [250]);
        smtp_cmd($fp, 'QUIT', [221, 250]);
    } finally {
        fclose($fp);
    }

    return true;
}

function smtp_is_configured(): bool
{
    return SMTP_HOST !== '' && SMTP_FROM_EMAIL !== '';
}

function smtp_cmd($fp, string $cmd, array $okCodes): string
{
    fwrite($fp, $cmd . "\r\n");
    return smtp_expect($fp, $okCodes);
}

function smtp_expect($fp, array $okCodes): string
{
    $response = '';
    while (($line = fgets($fp, 515)) !== false) {
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
        if ($line === '') {
            break;
        }
    }
    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $okCodes, true)) {
        throw new RuntimeException('SMTP unexpected reply (' . $code . '): ' . trim($response));
    }
    return $response;
}

function smtp_encode_header(string $value): string
{
    if (preg_match('/[^\x20-\x7E]/', $value)) {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
    return $value;
}

function smtp_encode_address(string $name, string $email): string
{
    $safe = smtp_encode_header($name);
    return ($safe !== '' ? $safe . ' ' : '') . '<' . $email . '>';
}
