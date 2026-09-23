<?php

declare(strict_types=1);

namespace Hfiuc\Tests\Support;

use Hfiuc\Auth\TurnstileVerifier;

final class FakeTurnstile implements TurnstileVerifier
{
    public function verify(string $token): bool
    {
        return $token === 'pass';
    }
}
