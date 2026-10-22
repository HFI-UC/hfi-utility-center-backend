<?php

declare(strict_types=1);

namespace Hfiuc\Tests;

use Hfiuc\Http\Application;
use Hfiuc\Tests\Support\DatabaseTestCase;

final class TaskApiTest extends DatabaseTestCase
{
    public function testPullAndExecuteRequireSecretsAndAcceptDuplicateDelivery(): void
    {
        $this->db->execute(
            'INSERT INTO outboxjob (kind, payload, status, availableAt) VALUES (?, ?, \'pending\', NOW())',
            ['reservation_created', '{"reservationId":999}',],
        );
        $id = $this->db->lastInsertId();
        $app = Application::create($this->config, $this->db, $this->logger);

        $unauthorized = $app->handle($this->request('GET', '/tasks', [], ['limit' => '1']));
        self::assertSame(401, $unauthorized->getStatusCode());

        $pulled = $app->handle($this->request('GET', '/tasks', [], ['limit' => '1'], ['Authorization' => 'Bearer test-pull']));
        self::assertSame(200, $pulled->getStatusCode());
        $data = json_decode((string) $pulled->getBody(), true);
        self::assertSame($id, $data['data']['tasks'][0]['taskId']);
        $token = $data['data']['tasks'][0]['dispatchToken'];

        $missingSecret = $app->handle($this->executeRequest($id, $token, 'wrong'));
        self::assertSame(401, $missingSecret->getStatusCode());

        $completed = $app->handle($this->executeRequest($id, $token, 'test-execute'));
        self::assertSame(200, $completed->getStatusCode());
        self::assertSame('completed', $this->db->fetch('SELECT status FROM outboxjob WHERE id = ?', [$id])['status']);
        self::assertSame(200, $app->handle($this->executeRequest($id, $token, 'test-execute'))->getStatusCode());
    }

    private function executeRequest(int $id, string $token, string $secret): \Psr\Http\Message\ServerRequestInterface
    {
        $request = $this->request('POST', '/tasks/' . $id . '/execute', [], [], [
            'Authorization' => 'Bearer ' . $secret,
            'Content-Type' => 'application/json',
        ]);
        $request->getBody()->write((string) json_encode(['dispatchToken' => $token]));
        $request->getBody()->rewind();

        return $request;
    }
}
