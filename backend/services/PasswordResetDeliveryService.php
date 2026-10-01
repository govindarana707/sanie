<?php

require_once __DIR__ . '/../config/config.php';

interface PasswordResetMailTransportInterface {
    public function send(string $recipient, string $subject, string $htmlBody, string $textBody): void;
}

class PasswordResetDeliveryConfigurationException extends RuntimeException {}

final class PhpMailerSmtpPasswordResetTransport implements PasswordResetMailTransportInterface {
    private array $config;

    public function __construct(array $config) {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (!is_file($autoload)) throw new PasswordResetDeliveryConfigurationException('Production mail dependency is not installed.');
        require_once $autoload;
        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) throw new PasswordResetDeliveryConfigurationException('Production mail dependency is unavailable.');
        $this->config = $this->validateConfig($config);
    }

    public function send(string $recipient, string $subject, string $htmlBody, string $textBody): void {
        if (!$this->validAddress($recipient)) throw new InvalidArgumentException('Invalid password-reset recipient.');
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $this->config['host'];
        $mail->Port = $this->config['port'];
        $mail->Timeout = $this->config['timeout'];
        $mail->SMTPKeepAlive = false;
        $mail->SMTPDebug = 0;
        $mail->CharSet = 'UTF-8';
        $mail->SMTPSecure = $this->config['encryption'] === 'ssl'
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPAutoTLS = true;
        if ($this->config['username'] !== '') {
            $mail->SMTPAuth = true;
            $mail->Username = $this->config['username'];
            $mail->Password = $this->config['password'];
        }
        $mail->setFrom($this->config['from_address'], $this->config['from_name']);
        $mail->addAddress($recipient);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody;
        $mail->send();
    }

    private function validateConfig(array $config): array {
        if (($config['transport'] ?? '') !== 'smtp') throw new PasswordResetDeliveryConfigurationException('MAIL_TRANSPORT must be smtp in production.');
        $host = trim((string)($config['host'] ?? ''));
        if ($host === '' || !preg_match('/\A(?:[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?|\[[0-9A-Fa-f:.]+\])\z/', $host)) throw new PasswordResetDeliveryConfigurationException('MAIL_HOST is missing or invalid.');
        $port = filter_var($config['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false) throw new PasswordResetDeliveryConfigurationException('MAIL_PORT is invalid.');
        $encryption = strtolower(trim((string)($config['encryption'] ?? '')));
        if (!in_array($encryption, ['tls', 'ssl'], true)) throw new PasswordResetDeliveryConfigurationException('MAIL_ENCRYPTION must be tls or ssl.');
        $username = (string)($config['username'] ?? '');
        $password = (string)($config['password'] ?? '');
        if (($username === '') !== ($password === '')) throw new PasswordResetDeliveryConfigurationException('MAIL_USERNAME and MAIL_PASSWORD must be configured together.');
        if (preg_match('/[\r\n]/', $username)) throw new PasswordResetDeliveryConfigurationException('MAIL_USERNAME is invalid.');
        $fromAddress = trim((string)($config['from_address'] ?? ''));
        if (!$this->validAddress($fromAddress)) throw new PasswordResetDeliveryConfigurationException('MAIL_FROM_ADDRESS is missing or invalid.');
        $fromName = trim((string)($config['from_name'] ?? 'SanIE'));
        if ($fromName === '' || strlen($fromName) > 100 || preg_match('/[\r\n]/', $fromName)) throw new PasswordResetDeliveryConfigurationException('MAIL_FROM_NAME is invalid.');
        $timeout = filter_var($config['timeout'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 30]]);
        if ($timeout === false) throw new PasswordResetDeliveryConfigurationException('MAIL_TIMEOUT_SECONDS must be between 1 and 30.');
        return [
            'transport' => 'smtp', 'host' => $host, 'port' => (int)$port,
            'username' => $username, 'password' => $password, 'encryption' => $encryption,
            'from_address' => $fromAddress, 'from_name' => $fromName, 'timeout' => (int)$timeout,
        ];
    }

    private function validAddress(string $address): bool {
        return !preg_match('/[\r\n]/', $address) && filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
    }
}

class PasswordResetDeliveryService {
    private string $environment;
    private ?PasswordResetMailTransportInterface $transport;
    private array $config;

    public function __construct(
        ?PasswordResetMailTransportInterface $transport = null,
        ?string $environment = null,
        ?array $config = null
    ) {
        $this->environment = strtolower($environment ?? APP_ENV);
        $this->transport = $transport;
        $this->config = $config ?? self::environmentConfig();
    }

    public function deliverPasswordReset(string $email, string $token, DateTimeImmutable $expiresAt): bool {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email)) return false;
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $token)) return false;
        try {
            $url = $this->resetUrl($token);
            if ($this->environment === 'development') return $this->writeDevelopmentDelivery($email, $url, $expiresAt);
            $transport = $this->transport ?? new PhpMailerSmtpPasswordResetTransport($this->config);
            [$subject, $html, $text] = $this->message($url, $expiresAt);
            $transport->send($email, $subject, $html, $text);
            return true;
        } catch (PasswordResetDeliveryConfigurationException $e) {
            error_log('[SanIE] Password reset delivery configuration error: ' . $e->getMessage());
            return false;
        } catch (Throwable $e) {
            error_log('[SanIE] Password reset delivery failed via configured mail transport (' . get_class($e) . ').');
            return false;
        }
    }

    /** Backward-compatible entry point for existing integrations. */
    public function deliver(string $email, string $token, DateTimeImmutable $expiresAt): bool {
        return $this->deliverPasswordReset($email, $token, $expiresAt);
    }

    private function resetUrl(string $token): string {
        $frontend = rtrim((string)($this->config['frontend_url'] ?? FRONTEND_URL), '/');
        $parts = parse_url($frontend);
        if (!$parts || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) throw new PasswordResetDeliveryConfigurationException('FRONTEND_URL is invalid.');
        if ($this->environment !== 'development') {
            $host = strtolower((string)$parts['host']);
            if (($parts['scheme'] ?? '') !== 'https' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) throw new PasswordResetDeliveryConfigurationException('FRONTEND_URL must be a public HTTPS URL in production.');
        }
        $separator = str_contains($frontend, '?') ? '&' : '?';
        return $frontend . $separator . 'reset_token=' . rawurlencode($token);
    }

    private function message(string $url, DateTimeImmutable $expiresAt): array {
        $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $expiry = $expiresAt->setTimezone(new DateTimeZone('Asia/Kathmandu'))->format('F j, Y \a\t g:i A T');
        $safeExpiry = htmlspecialchars($expiry, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $subject = 'Reset your SanIE password';
        $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#0f172a;line-height:1.6">'
            . '<h2 style="color:#10b981">SanIE Finance Manager</h2>'
            . '<p>We received a request to reset your SanIE password.</p>'
            . '<p><a href="' . $safeUrl . '" style="display:inline-block;background:#10b981;color:#fff;text-decoration:none;padding:10px 18px;border-radius:6px">Reset password</a></p>'
            . '<p>This link expires on ' . $safeExpiry . ' and can be used only once.</p>'
            . '<p>If you did not request a password reset, you can safely ignore this email.</p>'
            . '</body></html>';
        $text = "SanIE Finance Manager\n\nWe received a request to reset your SanIE password.\n\n"
            . "Reset password: {$url}\n\nThis link expires on {$expiry} and can be used only once.\n\n"
            . 'If you did not request a password reset, you can safely ignore this email.';
        return [$subject, $html, $text];
    }

    private function writeDevelopmentDelivery(string $email, string $url, DateTimeImmutable $expiresAt): bool {
        $path = (string)($this->config['dev_log'] ?? PASSWORD_RESET_DEV_LOG);
        if (!preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $path)) $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            error_log('[SanIE] Unable to create the development password-reset delivery directory.');
            return false;
        }
        $newRecord = [
            'created_at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
            'expires_at' => $expiresAt->format(DateTimeInterface::ATOM),
            'email' => $email,
            'reset_url' => $url,
        ];
        $handle = fopen($path, 'c+');
        if (!$handle) {
            error_log('[SanIE] Unable to write the development password-reset delivery log.');
            return false;
        }
        try {
            if (!flock($handle, LOCK_EX)) return false;
            $existing = stream_get_contents($handle);
            $records = [];
            foreach (preg_split('/\R/', (string)$existing, -1, PREG_SPLIT_NO_EMPTY) as $line) {
                $record = json_decode($line, true);
                if (!is_array($record) || ($record['email'] ?? null) === $email) continue;
                try { $recordExpiry = new DateTimeImmutable((string)($record['expires_at'] ?? '')); }
                catch (Throwable $ignored) { continue; }
                if ($recordExpiry > new DateTimeImmutable('now')) $records[] = $record;
            }
            $records = array_slice($records, -99);
            $records[] = $newRecord;
            $contents = implode(PHP_EOL, array_map(static fn(array $record): string => (string)json_encode($record, JSON_UNESCAPED_SLASHES), $records)) . PHP_EOL;
            ftruncate($handle, 0);
            rewind($handle);
            if (fwrite($handle, $contents) === false) return false;
            fflush($handle);
            @chmod($path, 0600);
            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function environmentConfig(): array {
        return [
            'transport' => MAIL_TRANSPORT, 'host' => MAIL_HOST, 'port' => MAIL_PORT,
            'username' => MAIL_USERNAME, 'password' => MAIL_PASSWORD, 'encryption' => MAIL_ENCRYPTION,
            'from_address' => MAIL_FROM_ADDRESS, 'from_name' => MAIL_FROM_NAME, 'timeout' => MAIL_TIMEOUT_SECONDS,
            'frontend_url' => FRONTEND_URL, 'dev_log' => PASSWORD_RESET_DEV_LOG,
        ];
    }
}
