<?php

declare(strict_types=1);

namespace Hfiuc\Worker;

use Hfiuc\Database;
use Hfiuc\Log\Logger;
use Hfiuc\Support\Clock;
use Hfiuc\Support\Token;

final class Outbox
{
    private ?int $pendingTransactionId = null;

    /** @var list<int> */
    private array $pendingTaskIds = [];

    public function __construct(
        private readonly Database $db,
        private readonly QueuePublisher $queue,
        private readonly ?Logger $logger = null,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function enqueue(string $kind, array $payload, ?\DateTimeImmutable $availableAt = null): int
    {
        if (in_array($kind, ['reservation_created', 'reservation_modified', 'reservation_cancelled', 'reservation_status_changed', 'admin_reservation_notification'], true)
            && isset($payload['reservationId'])) {
            $snapshot = $this->db->fetch(
                'SELECT r.email, r.studentName, r.reason, r.status, r.startTime, r.endTime, r.purposeType, r.needsMultimedia, rm.name AS room_name, c.name AS class_name, cp.name AS campus_name FROM reservation r LEFT JOIN room rm ON rm.id = r.roomId LEFT JOIN class c ON c.id = r.classId LEFT JOIN campus cp ON cp.id = rm.campusId WHERE r.id = ?',
                [(int) $payload['reservationId']],
            );
            if ($snapshot !== null) {
                $payload['snapshot'] = $snapshot;
            }
        }
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('Unable to encode outbox payload');
        }
        if ($availableAt === null) {
            $this->db->execute(
                'INSERT INTO outboxjob (kind, payload, status, availableAt) VALUES (?, ?, \'pending\', NOW())',
                [$kind, $encoded],
            );
        } else {
            $this->db->execute(
                'INSERT INTO outboxjob (kind, payload, status, availableAt) VALUES (?, ?, \'pending\', ?)',
                [$kind, $encoded, Clock::sql($availableAt)],
            );
        }
        $id = $this->db->lastInsertId();
        if ($availableAt === null || $availableAt <= Clock::now()) {
            $transactionId = $this->db->transactionId();
            if ($transactionId === null) {
                $this->dispatchBatch([$id]);
            } else {
                if ($this->pendingTransactionId !== $transactionId) {
                    $this->pendingTransactionId = $transactionId;
                    $this->pendingTaskIds = [];
                }
                $this->pendingTaskIds[] = $id;
                $this->db->afterCommitOnce('outbox:' . spl_object_id($this), function () use ($transactionId): void {
                    if ($this->pendingTransactionId !== $transactionId) {
                        return;
                    }
                    $taskIds = $this->pendingTaskIds;
                    $this->pendingTransactionId = null;
                    $this->pendingTaskIds = [];
                    $this->dispatchBatch($taskIds);
                });
            }
        }

        return $id;
    }

    /** @return list<array{taskId: int, kind: string, dispatchToken: string}> */
    public function claimDue(int $limit = 50, int|array|null $taskId = null): array
    {
        $limit = max(1, min(50, $limit));
        if ($taskId === []) {
            return [];
        }

        return $this->db->transaction(function () use ($limit, $taskId): array {
            $params = [];
            $where = '';
            if (is_array($taskId)) {
                $where = ' AND id IN (' . implode(', ', array_fill(0, count($taskId), '?')) . ')';
                $params = $taskId;
            } elseif ($taskId !== null) {
                $where = ' AND id = ?';
                $params[] = $taskId;
            }
            $rows = $this->db->fetchAll(
                'SELECT id, kind FROM outboxjob WHERE availableAt <= NOW()'
                . ' AND (status = \'pending\' OR (status = \'leased\' AND leaseUntil <= NOW())'
                . ' OR (status = \'processing\' AND lockedAt <= DATE_SUB(NOW(), INTERVAL 2 MINUTE)))'
                . $where . ' ORDER BY id LIMIT ' . $limit . ' FOR UPDATE',
                $params,
            );
            $tasks = [];
            foreach ($rows as $row) {
                $token = Token::random();
                $this->db->execute(
                    'UPDATE outboxjob SET status = \'leased\', dispatchToken = ?, leaseUntil = DATE_ADD(NOW(), INTERVAL 2 MINUTE) WHERE id = ?',
                    [$token, (int) $row['id']],
                );
                $tasks[] = [
                    'taskId' => (int) $row['id'],
                    'kind' => (string) $row['kind'],
                    'dispatchToken' => $token,
                ];
            }

            return $tasks;
        });
    }

    /** @param list<int> $taskIds */
    private function dispatchBatch(array $taskIds): void
    {
        if ($taskIds === []) {
            return;
        }
        try {
            // Bound synchronous Queue I/O; the DO dispatcher picks up any overflow.
            $tasks = $this->claimDue(50, array_slice($taskIds, 0, 50));
            if ($tasks === []) {
                return;
            }
            try {
                if (count($tasks) === 1) {
                    $this->queue->publish($tasks[0]);
                } else {
                    $this->queue->publishBatch($tasks);
                }
                foreach ($tasks as $task) {
                    $this->db->execute(
                        'UPDATE outboxjob SET publishedAt = NOW() WHERE id = ? AND dispatchToken = ?',
                        [$task['taskId'], $task['dispatchToken']],
                    );
                }
            } catch (\Throwable $error) {
                foreach ($tasks as $task) {
                    $this->db->execute(
                        'UPDATE outboxjob SET status = \'pending\', dispatchToken = NULL, leaseUntil = NULL, availableAt = DATE_ADD(NOW(), INTERVAL 30 SECOND), lastError = ? WHERE id = ? AND dispatchToken = ?',
                        [substr($error->getMessage(), 0, 2000), $task['taskId'], $task['dispatchToken']],
                    );
                }
                $this->logger?->error('Immediate queue batch publish failed', [
                    'taskIds' => array_column($tasks, 'taskId'),
                    'error' => $error->getMessage(),
                ]);
            }
        } catch (\Throwable $error) {
            $this->logger?->error('Immediate task dispatch failed', ['taskIds' => $taskIds, 'error' => $error->getMessage()]);
        }
    }
}
