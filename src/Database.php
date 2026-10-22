<?php

declare(strict_types=1);

namespace Hfiuc;

use PDO;

final class Database
{
    private PDO $pdo;

    /** @var list<callable(): void> */
    private array $afterCommit = [];

    /** @var array<string, true> */
    private array $afterCommitKeys = [];

    private int $transactionSequence = 0;

    private ?int $activeTransactionId = null;

    public function __construct(Config $config)
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config->dbHost,
            $config->dbPort,
            $config->dbName,
        );
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $this->pdo = new PDO($dsn, $config->dbUser, $config->dbPassword, $options);
        $this->pdo->exec("SET time_zone = '+08:00'");
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @param list<mixed> $params */
    public function fetch(string $sql, array $params = []): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll();

        return $rows === false ? [] : $rows;
    }

    /** @param list<mixed> $params */
    public function execute(string $sql, array $params = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /** @template T
     * @param callable(PDO): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        $this->activeTransactionId = ++$this->transactionSequence;
        $this->afterCommit = [];
        $this->afterCommitKeys = [];
        try {
            $result = $callback($this->pdo);
            $this->pdo->commit();
            $this->activeTransactionId = null;

            $callbacks = $this->afterCommit;
            $this->afterCommit = [];
            $this->afterCommitKeys = [];
            foreach ($callbacks as $afterCommit) {
                $afterCommit();
            }

            return $result;
        } catch (\Throwable $error) {
            $this->afterCommit = [];
            $this->afterCommitKeys = [];
            $this->activeTransactionId = null;
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function afterCommit(callable $callback): void
    {
        if (!$this->pdo->inTransaction()) {
            $callback();

            return;
        }
        $this->afterCommit[] = $callback;
    }

    public function afterCommitOnce(string $key, callable $callback): void
    {
        if (!$this->pdo->inTransaction()) {
            $callback();

            return;
        }
        if (isset($this->afterCommitKeys[$key])) {
            return;
        }
        $this->afterCommitKeys[$key] = true;
        $this->afterCommit[] = $callback;
    }

    public function transactionId(): ?int
    {
        return $this->activeTransactionId;
    }
}
