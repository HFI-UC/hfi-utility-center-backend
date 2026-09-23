<?php

declare(strict_types=1);

namespace Hfiuc\Support;

use Hfiuc\Http\HttpException;
use PDO;

final class RoomLock
{
    /** @var list<string> */
    private array $held = [];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function acquire(int $roomId): void
    {
        $name = 'hfiuc_room_' . $roomId;
        if (in_array($name, $this->held, true)) {
            return;
        }
        $statement = $this->pdo->prepare('SELECT GET_LOCK(?, 5)');
        $statement->execute([$name]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new HttpException(500, 'Unable to lock room.');
        }
        $this->held[] = $name;
    }

    public function release(): void
    {
        foreach ($this->held as $name) {
            $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $statement->execute([$name]);
        }
        $this->held = [];
    }
}
