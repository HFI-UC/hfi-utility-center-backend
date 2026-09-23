<?php

declare(strict_types=1);

namespace Hfiuc\Tests;

use Hfiuc\Http\Responder;
use Hfiuc\Reservation\Rules;
use Hfiuc\Support\Clock;
use Hfiuc\Worker\MailTemplate;
use Hfiuc\Xlsx\SimpleXlsx;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;
use ZipArchive;

final class RulesTest extends TestCase
{
    public function testUnixSecondBecomesShanghaiWallTime(): void
    {
        $local = Clock::fromUnix(1800000000);
        self::assertNotNull($local);
        self::assertSame('2027-01-15T16:00:00', Clock::api($local));
    }

    public function testStudentIdShape(): void
    {
        self::assertTrue(Rules::validStudentId('GJ20999999'));
        self::assertFalse(Rules::validStudentId('GJ2099999'));
        self::assertFalse(Rules::validStudentId('gj20999999'));
    }

    public function testPolicyWindow(): void
    {
        self::assertTrue(Rules::policyAllows([1, 3], [8, 0], [21, 30], 1, 8 * 60, 10 * 60));
        self::assertFalse(Rules::policyAllows([1, 3], [8, 0], [21, 30], 0, 8 * 60, 10 * 60));
        self::assertFalse(Rules::policyAllows([1], [8, 0], [9, 0], 1, 8 * 60, 10 * 60));
        self::assertFalse(Rules::policyAllows(null, [8, 0], [21, 30], 1, 8 * 60, 10 * 60));
        self::assertFalse(Rules::policyAllows([1], null, [21, 30], 1, 8 * 60, 10 * 60));
        self::assertFalse(Rules::validPolicy([1, 1], [8, 0], [9, 0]));
        self::assertFalse(Rules::validPolicy([7], [8, 0], [9, 0]));
        self::assertFalse(Rules::validPolicy([1], [24, 0], [9, 0]));
        self::assertFalse(Rules::validPolicy([1], [8], [9, 0]));
        self::assertFalse(Rules::validPolicy([1], [10, 0], [10, 0]));
        self::assertFalse(Rules::validPolicy([1], [18, 0], [8, 0]));
        self::assertTrue(Rules::validPolicy([0, 6], [8, 0], [21, 30]));
    }

    public function testPurposeMinutesAndFutureRange(): void
    {
        self::assertTrue(Rules::validPurpose(null));
        self::assertTrue(Rules::validPurpose('class'));
        self::assertFalse(Rules::validPurpose('party'));
        self::assertNull(Rules::minutes(null));
        self::assertNull(Rules::minutes([8]));
        self::assertSame(510, Rules::minutes([8, 30]));
        $range = Rules::futureRange(1800000000, 1800003600);
        self::assertNotNull($range);
        self::assertSame('2027-01-15T16:00:00', Clock::api($range[0]));
        self::assertSame('2027-01-15T17:00:00', Clock::api($range[1]));
    }

    public function testEnvelopeOmitsEmptyFields(): void
    {
        $message = json_decode((string) Responder::message(new Response(), 'Saved.')->getBody(), true);
        self::assertSame(['success' => true, 'message' => 'Saved.'], $message);
        $data = json_decode((string) Responder::data(new Response(), null)->getBody(), true);
        self::assertSame(['success' => true, 'data' => null], $data);
        $error = json_decode((string) Responder::error(new Response(), 403, 'You do not manage this room.')->getBody(), true);
        self::assertSame(['success' => false, 'message' => 'You do not manage this room.'], $error);
        $detailed = json_decode((string) Responder::error(new Response(), 422, 'Invalid request body.', ['field' => 'room', 'expected' => 'integer'])->getBody(), true);
        self::assertSame('room', $detailed['error']['field']);
    }

    public function testMailEscapesAndKeepsManageLink(): void
    {
        $html = MailTemplate::html(
            'Your reservation has been created',
            'We received your reservation request. You can review the details below.',
            'A & B',
            'GJ20280001',
            'G1',
            '505',
            'Knowledge City Campus',
            'Club <meeting>',
            'club',
            true,
            '2026-09-17T08:00:00',
            '2026-09-17T09:00:00',
            'https://www.hfiuc.org/reservation/cancel?token=abc',
        );
        self::assertStringContainsString('A &amp; B', $html);
        self::assertStringContainsString('Club &lt;meeting&gt;', $html);
        self::assertStringContainsString('Student Class', $html);
        self::assertStringContainsString('G1', $html);
        self::assertStringContainsString('Manage reservation', $html);
        self::assertStringContainsString('Multimedia equipment required', $html);
        self::assertStringContainsString('https://www.hfiuc.org/reservation/cancel?token=abc', $html);
        self::assertStringContainsString('Copyright © 2025 MAKERs&#39;.', $html);
        self::assertStringContainsString('#F97316', $html);
        self::assertStringContainsString('https://s21.ax1x.com/2025/09/25/pV5T6mt.png', $html);
    }

    public function testXlsxContainsHeader(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ext-zip is not installed');
        }
        $bytes = SimpleXlsx::build(['ID', 'Campus'], [['14', 'Knowledge City']]);
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        self::assertNotFalse($path);
        file_put_contents($path, $bytes);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path) === true);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);
        self::assertIsString($sheet);
        self::assertStringContainsString('Campus', $sheet);
        self::assertStringContainsString('Knowledge City', $sheet);
    }
}
