<?php

declare(strict_types=1);

namespace Hfiuc;

final class Config
{
    /** @param list<string> $allowedOrigins */
    public function __construct(
        public readonly string $dbHost,
        public readonly int $dbPort,
        public readonly string $dbName,
        public readonly string $dbUser,
        public readonly string $dbPassword,
        public readonly string $frontendUrl,
        public readonly string $smtpServer,
        public readonly int $smtpPort,
        public readonly string $smtpEmail,
        public readonly string $smtpPassword,
        public readonly string $cloudflareSecret,
        public readonly bool $turnstileVerifySsl,
        public readonly bool $aiVerifySsl,
        public readonly bool $aiEnabled,
        public readonly string $aiApiBaseUrl,
        public readonly string $aiModel,
        public readonly string $aiApiKey,
        public readonly int $aiAdminId,
        public readonly bool $cookieSecure,
        public readonly array $allowedOrigins,
        public readonly string $cfAccountId,
        public readonly string $cfQueueId,
        public readonly string $cfQueueToken,
        public readonly string $queueProcessSecret,
        public readonly bool $debug,
        public readonly string $taskPullSecret = '',
        public readonly string $taskExecuteSecret = '',
    ) {
    }

    public static function fromEnv(): self
    {
        $required = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'];
        foreach ($required as $key) {
            if (!array_key_exists($key, $_ENV) && !array_key_exists($key, $_SERVER) && getenv($key) === false) {
                throw new \RuntimeException($key . ' is required in .env');
            }
        }

        $frontend = self::string('FRONTEND_URL', 'https://www.hfiuc.org');
        $origins = array_values(array_unique(array_filter(
            array_map('trim', explode(',', self::string('CORS_ALLOWED_ORIGINS', ''))),
            static fn (string $origin): bool => $origin !== '',
        )));

        return new self(
            self::string('DB_HOST', '127.0.0.1'),
            self::int('DB_PORT', 3306),
            self::string('DB_NAME', 'hfiuc'),
            self::string('DB_USER', ''),
            self::string('DB_PASSWORD', ''),
            $frontend,
            self::string('SMTP_SERVER', ''),
            self::int('SMTP_PORT', 587),
            self::string('SMTP_EMAIL', ''),
            self::string('SMTP_PASSWORD', ''),
            self::string('CLOUDFLARE_SECRET', ''),
            self::bool('TURNSTILE_VERIFY_SSL', true),
            self::bool('AI_VERIFY_SSL', true),
            self::bool('AI_APPROVAL_ENABLED', false),
            self::string('OPENAI_API_BASE_URL', 'https://api.openai.com/v1'),
            self::string('OPENAI_MODEL', 'gpt-4o-mini'),
            self::string('OPENAI_API_KEY', ''),
            self::int('AI_APPROVAL_ADMIN_ID', 0),
            self::bool('COOKIE_SECURE', true),
            $origins,
            self::string('CF_ACCOUNT_ID', ''),
            self::string('CF_QUEUE_ID', ''),
            self::string('CF_QUEUE_TOKEN', ''),
            self::string('QUEUE_PROCESS_SECRET', ''),
            self::bool('DEBUG', false),
            self::string('TASK_PULL_SECRET', ''),
            self::string('TASK_EXECUTE_SECRET', ''),
        );
    }

    public function aiApprovalReady(): bool
    {
        if (!$this->aiEnabled || preg_match('/^[\x21-\x7E]+$/D', $this->aiApiKey) !== 1
            || preg_match('/^[A-Za-z0-9._:\/-]+$/D', $this->aiModel) !== 1) {
            return false;
        }

        if (filter_var($this->aiApiBaseUrl, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($this->aiApiBaseUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }

        return $parts['scheme'] === 'https'
            || ($parts['scheme'] === 'http' && in_array($parts['host'], ['127.0.0.1', 'localhost'], true));
    }

    private static function string(string $key, string $default): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return (string) $value;
    }

    private static function int(string $key, int $default): int
    {
        $raw = self::string($key, (string) $default);

        return is_numeric($raw) ? (int) $raw : $default;
    }

    private static function bool(string $key, bool $default): bool
    {
        $raw = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($raw === false || $raw === null || $raw === '') {
            return $default;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }
}
