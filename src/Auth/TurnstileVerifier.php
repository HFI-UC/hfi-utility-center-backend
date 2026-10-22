<?php

declare(strict_types=1);

namespace Hfiuc\Auth;

interface TurnstileVerifier
{
    public function verify(string $token): bool;

    /** @return array<string, mixed> */
    public function failure(): array;
}
