<?php

declare(strict_types=1);

namespace Hfiuc\Reservation;

use Hfiuc\Database;

final class ReservationAuthorization
{
    public function __construct(private readonly Database $db)
    {
    }

    public function administratorIdForEmail(string $email): ?int
    {
        $row = $this->db->fetch('SELECT id FROM admin WHERE LOWER(email) = LOWER(?) LIMIT 1', [trim($email)]);

        return $row === null ? null : (int) $row['id'];
    }

    public function isGlobal(int $adminId): bool
    {
        $row = $this->db->fetch('SELECT role FROM admin WHERE id = ?', [$adminId]);

        return $row !== null && (string) $row['role'] === 'global';
    }

    public function canManage(int $adminId, ?int $roomId): bool
    {
        if ($this->isGlobal($adminId)) {
            return true;
        }
        if ($roomId === null) {
            return false;
        }

        return $this->db->fetch('SELECT roomId FROM roomapprover WHERE adminId = ? AND roomId = ?', [$adminId, $roomId]) !== null;
    }

    /** @return array{0: string, 1: list<mixed>} */
    public function scope(int $adminId): array
    {
        if ($this->isGlobal($adminId)) {
            return ['', []];
        }

        return [' AND r.roomId IN (SELECT roomId FROM roomapprover WHERE adminId = ?)', [$adminId]];
    }

    /** @return list<int> */
    public function notificationAdminIds(int $roomId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id FROM admin a WHERE a.receiveReservationNotifications = 1 AND (a.role = \'global\' OR EXISTS (SELECT 1 FROM roomapprover ra2 WHERE ra2.adminId = a.id AND ra2.roomId = ?))',
            [$roomId],
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }
}
