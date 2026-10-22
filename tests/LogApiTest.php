<?php

declare(strict_types=1);

namespace Hfiuc\Tests;

use Hfiuc\Http\Application;
use Hfiuc\Tests\Support\DatabaseTestCase;
use Psr\Http\Message\ResponseInterface;

final class LogApiTest extends DatabaseTestCase
{
    public function testOnlyGlobalAdminCanReadPaginatedErrorLogs(): void
    {
        $this->insertAdmin('global@example.com', 'Global');
        $roomAdmin = $this->insertAdmin('room@example.com', 'Room');
        $campusId = $this->insertCampus('Campus');
        $roomId = $this->insertRoom('505', $campusId);
        $this->assignRoom($roomId, $roomAdmin);
        foreach (['first', 'second', 'third'] as $message) {
            $this->logger->error($message, ['httpStatus' => 400]);
        }
        $app = Application::create($this->config, $this->db, $this->logger);

        self::assertSame(401, $app->handle($this->request('GET', '/admin/logs/errors'))->getStatusCode());
        self::assertSame(403, $this->read($app, 'room@example.com', 'errors')->getStatusCode());

        $first = $this->read($app, 'global@example.com', 'errors', ['limit' => '2']);
        self::assertSame(200, $first->getStatusCode());
        self::assertSame('private, no-store', $first->getHeaderLine('Cache-Control'));
        $page = json_decode((string) $first->getBody(), true);
        self::assertSame(['third', 'second'], array_column($page['data']['items'], 'message'));
        self::assertSame(['httpStatus' => 400], $page['data']['items'][0]['context']);
        $cursor = $page['data']['nextBeforeId'];
        self::assertIsInt($cursor);

        $second = $this->read($app, 'global@example.com', 'errors', ['limit' => '2', 'beforeId' => (string) $cursor]);
        $nextPage = json_decode((string) $second->getBody(), true);
        self::assertSame(['first'], array_column($nextPage['data']['items'], 'message'));
        self::assertNull($nextPage['data']['nextBeforeId']);
        self::assertSame(400, $this->read($app, 'global@example.com', 'errors', ['limit' => '101'])->getStatusCode());
        self::assertSame(400, $this->read($app, 'global@example.com', 'errors', ['beforeId' => '0'])->getStatusCode());
        self::assertSame(404, $this->read($app, 'global@example.com', 'unknown')->getStatusCode());
    }

    public function testAuditReservationAndOutboxLogsOmitTaskPayloadAndTokens(): void
    {
        $adminId = $this->insertAdmin('global@example.com', 'Global');
        $campusId = $this->insertCampus('Campus');
        $roomId = $this->insertRoom('505', $campusId);
        [$start, $end] = $this->slot(3, 10);
        $reservationId = $this->insertReservation($roomId, $start, $end, 'student@example.com');
        $this->db->execute(
            'INSERT INTO auditlog (adminId, action, entity, entityId, detail, createdAt) VALUES (?, ?, ?, ?, ?, NOW())',
            [$adminId, 'reservation.approval', 'reservation', (string) $reservationId, '{"status":"rejected"}'],
        );
        $this->db->execute(
            'INSERT INTO reservationoperationlog (adminId, reservationId, operation, reason) VALUES (?, ?, ?, ?)',
            [$adminId, $reservationId, 'rejected', 'Room is reserved for exams.'],
        );
        $this->db->execute(
            "INSERT INTO outboxjob (kind, payload, status, attempts, availableAt, lastError, dispatchToken, lockToken) VALUES (?, ?, 'pending', 2, NOW(), ?, ?, ?)",
            ['ai_approval', json_encode([
                'reservationId' => $reservationId,
                'reviewVersion' => 3,
                'cancelToken' => 'private-cancel-token',
                'email' => 'student@example.com',
            ]), 'Temporary failure', 'private-dispatch-token', 'private-lock-token'],
        );
        $app = Application::create($this->config, $this->db, $this->logger);

        $audit = json_decode((string) $this->read($app, 'global@example.com', 'audit')->getBody(), true);
        self::assertSame(['status' => 'rejected'], $audit['data']['items'][0]['detail']);
        $operations = json_decode((string) $this->read($app, 'global@example.com', 'reservations')->getBody(), true);
        self::assertSame('Room is reserved for exams.', $operations['data']['items'][0]['reason']);
        $outboxResponse = $this->read($app, 'global@example.com', 'outbox');
        $outbox = json_decode((string) $outboxResponse->getBody(), true);
        self::assertSame($reservationId, $outbox['data']['items'][0]['reservationId']);
        self::assertSame(3, $outbox['data']['items'][0]['reviewVersion']);
        self::assertSame('Temporary failure', $outbox['data']['items'][0]['lastError']);
        self::assertSame('pending', $this->db->fetch('SELECT status FROM outboxjob ORDER BY id DESC LIMIT 1')['status']);
        foreach (['private-cancel-token', 'private-dispatch-token', 'private-lock-token', 'student@example.com'] as $secret) {
            self::assertStringNotContainsString($secret, (string) $outboxResponse->getBody());
        }
    }

    /** @param array<string, string> $query */
    private function read(\Slim\App $app, string $email, string $kind, array $query = []): ResponseInterface
    {
        return $app->handle($this->request('GET', '/admin/logs/' . $kind, [], $query, [
            'Cookie' => 'uc=' . $this->session($email),
        ]));
    }
}
