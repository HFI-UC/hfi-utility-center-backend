<?php

declare(strict_types=1);

namespace Hfiuc\Tests;

use Hfiuc\Http\Application;
use Hfiuc\Tests\Support\DatabaseTestCase;

final class DebugRouteTest extends DatabaseTestCase
{
    public function testSecretInspectionRequiresDebugAndGlobalAdmin(): void
    {
        $globalId = $this->insertAdmin('global@example.com', 'Global');
        $roomId = $this->insertAdmin('room@example.com', 'Room');
        $this->db->execute("UPDATE admin SET role = 'room' WHERE id = ?", [$roomId]);

        $globalRequest = $this->request('GET', '/debug', [], [], [
            'Cookie' => 'uc=' . $this->session('global@example.com'),
        ]);
        $disabled = Application::create($this->config, $this->db, $this->logger)->handle($globalRequest);
        self::assertSame(404, $disabled->getStatusCode());
        self::assertStringNotContainsString('test-pull', (string) $disabled->getBody());

        $app = Application::create($this->makeConfig(debug: true), $this->db, $this->logger);
        $anonymous = $app->handle($this->request('GET', '/debug'));
        self::assertSame(401, $anonymous->getStatusCode());
        self::assertStringNotContainsString('test-pull', (string) $anonymous->getBody());

        $roomRequest = $this->request('GET', '/debug', [], [], [
            'Cookie' => 'uc=' . $this->session('room@example.com'),
        ]);
        $room = $app->handle($roomRequest);
        self::assertSame(403, $room->getStatusCode());
        self::assertStringNotContainsString('test-pull', (string) $room->getBody());

        $global = $app->handle($globalRequest);
        self::assertSame(200, $global->getStatusCode());
        self::assertSame('private, no-store', $global->getHeaderLine('Cache-Control'));
        self::assertSame('no-cache', $global->getHeaderLine('Pragma'));
        $body = json_decode((string) $global->getBody(), true);
        self::assertSame([
            'QUEUE_PROCESS_SECRET' => 'test-process',
            'TASK_PULL_SECRET' => 'test-pull',
            'TASK_EXECUTE_SECRET' => 'test-execute',
        ], $body['data']);
        self::assertNotNull($this->db->fetch(
            'SELECT id FROM auditlog WHERE action = ? AND adminId = ?',
            ['debug.secrets.read', $globalId],
        ));
    }
}
