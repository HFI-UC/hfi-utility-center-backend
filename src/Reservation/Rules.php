<?php

declare(strict_types=1);

namespace Hfiuc\Reservation;

use Hfiuc\Support\Clock;

final class Rules
{
    public static function validStudentId(string $studentId): bool
    {
        return strlen($studentId) === 10
            && str_starts_with($studentId, 'GJ')
            && ctype_digit(substr($studentId, 2));
    }

    public static function validPurpose(?string $purpose): bool
    {
        return $purpose === null || in_array($purpose, ['personal', 'class', 'club'], true);
    }

    /** @param list<int> $days
     * @param list<int> $start
     * @param list<int> $end
     */
    public static function validPolicy(array $days, array $start, array $end): bool
    {
        $unique = array_values(array_unique($days));
        if (count($unique) !== count($days)) {
            return false;
        }
        foreach ($days as $day) {
            if ($day < 0 || $day > 6) {
                return false;
            }
        }

        return self::validClock($start) && self::validClock($end);
    }

    /** @param list<int> $clock */
    private static function validClock(array $clock): bool
    {
        if (count($clock) !== 2) {
            return false;
        }

        return $clock[0] >= 0 && $clock[0] <= 23 && $clock[1] >= 0 && $clock[1] <= 59;
    }

    /** @param list<int>|null $days
     * @param list<int>|null $start
     * @param list<int>|null $end
     */
    public static function policyAllows(?array $days, ?array $start, ?array $end, int $weekday, int $requestedStart, int $requestedEnd): bool
    {
        if ($days === null || !in_array($weekday, $days, true)) {
            return false;
        }
        $policyStart = self::minutes($start);
        $policyEnd = self::minutes($end);
        if ($policyStart === null || $policyEnd === null) {
            return false;
        }

        return $requestedStart >= $policyStart && $requestedEnd <= $policyEnd;
    }

    /** @param list<int>|null $clock */
    public static function minutes(?array $clock): ?int
    {
        if ($clock === null || count($clock) !== 2) {
            return null;
        }

        return $clock[0] * 60 + $clock[1];
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null */
    public static function futureRange(int $startEpoch, int $endEpoch): ?array
    {
        $start = Clock::fromUnix($startEpoch);
        $end = Clock::fromUnix($endEpoch);
        if ($start === null || $end === null) {
            return null;
        }

        return [$start, $end];
    }
}
