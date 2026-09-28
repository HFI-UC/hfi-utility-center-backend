<?php

declare(strict_types=1);

namespace Hfiuc\Tests;

use Hfiuc\Catalog\StudentService;
use Hfiuc\Tests\Support\DatabaseTestCase;

final class StudentServiceTest extends DatabaseTestCase
{
    public function testGlobalAdministratorCanManageMappingsWithoutChangingReservationHistory(): void
    {
        $campusId = $this->insertCampus('Knowledge City');
        $classId = $this->insertClass('A1', $campusId);
        $this->insertAdmin('admin@example.com', 'Global Admin');
        $service = new StudentService($this->db, $this->auth, $this->logger);

        $service->create($this->asAdmin('admin@example.com', [
            'email' => ' STUDENT@EXAMPLE.COM ',
            'name' => 'Li Lei',
            'classId' => $classId,
        ]));
        self::assertSame([[
            'email' => 'student@example.com',
            'name' => 'Li Lei',
            'classId' => $classId,
            'className' => 'A1',
        ]], $service->list($this->asAdminRead('admin@example.com')));

        [$start, $end] = $this->slot(3, 10);
        $roomId = $this->insertRoom('505', $campusId);
        $reservationId = $this->insertReservation($roomId, $start, $end, 'student@example.com', 'pending', $classId, 'Li Lei');
        $service->edit($this->asAdmin('admin@example.com', [
            'email' => 'student@example.com',
            'name' => 'Li Ming',
            'classId' => null,
        ]));
        self::assertSame('Li Ming', $service->list($this->asAdminRead('admin@example.com'))[0]['name']);
        $service->delete($this->asAdmin('admin@example.com', ['email' => 'student@example.com']));
        self::assertSame([], $service->list($this->asAdminRead('admin@example.com')));
        $snapshot = $this->db->fetch('SELECT studentName, classId FROM reservation WHERE id = ?', [$reservationId]);
        self::assertSame('Li Lei', $snapshot['studentName']);
        self::assertSame($classId, (int) $snapshot['classId']);
        $audits = $this->db->fetchAll("SELECT action, detail FROM auditlog WHERE action LIKE 'student.%' ORDER BY id");
        self::assertSame(['student.create', 'student.edit', 'student.delete'], array_column($audits, 'action'));
        self::assertSame(['email' => 'student@example.com', 'name' => 'Li Lei', 'classId' => $classId], json_decode((string) $audits[0]['detail'], true));
        self::assertSame(['email' => 'student@example.com', 'previousName' => 'Li Lei', 'previousClassId' => $classId, 'name' => 'Li Ming', 'classId' => null], json_decode((string) $audits[1]['detail'], true));
        self::assertSame(['email' => 'student@example.com', 'name' => 'Li Ming', 'classId' => null], json_decode((string) $audits[2]['detail'], true));
    }

    public function testOnlyGlobalAdministratorsCanManageMappings(): void
    {
        $campusId = $this->insertCampus('Knowledge City');
        $roomId = $this->insertRoom('505', $campusId);
        $adminId = $this->insertAdmin('room@example.com', 'Room Admin');
        $this->assignRoom($roomId, $adminId);
        $service = new StudentService($this->db, $this->auth, $this->logger);
        $this->expectHttp(
            fn () => $service->list($this->asAdminRead('room@example.com')),
            403,
            'Global administrator required.',
        );
        $this->expectHttp(
            fn () => $service->create($this->asAdmin('room@example.com', ['email' => 'student@example.com', 'name' => 'Li Lei'])),
            403,
            'Global administrator required.',
        );
        self::assertSame(0, (int) $this->db->fetch('SELECT COUNT(*) AS total FROM student')['total']);
    }

    public function testMappingRejectsArchivedClassAndDuplicateEmail(): void
    {
        $campusId = $this->insertCampus('Knowledge City');
        $classId = $this->insertClass('A1', $campusId);
        $this->insertAdmin('admin@example.com', 'Global Admin');
        $service = new StudentService($this->db, $this->auth, $this->logger);
        $service->create($this->asAdmin('admin@example.com', ['email' => 'student@example.com', 'name' => 'Li Lei', 'classId' => $classId]));
        $this->expectHttp(
            fn () => $service->create($this->asAdmin('admin@example.com', ['email' => 'STUDENT@example.com', 'name' => 'Li Ming'])),
            409,
            'Student email already exists.',
        );
        $this->db->execute('UPDATE class SET deletedAt = NOW() WHERE id = ?', [$classId]);
        $this->expectHttp(
            fn () => $service->edit($this->asAdmin('admin@example.com', ['email' => 'student@example.com', 'name' => 'Li Lei', 'classId' => $classId])),
            400,
            'Class not found.',
        );
    }
}
