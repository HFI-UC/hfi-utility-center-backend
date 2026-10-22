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
    public function campuses(?ServerRequestInterface $request = null): array
    {
        if ($request !== null && $this->includeArchived($request)) {
            $rows = $this->db->fetchAll('SELECT id, name, createdAt, deletedAt, deletedBy FROM campus ORDER BY id');

            return array_map(fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'createdAt' => Clock::fromSql($row['createdAt'] === null ? null : (string) $row['createdAt']),
                'deletedAt' => Clock::fromSql($row['deletedAt'] === null ? null : (string) $row['deletedAt']),
                'deletedBy' => $row['deletedBy'] === null ? null : (int) $row['deletedBy'],
            ], $rows);
        }

        return $this->cached('campuses', function (): array {
            $rows = $this->db->fetchAll('SELECT id, name, createdAt FROM campus WHERE deletedAt IS NULL ORDER BY id');

            return array_map(fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'createdAt' => Clock::fromSql($row['createdAt'] === null ? null : (string) $row['createdAt']),
            ], $rows);
        });
    }

    /** @return list<array<string, mixed>> */
    public function classes(?ServerRequestInterface $request = null): array
    {
        if ($request !== null && $this->includeArchived($request)) {
            $rows = $this->db->fetchAll('SELECT cl.id, cl.name, cl.campusId, cl.createdAt, cl.deletedAt, cl.deletedBy FROM class cl ORDER BY cl.id');

            return array_map(fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'campus' => $row['campusId'] === null ? null : (int) $row['campusId'],
                'createdAt' => Clock::fromSql($row['createdAt'] === null ? null : (string) $row['createdAt']),
                'deletedAt' => Clock::fromSql($row['deletedAt'] === null ? null : (string) $row['deletedAt']),
                'deletedBy' => $row['deletedBy'] === null ? null : (int) $row['deletedBy'],
            ], $rows);
        }

        return $this->cached('classes', function (): array {
            $rows = $this->db->fetchAll('SELECT cl.id, cl.name, cl.campusId, cl.createdAt FROM class cl LEFT JOIN campus ca ON ca.id = cl.campusId WHERE cl.deletedAt IS NULL AND (cl.campusId IS NULL OR ca.deletedAt IS NULL) ORDER BY cl.id');

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
        if ($this->includeArchived($request)) {
            return $this->loadRooms(true);
        }
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
        $rows = $this->db->fetchAll('SELECT id, name, email, role, createdAt, receiveReservationNotifications FROM admin ORDER BY id');

        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'email' => (string) $row['email'],
            'role' => (string) $row['role'],
            'createdAt' => Clock::fromSql($row['createdAt'] === null ? null : (string) $row['createdAt']),
            'receiveReservationNotifications' => self::flag($row['receiveReservationNotifications']),
        ], $rows);
    }

    /** @return list<array{adminId: int, role: string, roomIds: list<int>}> */
    public function permissions(ServerRequestInterface $request): array
    {
        $this->requireGlobal($request);
        $admins = $this->db->fetchAll('SELECT id, role FROM admin ORDER BY id');
        $rooms = $this->db->fetchAll('SELECT adminId, roomId FROM roomapprover ORDER BY adminId, roomId');
        $byAdmin = [];
        foreach ($rooms as $room) {
            $byAdmin[(int) $room['adminId']][] = (int) $room['roomId'];
        }

        return array_map(static fn (array $admin): array => [
            'adminId' => (int) $admin['id'],
            'role' => (string) $admin['role'],
            'roomIds' => $byAdmin[(int) $admin['id']] ?? [],
        ], $admins);
    }

    public function updatePermissions(ServerRequestInterface $request): void
    {
        $actor = $this->auth->requireAdminWrite($request);
        if ($actor['role'] !== 'global') {
            throw new HttpException(403, 'Global administrator required.');
        }
        $input = new Input($this->json($request));
        $adminId = $input->int('adminId');
        $role = $input->string('role');
        $roomIds = array_values(array_unique($input->intList('roomIds')));
        if (!in_array($role, ['global', 'room'], true) || ($role === 'global' && $roomIds !== []) || array_filter($roomIds, static fn (int $id): bool => $id <= 0) !== []) {
            throw new HttpException(422, 'Invalid permissions.');
        }

        $this->db->transaction(function () use ($adminId, $role, $roomIds): void {
            $globalAdmins = $this->db->fetchAll("SELECT id FROM admin WHERE role = 'global' FOR UPDATE");
            $target = $this->db->fetch('SELECT id, role FROM admin WHERE id = ? FOR UPDATE', [$adminId]);
            if ($target === null) {
                throw new HttpException(404, 'Admin not found.');
            }
            if ($target['role'] === 'global' && $role === 'room') {
                if (count($globalAdmins) <= 1) {
                    throw new HttpException(409, 'At least one global administrator is required.');
                }
            }
            if ($roomIds !== []) {
                $placeholders = implode(', ', array_fill(0, count($roomIds), '?'));
                $active = $this->db->fetchAll('SELECT ro.id FROM room ro LEFT JOIN campus ca ON ca.id = ro.campusId WHERE ro.id IN (' . $placeholders . ') AND ro.deletedAt IS NULL AND (ro.campusId IS NULL OR ca.deletedAt IS NULL) FOR UPDATE', $roomIds);
                if (count($active) !== count($roomIds)) {
                    throw new HttpException(422, 'Invalid roomIds.');
                }
            }

            $this->db->execute('UPDATE admin SET role = ? WHERE id = ?', [$role, $adminId]);
            $this->db->execute('DELETE FROM roomapprover WHERE adminId = ?', [$adminId]);
            if ($role === 'room') {
                foreach ($roomIds as $roomId) {
                    $this->db->execute('INSERT INTO roomapprover (roomId, adminId) VALUES (?, ?)', [$roomId, $adminId]);
                }
            }
        });
        $this->logger->setAdminId($actor['id']);
        $this->logger->audit('admin.permissions', 'admin', $adminId, ['role' => $role, 'roomIds' => $roomIds]);
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
        if ($this->db->execute('UPDATE campus SET name = ? WHERE id = ? AND deletedAt IS NULL', [$name, $id]) === 0) {
            throw new HttpException(404, 'Campus not found.');
        }
        $this->invalidate();
        $this->logger->audit('campus.edit', 'campus', $id, ['name' => $name]);
    }

    public function deleteCampus(ServerRequestInterface $request): void
    {
        $actor = $this->auth->requireAdminWrite($request);
        $id = (new Input($this->json($request)))->int('id');
        if ($this->db->execute('UPDATE campus SET deletedAt = NOW(), deletedBy = ? WHERE id = ? AND deletedAt IS NULL', [$actor['id'], $id]) === 0) {
            throw new HttpException(404, 'Campus not found.');
        }
        $this->invalidate();
        $this->logger->audit('campus.archive', 'campus', $id);
    }

    public function restoreCampus(ServerRequestInterface $request): void
    {
        $this->auth->requireAdminWrite($request);
        $id = (new Input($this->json($request)))->int('id');
        if ($this->db->execute('UPDATE campus SET deletedAt = NULL, deletedBy = NULL WHERE id = ? AND deletedAt IS NOT NULL', [$id]) === 0) {
            throw new HttpException(404, 'Archived campus not found.');
        }
        $this->invalidate();
        $this->logger->audit('campus.restore', 'campus', $id);
    }

    public function createClass(ServerRequestInterface $request): void
    {
        $this->auth->requireAdminWrite($request);
        $input = new Input($this->json($request));
        $name = trim((string) $input->string('name'));
        $campus = $input->int('campus');
        $this->assertActiveCampus($campus);
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
        $this->assertActiveCampus($campus);
        try {
            $changed = $this->db->execute('UPDATE class SET name = ?, campusId = ? WHERE id = ? AND deletedAt IS NULL', [$name, $campus, $id]);
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
        $actor = $this->auth->requireAdminWrite($request);
        $id = (new Input($this->json($request)))->int('id');
        if ($this->db->execute('UPDATE class SET deletedAt = NOW(), deletedBy = ? WHERE id = ? AND deletedAt IS NULL', [$actor['id'], $id]) === 0) {
            throw new HttpException(404, 'Class not found.');
        }
        $this->invalidate();
        $this->logger->audit('class.archive', 'class', $id);
    }

    public function restoreClass(ServerRequestInterface $request): void
    {
        $this->auth->requireAdminWrite($request);
        $id = (new Input($this->json($request)))->int('id');
        if ($this->db->execute('UPDATE class c LEFT JOIN campus cp ON cp.id = c.campusId SET c.deletedAt = NULL, c.deletedBy = NULL WHERE c.id = ? AND c.deletedAt IS NOT NULL AND (c.campusId IS NULL OR cp.deletedAt IS NULL)', [$id]) === 0) {
            if ($this->db->fetch('SELECT c.id FROM class c JOIN campus cp ON cp.id = c.campusId WHERE c.id = ? AND c.deletedAt IS NOT NULL AND cp.deletedAt IS NOT NULL', [$id]) !== null) {
                throw new HttpException(409, 'Restore the campus before restoring this class.');
            }
            throw new HttpException(404, 'Archived class not found.');
        }
        $this->invalidate();
        $this->logger->audit('class.restore', 'class', $id);
    }

    public function createRoom(ServerRequestInterface $request): void
    {
        $this->auth->requireAdminWrite($request);
        $input = new Input($this->json($request));
        $name = trim((string) $input->string('name'));
        $campus = $input->int('campus');
        $this->assertActiveCampus($campus);
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
        $this->assertActiveCampus($campus);
        if ($input->has('enabled')) {
            $enabled = $input->bool('enabled') ? 1 : 0;
            $changed = $this->guardCampus(fn (): int => $this->db->execute(
                'UPDATE room SET name = ?, campusId = ?, enabled = ? WHERE id = ? AND deletedAt IS NULL',
                [$name, $campus, $enabled, $id],
            ));
        } else {
            $changed = $this->guardCampus(fn (): int => $this->db->execute(
                'UPDATE room SET name = ?, campusId = ? WHERE id = ? AND deletedAt IS NULL',
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
        $actor = $this->auth->requireAdminWrite($request);
        $id = (new Input($this->json($request)))->int('id');
        if ($this->db->execute('UPDATE room SET deletedAt = NOW(), deletedBy = ? WHERE id = ? AND deletedAt IS NULL', [$actor['id'], $id]) === 0) {
            throw new HttpException(404, 'Room not found.');
        }
        $this->invalidate();
        $this->logger->audit('room.archive', 'room', $id);
    }

    public function restoreRoom(ServerRequestInterface $request): void
    {
        $this->auth->requireAdminWrite($request);
        $id = (new Input($this->json($request)))->int('id');
        if ($this->db->execute('UPDATE room rm LEFT JOIN campus cp ON cp.id = rm.campusId SET rm.deletedAt = NULL, rm.deletedBy = NULL WHERE rm.id = ? AND rm.deletedAt IS NOT NULL AND (rm.campusId IS NULL OR cp.deletedAt IS NULL)', [$id]) === 0) {
            if ($this->db->fetch('SELECT rm.id FROM room rm JOIN campus cp ON cp.id = rm.campusId WHERE rm.id = ? AND rm.deletedAt IS NOT NULL AND cp.deletedAt IS NOT NULL', [$id]) !== null) {
                throw new HttpException(409, 'Restore the campus before restoring this room.');
            }
            throw new HttpException(404, 'Archived room not found.');
        }
        $this->invalidate();
        $this->logger->audit('room.restore', 'room', $id);
    }

    public function createPolicy(ServerRequestInterface $request): void
    {
        $this->auth->requireAdminWrite($request);
        $input = new Input($this->json($request));
        [$days, $start, $end] = $this->policyFields($input);
        $room = $input->int('room');
        $this->assertActiveRoom($room);
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
        $existing = $this->db->fetch('SELECT roomId FROM roompolicy WHERE id = ?', [$id]);
        if ($existing === null) {
            throw new HttpException(404, 'Policy not found.');
        }
        $this->assertActiveRoom((int) $existing['roomId']);
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
        $existing = $this->db->fetch('SELECT roomId FROM roompolicy WHERE id = ?', [$id]);
        if ($existing === null) {
            throw new HttpException(404, 'Policy not found.');
        }
        $this->assertActiveRoom((int) $existing['roomId']);
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
        $existing = $this->db->fetch('SELECT roomId FROM roompolicy WHERE id = ?', [$id]);
        if ($existing === null) {
            throw new HttpException(404, 'Policy not found.');
        }
        $this->assertActiveRoom((int) $existing['roomId']);
        if ($this->db->execute('DELETE FROM roompolicy WHERE id = ?', [$id]) === 0) {
            throw new HttpException(404, 'Policy not found.');
        }
        $this->invalidate();
        $this->logger->audit('policy.delete', 'roompolicy', $id);
    }

    private function assertActiveRoom(int $roomId): void
    {
        $room = $this->db->fetch('SELECT ro.id FROM room ro LEFT JOIN campus ca ON ca.id = ro.campusId WHERE ro.id = ? AND ro.deletedAt IS NULL AND (ro.campusId IS NULL OR ca.deletedAt IS NULL)', [$roomId]);
        if ($room === null) {
            throw new HttpException(404, 'Room not found.');
        }
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
        $this->db->transaction(function () use ($id): void {
            $globalAdmins = $this->db->fetchAll("SELECT id FROM admin WHERE role = 'global' FOR UPDATE");
            $target = $this->db->fetch('SELECT id, role FROM admin WHERE id = ? FOR UPDATE', [$id]);
            if ($target === null) {
                throw new HttpException(404, 'Admin not found.');
            }
            if ($target['role'] === 'global' && count($globalAdmins) <= 1) {
                throw new HttpException(409, 'At least one global administrator is required.');
            }
            try {
                $this->db->execute('DELETE FROM admin WHERE id = ?', [$id]);
            } catch (PDOException $error) {
                if ($error->getCode() === '23000') {
                    throw new HttpException(409, 'Admin is still in use.', [], $error);
                }
                throw $error;
            }
        });
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
    private function loadRooms(bool $includeArchived = false): array
    {
        $rooms = $this->db->fetchAll($includeArchived
            ? 'SELECT ro.id, ro.name, ro.campusId, ro.enabled, ro.createdAt, ro.deletedAt, ro.deletedBy FROM room ro ORDER BY ro.id'
            : 'SELECT ro.id, ro.name, ro.campusId, ro.enabled, ro.createdAt FROM room ro LEFT JOIN campus ca ON ca.id = ro.campusId WHERE ro.deletedAt IS NULL AND (ro.campusId IS NULL OR ca.deletedAt IS NULL) ORDER BY ro.id');
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

        return array_map(static function (array $row) use ($byRoom, $includeArchived): array {
            $result = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'campus' => $row['campusId'] === null ? null : (int) $row['campusId'],
                'enabled' => $row['enabled'] === null ? true : self::flag($row['enabled']),
                'createdAt' => Clock::fromSql($row['createdAt'] === null ? null : (string) $row['createdAt']),
                'policies' => $byRoom[(int) $row['id']] ?? [],
            ];
            if ($includeArchived) {
                $result['deletedAt'] = Clock::fromSql($row['deletedAt'] === null ? null : (string) $row['deletedAt']);
                $result['deletedBy'] = $row['deletedBy'] === null ? null : (int) $row['deletedBy'];
            }

            return $result;
        }, $rooms);
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

    private function includeArchived(ServerRequestInterface $request): bool
    {
        if (($request->getQueryParams()['includeArchived'] ?? null) !== 'true') {
            return false;
        }

        $this->auth->requireAdmin($request);

        return true;
    }

    /** @return array{id: int, email: string, name: string, password: string, role: string} */
    private function requireGlobal(ServerRequestInterface $request): array
    {
        $actor = $this->auth->requireAdmin($request);
        if ($actor['role'] !== 'global') {
            throw new HttpException(403, 'Global administrator required.');
        }

        return $actor;
    }

    private function assertActiveCampus(int $id): void
    {
        if ($this->db->fetch('SELECT id FROM campus WHERE id = ? AND deletedAt IS NULL', [$id]) === null) {
            throw new HttpException(400, 'Invalid campus.');
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
