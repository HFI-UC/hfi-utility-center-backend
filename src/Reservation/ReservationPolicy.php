<?php

declare(strict_types=1);

namespace Hfiuc\Reservation;

use Hfiuc\Database;
use Hfiuc\Http\HttpException;
use Hfiuc\Support\Clock;

final class ReservationPolicy
{
    public function __construct(private readonly Database $db)
    {
    }

    public function assertCreateRange(\DateTimeImmutable $start, \DateTimeImmutable $end): void
    {
        if ($start >= $end) {
            throw new HttpException(400, 'Start time must be before end time.', ['field' => 'endTime']);
        }
    }

    public function assertOrdinaryCreateTimeAndPurpose(\DateTimeImmutable $start, \DateTimeImmutable $end, ?string $purpose): void
    {
        $now = Clock::now();
        if ($end->getTimestamp() - $start->getTimestamp() > 7200) {
            throw new HttpException(400, 'Reservation duration must not exceed 2 hours.', ['field' => 'endTime']);
        }
        if ($start <= $now) {
            throw new HttpException(400, 'Start time must be in the future.', ['field' => 'startTime']);
        }
        if ($start > $now->modify('+30 days')) {
            throw new HttpException(400, 'Start time must be within 30 days.', ['field' => 'startTime']);
        }
        if (!Rules::validPurpose($purpose)) {
            throw new HttpException(400, 'Invalid purpose type.', ['field' => 'purposeType']);
        }
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} */
    public function editTimes(int $startEpoch, int $endEpoch): array
    {
        $start = Clock::fromUnix($startEpoch);
        $end = Clock::fromUnix($endEpoch);
        if ($start === null) {
            throw new HttpException(400, 'Invalid start time.', ['field' => 'startTime']);
        }
        if ($end === null) {
            throw new HttpException(400, 'Invalid end time.', ['field' => 'endTime']);
        }
        $now = Clock::now();
        if ($start <= $now) {
            throw new HttpException(400, 'The edited reservation must start in the future and end after it starts.', ['field' => 'startTime']);
        }
        if ($start >= $end) {
            throw new HttpException(400, 'The edited reservation must start in the future and end after it starts.', ['field' => 'endTime']);
        }
        if ($end->getTimestamp() - $start->getTimestamp() > 7200) {
            throw new HttpException(400, 'Reservation duration must not exceed 2 hours.', ['field' => 'endTime']);
        }
        if ($start > $now->modify('+30 days')) {
            throw new HttpException(400, 'Start time must be within 30 days.', ['field' => 'startTime']);
        }

        return [$start, $end];
    }

    public function assertOrdinaryCreateAvailability(int $roomId, \DateTimeImmutable $start, \DateTimeImmutable $end, string $email): void
    {
        if (!$this->insidePolicy($roomId, $start, $end)) {
            throw new HttpException(400, 'Requested time is outside the room\'s bookable hours.', ['field' => 'room']);
        }
        $conflict = $this->count(
            'SELECT COUNT(*) AS total FROM reservation WHERE roomId = ? AND status NOT IN (\'rejected\', \'cancelled\') AND startTime < ? AND endTime > ?',
            [$roomId, Clock::sql($end), Clock::sql($start)],
        );
        if ($conflict > 0) {
            throw new HttpException(409, 'Start or end time conflicts with existing reservation.', ['field' => 'conflict']);
        }
        $dayStart = $start->setTime(0, 0, 0);
        $dayEnd = $dayStart->modify('+1 day');
        $daily = $this->count(
            'SELECT COUNT(*) AS total FROM reservation WHERE LOWER(TRIM(email)) = LOWER(?) AND startTime >= ? AND endTime <= ? AND status <> \'cancelled\'',
            [$email, Clock::sql($dayStart), Clock::sql($dayEnd)],
        );
        if ($daily >= 2) {
            throw new HttpException(400, 'You have reached your limit on reservation requests on this day.', ['field' => 'startTime']);
        }
    }

    public function assertEditedRoom(int $reservationId, int $roomId, \DateTimeImmutable $start, \DateTimeImmutable $end): void
    {
        $room = $this->db->fetch('SELECT rm.enabled, rm.deletedAt, cp.deletedAt AS campusDeletedAt FROM room rm LEFT JOIN campus cp ON cp.id = rm.campusId WHERE rm.id = ?', [$roomId]);
        if ($room === null || $room['deletedAt'] !== null || $room['campusDeletedAt'] !== null || ($room['enabled'] !== null && !self::flag($room['enabled']))) {
            throw new HttpException(400, 'Room not found or disabled.', ['field' => 'room']);
        }
        if (!$this->insidePolicy($roomId, $start, $end)) {
            throw new HttpException(400, 'Requested time is outside the room\'s bookable hours.', ['field' => 'room']);
        }
        $conflict = $this->count(
            'SELECT COUNT(*) AS total FROM reservation WHERE id <> ? AND roomId = ? AND status NOT IN (\'rejected\', \'cancelled\') AND startTime < ? AND endTime > ?',
            [$reservationId, $roomId, Clock::sql($end), Clock::sql($start)],
        );
        if ($conflict > 0) {
            throw new HttpException(409, 'The edited time conflicts with another reservation.', ['field' => 'conflict']);
        }
    }

    /** @return list<array<string, mixed>> */
    public function priorityConflicts(int $roomId, \DateTimeImmutable $start, \DateTimeImmutable $end, string $roomName): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, studentName, status, startTime, endTime FROM reservation WHERE roomId = ? AND status IN (\'pending\', \'approved\', \'ai_reviewing\') AND startTime < ? AND endTime > ? ORDER BY id FOR UPDATE',
            [$roomId, Clock::sql($end), Clock::sql($start)],
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'studentName' => (string) $row['studentName'],
            'startTime' => Clock::fromSql((string) $row['startTime']),
            'endTime' => Clock::fromSql((string) $row['endTime']),
            'status' => (string) $row['status'],
            'roomName' => $roomName,
        ], $rows);
    }

    /** @param list<int> $expectedIds
     * @param list<array<string, mixed>> $conflicts
     */
    public function assertPriorityConfirmation(array $expectedIds, array $conflicts): void
    {
        sort($expectedIds);
        $currentIds = array_column($conflicts, 'id');
        sort($currentIds);
        if ($expectedIds !== $currentIds) {
            throw new HttpException(409, 'Conflicts changed. Preview the priority reservation again.', ['field' => 'conflict']);
        }
    }

    private function insidePolicy(int $roomId, \DateTimeImmutable $start, \DateTimeImmutable $end): bool
    {
        if ($start->format('Y-m-d') !== $end->format('Y-m-d')) {
            return false;
        }
        $rows = $this->db->fetchAll('SELECT days, startTime, endTime FROM roompolicy WHERE roomId = ? AND enabled = 1', [$roomId]);
        $weekday = (int) $start->format('w');
        $requestedStart = ((int) $start->format('H')) * 60 + (int) $start->format('i');
        $requestedEnd = ((int) $end->format('H')) * 60 + (int) $end->format('i');
        foreach ($rows as $row) {
            if (Rules::policyAllows(self::intList($row['days']), self::intList($row['startTime']), self::intList($row['endTime']), $weekday, $requestedStart, $requestedEnd)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<mixed> $params */
    private function count(string $sql, array $params): int
    {
        $row = $this->db->fetch($sql, $params);

        return (int) ($row['total'] ?? 0);
    }

    /** @return list<int> */
    private static function intList(mixed $value): array
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
}
