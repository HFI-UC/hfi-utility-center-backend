<?php

declare(strict_types=1);

namespace Hfiuc\Tests;

use Hfiuc\Config;
use Hfiuc\Http\CorsMiddleware;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

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

    public function testAiVerifySslDefaultsToTrue(): void
    {
        $this->setRequiredDatabaseEnv();
        $this->clear('AI_VERIFY_SSL');

        self::assertTrue(Config::fromEnv()->aiVerifySsl);
    }

    public function testAiVerifySslCanBeDisabled(): void
    {
        $this->setRequiredDatabaseEnv();
        $this->set('AI_VERIFY_SSL', 'false');

        self::assertFalse(Config::fromEnv()->aiVerifySsl);
    }

    public function testCorsOriginsComeOnlyFromEnvironment(): void
    {
        $this->setRequiredDatabaseEnv();
        $this->set('FRONTEND_URL', 'https://frontend.example');
        $this->set('CORS_ALLOWED_ORIGINS', ' https://one.example, ,https://two.example,https://one.example ');

        self::assertSame(
            ['https://one.example', 'https://two.example'],
            Config::fromEnv()->allowedOrigins,
        );
    }

    public function testCorsOriginsDefaultToEmpty(): void
    {
        $this->setRequiredDatabaseEnv();
        $this->set('FRONTEND_URL', 'https://frontend.example');
        $this->clear('CORS_ALLOWED_ORIGINS');

        self::assertSame([], Config::fromEnv()->allowedOrigins);
    }

    public function testCorsMiddlewareUsesConfiguredOrigins(): void
    {
        $this->setRequiredDatabaseEnv();
        $this->set('CORS_ALLOWED_ORIGINS', 'https://allowed.example');
        $cors = new CorsMiddleware(Config::fromEnv());
        $requestFactory = new ServerRequestFactory();

        $allowed = $cors->decorate(
            $requestFactory->createServerRequest('GET', '/health')->withHeader('Origin', 'https://allowed.example'),
            new Response(),
        );
        $blocked = $cors->decorate(
            $requestFactory->createServerRequest('GET', '/health')->withHeader('Origin', 'https://blocked.example'),
            new Response(),
        );

        self::assertSame('https://allowed.example', $allowed->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('true', $allowed->getHeaderLine('Access-Control-Allow-Credentials'));
        self::assertSame('', $blocked->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testAiApprovalUsesOpenAiConfiguration(): void
    {
        $this->setRequiredDatabaseEnv();
        $this->clear('OPENAI_API_BASE_URL');
        $this->set('OPENAI_MODEL', 'gpt-4o-mini');
        $this->set('OPENAI_API_KEY', 'test-openai-key');

        $config = Config::fromEnv();
        self::assertSame(
            'https://api.openai.com/v1',
            $config->aiApiBaseUrl,
        );
        self::assertSame('gpt-4o-mini', $config->aiModel);
        self::assertSame('test-openai-key', $config->aiApiKey);
    }

    public function testOpenAiModelDefaultsToValidatedFlashVersion(): void
    {
        $this->setRequiredDatabaseEnv();
        $this->clear('OPENAI_MODEL');

        self::assertSame('gpt-4o-mini', Config::fromEnv()->aiModel);
    }

    public function testAiApprovalIsReadyOnlyWithEnabledAndValidOpenAiConfiguration(): void
    {
        $this->setRequiredDatabaseEnv();
        $this->set('AI_APPROVAL_ENABLED', 'true');
        $this->set('OPENAI_API_BASE_URL', 'https://api.openai.com/v1');
        $this->set('OPENAI_MODEL', 'gpt-4o-mini');
        $this->set('OPENAI_API_KEY', 'test-openai-key');

        self::assertTrue(Config::fromEnv()->aiApprovalReady());

        $this->clear('OPENAI_API_KEY');
        self::assertFalse(Config::fromEnv()->aiApprovalReady());

        $this->set('OPENAI_API_KEY', 'test-openai-key');
        $this->set('OPENAI_API_BASE_URL', 'not-a-url');
        self::assertFalse(Config::fromEnv()->aiApprovalReady());

        $this->set('OPENAI_API_BASE_URL', 'https://api.openai.com/v1');
        $this->set('OPENAI_MODEL', 'model with space');
        self::assertFalse(Config::fromEnv()->aiApprovalReady());

        $this->set('OPENAI_MODEL', 'gpt-4o-mini');
        $this->set('AI_APPROVAL_ENABLED', 'false');
        self::assertFalse(Config::fromEnv()->aiApprovalReady());
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
