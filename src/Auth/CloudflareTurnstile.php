<?php

declare(strict_types=1);

namespace Hfiuc\Auth;

use Hfiuc\Config;
use Hfiuc\Log\Logger;

final class CloudflareTurnstile implements TurnstileVerifier
{
    /** @var array<string, mixed> */
    private array $failure = [];

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    public function verify(string $token): bool
    {
        $this->failure = [];
        if ($this->config->cloudflareSecret === '') {
            $this->failure = ['reason' => 'missing_secret'];

            return false;
        }
        if ($token === '') {
            $this->failure = ['reason' => 'missing_token'];

            return false;
        }
        $handle = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        if ($handle === false) {
            $this->failure = ['reason' => 'curl_init_failed'];

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
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $curlError = curl_error($handle);
        curl_close($handle);
        if (!is_string($raw) || $status < 200 || $status >= 300) {
            $this->failure = [
                'reason' => 'request_failed',
                'status' => $status,
                'curlError' => $curlError,
            ];
            $this->logger->error('Turnstile request failed', ['status' => $status]);

            return false;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || ($decoded['success'] ?? false) !== true) {
            $codes = is_array($decoded) && isset($decoded['error-codes']) && is_array($decoded['error-codes'])
                ? array_values(array_map(static fn (mixed $code): string => (string) $code, $decoded['error-codes']))
                : [];
            $this->failure = [
                'reason' => 'rejected',
                'errorCodes' => $codes,
            ];
            $this->logger->error('Turnstile verification rejected', ['errorCodes' => $codes]);

            return false;
        }

        return true;
    }

    public function failure(): array
    {
        return $this->failure;
    }
}
