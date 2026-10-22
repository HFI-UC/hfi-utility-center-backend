<?php

declare(strict_types=1);

namespace Hfiuc\Tests;

use Hfiuc\Worker\MailTemplate;
use PHPUnit\Framework\TestCase;

final class MailTemplateTest extends TestCase
{
    public function testCancellationReasonIsSeparateFromBookingReasonAndEscaped(): void
    {
        $html = MailTemplate::html(
            'Your reservation was cancelled by an administrator',
            'See the cancellation reason below.',
            'Student',
            'A1',
            '505',
            'Campus',
            'Original study group',
            'club',
            false,
            '2026-12-01T10:00:00',
            '2026-12-01T11:00:00',
            null,
            '教师会议 <script>alert("x")</script> & 调整',
            'Cancellation Reason',
        );
        self::assertStringContainsString('Cancellation Reason', $html);
        self::assertStringContainsString('Original study group', $html);
        self::assertStringContainsString('教师会议 &lt;script&gt;', $html);
        self::assertStringContainsString('&amp; 调整', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('Manage reservation', $html);
    }
}
