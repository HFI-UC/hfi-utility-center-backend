<?php

declare(strict_types=1);

namespace Hfiuc\Auth;

use Hfiuc\Config;
use Hfiuc\Log\Logger;

final class CloudflareTurnstile implements TurnstileVerifier
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    public function verify(string $token): bool
    {
        if ($this->config->cloudflareSecret === '' || $token === '') {
            return false;
        }
        $handle = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        if ($handle === false) {
            return false;
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_POSTFIELDS => http_build_query([
                'secret' => $this->config->cloudflareSecret,
                'response' => $token,
            ]),
        ]);
        $raw = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        if (!is_string($raw) || $status < 200 || $status >= 300) {
            $this->logger->error('Turnstile request failed', ['status' => $status]);

            return false;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) && ($decoded['success'] ?? false) === true;
    }
}
