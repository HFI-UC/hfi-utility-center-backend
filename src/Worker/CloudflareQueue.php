<?php

declare(strict_types=1);

namespace Hfiuc\Worker;

use Hfiuc\Config;
use Hfiuc\Log\Logger;

final class CloudflareQueue implements QueuePublisher
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    /** @param array<string, mixed> $body */
    public function publish(array $body): void
    {
        if ($this->config->cfAccountId === '' || $this->config->cfQueueId === '' || $this->config->cfQueueToken === '') {
            throw new \RuntimeException('Cloudflare Queue is not configured');
        }
        $url = sprintf(
            'https://api.cloudflare.com/client/v4/accounts/%s/queues/%s/messages',
            rawurlencode($this->config->cfAccountId),
            rawurlencode($this->config->cfQueueId),
        );
        $payload = json_encode(['body' => $body], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new \RuntimeException('Unable to encode queue message');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('Unable to open Cloudflare Queue request');
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->config->cfQueueToken,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $ok = $status >= 200 && $status < 300 && is_array($decoded) && ($decoded['success'] ?? false) === true;
        if ($ok) {
            return;
        }
        $this->logger->error('Cloudflare Queue publish failed', [
            'status' => $status,
            'jobId' => $body['jobId'] ?? null,
            'kind' => $body['kind'] ?? null,
        ]);
        throw new \RuntimeException('Cloudflare Queue publish failed');
    }
}
