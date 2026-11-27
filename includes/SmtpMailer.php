<?php
declare(strict_types=1);

/**
 * Small dependency-free SMTP client used when PHPMailer is not installed.
 * Supports plain, STARTTLS and implicit SSL SMTP with LOGIN/PLAIN authentication.
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
    private string $lastStep = '(connect)';

    public function addAttachment(string $filename, string $content): void
    {
        $this->attachments[] = ['name' => $filename, 'content' => $content];
    }

    public function send(): void
    {
        if ($this->host === '') {
            fail('SMTP is not configured. Ask the administrator to set the SMTP host in the database settings.');
        }
        if ($this->port < 1 || $this->port > 65535) {
            fail('Invalid SMTP port.');
        }
        if ($this->to === '' || !filter_var($this->to, FILTER_VALIDATE_EMAIL)) {
            fail('A valid destination email address is required.');
        }

        $secure = strtolower($this->secure);
        if (!in_array($secure, ['tls', 'ssl', 'none'], true)) {
            fail('Invalid SMTP security option.');
        }

        // Port 465 is implicit TLS/SSL even when some panels label it "TLS".
        if ($secure === 'tls' && $this->port === 465) {
            $secure = 'ssl';
        }

        $context = stream_context_create([
            'ssl' => [
                'peer_name' => $this->host,
                'crypto_method' => $this->cryptoMethod(),
            ],
        ]);

        $peer = ($secure === 'ssl' ? 'ssl://' : '') . $this->host . ':' . $this->port;
        $this->lastStep = 'connection to ' . $this->host . ':' . $this->port;
        $this->socket = @stream_socket_client(
            $peer,
            $errno,
            $errstr,
            20,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!$this->socket) {
            $hint = $this->port === 465 ? ' Port 465 usually requires SSL.' : '';
            throw new RuntimeException(
                'Unable to connect to ' . $this->host . ':' . $this->port . ': ' . $errstr . $hint
            );
        }
        stream_set_timeout($this->socket, 30);

        try {
            $this->lastStep = 'server greeting';
            $this->expect(220, $this->readResponse(), 'server greeting');

            $this->lastStep = 'EHLO';
            $ehlo = $this->command('EHLO ' . $this->helloHost(), 250, 'EHLO');
            $extensions = $this->extensionLines($ehlo);

            if ($secure === 'tls') {
                if (!$this->hasExtension($extensions, 'STARTTLS')) {
                    throw new RuntimeException(
                        $this->host . ':' . $this->port . ' does not support STARTTLS. '
                        . 'Use SSL on port 465, port 587 with TLS, or None if your mail host requires no encryption.'
                    );
                }
                $this->command('STARTTLS', 220, 'STARTTLS');
                if (!@stream_socket_enable_crypto($this->socket, true, $this->cryptoMethod())) {
                    throw new RuntimeException('SMTP TLS negotiation failed after STARTTLS.');
                }
                $ehlo = $this->command('EHLO ' . $this->helloHost(), 250, 'EHLO after STARTTLS');
                $extensions = $this->extensionLines($ehlo);
            }

            if ($this->username !== '') {
                $mechanisms = $this->authMechanisms($extensions);
                if (in_array('PLAIN', $mechanisms, true)) {
                    $blob = base64_encode("\0" . $this->username . "\0" . $this->password);
                    $plain = $this->command('AUTH PLAIN ' . $blob, [235, 334], 'AUTH PLAIN');
                    if ($plain['code'] === 334) {
                        $this->command($blob, 235, 'AUTH PLAIN response');
                    }
                } elseif (in_array('LOGIN', $mechanisms, true)) {
                    $this->command('AUTH LOGIN', 334, 'AUTH LOGIN');
                    $this->command(base64_encode($this->username), 334, 'AUTH username');
                    $this->command(base64_encode($this->password), 235, 'AUTH password');
                } elseif ($mechanisms === []) {
                    throw new RuntimeException(
                        'SMTP server did not offer a supported authentication method. '
                        . 'Check that this host/port allows SMTP AUTH, then use PLAIN or LOGIN.'
                    );
                } else {
                    throw new RuntimeException(
                        'SMTP server offers unsupported authentication: ' . implode(', ', $mechanisms) . '.'
                    );
                }
            }

            $this->command('MAIL FROM:<' . $this->from . '>', 250, 'MAIL FROM');
            $this->command('RCPT TO:<' . $this->to . '>', [250, 251], 'RCPT TO');
            $this->command('DATA', 354, 'DATA');

            $this->lastStep = 'message data';
            $this->writeAll($this->prepareMessage($this->buildMessage()));
            $this->expect(250, $this->readResponse(), 'message data');

            // Some servers close immediately after accepting the message.
            // The email is already delivered, so a failed QUIT handshake is not fatal.
            try {
                $this->command('QUIT', 221, 'QUIT');
            } catch (RuntimeException $ex) {
                // Ignore cleanup-only SMTP errors.
            }
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

    private function cryptoMethod(): int
    {
        $method = 0;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $method |= constant('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT');
        }
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $method |= constant('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT');
        }
        return $method > 0 ? $method : STREAM_CRYPTO_METHOD_TLS_CLIENT;
    }

    /** @return array<int,string> */
    private function extensionLines(array $response): array
    {
        $lines = [];
        foreach ($response['lines'] as $line) {
            $line = preg_replace('/^\d{3}(\s|-)?/', '', $line) ?? $line;
            $line = strtoupper(trim($line));
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        return $lines;
    }

    /** @param array<int,string> $lines */
    private function hasExtension(array $lines, string $name): bool
    {
        foreach ($lines as $line) {
            if (preg_match('/(?:^|\s)' . preg_quote($name, '/') . '(?:\s|$)/', $line)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<int,string> $lines @return array<int,string> */
    private function authMechanisms(array $lines): array
    {
        $mechanisms = [];
        foreach ($lines as $line) {
            if (!preg_match('/\bAUTH[ =](.+)$/', $line, $matches)) {
                continue;
            }
            foreach (preg_split('/\s+/', trim($matches[1])) ?: [] as $mechanism) {
                $mechanism = strtoupper(trim($mechanism));
                if ($mechanism !== '' && !in_array($mechanism, $mechanisms, true)) {
                    $mechanisms[] = $mechanism;
                }
            }
        }
        return $mechanisms;
    }

    /** Normalize line endings, escape leading dots, and close the DATA block. */
    private function prepareMessage(string $message): string
    {
        $message = str_replace(["\r\n", "\r"], "\n", $message);
        $message = preg_replace('/^\./m', '..', $message) ?? $message;
        $message = rtrim($message, "\n") . "\n.\n";
        return str_replace("\n", "\r\n", $message);
    }

    private function buildMessage(): string
    {
        $boundary = 'POSBOUND' . bin2hex(random_bytes(12));
        $subject = '=?UTF-8?B?' . base64_encode($this->subject) . '?=';
        $fromName = str_replace(["\r", "\n"], ' ', $this->fromName);
        $fromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $headers = [
            'From: ' . $fromName . ' <' . $this->from . '>',
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

    /**
     * @param int|array<int,int> $expectedCodes
     * @return array{code:int,lines:array<int,string>}
     */
    private function command(string $command, $expectedCodes, string $label = ''): array
    {
        $this->lastStep = $label !== '' ? $label : $this->redactCommand($command);
        fwrite($this->socket, $command . "\r\n");
        return $this->expect($expectedCodes, $this->readResponse());
    }

    private function redactCommand(string $command): string
    {
        // Raw AUTH responses are base64-encoded credentials and must never be shown.
        return preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $command) ? '(AUTH credential)' : $command;
    }

    private function writeAll(string $data): void
    {
        $written = 0;
        $length = strlen($data);
        while ($written < $length) {
            $sent = @fwrite($this->socket, substr($data, $written));
            if ($sent === false || $sent === 0) {
                throw new RuntimeException('SMTP connection closed unexpectedly after message data.');
            }
            $written += $sent;
        }
    }

    /** @return array{code:int,lines:array<int,string>} */
    private function readResponse(): array
    {
        $lines = [];
        $code = 0;
        while (true) {
            $line = fgets($this->socket, 8192);
            if ($line === false) {
                throw new RuntimeException(
                    'SMTP connection closed unexpectedly after ' . $this->lastStep . '.'
                );
            }
            $line = rtrim($line, "\r\n");
            $lines[] = $line;
            if (!preg_match('/^(\d{3})(\s|-|$)/', $line, $matches)) {
                continue;
            }
            $code = (int)$matches[1];
            // A hyphen means more lines follow; space/end means this is the final reply line.
            if ($matches[2] !== '-') {
                break;
            }
        }
        return ['code' => $code, 'lines' => $lines];
    }

    /**
     * @param int|array<int,int> $expectedCodes
     * @param array{code:int,lines:array<int,string>} $response
     * @return array{code:int,lines:array<int,string>}
     */
    private function expect($expectedCodes, array $response, string $command = ''): array
    {
        $codes = is_array($expectedCodes) ? $expectedCodes : [$expectedCodes];
        if (!in_array($response['code'], $codes, true)) {
            $label = $command !== '' ? $command : $this->lastStep;
            $detail = implode(' | ', $response['lines']);
            throw new RuntimeException(
                'SMTP error ' . $response['code'] . ' after ' . $label
                . ($detail !== '' ? ': ' . $detail : '.')
            );
        }
        return $response;
    }
}
