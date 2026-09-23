<?php

declare(strict_types=1);

namespace Hfiuc\Tests;

use Hfiuc\Tests\Support\DatabaseTestCase;

final class CatalogServiceTest extends DatabaseTestCase
{
    public function testCatalogLifecycleAndCache(): void
    {
        $this->insertAdmin('super@example.com', 'Super');
        $this->expectHttp(
            fn () => $this->catalog->createCampus($this->request('POST', '/campus/create', ['name' => 'Knowledge City'])),
            401,
            'User is not logged in.',
        );
        $this->expectHttp(
            fn () => $this->catalog->createCampus($this->asAdmin('super@example.com', ['name' => 'Knowledge City'], [], false)),
            403,
            'CSRF token missing or invalid.',
        );
        $this->expectHttp(
            fn () => $this->catalog->createCampus($this->asAdmin('super@example.com', ['name' => '   '])),
            400,
            'Campus name is required.',
        );

        $this->catalog->createCampus($this->asAdmin('super@example.com', ['name' => 'Knowledge City']));
        $campusId = (int) $this->db->fetch('SELECT id FROM campus WHERE name = ?', ['Knowledge City'])['id'];
        $this->catalog->editCampus($this->asAdmin('super@example.com', ['id' => $campusId, 'name' => 'Knowledge City Campus']));
        $this->expectHttp(
            fn () => $this->catalog->editCampus($this->asAdmin('super@example.com', ['id' => 999999, 'name' => 'Missing'])),
            404,
            'Campus not found.',
        );

        $this->catalog->createClass($this->asAdmin('super@example.com', ['name' => 'A1', 'campus' => $campusId]));
        $classId = (int) $this->db->fetch('SELECT id FROM class WHERE name = ?', ['A1'])['id'];
        $this->expectHttp(
            fn () => $this->catalog->createClass($this->asAdmin('super@example.com', ['name' => 'A2', 'campus' => 999999])),
            400,
            'Invalid campus.',
        );
        $this->expectHttp(
            fn () => $this->catalog->deleteCampus($this->asAdmin('super@example.com', ['id' => $campusId])),
            409,
            'Campus is still in use.',
        );

        $this->catalog->createRoom($this->asAdmin('super@example.com', ['name' => '505', 'campus' => $campusId]));
        $roomId = (int) $this->db->fetch('SELECT id FROM room WHERE name = ?', ['505'])['id'];
        $this->catalog->createPolicy($this->asAdmin('super@example.com', [
            'room' => $roomId,
            'days' => [1, 3, 5],
            'startTime' => [8, 0],
            'endTime' => [21, 30],
        ]));
        $this->expectHttp(
            fn () => $this->catalog->createPolicy($this->asAdmin('super@example.com', [
                'room' => $roomId,
                'days' => [1, 1],
                'startTime' => [8, 0],
                'endTime' => [9, 0],
            ])),
            400,
            'Invalid room policy.',
        );
        $this->expectHttp(
            fn () => $this->catalog->createPolicy($this->asAdmin('super@example.com', [
                'room' => 999999,
                'days' => [1],
                'startTime' => [8, 0],
                'endTime' => [9, 0],
            ])),
            404,
            'Room not found.',
        );

        $guestRooms = $this->catalog->rooms($this->request('GET', '/room/list'));
        self::assertSame('505', $guestRooms[0]['name']);
        self::assertSame([1, 3, 5], $guestRooms[0]['policies'][0]['days']);
        self::assertTrue($guestRooms[0]['enabled']);
        $this->db->execute('UPDATE room SET name = ? WHERE id = ?', ['505 renamed', $roomId]);
        self::assertSame('505', $this->catalog->rooms($this->request('GET', '/room/list'))[0]['name']);
        self::assertSame('505 renamed', $this->catalog->rooms($this->asAdminRead('super@example.com'))[0]['name']);

        $cachedCampuses = $this->catalog->campuses();
        self::assertSame('Knowledge City Campus', $cachedCampuses[0]['name']);
        self::assertFalse($cachedCampuses[0]['isPrivileged']);
        $this->db->execute('UPDATE campus SET name = ? WHERE id = ?', ['Stale name', $campusId]);
        self::assertSame('Knowledge City Campus', $this->catalog->campuses()[0]['name']);
        $this->catalog->invalidate();
        self::assertSame('Stale name', $this->catalog->campuses()[0]['name']);

        $policyId = (int) $guestRooms[0]['policies'][0]['id'];
        $this->catalog->editPolicy($this->asAdmin('super@example.com', [
            'id' => $policyId,
            'days' => [0, 6],
            'startTime' => [9, 30],
            'endTime' => [18, 0],
        ]));
        $this->catalog->togglePolicy($this->asAdmin('super@example.com', ['id' => $policyId]));
        $policy = $this->db->fetch('SELECT days, enabled FROM roompolicy WHERE id = ?', [$policyId]);
        self::assertSame('[0,6]', $policy['days']);
        self::assertSame(0, (int) $policy['enabled']);
        $this->catalog->deletePolicy($this->asAdmin('super@example.com', ['id' => $policyId]));
        $this->expectHttp(
            fn () => $this->catalog->deletePolicy($this->asAdmin('super@example.com', ['id' => $policyId])),
            404,
            'Policy not found.',
        );

        $this->catalog->editRoom($this->asAdmin('super@example.com', [
            'id' => $roomId,
            'name' => '506',
            'campus' => $campusId,
            'enabled' => false,
        ]));
        self::assertSame(0, (int) $this->db->fetch('SELECT enabled FROM room WHERE id = ?', [$roomId])['enabled']);
        $this->catalog->deleteRoom($this->asAdmin('super@example.com', ['id' => $roomId]));
        $this->expectHttp(
            fn () => $this->catalog->deleteRoom($this->asAdmin('super@example.com', ['id' => $roomId])),
            404,
            'Room not found.',
        );
        $this->catalog->deleteClass($this->asAdmin('super@example.com', ['id' => $classId]));
        $this->catalog->deleteCampus($this->asAdmin('super@example.com', ['id' => $campusId]));
        self::assertSame(0, (int) $this->db->fetch('SELECT COUNT(*) AS total FROM campus')['total']);
        self::assertNotNull($this->db->fetch('SELECT id FROM auditlog WHERE action = ?', ['campus.delete']));
    }

    public function testAdminAccountsAndNotificationSettings(): void
    {
        $actor = $this->insertAdmin('super@example.com', 'Super', false, 'secret');
        $this->expectHttp(
            fn () => $this->catalog->admins($this->request('GET', '/admin/list')),
            401,
            'User is not logged in.',
        );
        $listed = $this->catalog->admins($this->asAdminRead('super@example.com'));
        self::assertSame('super@example.com', $listed[0]['email']);
        self::assertFalse($listed[0]['receiveReservationNotifications']);

        $this->expectHttp(
            fn () => $this->catalog->createAdmin($this->asAdmin('super@example.com', [
                'name' => 'New',
                'email' => 'new@example.com',
                'password' => 'short',
            ])),
            400,
            'Invalid email or password.',
        );
        $this->catalog->createAdmin($this->asAdmin('super@example.com', [
            'name' => 'New Admin',
            'email' => 'new@example.com',
            'password' => 'secret1',
        ]));
        $created = $this->db->fetch('SELECT id, password FROM admin WHERE email = ?', ['new@example.com']);
        self::assertNotNull($created);
        self::assertTrue(password_verify('secret1', (string) $created['password']));
        $this->expectHttp(
            fn () => $this->catalog->createAdmin($this->asAdmin('super@example.com', [
                'name' => 'Duplicate',
                'email' => 'new@example.com',
                'password' => 'secret1',
            ])),
            409,
            'Admin already exists.',
        );

        $session = $this->auth->login($this->request('POST', '/admin/login', [
            'email' => 'new@example.com',
            'password' => 'secret1',
            'turnstileToken' => 'pass',
        ], [], ['x-csrf-token' => $this->csrf()]));
        $check = $this->auth->check($this->request('GET', '/admin/check', [], [], ['Cookie' => 'uc=' . $session]));
        self::assertSame('New Admin', $check['name']);

        $this->catalog->editAdmin($this->asAdmin('super@example.com', [
            'id' => (int) $created['id'],
            'name' => 'Renamed',
            'email' => 'renamed@example.com',
        ]));
        $this->expectHttp(
            fn () => $this->catalog->editPassword($this->asAdmin('super@example.com', [
                'admin' => (int) $created['id'],
                'newPassword' => '12345',
            ])),
            400,
            'Password must be at least 6 characters.',
        );
        $this->catalog->editPassword($this->asAdmin('super@example.com', [
            'admin' => (int) $created['id'],
            'newPassword' => 'secret2',
        ]));
        self::assertTrue(password_verify('secret2', (string) $this->db->fetch('SELECT password FROM admin WHERE id = ?', [(int) $created['id']])['password']));

        $this->catalog->notificationSettings($this->asAdmin('super@example.com', [
            'id' => (int) $created['id'],
            'enabled' => true,
        ]));
        self::assertSame(1, (int) $this->db->fetch('SELECT receiveReservationNotifications FROM admin WHERE id = ?', [(int) $created['id']])['receiveReservationNotifications']);
        $this->expectHttp(
            fn () => $this->catalog->deleteAdmin($this->asAdmin('super@example.com', ['id' => $actor])),
            409,
            'You cannot delete your active account.',
        );
        $this->catalog->deleteAdmin($this->asAdmin('super@example.com', ['id' => (int) $created['id']]));
        $this->expectHttp(
            fn () => $this->catalog->deleteAdmin($this->asAdmin('super@example.com', ['id' => (int) $created['id']])),
            404,
            'Admin not found.',
        );
    }

    public function testScopedApproverCannotManageAccountsAndInvalidateRequiresAdmin(): void
    {
        $campusId = $this->insertCampus('Knowledge City');
        $roomId = $this->insertRoom('505', $campusId, 1);
        $scoped = $this->insertAdmin('room@example.com', 'Room');
        $this->assignRoom($roomId, $scoped);
        $target = $this->insertAdmin('target@example.com', 'Target', false, 'secret');
        $denied = 'Only an unrestricted administrator can manage accounts.';
        $this->expectHttp(
            fn () => $this->catalog->createAdmin($this->asAdmin('room@example.com', [
                'name' => 'Evil',
                'email' => 'evil@example.com',
                'password' => 'secret1',
            ])),
            403,
            $denied,
        );
        $this->expectHttp(
            fn () => $this->catalog->editPassword($this->asAdmin('room@example.com', [
                'admin' => $target,
                'newPassword' => 'secret2',
            ])),
            403,
            $denied,
        );
        $this->expectHttp(
            fn () => $this->catalog->deleteAdmin($this->asAdmin('room@example.com', ['id' => $target])),
            403,
            $denied,
        );
        self::assertTrue(password_verify('secret', (string) $this->db->fetch('SELECT password FROM admin WHERE id = ?', [$target])['password']));
        self::assertNull($this->db->fetch('SELECT id FROM admin WHERE email = ?', ['evil@example.com']));

        $this->expectHttp(
            fn () => $this->catalog->invalidateAndAudit($this->request('POST', '/catalog/invalidate')),
            401,
            'User is not logged in.',
        );
        $this->db->execute(
            'INSERT INTO catalogcache (cacheKey, payload, updatedAt) VALUES (?, ?, NOW())',
            ['campuses', '[]'],
        );
        $this->catalog->invalidateAndAudit($this->asAdmin('target@example.com'));
        self::assertSame(0, (int) $this->db->fetch('SELECT COUNT(*) AS total FROM catalogcache')['total']);
        self::assertNotNull($this->db->fetch('SELECT id FROM auditlog WHERE action = ?', ['catalog.invalidate']));
    }
}
