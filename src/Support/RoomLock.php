<?php

declare(strict_types=1);

namespace Hfiuc\Support;

use Hfiuc\Database;
use Hfiuc\Http\HttpException;
use PDO;

final class RoomLock
{
    /** @var list<array{name: string, pdo: PDO}> */
    private array $held = [];

    /** @var list<PDO> */
    private array $extra = [];

    public function __construct(private readonly Database $db)
    {
    }

    public function acquire(int $roomId): void
    {
        $name = 'hfiuc_room_' . $roomId;
        foreach ($this->held as $held) {
            if ($held['name'] === $name) {
                return;
            }
        }
        $pdo = $this->pdoForNewLock();
        $statement = $pdo->prepare('SELECT GET_LOCK(?, 5)');
        $statement->execute([$name]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new HttpException(500, 'Unable to lock room.');
        }
        $this->held[] = ['name' => $name, 'pdo' => $pdo];
    }

    public function release(): void
    {
        foreach (array_reverse($this->held) as $held) {
            $statement = $held['pdo']->prepare('SELECT RELEASE_LOCK(?)');
            $statement->execute([$held['name']]);
            $statement->fetchColumn();
        }
        $this->held = [];
        $this->extra = [];
    }

    private function pdoForNewLock(): PDO
    {
        if ($this->held === []) {
            return $this->db->pdo();
        }
        // MySQL 5.6 keeps only one advisory lock per connection.
        $pdo = $this->db->connect();
        $this->extra[] = $pdo;

        return $pdo;
    }
}
