<?php

declare(strict_types=1);

namespace Hfiuc\Tests;

use Hfiuc\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    /** @var array<string, array{env: mixed, envSet: bool, server: mixed, serverSet: bool, getenv: string|false}> */
    private array $saved = [];

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $previous) {
            if ($previous['envSet']) {
                $_ENV[$key] = $previous['env'];
            } else {
                unset($_ENV[$key]);
            }
            if ($previous['serverSet']) {
                $_SERVER[$key] = $previous['server'];
            } else {
                unset($_SERVER[$key]);
            }
            if ($previous['getenv'] === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $previous['getenv']);
            }
        }
    }

    public function testTurnstileVerifySslDefaultsToTrue(): void
    {
        $this->setRequiredDatabaseEnv();
        $this->clear('TURNSTILE_VERIFY_SSL');

        self::assertTrue(Config::fromEnv()->turnstileVerifySsl);
    }

    public function testTurnstileVerifySslCanBeDisabled(): void
    {
        $this->setRequiredDatabaseEnv();
        $this->set('TURNSTILE_VERIFY_SSL', 'false');

        self::assertFalse(Config::fromEnv()->turnstileVerifySsl);
    }

    private function setRequiredDatabaseEnv(): void
    {
        $this->set('DB_HOST', '127.0.0.1');
        $this->set('DB_NAME', 'hfiuc');
        $this->set('DB_USER', 'hfiuc');
        $this->set('DB_PASSWORD', 'secret');
    }

    private function set(string $key, string $value): void
    {
        $this->remember($key);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }

    private function clear(string $key): void
    {
        $this->remember($key);
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    }

    private function remember(string $key): void
    {
        if (array_key_exists($key, $this->saved)) {
            return;
        }
        $this->saved[$key] = [
            'env' => $_ENV[$key] ?? null,
            'envSet' => array_key_exists($key, $_ENV),
            'server' => $_SERVER[$key] ?? null,
            'serverSet' => array_key_exists($key, $_SERVER),
            'getenv' => getenv($key),
        ];
    }
}
