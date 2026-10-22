<?php

declare(strict_types=1);

namespace Hfiuc\Tests\Support;

use Hfiuc\Worker\QueuePublisher;

final class RecordingQueue implements QueuePublisher
{
    /** @var list<array<string, mixed>> */
    public array $messages = [];

    public bool $fail = false;

    public int $batchCalls = 0;

    public function publish(array $body): void
    {
        if ($this->fail) {
            throw new \RuntimeException('queue down');
        }
        $this->messages[] = $body;
    }

    public function publishBatch(array $bodies): void
    {
        ++$this->batchCalls;
        if ($this->fail) {
            throw new \RuntimeException('queue down');
        }
        array_push($this->messages, ...$bodies);
    }
}
