<?php

declare(strict_types=1);

namespace Hfiuc\Worker;

interface QueuePublisher
{
    /** @param array<string, mixed> $body */
    public function publish(array $body): void;
}
