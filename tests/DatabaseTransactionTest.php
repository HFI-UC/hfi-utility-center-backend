<?php

declare(strict_types=1);

namespace Hfiuc\Tests;

use Hfiuc\Tests\Support\DatabaseTestCase;

final class DatabaseTransactionTest extends DatabaseTestCase
{
    public function testTransactionCommitsAndRollsBack(): void
    {
        $id = $this->db->transaction(function (): int {
            $this->db->execute('INSERT INTO campus (name) VALUES (?)', ['Kept Campus']);

            return $this->db->lastInsertId();
        });
        self::assertNotNull($this->db->fetch('SELECT id FROM campus WHERE id = ?', [$id]));
        self::assertNull($this->db->fetch('SELECT id FROM campus WHERE name = ?', ['Missing']));
        self::assertSame([], $this->db->fetchAll('SELECT id FROM campus WHERE name = ?', ['Missing']));

        try {
            $this->db->transaction(function (): void {
                $this->db->execute('INSERT INTO campus (name) VALUES (?)', ['Rolled Back']);
                throw new \RuntimeException('nope');
            });
            self::fail('Expected the transaction to throw.');
        } catch (\RuntimeException $error) {
            self::assertSame('nope', $error->getMessage());
        }
        self::assertNull($this->db->fetch('SELECT id FROM campus WHERE name = ?', ['Rolled Back']));
        self::assertNotNull($this->db->fetch('SELECT id FROM campus WHERE name = ?', ['Kept Campus']));
    }
}
