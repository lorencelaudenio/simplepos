<?php
declare(strict_types=1);

/**
 * Small dependency-free SMTP client used when PHPMailer is not installed.
 * Supports plain, STARTTLS and SSL SMTP with LOGIN/PLAIN authentication.
 */
class SmtpMailer
{
    public string $host = '';
    public int $port = 587;
    public string $secure = 'tls';
    public string $username = '';
    public string $password = '';
    public string $from = '';
    public string $fromName = 'POS Backup Service';
    public string $to = '';
    public string $subject = '';
    public string $body = '';
    private array $attachments = [];
    private $socket = null;

    public function addAttachment(string $filename, string $content): void
    {
        $this->attachments[] = ['name' => $filename, 'content' => $content];
    }

    public function send(): void
    {
        if ($this->host === '') {
            fail('SMTP is not configured. Ask the administrator to set the SMTP host in the database settings.');
        }
        if ($this->to === '' || !filter_var($this->to, FILTER_VALIDATE_EMAIL)) {
            fail('A valid destination email address is required.');
        }
        $peer = ($this->secure === 'ssl' ? 'ssl://' : '') . $this->host . ':' . $this->port;
        $this->socket = @stream_socket_client($peer, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
        if (!$this->socket) {
            throw new RuntimeException('Unable to connect to the SMTP server: ' . $errstr);
        }
        stream_set_timeout($this->socket, 30);
        try {
            $this->expect(220, $this->readResponse());
            $this->command('EHLO ' . $this->helloHost(), 250);
            if ($this->secure === 'tls') {
                $this->command('STARTTLS', 220);
                $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                    $method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                }
                if (!@stream_socket_enable_crypto($this->socket, true, $method)) {
                    throw new RuntimeException('SMTP TLS negotiation failed.');
                }
                $this->command('EHLO ' . $this->helloHost(), 250);
            }
            if ($this->username !== '') {
                try {
                    $this->command('AUTH LOGIN', 334);
                    $this->command(base64_encode($this->username), 334);
                    $this->command(base64_encode($this->password), 235);
                } catch (RuntimeException $ex) {
                    // Fall back to AUTH PLAIN for servers that do not advertise LOGIN.
                    $this->command('AUTH PLAIN ' . base64_encode("\0" . $this->username . "\0" . $this->password), 235);
                }
            }
            $this->command('MAIL FROM:<' . $this->from . '>', 250);
            $this->command('RCPT TO:<' . $this->to . '>', [250, 251]);
            $this->command('DATA', 354);
            $message = $this->buildMessage();
            $message = str_replace("\r\n", "\n", $message);
            $message = preg_replace('/^\./m', '..', $message) ?? $message;
            $message = str_replace("\n", "\r\n", $message);
            fwrite($this->socket, $message);
            $this->expect(250, $this->readResponse());
            $this->command('QUIT', 221);
        } finally {
            if (is_resource($this->socket)) {
                fclose($this->socket);
            }
            $this->socket = null;
        }
    }

    private function helloHost(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return preg_replace('/[^A-Za-z0-9.\-]/', '', $host) ?: 'localhost';
    }

    private function buildMessage(): string
    {
        $boundary = 'POSBOUND' . bin2hex(random_bytes(12));
        $subject = '=?UTF-8?B?' . base64_encode($this->subject) . '?=';
        $headers = [
            'From: ' . $this->fromName . ' <' . $this->from . '>',
            'To: <' . $this->to . '>',
            'Subject: ' . $subject,
            'Date: ' . date('r'),
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
        ];
        $body = '--' . $boundary . "\n"
            . "Content-Type: text/plain; charset=UTF-8\n"
            . "Content-Transfer-Encoding: 8bit\n\n"
            . $this->body . "\n\n";
        foreach ($this->attachments as $attachment) {
            $body .= '--' . $boundary . "\n"
                . 'Content-Type: application/octet-stream; name="' . $attachment['name'] . '"' . "\n"
                . 'Content-Disposition: attachment; filename="' . $attachment['name'] . '"' . "\n"
                . "Content-Transfer-Encoding: base64\n\n"
                . chunk_split(base64_encode($attachment['content']), 76, "\n") . "\n";
        }
        $body .= '--' . $boundary . "--\n";
        return implode("\n", $headers) . "\n\n" . $body;
    }

    private function command(string $command, $expectedCodes): array
    {
        fwrite($this->socket, $command . "\r\n");
        return $this->expect($expectedCodes, $this->readResponse(), $command);
    }

    private function readResponse(): array
    {
        $lines = [];
        $code = 0;
        while (true) {
            $line = fgets($this->socket, 8192);
            if ($line === false) {
                throw new RuntimeException('SMTP connection closed unexpectedly.');
            }
            $lines[] = rtrim($line, "\r\n");
            if (!preg_match('/^(\d{3})[\s-]/', $line, $matches)) {
                continue;
            }
            $code = (int)$matches[1];
            // A space after the code marks the final line of a multiline reply.
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return ['code' => $code, 'lines' => $lines];
    }

    private function expect($expectedCodes, array $response, string $command = ''): array
    {
        $codes = is_array($expectedCodes) ? $expectedCodes : [$expectedCodes];
        if (!in_array($response['code'], $codes, true)) {
            throw new RuntimeException(
                'SMTP error ' . $response['code'] . ($command ? ' after ' . $command : '') . ': '
                . implode(' | ', $response['lines'])
            );
        }
        return $response;
    }
}
