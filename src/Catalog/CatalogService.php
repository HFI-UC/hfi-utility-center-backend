<?php

declare(strict_types=1);

namespace Hfiuc\Catalog;

use Hfiuc\Auth\AuthService;
use Hfiuc\Database;
use Hfiuc\Http\HttpException;
use Hfiuc\Http\Input;
use Hfiuc\Log\Logger;
use Hfiuc\Reservation\Rules;
use Hfiuc\Support\Clock;
use PDOException;
use Psr\Http\Message\ServerRequestInterface;

final class CatalogService
{
    public function __construct(
        private readonly Database $db,
        private readonly AuthService $auth,
        private readonly Logger $logger,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function campuses(): array
    {
        return $this->cached('campuses', function (): array {
            $rows = $this->db->fetchAll('SELECT id, name, isPrivileged, createdAt FROM campus ORDER BY id');

            return array_map(fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'isPrivileged' => self::flag($row['isPrivileged']),
                'createdAt' => Clock::fromSql($row['createdAt'] === null ? null : (string) $row['createdAt']),
            ], $rows);
        });
    }

    /** @return list<array<string, mixed>> */
    public function classes(): array
    {
        return $this->cached('classes', function (): array {
            $rows = $this->db->fetchAll('SELECT id, name, campusId, createdAt FROM class ORDER BY id');

            return array_map(fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'campus' => $row['campusId'] === null ? null : (int) $row['campusId'],
                'createdAt' => Clock::fromSql($row['createdAt'] === null ? null : (string) $row['createdAt']),
            ], $rows);
        });
    }

    /** @return list<array<string, mixed>> */
    public function rooms(ServerRequestInterface $request): array
    {
        $isAdmin = $this->auth->currentAdmin($request) !== null;
        if (!$isAdmin) {
            return $this->cached('rooms', fn (): array => $this->loadRooms());
        }

        return $this->loadRooms();
    }

    /** @return list<array<string, mixed>> */
    public function admins(ServerRequestInterface $request): array
    {
        $this->auth->requireAdmin($request);
        $rows = $this->db->fetchAll('SELECT id, name, email, createdAt, receiveReservationNotifications FROM admin ORDER BY id');

        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'email' => (string) $row['email'],
            'createdAt' => Clock::fromSql($row['createdAt'] === null ? null : (string) $row['createdAt']),
            'receiveReservationNotifications' => self::flag($row['receiveReservationNotifications']),
        ], $rows);
    }

    public function createCampus(ServerRequestInterface $request): void
    {
        $this->auth->requireAdminWrite($request);
        $name = trim((string) (new Input($this->json($request)))->string('name'));
        if ($name === '') {
            throw new HttpException(400, 'Campus name is required.');
        }
        $this->db->execute('INSERT INTO campus (name) VALUES (?)', [$name]);
        $id = $this->db->lastInsertId();
        $this->invalidate();
        $this->logger->audit('campus.create', 'campus', $id, ['name' => $name]);
    }

    public function editCampus(ServerRequestInterface $request): void
    {
        $this->auth->requireAdminWrite($request);
        $input = new Input($this->json($request));
        $id = $input->int('id');
        $name = trim((string) $input->string('name'));
        if ($name === '') {
            throw new HttpException(400, 'Campus name is required.');
        }
        if ($this->db->execute('UPDATE campus SET name = ? WHERE id = ?', [$name, $id]) === 0) {
            throw new HttpException(404, 'Campus not found.');
        }
        $this->invalidate();
        $this->logger->audit('campus.edit', 'campus', $id, ['name' => $name]);
    }

    public function deleteCampus(ServerRequestInterface $request): void
    {
        $this->auth->requireAdminWrite($request);
        $id = (new Input($this->json($request)))->int('id');
        $this->deleteRow('DELETE FROM campus WHERE id = ?', [$id], 'Campus not found.', 'Campus is still in use.');
        $this->invalidate();
        $this->logger->audit('campus.delete', 'campus', $id);
    }

    public function createClass(ServerRequestInterface $request): void
    {
        $this->auth->requireAdminWrite($request);
        $input = new Input($this->json($request));
        $name = trim((string) $input->string('name'));
        $campus = $input->int('campus');
        try {
            $this->db->execute('INSERT INTO class (name, campusId) VALUES (?, ?)', [$name, $campus]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                throw new HttpException(400, 'Invalid campus.', [], $error);
            }
            $this->logger->error('Unable to create class', ['error' => $error->getMessage()]);
            throw new HttpException(500, 'Unable to create class.', [], $error);
        }
        $id = $this->db->lastInsertId();
        $this->invalidate();
        $this->logger->audit('class.create', 'class', $id, ['name' => $name, 'campus' => $campus]);
    }

    public function editClass(ServerRequestInterface $request): void
    {
        $this->auth->requireAdminWrite($request);
        $input = new Input($this->json($request));
        $id = $input->int('id');
        $name = trim((string) $input->string('name'));
        $campus = $input->int('campus');
        try {
            $changed = $this->db->execute('UPDATE class SET name = ?, campusId = ? WHERE id = ?', [$name, $campus, $id]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                throw new HttpException(400, 'Invalid campus.', [], $error);
            }
            throw new HttpException(500, 'Unable to edit class.', [], $error);
        }
        if ($changed === 0) {
            throw new HttpException(404, 'Class not found.');
        }
        $this->invalidate();
        $this->logger->audit('class.edit', 'class', $id, ['name' => $name, 'campus' => $campus]);
    }

    public function deleteClass(ServerRequestInterface $request): void
    {
        $this->auth->requireAdminWrite($request);
        $id = (new Input($this->json($request)))->int('id');
        $this->deleteRow('DELETE FROM class WHERE id = ?', [$id], 'Class not found.', 'Class is still in use.');
        $this->invalidate();
        $this->logger->audit('class.delete', 'class', $id);
    }

    public function createRoom(ServerRequestInterface $request): void
    {
        $this->auth->requireAdminWrite($request);
        $input = new Input($this->json($request));
        $name = trim((string) $input->string('name'));
        $campus = $input->int('campus');
        try {
            $this->db->execute('INSERT INTO room (name, campusId, enabled) VALUES (?, ?, 1)', [$name, $campus]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                throw new HttpException(400, 'Invalid campus.', [], $error);
            }
            throw new HttpException(500, 'Unable to create room.', [], $error);
        }
        $id = $this->db->lastInsertId();
        $this->invalidate();
        $this->logger->audit('room.create', 'room', $id, ['name' => $name, 'campus' => $campus]);
    }

    public function editRoom(ServerRequestInterface $request): void
    {
        $this->auth->requireAdminWrite($request);
        $input = new Input($this->json($request));
        $id = $input->int('id');
        $name = trim((string) $input->string('name'));
        $campus = $input->int('campus');
        if ($input->has('enabled')) {
            $enabled = $input->bool('enabled') ? 1 : 0;
            $changed = $this->guardCampus(fn (): int => $this->db->execute(
                'UPDATE room SET name = ?, campusId = ?, enabled = ? WHERE id = ?',
                [$name, $campus, $enabled, $id],
            ));
        } else {
            $changed = $this->guardCampus(fn (): int => $this->db->execute(
                'UPDATE room SET name = ?, campusId = ? WHERE id = ?',
                [$name, $campus, $id],
            ));
        }
        if ($changed === 0) {
            throw new HttpException(404, 'Room not found.');
        }
        $this->invalidate();
        $this->logger->audit('room.edit', 'room', $id, ['name' => $name, 'campus' => $campus]);
    }

    public function deleteRoom(ServerRequestInterface $request): void
    {
        $this->auth->requireAdminWrite($request);
        $id = (new Input($this->json($request)))->int('id');
        $this->deleteRow('DELETE FROM room WHERE id = ?', [$id], 'Room not found.', 'Room is still in use.');
        $this->invalidate();
        $this->logger->audit('room.delete', 'room', $id);
    }

    public function createPolicy(ServerRequestInterface $request): void
    {
        $this->auth->requireAdminWrite($request);
        $input = new Input($this->json($request));
        [$days, $start, $end] = $this->policyFields($input);
        $room = $input->int('room');
        try {
            $this->db->execute(
                'INSERT INTO roompolicy (roomId, days, startTime, endTime, enabled) VALUES (?, ?, ?, ?, 1)',
                [$room, self::encodeList($days), self::encodeList($start), self::encodeList($end)],
            );
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                throw new HttpException(404, 'Room not found.', [], $error);
            }
            throw new HttpException(500, 'Unable to create policy.', [], $error);
        }
        $id = $this->db->lastInsertId();
        $this->invalidate();
        $this->logger->audit('policy.create', 'roompolicy', $id, ['room' => $room, 'days' => $days]);
    }

    public function editPolicy(ServerRequestInterface $request): void
    {
        $this->auth->requireAdminWrite($request);
        $input = new Input($this->json($request));
        [$days, $start, $end] = $this->policyFields($input);
        $id = $input->int('id');
        if ($this->db->execute(
            'UPDATE roompolicy SET days = ?, startTime = ?, endTime = ? WHERE id = ?',
            [self::encodeList($days), self::encodeList($start), self::encodeList($end), $id],
        ) === 0) {
            throw new HttpException(404, 'Policy not found.');
        }
        $this->invalidate();
        $this->logger->audit('policy.edit', 'roompolicy', $id, ['days' => $days]);
    }

    public function togglePolicy(ServerRequestInterface $request): void
    {
        $this->auth->requireAdminWrite($request);
        $id = (new Input($this->json($request)))->int('id');
        if ($this->db->execute('UPDATE roompolicy SET enabled = IF(enabled = 1, 0, 1) WHERE id = ?', [$id]) === 0) {
            throw new HttpException(404, 'Policy not found.');
        }
        $this->invalidate();
        $this->logger->audit('policy.toggle', 'roompolicy', $id);
    }

    public function deletePolicy(ServerRequestInterface $request): void
    {
        $this->auth->requireAdminWrite($request);
        $id = (new Input($this->json($request)))->int('id');
        if ($this->db->execute('DELETE FROM roompolicy WHERE id = ?', [$id]) === 0) {
            throw new HttpException(404, 'Policy not found.');
        }
        $this->invalidate();
        $this->logger->audit('policy.delete', 'roompolicy', $id);
    }

    public function createAdmin(ServerRequestInterface $request): void
    {
        $actor = $this->auth->requireAdminWrite($request);
        $input = new Input($this->json($request));
        $name = trim((string) $input->string('name'));
        $email = trim((string) $input->string('email'));
        $password = (string) $input->string('password');
        if (strlen($password) < 6 || !str_contains($email, '@')) {
            throw new HttpException(400, 'Invalid email or password.');
        }
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        if ($hash === false) {
            throw new HttpException(500, 'Unable to hash password.');
        }
        try {
            $this->db->execute('INSERT INTO admin (name, email, password) VALUES (?, ?, ?)', [$name, $email, $hash]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                throw new HttpException(409, 'Admin already exists.', [], $error);
            }
            throw new HttpException(500, 'Unable to create admin.', [], $error);
        }
        $id = $this->db->lastInsertId();
        $this->logger->setAdminId($actor['id']);
        $this->logger->audit('admin.create', 'admin', $id, ['email' => $email, 'name' => $name]);
    }

    public function editAdmin(ServerRequestInterface $request): void
    {
        $this->auth->requireAdminWrite($request);
        $input = new Input($this->json($request));
        $id = $input->int('id');
        $name = trim((string) $input->string('name'));
        $email = trim((string) $input->string('email'));
        if (!str_contains($email, '@')) {
            throw new HttpException(400, 'Invalid email format.');
        }
        try {
            $changed = $this->db->execute('UPDATE admin SET name = ?, email = ? WHERE id = ?', [$name, $email, $id]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                throw new HttpException(409, 'Admin already exists.', [], $error);
            }
            throw new HttpException(500, 'Unable to edit admin.', [], $error);
        }
        if ($changed === 0) {
            throw new HttpException(404, 'Admin not found.');
        }
        $this->logger->audit('admin.edit', 'admin', $id, ['email' => $email, 'name' => $name]);
    }

    public function editPassword(ServerRequestInterface $request): void
    {
        $this->auth->requireAdminWrite($request);
        $input = new Input($this->json($request));
        $id = $input->int('admin');
        $password = (string) $input->string('newPassword');
        if (strlen($password) < 6) {
            throw new HttpException(400, 'Password must be at least 6 characters.');
        }
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        if ($hash === false) {
            throw new HttpException(500, 'Unable to hash password.');
        }
        if ($this->db->execute('UPDATE admin SET password = ? WHERE id = ?', [$hash, $id]) === 0) {
            throw new HttpException(404, 'Admin not found.');
        }
        $this->logger->audit('admin.password', 'admin', $id);
    }

    public function notificationSettings(ServerRequestInterface $request): void
    {
        $this->auth->requireAdminWrite($request);
        $input = new Input($this->json($request));
        $id = $input->int('id');
        $enabled = $input->bool('enabled');
        if ($this->db->execute(
            'UPDATE admin SET receiveReservationNotifications = ? WHERE id = ?',
            [$enabled ? 1 : 0, $id],
        ) === 0) {
            throw new HttpException(404, 'Admin not found.');
        }
        $this->logger->audit('admin.notification', 'admin', $id, ['enabled' => $enabled]);
    }

    public function deleteAdmin(ServerRequestInterface $request): void
    {
        $actor = $this->auth->requireAdminWrite($request);
        $id = (new Input($this->json($request)))->int('id');
        if ($actor['id'] === $id) {
            throw new HttpException(409, 'You cannot delete your active account.');
        }
        if ($this->db->execute('DELETE FROM admin WHERE id = ?', [$id]) === 0) {
            throw new HttpException(404, 'Admin not found.');
        }
        $this->logger->audit('admin.delete', 'admin', $id);
    }

    public function invalidate(): void
    {
        $this->db->execute('DELETE FROM catalogcache');
    }

    public function invalidateAndAudit(): void
    {
        $this->invalidate();
        $this->logger->audit('catalog.invalidate', 'catalog');
    }

    /** @return list<array<string, mixed>> */
    private function loadRooms(): array
    {
        $rooms = $this->db->fetchAll('SELECT id, name, campusId, enabled, createdAt FROM room ORDER BY id');
        $policies = $rooms === []
            ? []
            : $this->db->fetchAll('SELECT id, roomId, days, startTime, endTime, enabled FROM roompolicy ORDER BY id');
        $byRoom = [];
        foreach ($policies as $policy) {
            $roomId = (int) $policy['roomId'];
            $byRoom[$roomId][] = [
                'id' => (int) $policy['id'],
                'roomId' => $roomId,
                'days' => self::decodeList($policy['days']),
                'startTime' => self::decodeList($policy['startTime']),
                'endTime' => self::decodeList($policy['endTime']),
                'enabled' => self::flag($policy['enabled']),
            ];
        }

        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'campus' => $row['campusId'] === null ? null : (int) $row['campusId'],
            'enabled' => $row['enabled'] === null ? true : self::flag($row['enabled']),
            'createdAt' => Clock::fromSql($row['createdAt'] === null ? null : (string) $row['createdAt']),
            'policies' => $byRoom[(int) $row['id']] ?? [],
        ], $rooms);
    }

    /** @param callable(): array<mixed> $loader
     * @return array<mixed>
     */
    private function cached(string $key, callable $loader): array
    {
        $row = $this->db->fetch('SELECT payload FROM catalogcache WHERE cacheKey = ?', [$key]);
        if ($row !== null) {
            $decoded = json_decode((string) $row['payload'], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        $value = $loader();
        $this->db->execute(
            'INSERT INTO catalogcache (cacheKey, payload, updatedAt) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE payload = VALUES(payload), updatedAt = NOW()',
            [$key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        );

        return $value;
    }

    /** @param list<mixed> $params */
    private function deleteRow(string $sql, array $params, string $missing, string $inUse): void
    {
        try {
            $changed = $this->db->execute($sql, $params);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                throw new HttpException(409, $inUse, [], $error);
            }
            $this->logger->error('Delete failed', ['error' => $error->getMessage()]);
            throw new HttpException(500, 'Unable to delete record.', [], $error);
        }
        if ($changed === 0) {
            throw new HttpException(404, $missing);
        }
    }

    /** @param callable(): int $action */
    private function guardCampus(callable $action): int
    {
        try {
            return $action();
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                throw new HttpException(400, 'Invalid campus.', [], $error);
            }
            throw new HttpException(500, 'Unable to edit room.', [], $error);
        }
    }

    /** @return array{0: list<int>, 1: list<int>, 2: list<int>} */
    private function policyFields(Input $input): array
    {
        $days = $input->intList('days');
        $start = $input->intList('startTime');
        $end = $input->intList('endTime');
        if (!Rules::validPolicy($days, $start, $end)) {
            throw new HttpException(400, 'Invalid room policy.');
        }

        return [$days, $start, $end];
    }

    /** @param list<int> $value */
    private static function encodeList(array $value): string
    {
        return (string) json_encode($value);
    }

    /** @return list<int> */
    private static function decodeList(mixed $value): array
    {
        $decoded = json_decode((string) $value, true);
        if (!is_array($decoded)) {
            return [];
        }
        $numbers = [];
        foreach ($decoded as $item) {
            if (is_int($item) || (is_string($item) && preg_match('/^-?\d+$/', $item) === 1)) {
                $numbers[] = (int) $item;
            }
        }

        return $numbers;
    }

    private static function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }

    /** @return array<string, mixed> */
    private function json(ServerRequestInterface $request): array
    {
        $data = $request->getAttribute('json');

        return is_array($data) ? $data : [];
    }
}
