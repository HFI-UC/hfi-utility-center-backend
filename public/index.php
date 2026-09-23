<?php

declare(strict_types=1);

use Hfiuc\Bootstrap;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    Bootstrap::http();
} catch (Throwable $error) {
    error_log($error->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"success":false,"message":"Server configuration is missing."}';
}
