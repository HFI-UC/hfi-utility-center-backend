<?php

declare(strict_types=1);

$vars = [];
foreach (file(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env', FILE_IGNORE_NEW_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $vars[$key] = $value;
}

$host = $vars['DB_HOST'] ?? '127.0.0.1';
$port = $vars['DB_PORT'] ?? '3306';
$name = $vars['DB_NAME'] ?? '';
$user = $vars['DB_USER'] ?? '';
$password = $vars['DB_PASSWORD'] ?? '';

echo 'host=' . $host . ' port=' . $port . ' database=' . $name . ' user=' . $user . PHP_EOL;

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, (int) $port, $name),
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
    );
    $pdo->exec("SET time_zone = '+08:00'");
    $version = (string) $pdo->query('SELECT VERSION() AS version')->fetch()['version'];
    $now = (string) $pdo->query('SELECT NOW() AS now')->fetch()['now'];
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    sort($tables);
    echo 'connected=yes version=' . $version . ' now=' . $now . PHP_EOL;
    echo 'tables=' . ($tables === [] ? '(none)' : implode(',', $tables)) . PHP_EOL;
} catch (Throwable $error) {
    $message = $password === '' ? $error->getMessage() : (preg_replace('/' . preg_quote($password, '/') . '/', '[redacted]', $error->getMessage()) ?? $error->getMessage());
    echo 'connected=no error=' . $message . PHP_EOL;
    exit(1);
}
