<?php

declare(strict_types=1);

namespace Hfiuc\Tests;

use Hfiuc\Http\DebugError;
use Hfiuc\Http\HttpException;
use PHPUnit\Framework\TestCase;

final class DebugErrorTest extends TestCase
{
    public function testPayloadIncludesBusinessDetailAndCause(): void
    {
        $cause = new \RuntimeException('database said no');
        $error = new HttpException(409, 'Admin already exists.', ['constraint' => 'admin_email'], $cause);
        $payload = DebugError::payload($error);

        self::assertSame(HttpException::class, $payload['type']);
        self::assertSame(409, $payload['status']);
        self::assertSame('Admin already exists.', $payload['message']);
        self::assertSame('admin_email', $payload['detail']['constraint']);
        self::assertSame('database said no', $payload['previous']['message']);
        self::assertNotSame('', $payload['file']);
    }
}
