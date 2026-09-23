<?php

declare(strict_types=1);

namespace Hfiuc\Http;

final class QueryInput
{
    /** @param array<string, mixed> $query */
    public function __construct(private readonly array $query)
    {
    }

    public function optionalInt(string $key): ?int
    {
        if (!array_key_exists($key, $this->query) || $this->query[$key] === '') {
            return null;
        }
        $raw = (string) $this->query[$key];
        if (!preg_match('/^-?\d+$/', $raw)) {
            throw new HttpException(400, 'Invalid query parameter.');
        }

        return (int) $raw;
    }

    public function requireInt(string $key): int
    {
        $value = $this->optionalInt($key);
        if ($value === null) {
            throw new HttpException(400, 'Invalid query parameter.');
        }

        return $value;
    }

    public function optionalString(string $key): ?string
    {
        if (!array_key_exists($key, $this->query) || $this->query[$key] === '') {
            return null;
        }

        return (string) $this->query[$key];
    }

    public function requireString(string $key): string
    {
        $value = $this->optionalString($key);
        if ($value === null) {
            throw new HttpException(400, 'Invalid query parameter.');
        }

        return $value;
    }

    public function optionalBool(string $key): ?bool
    {
        $raw = $this->optionalString($key);
        if ($raw === null) {
            return null;
        }
        $normalized = strtolower($raw);
        if ($normalized === 'true') {
            return true;
        }
        if ($normalized === 'false') {
            return false;
        }
        throw new HttpException(400, 'Invalid query parameter.');
    }
}
