<?php

declare(strict_types=1);

namespace Hfiuc\Tests;

use Hfiuc\Http\HttpException;
use Hfiuc\Http\Input;
use Hfiuc\Http\QueryInput;
use PHPUnit\Framework\TestCase;

final class InputTest extends TestCase
{
    public function testJsonBodyRequiresTypedFields(): void
    {
        $input = new Input([
            'room' => 4,
            'name' => '505',
            'enabled' => false,
            'days' => [1, 3],
            'optional' => null,
        ]);
        self::assertSame(4, $input->int('room'));
        self::assertSame('505', $input->string('name'));
        self::assertFalse($input->bool('enabled'));
        self::assertFalse($input->bool('missing', false));
        self::assertTrue($input->bool('missing', true));
        self::assertNull($input->int('optional', false));
        self::assertSame([1, 3], $input->intList('days'));
        self::assertFalse($input->has('optional'));

        $this->expectInput(fn () => $input->int('missing'));
        $this->expectInput(fn () => $input->int('name'));
        $this->expectInput(fn () => $input->string('room'));
        $this->expectInput(fn () => $input->bool('name'));
        $this->expectInput(fn () => $input->intList('room'));
        $this->expectInput(fn () => (new Input(['days' => [1, '3']]))->intList('days'));
    }

    public function testQueryParametersRejectMalformedValues(): void
    {
        $query = new QueryInput([
            'roomId' => '12',
            'keyword' => 'club',
            'needsMultimedia' => 'true',
            'blank' => '',
        ]);
        self::assertSame(12, $query->requireInt('roomId'));
        self::assertNull($query->optionalInt('blank'));
        self::assertSame('club', $query->requireString('keyword'));
        self::assertNull($query->optionalString('missing'));
        self::assertTrue($query->optionalBool('needsMultimedia'));
        self::assertFalse((new QueryInput(['needsMultimedia' => 'FALSE']))->optionalBool('needsMultimedia'));

        $this->expectQuery(fn () => $query->requireInt('missing'));
        $this->expectQuery(fn () => $query->optionalInt('keyword'));
        $this->expectQuery(fn () => (new QueryInput(['needsMultimedia' => 'yes']))->optionalBool('needsMultimedia'));
    }

    private function expectInput(callable $action): void
    {
        $this->expectStatus($action, 422, 'Invalid request body.');
    }

    private function expectQuery(callable $action): void
    {
        $this->expectStatus($action, 400, 'Invalid query parameter.');
    }

    private function expectStatus(callable $action, int $status, string $message): void
    {
        try {
            $action();
            self::fail('Expected HTTP ' . $status);
        } catch (HttpException $error) {
            self::assertSame($status, $error->status);
            self::assertSame($message, $error->getMessage());
        }
    }
}
