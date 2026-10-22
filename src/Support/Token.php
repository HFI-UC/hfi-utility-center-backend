<?php

declare(strict_types=1);

namespace Hfiuc\Support;

final class Token
{
    public static function random(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }
}
