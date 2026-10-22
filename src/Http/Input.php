<?php

declare(strict_types=1);

namespace Hfiuc\Http;

final class Input
{
    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data) && $this->data[$key] !== null;
    }

    public function int(string $key, bool $required = true): ?int
    {
        if (!$this->has($key)) {
            if ($required) {
                throw self::invalid($key, 'integer');
            }

            return null;
        }
        if (!is_int($this->data[$key])) {
            throw self::invalid($key, 'integer');
        }

        return $this->data[$key];
    }

    public function string(string $key, bool $required = true): ?string
    {
        if (!$this->has($key)) {
            if ($required) {
                throw self::invalid($key, 'string');
            }

            return null;
        }
        if (!is_string($this->data[$key])) {
            throw self::invalid($key, 'string');
        }

        return $this->data[$key];
    }

    public function bool(string $key, ?bool $default = null): bool
    {
        if (!$this->has($key)) {
            if ($default === null) {
                throw self::invalid($key, 'boolean');
            }

            return $default;
        }
        if (!is_bool($this->data[$key])) {
            throw self::invalid($key, 'boolean');
        }

        return $this->data[$key];
    }

    /** @return list<int> */
    public function intList(string $key): array
    {
        if (!$this->has($key) || !is_array($this->data[$key])) {
            throw self::invalid($key, 'integer list');
        }
        $values = [];
        foreach ($this->data[$key] as $value) {
            if (!is_int($value)) {
                throw self::invalid($key, 'integer list');
            }
            $values[] = $value;
        }

        return $values;
    }

    private static function invalid(string $field, string $expected): HttpException
    {
        return new HttpException(422, 'Invalid request body.', [
            'field' => $field,
            'expected' => $expected,
        ]);
    }
}
