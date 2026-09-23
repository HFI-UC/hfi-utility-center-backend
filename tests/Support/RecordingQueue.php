<?php

declare(strict_types=1);

namespace Hfiuc\Tests\Support;

use Hfiuc\Worker\QueuePublisher;

final class RecordingQueue implements QueuePublisher
{
    /** @var list<array<string, mixed>> */
    public array $messages = [];

    public bool $fail = false;

    public function publish(array $body): void
    {
        if ($this->fail) {
            throw new \RuntimeException('queue down');
        }
        $this->messages[] = $body;
    }
}
