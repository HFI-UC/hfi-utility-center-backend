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
    $debug = filter_var($_ENV['DEBUG'] ?? $_SERVER['DEBUG'] ?? getenv('DEBUG') ?: '', FILTER_VALIDATE_BOOLEAN);
    $body = ['success' => false, 'message' => 'Server configuration is missing.'];
    if ($debug) {
        $body['error'] = \Hfiuc\Http\DebugError::payload($error);
    }
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
