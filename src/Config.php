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
        public readonly bool $aiEnabled,
        public readonly string $aiUrl,
        public readonly string $aiSecret,
        public readonly int $aiAdminId,
        public readonly bool $cookieSecure,
        public readonly array $allowedOrigins,
        public readonly string $cfAccountId,
        public readonly string $cfQueueId,
        public readonly string $cfQueueToken,
        public readonly string $queueProcessSecret,
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
        $origins = array_values(array_unique(array_filter([
            $frontend,
            'https://hfiuc.org',
            'https://www.hfiuc.org',
            'https://preview.hfiuc.org',
            'https://neo.hfiuc.org',
            'http://localhost:3000',
            'http://127.0.0.1:3000',
            'http://localhost:5173',
            'http://127.0.0.1:5173',
            'http://localhost:5174',
            'http://127.0.0.1:5174',
        ])));

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
            self::bool('AI_APPROVAL_ENABLED', false),
            self::string('AI_APPROVAL_URL', ''),
            self::string('AI_APPROVAL_SECRET', ''),
            self::int('AI_APPROVAL_ADMIN_ID', 0),
            self::bool('COOKIE_SECURE', true),
            $origins,
            self::string('CF_ACCOUNT_ID', ''),
            self::string('CF_QUEUE_ID', ''),
            self::string('CF_QUEUE_TOKEN', ''),
            self::string('QUEUE_PROCESS_SECRET', ''),
        );
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
