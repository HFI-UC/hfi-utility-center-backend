<?php

declare(strict_types=1);

namespace Hfiuc\Http;

final class HttpException extends \RuntimeException
{
    /** @param array<string, mixed> $detail */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $detail = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
