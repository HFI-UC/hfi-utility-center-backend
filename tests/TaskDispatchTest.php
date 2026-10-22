<?php

declare(strict_types=1);

namespace Hfiuc\Tests;

use Hfiuc\Support\Clock;
use Hfiuc\Tests\Support\DatabaseTestCase;

final class TaskDispatchTest extends DatabaseTestCase
{
    public function testImmediateDispatchHappensAfterCommitAndCanBeRecovered(): void
    {
        $taskId = $this->db->transaction(function (): int {
            $id = $this->outbox->enqueue('reservation_created', ['reservationId' => 999]);
            self::assertSame([], $this->queue->messages);

            return $id;
        });
        $committed = $this->db->fetch('SELECT status, availableAt, NOW() AS serverNow, lastError FROM outboxjob WHERE id = ?', [$taskId]);
        self::assertSame('leased', $committed['status'], json_encode($committed));
        self::assertCount(1, $this->queue->messages);
        self::assertSame($taskId, $this->queue->messages[0]['taskId']);
        self::assertNotSame('', $this->queue->messages[0]['dispatchToken']);
        self::assertSame('leased', $this->db->fetch('SELECT status FROM outboxjob WHERE id = ?', [$taskId])['status']);

        try {
            $this->db->transaction(function (): void {
                $this->outbox->enqueue('reservation_created', ['reservationId' => 998]);
                throw new \RuntimeException('rollback');
            });
            self::fail('Expected rollback.');
        } catch (\RuntimeException $error) {
            self::assertSame('rollback', $error->getMessage());
        }
        self::assertCount(1, $this->queue->messages);
        self::assertSame(1, (int) $this->db->fetch('SELECT COUNT(*) AS total FROM outboxjob')['total']);

        $this->queue->fail = true;
        $recoverableId = $this->outbox->enqueue('reservation_created', ['reservationId' => 997]);
        self::assertSame('pending', $this->db->fetch('SELECT status FROM outboxjob WHERE id = ?', [$recoverableId])['status']);
        self::assertSame([], $this->outbox->claimDue());
        $this->db->execute('UPDATE outboxjob SET availableAt = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ?', [$recoverableId]);
        $tasks = $this->outbox->claimDue();
        self::assertCount(1, $tasks);
        self::assertSame($recoverableId, $tasks[0]['taskId']);
        $this->worker->processQueuedJob($recoverableId, $tasks[0]['dispatchToken']);
        $this->worker->processQueuedJob($recoverableId, $tasks[0]['dispatchToken']);
        self::assertSame('completed', $this->db->fetch('SELECT status FROM outboxjob WHERE id = ?', [$recoverableId])['status']);
    }

    public function testTasksFromOneCommitUseOneBatchAndDoNotIncludeOldPendingTasks(): void
    {
        $this->queue->fail = true;
        $oldTaskId = $this->outbox->enqueue('reservation_created', ['reservationId' => 991]);
        $this->db->execute('UPDATE outboxjob SET availableAt = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ?', [$oldTaskId]);
        $this->queue->fail = false;

        $taskIds = $this->db->transaction(function (): array {
            $ids = [];
            foreach ([992, 993, 994] as $reservationId) {
                $ids[] = $this->outbox->enqueue('reservation_created', ['reservationId' => $reservationId]);
            }
            self::assertSame([], $this->queue->messages);

            return $ids;
        });

        self::assertSame(1, $this->queue->batchCalls);
        self::assertSame($taskIds, array_column($this->queue->messages, 'taskId'));
        self::assertSame('pending', $this->db->fetch('SELECT status FROM outboxjob WHERE id = ?', [$oldTaskId])['status']);
    }

    public function testBatchFailureLeavesEveryTaskRecoverableWithoutRetryingPerCallback(): void
    {
        $this->queue->fail = true;
        $taskIds = $this->db->transaction(function (): array {
            return [
                $this->outbox->enqueue('reservation_created', ['reservationId' => 995]),
                $this->outbox->enqueue('reservation_created', ['reservationId' => 996]),
                $this->outbox->enqueue('reservation_created', ['reservationId' => 997]),
            ];
        });

        self::assertSame(1, $this->queue->batchCalls);
        self::assertSame([], $this->queue->messages);
        foreach ($taskIds as $taskId) {
            $row = $this->db->fetch('SELECT status, dispatchToken, leaseUntil, lastError FROM outboxjob WHERE id = ?', [$taskId]);
            self::assertSame('pending', $row['status']);
            self::assertNull($row['dispatchToken']);
            self::assertNull($row['leaseUntil']);
            self::assertNotSame('', $row['lastError']);
            $this->db->execute('UPDATE outboxjob SET availableAt = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ?', [$taskId]);
        }
        self::assertSame($taskIds, array_column($this->outbox->claimDue(), 'taskId'));
    }

    public function testRolledBackTransactionDoesNotLeakIdsIntoNextBatch(): void
    {
        try {
            $this->db->transaction(function (): void {
                $this->outbox->enqueue('reservation_created', ['reservationId' => 995]);
                throw new \RuntimeException('rollback');
            });
            self::fail('Expected rollback.');
        } catch (\RuntimeException $error) {
            self::assertSame('rollback', $error->getMessage());
        }

        $taskIds = $this->db->transaction(function (): array {
            return [
                $this->outbox->enqueue('reservation_created', ['reservationId' => 996]),
                $this->outbox->enqueue('reservation_created', ['reservationId' => 997]),
            ];
        });
        self::assertSame(1, $this->queue->batchCalls);
        self::assertSame($taskIds, array_column($this->queue->messages, 'taskId'));
    }

    public function testAiTaskIsOnlyClaimableAfterItsDelay(): void
    {
        $taskId = $this->outbox->enqueue(
            'ai_approval',
            ['reservationId' => 123, 'reviewVersion' => 0],
            Clock::now()->modify('+15 minutes'),
        );
        self::assertSame([], $this->queue->messages);
        self::assertSame([], $this->outbox->claimDue());

        $this->db->execute('UPDATE outboxjob SET availableAt = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ?', [$taskId]);
        $claimed = $this->outbox->claimDue();
        self::assertCount(1, $claimed);
        self::assertSame($taskId, $claimed[0]['taskId']);
    }

    public function testEmailTaskKeepsReservationDetailsFromItsEvent(): void
    {
        $campusId = $this->insertCampus('Snapshot campus');
        $roomId = $this->insertRoom('Snapshot room', $campusId);
        [$start, $end] = $this->slot(2, 10);
        $reservationId = $this->insertReservation($roomId, $start, $end, 'student@example.com', 'pending');

        $taskId = $this->outbox->enqueue('reservation_created', ['reservationId' => $reservationId]);
        $this->db->execute('UPDATE reservation SET reason = ? WHERE id = ?', ['Later change', $reservationId]);
        $row = $this->db->fetch('SELECT payload FROM outboxjob WHERE id = ?', [$taskId]);
        $payload = json_decode((string) $row['payload'], true);
        self::assertSame('Study group', $payload['snapshot']['reason']);
        self::assertSame('Snapshot room', $payload['snapshot']['room_name']);
    }
}
