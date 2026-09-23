<?php

declare(strict_types=1);

namespace Hfiuc\Tests;

use Hfiuc\Support\Clock;
use Hfiuc\Tests\Support\DatabaseTestCase;

final class AnalyticsServiceTest extends DatabaseTestCase
{
    public function testOverviewCountsTodayAndPendingReservations(): void
    {
        $roomId = $this->room();
        $noon = Clock::now()->setTime(12, 0, 0);
        $this->insertReservation($roomId, $noon, $noon->modify('+1 hour'), 'today@example.com', 'pending');
        $this->insertReservation($roomId, $noon->modify('+2 hours'), $noon->modify('+3 hours'), 'cancelled@example.com', 'cancelled');
        $tomorrow = $noon->modify('+1 day');
        $this->insertReservation($roomId, $tomorrow, $tomorrow->modify('+1 hour'), 'tomorrow@example.com', 'pending');

        $this->expectHttp(
            fn () => $this->analytics->overview($this->request('GET', '/analytics/overview')),
            401,
            'User is not logged in.',
        );
        $this->insertAdmin('analytics@example.com', 'Analytics');
        $overview = $this->analytics->overview($this->asAdminRead('analytics@example.com'));
        self::assertSame(1, $overview['today']['reservations']);
        self::assertSame(0, $overview['today']['approvals']);
        self::assertSame(2, $overview['pending']);
    }

    public function testWeeklySummaryGroupsThePreviousSevenDays(): void
    {
        $roomId = $this->room();
        $roomName = (string) $this->db->fetch('SELECT name FROM room WHERE id = ?', [$roomId])['name'];
        $yesterday = Clock::now()->modify('-1 day')->setTime(15, 0, 0);
        foreach (['approved', 'rejected', 'cancelled'] as $status) {
            $this->insertReservation(
                $roomId,
                $yesterday,
                $yesterday->modify('+1 hour'),
                $status . '@example.com',
                $status,
                null,
                'GJ20240001',
                'Li Lei',
                'Study',
                'personal',
                false,
                $yesterday,
            );
        }

        $this->expectHttp(
            fn () => $this->analytics->weekly($this->request('GET', '/analytics/weekly')),
            401,
            'User is not logged in.',
        );
        $this->insertAdmin('analytics@example.com', 'Analytics');
        $weekly = $this->analytics->weekly($this->asAdminRead('analytics@example.com'));
        self::assertSame(3, $weekly['totalReservations']);
        self::assertSame(3, $weekly['totalReservationCreations']);
        self::assertSame(1, $weekly['totalApprovals']);
        self::assertSame(1, $weekly['totalRejections']);
        self::assertCount(24, $weekly['hourlyReservations']);
        self::assertCount(7, $weekly['dailyReservations']);
        self::assertSame([], $weekly['reasons']);
        $dow = (int) $this->db->fetch('SELECT DAYOFWEEK(?) AS dow', [Clock::sql($yesterday)])['dow'];
        self::assertSame(1, $weekly['dailyReservations'][$dow - 1]);
        self::assertSame($roomName, $weekly['rooms'][0]['roomName']);
        self::assertSame(3, $weekly['rooms'][0]['reservations']);
        self::assertSame(3, $weekly['rooms'][0]['reservationCreations']);
    }

    public function testAnalyticsExportRequiresAnAdmin(): void
    {
        $this->db->execute(
            'INSERT INTO analytic (date, reservations, reservationCreations, requests, approvals, rejections) VALUES (?, 2, 3, 4, 5, 6)',
            ['2026-09-01 00:00:00'],
        );
        $this->insertAdmin('super@example.com', 'Super');
        $this->expectHttp(
            fn () => $this->analytics->export($this->request('GET', '/analytics/export')),
            401,
            'User is not logged in.',
        );
        $csv = $this->analytics->export($this->asAdminRead('super@example.com'));
        self::assertStringContainsString("date,reservations,reservationCreations,requests,approvals,rejections\n", $csv);
        self::assertStringContainsString('2026-09-01T00:00:00,2,3,4,5,6', $csv);
    }

    private function room(): int
    {
        $campusId = $this->insertCampus('Knowledge City');
        $roomId = $this->insertRoom('505', $campusId, 1);
        $this->insertPolicy($roomId);

        return $roomId;
    }
}
