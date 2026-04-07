<?php

class SmtpMailer {
    private $config;

    public function __construct(array $config) {
        $this->config = $config;
    }

    public function send($to, $subject, $body) {
        $socket = null;

        try {
            $socket = $this->openConnection();
            $this->expect($socket, 220, 'Server greeting');

            $this->command($socket, 'EHLO scholarship-finder.local', 250, 'EHLO');

            if (($this->config['encryption'] ?? 'tls') === 'tls') {
                $this->command($socket, 'STARTTLS', 220, 'STARTTLS');

                $cryptoEnabled = @stream_socket_enable_crypto(
                    $socket,
                    true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT
                );

                if ($cryptoEnabled !== true) {
                    throw new RuntimeException('Unable to secure the SMTP connection with TLS.');
                }

                $this->command($socket, 'EHLO scholarship-finder.local', 250, 'EHLO after STARTTLS');
            }

            $this->command($socket, 'AUTH LOGIN', 334, 'AUTH LOGIN');
            $this->command($socket, base64_encode($this->config['username']), 334, 'SMTP username');
            $this->command($socket, base64_encode($this->config['password']), 235, 'SMTP password');
            $this->command($socket, 'MAIL FROM:<' . $this->config['from_email'] . '>', 250, 'MAIL FROM');
            $this->command($socket, 'RCPT TO:<' . $to . '>', [250, 251], 'RCPT TO');
            $this->command($socket, 'DATA', 354, 'DATA');

            $message = $this->buildMessage($to, $subject, $body);
            fwrite($socket, $message . "\r\n.\r\n");
            $this->expect($socket, 250, 'Message body');

            $this->command($socket, 'QUIT', 221, 'QUIT');

            $this->log('Email accepted by SMTP server for ' . $to);

            return [
                'success' => true,
                'error' => null,
            ];
        } catch (Throwable $e) {
            $this->log('SMTP send failed for ' . $to . ': ' . $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    private function openConnection() {
        $host = $this->config['host'];
        $port = (int) $this->config['port'];
        $timeout = (int) ($this->config['timeout'] ?? 20);

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => (bool) ($this->config['verify_peer'] ?? true),
                'verify_peer_name' => (bool) ($this->config['verify_peer'] ?? true),
                'allow_self_signed' => false,
            ],
        ]);

        $transport = ($this->config['encryption'] ?? 'tls') === 'ssl'
            ? 'ssl://' . $host . ':' . $port
            : 'tcp://' . $host . ':' . $port;

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($transport, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);

        if (!$socket) {
            throw new RuntimeException('SMTP connection failed: ' . trim($errstr ?: 'Unknown error'));
        }

        stream_set_timeout($socket, $timeout);

        return $socket;
    }

    private function buildMessage($to, $subject, $body) {
        $fromEmail = $this->config['from_email'];
        $fromName = $this->encodeHeader($this->config['from_name']);
        $replyTo = $this->config['reply_to'] ?? $fromEmail;
        $domain = substr(strrchr($fromEmail, '@'), 1) ?: 'localhost';

        $headers = [
            'Date: ' . date('r'),
            'From: ' . $fromName . ' <' . $fromEmail . '>',
            'To: <' . $to . '>',
            'Reply-To: ' . $replyTo,
            'Subject: ' . $this->encodeHeader($subject),
            'Message-ID: <' . uniqid('sf-', true) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'X-Mailer: Scholarship Finder SMTP',
        ];

        $normalizedBody = str_replace(["\r\n", "\r"], "\n", $body);
        $bodyLines = explode("\n", $normalizedBody);
        foreach ($bodyLines as &$line) {
            if ($line !== '' && $line[0] === '.') {
                $line = '.' . $line;
            }
        }
        unset($line);

        return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $bodyLines);
    }

    private function encodeHeader($value) {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function command($socket, $command, $expectedCodes, $label) {
        fwrite($socket, $command . "\r\n");
        $this->expect($socket, $expectedCodes, $label);
    }

    private function expect($socket, $expectedCodes, $label) {
        $response = $this->readResponse($socket);
        $code = (int) substr($response, 0, 3);
        $expectedCodes = (array) $expectedCodes;

        if (!in_array($code, $expectedCodes, true)) {
            throw new RuntimeException($label . ' failed: ' . trim($response));
        }

        return $response;
    }

    private function readResponse($socket) {
        $response = '';

        while (!feof($socket)) {
            $line = fgets($socket, 515);
            if ($line === false) {
                break;
            }

            $response .= $line;

            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }

        if ($response === '') {
            throw new RuntimeException('Empty SMTP response received.');
        }

        return $response;
    }

    private function log($message) {
        $logFile = $this->config['log_file'] ?? null;
        if (!$logFile) {
            return;
        }

        $directory = dirname($logFile);
        if (!is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }

        @error_log('[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, 3, $logFile);
    }
}
?>
