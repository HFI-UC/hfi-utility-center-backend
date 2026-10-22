<?php

declare(strict_types=1);

$envPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
$vars = [];
foreach (file($envPath, FILE_IGNORE_NEW_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $vars[$key] = $value;
}

function request(string $method, string $url, array $headers, ?string $body = null): array
{
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => $body,
    ]);
    $raw = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
    $error = curl_error($handle);
    curl_close($handle);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;

    return [
        'status' => $status,
        'curlError' => $error,
        'contentType' => $contentType,
        'success' => is_array($decoded) ? ($decoded['success'] ?? null) : null,
        'message' => is_array($decoded) ? ($decoded['message'] ?? ($decoded['errors'][0]['message'] ?? null)) : null,
        'bytes' => is_string($raw) ? strlen($raw) : 0,
        'snippet' => is_string($raw) ? substr(preg_replace('/\s+/', ' ', $raw) ?? '', 0, 180) : '',
    ];
}

$account = $vars['CF_ACCOUNT_ID'] ?? '';
$queue = $vars['CF_QUEUE_ID'] ?? '';
$token = $vars['CF_QUEUE_TOKEN'] ?? '';
$secret = $vars['QUEUE_PROCESS_SECRET'] ?? '';

echo "token_present=" . ($token !== '' ? 'yes' : 'no') . PHP_EOL;
echo "secret_present=" . ($secret !== '' ? 'yes' : 'no') . PHP_EOL;

$auth = ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];
$queueUrl = 'https://api.cloudflare.com/client/v4/accounts/' . rawurlencode($account) . '/queues/' . rawurlencode($queue);
$read = request('GET', $queueUrl, $auth);
echo 'queue_read status=' . $read['status'] . ' success=' . json_encode($read['success']) . ' message=' . json_encode($read['message']) . PHP_EOL;
if ($read['success'] !== true && $read['snippet'] !== '') {
    echo 'queue_read_snippet=' . $read['snippet'] . PHP_EOL;
}

$publish = request('POST', $queueUrl . '/messages', $auth, '{"body":{"ping":true}}');
echo 'queue_publish status=' . $publish['status'] . ' success=' . json_encode($publish['success']) . ' message=' . json_encode($publish['message']) . PHP_EOL;
if ($publish['success'] !== true && $publish['snippet'] !== '') {
    echo 'queue_publish_snippet=' . $publish['snippet'] . PHP_EOL;
}

$callback = request(
    'POST',
    'https://api.hfiuc.org/internal/outbox/process',
    ['Authorization: Bearer ' . $secret, 'Content-Type: application/json'],
    '{"jobId":0}',
);
$health = request('GET', 'https://api.hfiuc.org/healthz', ['Accept: application/json']);
echo 'healthz status=' . $health['status'] . ' type=' . json_encode($health['contentType']) . ' bytes=' . $health['bytes'] . ' snippet=' . json_encode($health['snippet']) . PHP_EOL;
echo 'callback status=' . $callback['status'] . ' type=' . json_encode($callback['contentType']) . ' bytes=' . $callback['bytes'] . ' message=' . json_encode($callback['message']) . ' snippet=' . json_encode($callback['snippet']) . PHP_EOL;
