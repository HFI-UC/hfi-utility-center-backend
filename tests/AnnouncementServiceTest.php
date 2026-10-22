<?php

declare(strict_types=1);

namespace Hfiuc\Tests;

use Hfiuc\Tests\Support\DatabaseTestCase;

final class AnnouncementServiceTest extends DatabaseTestCase
{
    public function testAnnouncementStaysHiddenUntilItHasEnabledContent(): void
    {
        self::assertNull($this->announcements->current());
        $this->insertAdmin('super@example.com', 'Super');
        $this->expectHttp(
            fn () => $this->announcements->admin($this->request('GET', '/announcement/admin')),
            401,
            'User is not logged in.',
        );
        self::assertSame(
            ['id' => null, 'title' => '', 'content' => '', 'enabled' => false, 'updatedAt' => null],
            $this->announcements->admin($this->asAdminRead('super@example.com')),
        );
        $this->expectHttp(
            fn () => $this->announcements->update($this->asAdmin('super@example.com', [
                'title' => 'Hi',
                'content' => '   ',
                'enabled' => true,
            ])),
            400,
            'Invalid announcement content.',
        );
        $this->expectHttp(
            fn () => $this->announcements->update($this->asAdmin('super@example.com', [
                'title' => str_repeat('a', 121),
                'content' => 'Hello',
                'enabled' => true,
            ])),
            400,
            'Invalid announcement content.',
        );
        $this->expectHttp(
            fn () => $this->announcements->update($this->asAdmin('super@example.com', [
                'title' => 'Hi',
                'content' => str_repeat('b', 4001),
                'enabled' => false,
            ])),
            400,
            'Invalid announcement content.',
        );

        $this->announcements->update($this->asAdmin('super@example.com', [
            'title' => 'Notice',
            'content' => 'Library closed',
            'enabled' => true,
        ]));
        $current = $this->announcements->current();
        self::assertNotNull($current);
        self::assertSame('Notice', $current['title']);
        self::assertSame('Library closed', $current['content']);
        self::assertTrue($current['enabled']);
        self::assertSame(1, $current['id']);

        $this->announcements->update($this->asAdmin('super@example.com', [
            'title' => 'Notice',
            'content' => 'Library closed',
            'enabled' => false,
        ]));
        self::assertNull($this->announcements->current());
        $stored = $this->announcements->admin($this->asAdminRead('super@example.com'));
        self::assertFalse($stored['enabled']);
        self::assertSame('Library closed', $stored['content']);
        self::assertSame(1, (int) $this->db->fetch('SELECT COUNT(*) AS total FROM announcement')['total']);
        self::assertNotNull($this->db->fetch('SELECT id FROM auditlog WHERE action = ?', ['announcement.update']));
    }
}
