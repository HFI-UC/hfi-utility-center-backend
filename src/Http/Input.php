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
                throw new HttpException(422, 'Invalid request body.');
            }

            return null;
        }
        if (!is_int($this->data[$key])) {
            throw new HttpException(422, 'Invalid request body.');
        }

        return $this->data[$key];
    }

    public function string(string $key, bool $required = true): ?string
    {
        if (!$this->has($key)) {
            if ($required) {
                throw new HttpException(422, 'Invalid request body.');
            }

            return null;
        }
        if (!is_string($this->data[$key])) {
            throw new HttpException(422, 'Invalid request body.');
        }

        return $this->data[$key];
    }

    public function bool(string $key, ?bool $default = null): bool
    {
        if (!$this->has($key)) {
            if ($default === null) {
                throw new HttpException(422, 'Invalid request body.');
            }

            return $default;
        }
        if (!is_bool($this->data[$key])) {
            throw new HttpException(422, 'Invalid request body.');
        }

        return $this->data[$key];
    }

    /** @return list<int> */
    public function intList(string $key): array
    {
        if (!$this->has($key) || !is_array($this->data[$key])) {
            throw new HttpException(422, 'Invalid request body.');
        }
        $values = [];
        foreach ($this->data[$key] as $value) {
            if (!is_int($value)) {
                throw new HttpException(422, 'Invalid request body.');
            }
            $values[] = $value;
        }

        return $values;
    }
}
