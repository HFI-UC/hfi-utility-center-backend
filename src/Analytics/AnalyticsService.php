<?php

declare(strict_types=1);

namespace Hfiuc\Analytics;

use Hfiuc\Auth\AuthService;
use Hfiuc\Database;
use Hfiuc\Support\Clock;
use Psr\Http\Message\ServerRequestInterface;

final class AnalyticsService
{
    public function __construct(
        private readonly Database $db,
        private readonly AuthService $auth,
    ) {
    }

    /** @return array<string, mixed> */
    public function overview(ServerRequestInterface $request): array
    {
        $this->auth->requireAdmin($request);
        $start = Clock::now()->setTime(0, 0, 0);
        $end = $start->modify('+1 day');
        $today = $this->count(
            'SELECT COUNT(*) AS total FROM reservation WHERE startTime >= ? AND startTime < ? AND status <> \'cancelled\'',
            [Clock::sql($start), Clock::sql($end)],
        );
        $pending = $this->count('SELECT COUNT(*) AS total FROM reservation WHERE status = \'pending\'', []);

        return [
            'today' => [
                'reservations' => $today,
                'reservationCreations' => 0,
                'requests' => 0,
                'approvals' => 0,
                'rejections' => 0,
            ],
            'pending' => $pending,
        ];
    }

    /** @return array<string, mixed> */
    public function weekly(ServerRequestInterface $request): array
    {
        $this->auth->requireAdmin($request);
        $end = Clock::now()->setTime(0, 0, 0);
        $start = $end->modify('-7 days');
        $startSql = Clock::sql($start);
        $endSql = Clock::sql($end);
        $totals = $this->db->fetch(
            'SELECT SUM(status = \'approved\') AS approvals, SUM(status = \'rejected\') AS rejections, COUNT(*) AS reservations, SUM(createdAt >= ? AND createdAt < ?) AS creations FROM reservation WHERE startTime >= ? AND startTime < ?',
            [$startSql, $endSql, $startSql, $endSql],
        ) ?? [];
        $dailyRows = $this->db->fetchAll(
            'SELECT DAYOFWEEK(startTime) AS dow, COUNT(*) AS total FROM reservation WHERE startTime >= ? AND startTime < ? AND status = \'approved\' GROUP BY DAYOFWEEK(startTime)',
            [$startSql, $endSql],
        );
        $daily = array_fill(0, 7, 0);
        foreach ($dailyRows as $row) {
            $index = (int) $row['dow'] - 1;
            if ($index >= 0 && $index < 7) {
                $daily[$index] = (int) $row['total'];
            }
        }
        $rooms = $this->db->fetchAll(
            'SELECT rm.name AS roomName, SUM(r.startTime >= ? AND r.startTime < ?) AS reservations, SUM(r.createdAt >= ? AND r.createdAt < ?) AS creations FROM room rm LEFT JOIN reservation r ON r.roomId = rm.id GROUP BY rm.id, rm.name ORDER BY reservations DESC LIMIT 5',
            [$startSql, $endSql, $startSql, $endSql],
        );

        return [
            'totalReservations' => (int) ($totals['reservations'] ?? 0),
            'totalReservationCreations' => (int) ($totals['creations'] ?? 0),
            'totalApprovals' => (int) ($totals['approvals'] ?? 0),
            'totalRejections' => (int) ($totals['rejections'] ?? 0),
            'rooms' => array_map(fn (array $row): array => [
                'roomName' => (string) $row['roomName'],
                'reservations' => (int) ($row['reservations'] ?? 0),
                'reservationCreations' => (int) ($row['creations'] ?? 0),
            ], $rooms),
            'reasons' => [],
            'hourlyReservations' => array_fill(0, 24, 0),
            'dailyReservations' => $daily,
            'dailyReservationCreations' => array_fill(0, 7, 0),
        ];
    }

    public function export(ServerRequestInterface $request): string
    {
        $this->auth->requireAdmin($request);
        $rows = $this->db->fetchAll(
            'SELECT date, reservations, reservationCreations, requests, approvals, rejections FROM analytic ORDER BY date DESC LIMIT 365',
        );
        $csv = "date,reservations,reservationCreations,requests,approvals,rejections\n";
        foreach ($rows as $row) {
            $csv .= implode(',', [
                (string) Clock::fromSql((string) $row['date']),
                (int) $row['reservations'],
                (int) $row['reservationCreations'],
                (int) $row['requests'],
                (int) $row['approvals'],
                (int) $row['rejections'],
            ]) . "\n";
        }

        return $csv;
    }

    /** @param list<mixed> $params */
    private function count(string $sql, array $params): int
    {
        $row = $this->db->fetch($sql, $params);

        return (int) ($row['total'] ?? 0);
    }
}
