<?php

declare(strict_types=1);

namespace Hfiuc\Tests;

use Hfiuc\Tests\Support\DatabaseTestCase;

final class LoggerDatabaseTest extends DatabaseTestCase
{
    public function testErrorAndAuditLogsPersistRequestContext(): void
    {
        $path = '/' . str_repeat('a', 240);
        $this->logger->setRequest('req-1', 'POST', $path, '203.0.113.10');
        $this->logger->setAdminId(4);
        $this->logger->error('Unable to create class', ['error' => 'duplicate']);
        $this->logger->audit('campus.create', 'campus', 9, ['name' => 'Knowledge City']);
        $this->logger->audit('campus.delete', 'campus');

        $error = $this->db->fetch('SELECT level, message, context, method, path, requestId FROM errorlog');
        self::assertNotNull($error);
        self::assertSame('error', $error['level']);
        self::assertSame('Unable to create class', $error['message']);
        self::assertSame('POST', $error['method']);
        self::assertSame(191, strlen((string) $error['path']));
        self::assertSame('req-1', $error['requestId']);
        self::assertSame('duplicate', json_decode((string) $error['context'], true)['error']);

        $audit = $this->db->fetch('SELECT adminId, action, entity, entityId, detail, requestId, ip FROM auditlog WHERE action = ?', ['campus.create']);
        self::assertNotNull($audit);
        self::assertSame(4, (int) $audit['adminId']);
        self::assertSame('campus', $audit['entity']);
        self::assertSame('9', $audit['entityId']);
        self::assertSame('req-1', $audit['requestId']);
        self::assertSame('203.0.113.10', $audit['ip']);
        self::assertSame('Knowledge City', json_decode((string) $audit['detail'], true)['name']);

        $deleted = $this->db->fetch('SELECT entityId, detail FROM auditlog WHERE action = ?', ['campus.delete']);
        self::assertNotNull($deleted);
        self::assertNull($deleted['entityId']);
        self::assertNull($deleted['detail']);
    }
}
