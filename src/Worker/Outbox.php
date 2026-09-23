<?php

declare(strict_types=1);

namespace Hfiuc\Worker;

use Hfiuc\Database;

final class Outbox
{
    public function __construct(
        private readonly Database $db,
        private readonly QueuePublisher $queue,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function enqueue(string $kind, array $payload): int
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('Unable to encode outbox payload');
        }
        $this->db->execute(
            'INSERT INTO outboxjob (kind, payload, status) VALUES (?, ?, \'pending\')',
            [$kind, $encoded],
        );
        $id = $this->db->lastInsertId();
        $this->queue->publish(['jobId' => $id, 'kind' => $kind]);

        return $id;
    }
}
