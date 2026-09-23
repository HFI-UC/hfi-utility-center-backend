<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$envFile = __DIR__ . '/.env';
if (is_file($envFile)) {
    Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
}

date_default_timezone_set('Asia/Shanghai');
