<?php

declare(strict_types=1);

namespace Hfiuc;

use Dotenv\Dotenv;
use Hfiuc\Http\Application;
use Hfiuc\Log\Logger;
use Slim\App;

final class Bootstrap
{
    public static function http(): void
    {
        self::app()->run();
    }

    public static function app(): App
    {
        [$config, $db, $logger] = self::services();

        return Application::create($config, $db, $logger);
    }

    public static function database(): Database
    {
        [, $db] = self::services();

        return $db;
    }

    /** @return array{0: Config, 1: Database, 2: Logger} */
    private static function services(): array
    {
        $root = dirname(__DIR__);
        if (!is_file($root . '/.env')) {
            throw new \RuntimeException('php/.env is required');
        }
        Dotenv::createImmutable($root)->load();
        date_default_timezone_set('Asia/Shanghai');
        $config = Config::fromEnv();

        try {
            $db = new Database($config);
        } catch (\PDOException $error) {
            error_log('database connection failed: ' . $error->getMessage());
            throw new \RuntimeException('Database connection failed');
        }

        return [$config, $db, new Logger($db)];
    }
}
