<?php

declare(strict_types=1);

namespace Hfiuc\Log;

use Hfiuc\Auth\AuthService;
use Hfiuc\Database;
use Hfiuc\Http\HttpException;
use Hfiuc\Http\QueryInput;
use Psr\Http\Message\ServerRequestInterface;

final class LogService
{
    public function __construct(
        private readonly Database $db,
        private readonly AuthService $auth,
    ) {
    }

    /** @return array{items: list<array<string, mixed>>, nextBeforeId: ?int} */
    public function list(ServerRequestInterface $request, string $kind): array
    {
        $admin = $this->auth->requireAdmin($request);
        if ($admin['role'] !== 'global') {
            throw new HttpException(403, 'Global administrator required.');
        }

        [$table, $columns] = match ($kind) {
            'errors' => ['errorlog', 'id, level, message, context, method, path, requestId, createdAt'],
            'audit' => ['auditlog', 'id, adminId, action, entity, entityId, detail, requestId, ip, createdAt'],
            'reservations' => ['reservationoperationlog', 'id, adminId, reservationId, operation, reason, createdAt'],
            'outbox' => ['outboxjob', 'id, kind, status, attempts, availableAt, lockedAt, publishedAt, completedAt, createdAt, lastError, payload'],
            default => throw new HttpException(404, 'Log type not found.'),
        };

        $query = new QueryInput($request->getQueryParams());
        $limit = $query->optionalInt('limit') ?? 50;
        if ($limit < 1 || $limit > 100) {
            throw new HttpException(400, 'Invalid query parameter.', ['field' => 'limit']);
        }
        $beforeId = $query->optionalInt('beforeId');
        if ($beforeId !== null && $beforeId < 1) {
            throw new HttpException(400, 'Invalid query parameter.', ['field' => 'beforeId']);
        }

        $sql = 'SELECT ' . $columns . ' FROM ' . $table;
        $params = [];
        if ($beforeId !== null) {
            $sql .= ' WHERE id < ?';
            $params[] = $beforeId;
        }
        $rows = $this->db->fetchAll($sql . ' ORDER BY id DESC LIMIT ' . ($limit + 1), $params);
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $nextBeforeId = $hasMore ? (int) $rows[count($rows) - 1]['id'] : null;

        return [
            'items' => array_map(fn (array $row): array => $this->format($kind, $row), $rows),
            'nextBeforeId' => $nextBeforeId,
        ];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function format(string $kind, array $row): array
    {
        $row['id'] = (int) $row['id'];
        if ($kind === 'outbox') {
            $payload = json_decode((string) $row['payload'], true);
            unset($row['payload']);
            $row['attempts'] = (int) $row['attempts'];
            $row['reservationId'] = is_array($payload) && isset($payload['reservationId'])
                ? (int) $payload['reservationId'] : null;
            $row['reviewVersion'] = is_array($payload) && isset($payload['reviewVersion'])
                ? (int) $payload['reviewVersion'] : null;
        } elseif ($kind === 'reservations') {
            $row['adminId'] = $row['adminId'] === null ? null : (int) $row['adminId'];
            $row['reservationId'] = (int) $row['reservationId'];
        } elseif ($kind === 'audit') {
            $row['adminId'] = $row['adminId'] === null ? null : (int) $row['adminId'];
            $row['detail'] = self::decodeJson($row['detail']);
        } else {
            $row['context'] = self::decodeJson($row['context']);
        }

        return $row;
    }

    private static function decodeJson(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }
}
