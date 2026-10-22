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
            throw self::invalid($key, 'integer');
        }

        return (int) $raw;
    }

    public function requireInt(string $key): int
    {
        $value = $this->optionalInt($key);
        if ($value === null) {
            throw self::invalid($key, 'integer');
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
            throw self::invalid($key, 'string');
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
        throw self::invalid($key, 'true or false');
    }

    private static function invalid(string $field, string $expected): HttpException
    {
        return new HttpException(400, 'Invalid query parameter.', [
            'field' => $field,
            'expected' => $expected,
        ]);
    }
}
