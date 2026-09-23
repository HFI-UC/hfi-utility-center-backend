<?php

declare(strict_types=1);

namespace Hfiuc\Tests;

use Hfiuc\Reservation\ReservationService;
use Hfiuc\Support\Clock;
use Hfiuc\Support\RoomLock;
use Hfiuc\Support\Token;
use Hfiuc\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ZipArchive;

final class ReservationServiceTest extends DatabaseTestCase
{
    /** @return list<array{0: string, 1: int, 2: string}> */
    public static function rejectedCreates(): array
    {
        return [
            ['email', 400, 'Invalid email format.'],
            ['reason', 400, 'Reservation reason is required.'],
            ['reversed', 400, 'Start time must be before end time.'],
            ['duration', 400, 'Reservation duration must not exceed 2 hours.'],
            ['past', 400, 'Start time must be in the future.'],
            ['far', 400, 'Start time must be within 30 days.'],
            ['purpose', 400, 'Invalid purpose type.'],
            ['student', 400, 'Invalid student ID format.'],
            ['missing-room', 422, 'Invalid request body.'],
            ['unknown-room', 404, 'Room not found.'],
            ['disabled', 400, 'Room not found or disabled.'],
            ['class', 400, 'Class not found.'],
            ['policy', 400, 'Requested time is outside the room\'s bookable hours.'],
            ['midnight', 400, 'Requested time is outside the room\'s bookable hours.'],
            ['csrf-missing', 403, 'CSRF token missing or invalid.'],
            ['csrf-bad', 403, 'CSRF token missing or invalid.'],
        ];
    }

    #[DataProvider('rejectedCreates')]
    public function testCreateRejectsInvalidRequest(string $case, int $status, string $message): void
    {
        $rooms = $this->rooms();
        [$start, $end] = $this->slot(3, 10, 0, 120);
        $body = $this->reservationBody($rooms['roomA'], $start, $end, $rooms['classId']);
        $headers = ['x-csrf-token' => $this->csrf()];
        switch ($case) {
            case 'email':
                $body['email'] = 'not-an-email';
                break;
            case 'reason':
                $body['reason'] = '   ';
                break;
            case 'reversed':
                $body['startTime'] = $end->getTimestamp();
                $body['endTime'] = $start->getTimestamp();
                break;
            case 'duration':
                $body['endTime'] = $start->modify('+121 minutes')->getTimestamp();
                break;
            case 'past':
                $past = Clock::now()->modify('-1 day')->setTime(10, 0, 0);
                $body['startTime'] = $past->getTimestamp();
                $body['endTime'] = $past->modify('+1 hour')->getTimestamp();
                break;
            case 'far':
                $far = Clock::now()->modify('+31 days')->setTime(10, 0, 0);
                $body['startTime'] = $far->getTimestamp();
                $body['endTime'] = $far->modify('+1 hour')->getTimestamp();
                break;
            case 'purpose':
                $body['purposeType'] = 'party';
                break;
            case 'student':
                $body['studentId'] = 'bad';
                break;
            case 'missing-room':
                unset($body['room']);
                break;
            case 'unknown-room':
                $body['room'] = 999999;
                break;
            case 'disabled':
                $body['room'] = $rooms['disabledRoom'];
                break;
            case 'class':
                $body['classId'] = 999999;
                break;
            case 'policy':
                [$late, $lateEnd] = $this->slot(3, 22);
                $body['startTime'] = $late->getTimestamp();
                $body['endTime'] = $lateEnd->getTimestamp();
                break;
            case 'midnight':
                $night = Clock::now()->modify('+3 days')->setTime(23, 0, 0);
                $body['startTime'] = $night->getTimestamp();
                $body['endTime'] = $night->modify('+90 minutes')->getTimestamp();
                break;
            case 'csrf-missing':
                $headers = [];
                break;
            case 'csrf-bad':
                $headers = ['x-csrf-token' => 'nope'];
                break;
            default:
                self::fail('Unknown case ' . $case);
        }

        $this->expectHttp(
            fn () => $this->createReservation($body, $headers),
            $status,
            $message,
        );
    }

    public function testCreatePendingReservationNotifiesOnlyEligibleApprovers(): void
    {
        $rooms = $this->rooms();
        $people = $this->people($rooms['roomA'], $rooms['roomB']);
        [$start, $end] = $this->slot(3, 10, 0, 120);
        $created = $this->createReservation(
            $this->reservationBody($rooms['roomA'], $start, $end, $rooms['classId']),
            ['x-csrf-token' => $this->csrf()],
        );

        $row = $this->db->fetch('SELECT status, purposeType, needsMultimedia, studentId, roomId FROM reservation WHERE id = ?', [$created['reservationId']]);
        self::assertNotNull($row);
        self::assertSame('pending', $row['status']);
        self::assertSame('club', $row['purposeType']);
        self::assertSame(1, (int) $row['needsMultimedia']);
        self::assertSame('GJ20240001', $row['studentId']);
        self::assertSame($rooms['roomA'], (int) $row['roomId']);

        $createdJobs = $this->jobPayloads('reservation_created');
        self::assertCount(1, $createdJobs);
        self::assertSame($created['reservationId'], $createdJobs[0]['reservationId']);
        self::assertSame('pending', $createdJobs[0]['status']);
        self::assertNotSame('', (string) $createdJobs[0]['cancelToken']);
        $token = $this->db->fetch('SELECT tokenHash, expiresAt FROM reservationcanceltoken WHERE reservationId = ?', [$created['reservationId']]);
        self::assertNotNull($token);
        self::assertSame(Token::hash((string) $createdJobs[0]['cancelToken']), $token['tokenHash']);
        self::assertSame(Clock::sql($start), $token['expiresAt']);
        self::assertSame([], $this->jobPayloads('ai_approval'));

        $notified = array_map(static fn (array $payload): int => (int) $payload['adminId'], $this->jobPayloads('admin_reservation_notification'));
        sort($notified);
        $expected = [$people['super'], $people['approverA'], $people['both']];
        sort($expected);
        self::assertSame($expected, $notified);
        self::assertSame('reservation_created', $this->queue->messages[0]['kind']);

        $log = $this->db->fetch('SELECT operation, reason FROM reservationoperationlog WHERE reservationId = ?', [$created['reservationId']]);
        self::assertNotNull($log);
        self::assertSame('created', $log['operation']);
        self::assertSame('pending', $log['reason']);
        $audit = $this->db->fetch('SELECT action, entity, entityId FROM auditlog WHERE action = ?', ['reservation.create']);
        self::assertNotNull($audit);
        self::assertSame('reservation', $audit['entity']);
        self::assertSame((string) $created['reservationId'], $audit['entityId']);
    }

    public function testNullEnabledRoomCanBeBooked(): void
    {
        $rooms = $this->rooms();
        [$start, $end] = $this->slot(3, 11);
        $created = $this->createReservation(
            $this->reservationBody($rooms['openRoom'], $start, $end, $rooms['classId']),
            ['x-csrf-token' => $this->csrf()],
        );
        $row = $this->db->fetch('SELECT status, roomId FROM reservation WHERE id = ?', [$created['reservationId']]);
        self::assertNotNull($row);
        self::assertSame('pending', $row['status']);
        self::assertSame($rooms['openRoom'], (int) $row['roomId']);
        $token = (string) $this->jobPayloads('reservation_created')[0]['cancelToken'];
        [$nextStart, $nextEnd] = $this->slot(3, 12);
        $edited = $this->reservations->modify($this->request(
            'POST',
            '/reservation/modify',
            $this->modifyBody($token, $rooms['openRoom'], $nextStart, $nextEnd, 'Moved a historical room'),
        ));
        self::assertSame(1, $edited['editCount']);
        self::assertSame($rooms['openRoom'], (int) $this->db->fetch('SELECT roomId FROM reservation WHERE id = ?', [$created['reservationId']])['roomId']);
    }

    public function testQueueFailureRollsBackTheReservation(): void
    {
        $rooms = $this->rooms();
        [$start, $end] = $this->slot(3, 10);
        $this->queue->fail = true;
        try {
            $this->createReservation(
                $this->reservationBody($rooms['roomA'], $start, $end, $rooms['classId']),
                ['x-csrf-token' => $this->csrf()],
            );
            self::fail('Expected the queue failure to abort reservation creation.');
        } catch (\RuntimeException $error) {
            self::assertSame('queue down', $error->getMessage());
        }
        self::assertSame(0, $this->countRows('reservation'));
        self::assertSame(0, $this->countRows('outboxjob'));
    }

    public function testConflictsAndDailyLimitIgnoreCancelledButCountRejected(): void
    {
        $rooms = $this->rooms();
        [$firstStart, $firstEnd] = $this->slot(3, 10);
        [$secondStart, $secondEnd] = $this->slot(3, 11);
        $body = $this->reservationBody($rooms['roomA'], $firstStart, $firstEnd, $rooms['classId']);
        $first = $this->createReservation($body, ['x-csrf-token' => $this->csrf()]);
        $overlap = $body;
        $overlap['startTime'] = $firstStart->modify('+30 minutes')->getTimestamp();
        $overlap['endTime'] = $firstEnd->modify('+30 minutes')->getTimestamp();
        $this->expectHttp(
            fn () => $this->createReservation($overlap, ['x-csrf-token' => $this->csrf()]),
            409,
            'Start or end time conflicts with existing reservation.',
        );

        $secondBody = $body;
        $secondBody['startTime'] = $secondStart->getTimestamp();
        $secondBody['endTime'] = $secondEnd->getTimestamp();
        $second = $this->createReservation($secondBody, ['x-csrf-token' => $this->csrf()]);
        $this->db->execute('UPDATE reservation SET status = \'rejected\' WHERE id = ?', [$second['reservationId']]);

        $this->expectHttp(
            fn () => $this->createReservation($secondBody, ['x-csrf-token' => $this->csrf()]),
            400,
            'You have reached your limit on reservation requests on this day.',
        );
        $this->db->execute('UPDATE reservation SET status = \'cancelled\' WHERE id = ?', [$first['reservationId']]);
        $replacement = $this->createReservation($body, ['x-csrf-token' => $this->csrf()]);
        self::assertGreaterThan(0, $replacement['reservationId']);
        self::assertSame(3, $this->countRows('reservation'));
    }

    public function testOfficeTeachersOverridePolicyConflictsAndLimits(): void
    {
        $rooms = $this->rooms();
        $super = $this->insertAdmin('super@example.com', 'Super', true);
        $office = $this->insertAdmin('office@example.com', 'Office', true);
        [$ten, $eleven] = $this->slot(3, 10);
        [$twelve, $thirteen] = $this->slot(3, 12);
        [$fourteen, $fifteen] = $this->slot(3, 14);
        [$sixteen, $seventeen] = $this->slot(3, 16);
        [$night, $nightEnd] = $this->slot(3, 22);
        $normal = $this->reservationBody($rooms['roomA'], $ten, $eleven, $rooms['classId'], [
            'email' => 'office@example.com',
            'studentId' => 'GJ20240009',
        ]);
        $first = $this->createReservation($normal, ['x-csrf-token' => $this->csrf()]);
        $secondBody = $normal;
        $secondBody['startTime'] = $twelve->getTimestamp();
        $secondBody['endTime'] = $thirteen->getTimestamp();
        $this->createReservation($secondBody, ['x-csrf-token' => $this->csrf()]);
        $thirdBody = $normal;
        $thirdBody['startTime'] = $fourteen->getTimestamp();
        $thirdBody['endTime'] = $fifteen->getTimestamp();
        $this->expectHttp(
            fn () => $this->createReservation($thirdBody, ['x-csrf-token' => $this->csrf()]),
            400,
            'You have reached your limit on reservation requests on this day.',
        );

        $notificationsBefore = array_map(
            static fn (array $payload): int => (int) $payload['adminId'],
            $this->jobPayloads('admin_reservation_notification'),
        );
        sort($notificationsBefore);
        self::assertSame([$super, $super, $office, $office], $notificationsBefore);

        $priority = $thirdBody;
        $priority['classId'] = $rooms['officeClassId'];
        $priority['studentId'] = 'not-a-student';
        $priorityId = $this->createReservation($priority, ['x-csrf-token' => $this->csrf()])['reservationId'];
        $priorityRow = $this->db->fetch('SELECT status, studentId, latestExecutorId FROM reservation WHERE id = ?', [$priorityId]);
        self::assertNotNull($priorityRow);
        self::assertSame('approved', $priorityRow['status']);
        self::assertSame('-', $priorityRow['studentId']);
        self::assertSame($office, (int) $priorityRow['latestExecutorId']);
        self::assertCount(4, $this->jobPayloads('admin_reservation_notification'));
        self::assertSame('approved', $this->jobPayloads('reservation_status_changed')[0]['status']);

        $override = $normal;
        $override['classId'] = $rooms['officeClassId'];
        $override['studentId'] = 'GJ20240009';
        $this->createReservation($override, ['x-csrf-token' => $this->csrf()]);
        $displaced = $this->db->fetch('SELECT status, latestExecutorId FROM reservation WHERE id = ?', [$first['reservationId']]);
        self::assertNotNull($displaced);
        self::assertSame('cancelled', $displaced['status']);
        self::assertSame($office, (int) $displaced['latestExecutorId']);
        $priorityLog = $this->db->fetch(
            'SELECT operation, reason FROM reservationoperationlog WHERE reservationId = ? AND operation = \'cancelled_by_priority\'',
            [$first['reservationId']],
        );
        self::assertNotNull($priorityLog);
        self::assertSame('Cancelled because an Office Teachers priority reservation occupies this time', $priorityLog['reason']);
        self::assertSame('higher_priority', $this->jobPayloads('reservation_cancelled')[0]['reason']);
        self::assertCount(4, $this->jobPayloads('admin_reservation_notification'));

        $studentNight = $this->reservationBody($rooms['roomA'], $night, $nightEnd, $rooms['classId'], [
            'email' => 'student@example.com',
        ]);
        $this->expectHttp(
            fn () => $this->createReservation($studentNight, ['x-csrf-token' => $this->csrf()]),
            400,
            'Requested time is outside the room\'s bookable hours.',
        );
        $officeNight = $studentNight;
        $officeNight['email'] = 'office@example.com';
        $officeNight['classId'] = $rooms['officeClassId'];
        $nightId = $this->createReservation($officeNight, ['x-csrf-token' => $this->csrf()])['reservationId'];
        self::assertSame('approved', $this->db->fetch('SELECT status FROM reservation WHERE id = ?', [$nightId])['status']);

        $stranger = $this->reservationBody($rooms['roomA'], $sixteen, $seventeen, $rooms['officeClassId'], [
            'email' => 'stranger@example.com',
        ]);
        $this->expectHttp(
            fn () => $this->createReservation($stranger, ['x-csrf-token' => $this->csrf()]),
            403,
            'Office Teachers reservations require an administrator email address.',
        );
        $disabled = $priority;
        $disabled['room'] = $rooms['disabledRoom'];
        $disabled['startTime'] = $sixteen->getTimestamp();
        $disabled['endTime'] = $seventeen->getTimestamp();
        $this->expectHttp(
            fn () => $this->createReservation($disabled, ['x-csrf-token' => $this->csrf()]),
            400,
            'Room not found or disabled.',
        );
    }

    public function testAiApprovalIsQueuedWhenEnabled(): void
    {
        $rooms = $this->rooms();
        [$start, $end] = $this->slot(3, 10);
        $service = new ReservationService($this->db, $this->auth, $this->makeConfig(true, 'https://ai.example.test'), $this->logger, $this->outbox);
        $created = $service->create($this->request(
            'POST',
            '/reservation/create',
            $this->reservationBody($rooms['roomA'], $start, $end, $rooms['classId']),
            [],
            ['x-csrf-token' => $this->csrf()],
        ));
        $kinds = array_column($this->queue->messages, 'kind');
        self::assertContains('reservation_created', $kinds);
        self::assertContains('ai_approval', $kinds);
        self::assertSame($created['reservationId'], $this->jobPayloads('ai_approval')[0]['reservationId']);
    }

    public function testEveryManagerStillSeesAnApprovedReservation(): void
    {
        $rooms = $this->rooms();
        $people = $this->people($rooms['roomA'], $rooms['roomB']);
        [$start, $end] = $this->slot(4, 10);
        [$otherStart, $otherEnd] = $this->slot(4, 12);
        $created = $this->createReservation(
            $this->reservationBody($rooms['roomA'], $start, $end, $rooms['classId']),
            ['x-csrf-token' => $this->csrf()],
        );
        $id = $created['reservationId'];
        $this->expectHttp(
            fn () => $this->reservations->approve($this->asAdmin('room-b@example.com', ['id' => $id, 'approved' => true])),
            403,
            'You do not manage this room.',
        );
        $second = $this->createReservation(
            $this->reservationBody($rooms['roomA'], $otherStart, $otherEnd, $rooms['classId'], ['email' => 'second@example.com', 'studentId' => 'GJ20240002']),
            ['x-csrf-token' => $this->csrf()],
        );
        $this->expectHttp(
            fn () => $this->reservations->approve($this->asAdmin('room-a@example.com', ['id' => $second['reservationId'], 'approved' => false, 'reason' => ' '])),
            400,
            'Reason is required for rejection.',
        );
        $this->reservations->approve($this->asAdmin('room-a@example.com', [
            'id' => $second['reservationId'],
            'approved' => false,
            'reason' => 'Room is reserved for exams.',
        ]));
        self::assertSame('rejected', $this->db->fetch('SELECT status FROM reservation WHERE id = ?', [$second['reservationId']])['status']);

        $this->reservations->approve($this->asAdmin('quiet-a@example.com', ['id' => $id, 'approved' => true, 'reason' => 'Approved.']));
        self::assertSame('approved', $this->db->fetch('SELECT status, latestExecutorId FROM reservation WHERE id = ?', [$id])['status']);
        self::assertSame($people['quietA'], (int) $this->db->fetch('SELECT latestExecutorId FROM reservation WHERE id = ?', [$id])['latestExecutorId']);
        $this->expectHttp(
            fn () => $this->reservations->approve($this->asAdmin('room-a@example.com', ['id' => $id, 'approved' => true])),
            409,
            'Reservation has already been processed or is no longer editable.',
        );

        foreach (['room-a@example.com', 'quiet-a@example.com', 'both@example.com', 'super@example.com'] as $email) {
            $visible = $this->ids($this->reservations->list($this->asAdminRead($email))['reservations']);
            self::assertContains($id, $visible);
            self::assertContains($second['reservationId'], $visible);
            $future = $this->ids($this->reservations->future($this->asAdminRead($email)));
            self::assertContains($id, $future);
        }
        $hidden = $this->ids($this->reservations->list($this->asAdminRead('room-b@example.com'))['reservations']);
        self::assertNotContains($id, $hidden);

        $public = $this->reservations->list($this->request('GET', '/reservation/list'));
        $publicRow = $this->byId($public['reservations'])[$id];
        self::assertNull($publicRow['email']);
        self::assertNull($publicRow['studentId']);
        self::assertSame('approved', $publicRow['status']);
        $adminRow = $this->byId($this->reservations->list($this->asAdminRead('super@example.com'))['reservations'])[$id];
        self::assertSame('student@example.com', $adminRow['email']);
        self::assertSame('GJ20240001', $adminRow['studentId']);

        $pastId = $this->insertReservation(
            $rooms['roomA'],
            Clock::now()->modify('-1 day')->setTime(10, 0, 0),
            Clock::now()->modify('-1 day')->setTime(11, 0, 0),
            'past@example.com',
            'pending',
            $rooms['classId'],
        );
        $this->expectHttp(
            fn () => $this->reservations->approve($this->asAdmin('super@example.com', ['id' => $pastId, 'approved' => true])),
            409,
            'Reservation has already been processed or is no longer editable.',
        );
        $this->expectHttp(
            fn () => $this->reservations->approve($this->asAdmin('super@example.com', ['id' => 999999, 'approved' => true])),
            404,
            'Reservation not found.',
        );
    }

    public function testMovingARoomRequiresRightsOnBothRooms(): void
    {
        $rooms = $this->rooms();
        $this->people($rooms['roomA'], $rooms['roomB']);
        [$start, $end] = $this->slot(5, 10);
        $id = $this->createReservation(
            $this->reservationBody($rooms['roomA'], $start, $end, $rooms['classId']),
            ['x-csrf-token' => $this->csrf()],
        )['reservationId'];
        $this->reservations->approve($this->asAdmin('room-a@example.com', ['id' => $id, 'approved' => true]));

        $outside = $this->editBody($id, $rooms['roomA'], ...$this->slot(5, 22));
        $this->expectHttp(
            fn () => $this->reservations->adminEdit($this->asAdmin('room-a@example.com', $outside)),
            400,
            'Requested time is outside the room\'s bookable hours.',
        );
        self::assertSame('approved', $this->db->fetch('SELECT status FROM reservation WHERE id = ?', [$id])['status']);

        $sameRoom = $this->editBody($id, $rooms['roomA'], $start, $end, 'Exam review');
        $this->reservations->adminEdit($this->asAdmin('room-a@example.com', $sameRoom));
        self::assertSame('Exam review', $this->db->fetch('SELECT reason, status, roomId FROM reservation WHERE id = ?', [$id])['reason']);
        self::assertSame('approved', $this->db->fetch('SELECT status FROM reservation WHERE id = ?', [$id])['status']);
        self::assertSame($rooms['roomA'], (int) $this->db->fetch('SELECT roomId FROM reservation WHERE id = ?', [$id])['roomId']);

        $this->expectHttp(
            fn () => $this->reservations->adminEdit($this->asAdmin('room-a@example.com', $this->editBody($id, $rooms['roomB'], $start, $end))),
            403,
            'You do not manage this room.',
        );
        $blocker = $this->insertReservation($rooms['roomB'], $start, $end, 'blocker@example.com', 'approved', $rooms['classId']);
        $this->expectHttp(
            fn () => $this->reservations->adminEdit($this->asAdmin('both@example.com', $this->editBody($id, $rooms['roomB'], $start, $end))),
            409,
            'The edited time conflicts with another reservation.',
        );
        $this->db->execute('UPDATE reservation SET status = \'cancelled\' WHERE id = ?', [$blocker]);
        $this->reservations->adminEdit($this->asAdmin('both@example.com', $this->editBody($id, $rooms['roomB'], $start, $end, 'Moved by both')));
        self::assertSame($rooms['roomB'], (int) $this->db->fetch('SELECT roomId FROM reservation WHERE id = ?', [$id])['roomId']);
        self::assertSame(Clock::sql($start), $this->db->fetch('SELECT expiresAt FROM reservationcanceltoken WHERE reservationId = ? AND usedAt IS NULL ORDER BY id DESC', [$id])['expiresAt']);
        self::assertNotNull($this->db->fetch('SELECT id FROM reservationoperationlog WHERE reservationId = ? AND operation = \'modified_by_admin\'', [$id]));

        $this->reservations->adminEdit($this->asAdmin('super@example.com', $this->editBody($id, $rooms['roomA'], $start, $end, 'Moved back')));
        self::assertSame($rooms['roomA'], (int) $this->db->fetch('SELECT roomId FROM reservation WHERE id = ?', [$id])['roomId']);

        [$pendingStart, $pendingEnd] = $this->slot(5, 15);
        $pending = $this->createReservation(
            $this->reservationBody($rooms['roomA'], $pendingStart, $pendingEnd, $rooms['classId'], ['email' => 'other@example.com', 'studentId' => 'GJ20240008']),
            ['x-csrf-token' => $this->csrf()],
        )['reservationId'];
        $pendingTimes = $this->slot(5, 15);
        $this->expectHttp(
            fn () => $this->reservations->adminEdit($this->asAdmin('super@example.com', $this->editBody($pending, $rooms['roomA'], $pendingTimes[0], $pendingTimes[1]))),
            409,
            'Only approved, unexpired reservations can be edited by an admin.',
        );
        $expired = $this->insertReservation(
            $rooms['roomA'],
            Clock::now()->modify('-2 hours'),
            Clock::now()->modify('-1 hour'),
            'expired@example.com',
            'approved',
            $rooms['classId'],
        );
        $this->expectHttp(
            fn () => $this->reservations->adminEdit($this->asAdmin('super@example.com', $this->editBody($expired, $rooms['roomA'], $start, $end))),
            409,
            'Only approved, unexpired reservations can be edited by an admin.',
        );
        $this->expectHttp(
            fn () => $this->reservations->adminEdit($this->asAdmin('super@example.com', $this->editBody($id, $rooms['disabledRoom'], $start, $end))),
            400,
            'Room not found or disabled.',
        );
    }

    public function testRequesterCanModifyTwiceAndApprovalReturnsToPending(): void
    {
        $rooms = $this->rooms();
        $this->people($rooms['roomA'], $rooms['roomB']);
        [$start, $end] = $this->slot(6, 10);
        $created = $this->createReservation(
            $this->reservationBody($rooms['roomA'], $start, $end, $rooms['classId']),
            ['x-csrf-token' => $this->csrf()],
        );
        $token = (string) $this->jobPayloads('reservation_created')[0]['cancelToken'];
        [$secondStart, $secondEnd] = $this->slot(6, 12);
        $firstEdit = $this->reservations->modify($this->request('POST', '/reservation/modify', $this->modifyBody($token, $rooms['roomA'], $secondStart, $secondEnd, 'First edit')));
        self::assertSame(1, $firstEdit['editCount']);
        self::assertSame(1, $firstEdit['remainingEdits']);
        self::assertSame('pending', $this->db->fetch('SELECT status, reason FROM reservation WHERE id = ?', [$created['reservationId']])['status']);
        self::assertSame(Clock::sql($secondStart), $this->db->fetch('SELECT expiresAt FROM reservationcanceltoken WHERE reservationId = ?', [$created['reservationId']])['expiresAt']);

        [$thirdStart, $thirdEnd] = $this->slot(6, 14);
        $secondEdit = $this->reservations->modify($this->request('POST', '/reservation/modify', $this->modifyBody($token, $rooms['roomA'], $thirdStart, $thirdEnd, 'Second edit')));
        self::assertSame(2, $secondEdit['editCount']);
        self::assertSame(0, $secondEdit['remainingEdits']);
        $this->expectHttp(
            fn () => $this->reservations->modify($this->request('POST', '/reservation/modify', $this->modifyBody($token, $rooms['roomA'], $start, $end, 'Third edit'))),
            409,
            'This reservation has already been modified twice.',
        );

        [$freshStart, $freshEnd] = $this->slot(6, 16);
        $other = $this->createReservation(
            $this->reservationBody($rooms['roomA'], $freshStart, $freshEnd, $rooms['classId'], ['email' => 'other@example.com', 'studentId' => 'GJ20240003']),
            ['x-csrf-token' => $this->csrf()],
        );
        $otherToken = (string) $this->jobPayloads('reservation_created')[1]['cancelToken'];
        $this->expectHttp(
            fn () => $this->reservations->modify($this->request('POST', '/reservation/modify', $this->modifyBody($otherToken, $rooms['roomA'], $thirdStart, $thirdEnd, 'Clash'))),
            409,
            'The edited time conflicts with another reservation.',
        );
        [$lateStart, $lateEnd] = $this->slot(6, 22);
        $this->expectHttp(
            fn () => $this->reservations->modify($this->request('POST', '/reservation/modify', $this->modifyBody($otherToken, $rooms['roomA'], $lateStart, $lateEnd, 'Late'))),
            400,
            'Requested time is outside the room\'s bookable hours.',
        );
        $this->expectHttp(
            fn () => $this->reservations->modify($this->request('POST', '/reservation/modify', $this->modifyBody('missing-token', $rooms['roomA'], $freshStart, $freshEnd, 'Nope'))),
            404,
            'Reservation management link is invalid.',
        );
        $this->db->execute('UPDATE reservationcanceltoken SET expiresAt = ? WHERE reservationId = ?', [Clock::sql(Clock::now()->modify('-1 minute')), $other['reservationId']]);
        $this->expectHttp(
            fn () => $this->reservations->modify($this->request('POST', '/reservation/modify', $this->modifyBody($otherToken, $rooms['roomA'], $freshStart, $freshEnd, 'Expired'))),
            410,
            'Reservation management link is expired.',
        );

        [$approvedStart, $approvedEnd] = $this->slot(6, 18);
        $approved = $this->createReservation(
            $this->reservationBody($rooms['roomA'], $approvedStart, $approvedEnd, $rooms['classId'], ['email' => 'approved@example.com', 'studentId' => 'GJ20240004']),
            ['x-csrf-token' => $this->csrf()],
        );
        $this->reservations->approve($this->asAdmin('super@example.com', ['id' => $approved['reservationId'], 'approved' => true]));
        $approvedToken = (string) $this->jobPayloads('reservation_created')[2]['cancelToken'];
        [$movedStart, $movedEnd] = $this->slot(6, 19);
        $this->reservations->modify($this->request('POST', '/reservation/modify', $this->modifyBody($approvedToken, $rooms['roomA'], $movedStart, $movedEnd, 'Student changed an approved reservation')));
        self::assertSame('pending', $this->db->fetch('SELECT status FROM reservation WHERE id = ?', [$approved['reservationId']])['status']);
    }

    public function testOfficeTeacherEditsKeepTheOverride(): void
    {
        $rooms = $this->rooms();
        $this->people($rooms['roomA'], $rooms['roomB']);
        [$start, $end] = $this->slot(4, 10);
        $occupied = $this->createReservation(
            $this->reservationBody($rooms['roomA'], $start, $end, $rooms['classId']),
            ['x-csrf-token' => $this->csrf()],
        )['reservationId'];
        [$night, $nightEnd] = $this->slot(4, 22);
        $created = $this->createReservation(
            $this->reservationBody($rooms['roomA'], $night, $nightEnd, $rooms['officeClassId'], [
                'email' => 'office@example.com',
                'studentId' => 'not-a-student',
            ]),
            ['x-csrf-token' => $this->csrf()],
        );
        $id = $created['reservationId'];
        self::assertSame('approved', $this->db->fetch('SELECT status FROM reservation WHERE id = ?', [$id])['status']);
        $token = (string) $this->jobPayloads('reservation_status_changed')[0]['cancelToken'];
        [$later, $laterEnd] = $this->slot(4, 22, 30);
        $edited = $this->reservations->modify($this->request(
            'POST',
            '/reservation/modify',
            $this->modifyBody($token, $rooms['roomA'], $later, $laterEnd, 'Office moved outside policy'),
        ));
        self::assertSame(1, $edited['editCount']);
        self::assertSame('approved', $this->db->fetch('SELECT status FROM reservation WHERE id = ?', [$id])['status']);
        self::assertSame('Office moved outside policy', $this->db->fetch('SELECT reason FROM reservation WHERE id = ?', [$id])['reason']);
        self::assertSame([], $this->jobPayloads('ai_approval'));

        $this->reservations->adminEdit($this->asAdmin('super@example.com', $this->editBody($id, $rooms['roomA'], $start, $end, 'Office takes the occupied slot')));
        self::assertSame('approved', $this->db->fetch('SELECT status FROM reservation WHERE id = ?', [$id])['status']);
        self::assertSame(Clock::sql($start), $this->db->fetch('SELECT startTime FROM reservation WHERE id = ?', [$id])['startTime']);
        self::assertSame('cancelled', $this->db->fetch('SELECT status FROM reservation WHERE id = ?', [$occupied])['status']);
    }

    public function testMovingLocksBothRoomsUntilTheTransactionEnds(): void
    {
        $rooms = $this->rooms();
        $lock = new RoomLock($this->db);
        try {
            $this->db->transaction(function () use ($lock, $rooms): void {
                $lock->acquire($rooms['roomB']);
                $lock->acquire($rooms['roomA']);
                $owners = [];
                foreach ([$rooms['roomA'], $rooms['roomB']] as $roomId) {
                    $owner = $this->db->fetch('SELECT IS_USED_LOCK(?) AS owner', ['hfiuc_room_' . $roomId]);
                    self::assertNotNull($owner);
                    self::assertNotNull($owner['owner']);
                    $owners[] = (string) $owner['owner'];
                }
                self::assertNotSame($owners[0], $owners[1]);
            });
            foreach ([$rooms['roomA'], $rooms['roomB']] as $roomId) {
                $stillHeld = $this->db->fetch('SELECT IS_USED_LOCK(?) AS owner', ['hfiuc_room_' . $roomId]);
                self::assertNotNull($stillHeld);
                self::assertNotNull($stillHeld['owner']);
            }
        } finally {
            $lock->release();
        }
        $released = $this->db->fetch('SELECT IS_USED_LOCK(?) AS owner', ['hfiuc_room_' . $rooms['roomA']]);
        self::assertTrue($released === null || $released['owner'] === null);
    }

    public function testCancelByToken(): void
    {
        $rooms = $this->rooms();
        $this->people($rooms['roomA'], $rooms['roomB']);
        [$start, $end] = $this->slot(3, 10);
        $created = $this->createReservation(
            $this->reservationBody($rooms['roomA'], $start, $end, $rooms['classId']),
            ['x-csrf-token' => $this->csrf()],
        );
        $token = (string) $this->jobPayloads('reservation_created')[0]['cancelToken'];
        $preview = $this->reservations->cancelPreview($this->request('GET', '/reservation/cancel-preview', [], ['token' => $token]));
        self::assertSame($created['reservationId'], $preview['reservationId']);
        self::assertSame('505', $preview['roomName']);
        self::assertSame(2, $preview['remainingEdits']);
        self::assertTrue($preview['needsMultimedia']);

        self::assertSame(
            'Reservation cancelled successfully.',
            $this->reservations->cancel($this->request('POST', '/reservation/cancel', ['token' => $token])),
        );
        self::assertSame('cancelled', $this->db->fetch('SELECT status FROM reservation WHERE id = ?', [$created['reservationId']])['status']);
        self::assertNotNull($this->db->fetch('SELECT usedAt FROM reservationcanceltoken WHERE reservationId = ?', [$created['reservationId']])['usedAt']);
        self::assertSame(
            'Reservation is already cancelled.',
            $this->reservations->cancel($this->request('POST', '/reservation/cancel', ['token' => $token])),
        );

        [$rejectStart, $rejectEnd] = $this->slot(3, 13);
        $rejected = $this->createReservation(
            $this->reservationBody($rooms['roomA'], $rejectStart, $rejectEnd, $rooms['classId'], ['email' => 'reject@example.com', 'studentId' => 'GJ20240005']),
            ['x-csrf-token' => $this->csrf()],
        );
        $rejectToken = (string) $this->jobPayloads('reservation_created')[1]['cancelToken'];
        $this->reservations->approve($this->asAdmin('super@example.com', [
            'id' => $rejected['reservationId'],
            'approved' => false,
            'reason' => 'Not this week.',
        ]));
        $this->expectHttp(
            fn () => $this->reservations->cancel($this->request('POST', '/reservation/cancel', ['token' => $rejectToken])),
            409,
            'Rejected reservations cannot be cancelled.',
        );

        $pastStart = Clock::now()->modify('-1 day')->setTime(10, 0, 0);
        $pastId = $this->insertReservation($rooms['roomA'], $pastStart, $pastStart->modify('+1 hour'), 'past@example.com', 'approved', $rooms['classId']);
        $this->insertToken($pastId, 'past-token', Clock::now()->modify('+1 day'));
        $this->expectHttp(
            fn () => $this->reservations->cancel($this->request('POST', '/reservation/cancel', ['token' => 'past-token'])),
            409,
            'Past reservations cannot be cancelled.',
        );

        [$futureStart, $futureEnd] = $this->slot(3, 18);
        $futureId = $this->insertReservation($rooms['roomA'], $futureStart, $futureEnd, 'future@example.com', 'pending', $rooms['classId']);
        $this->insertToken($futureId, 'expired-token', Clock::now()->modify('-5 minutes'));
        $this->expectHttp(
            fn () => $this->reservations->cancelPreview($this->request('GET', '/reservation/cancel-preview', [], ['token' => 'expired-token'])),
            410,
            'Cancellation link is expired.',
        );
        $this->expectHttp(
            fn () => $this->reservations->cancel($this->request('POST', '/reservation/cancel', ['token' => 'expired-token'])),
            410,
            'Cancellation link is expired.',
        );
        $this->expectHttp(
            fn () => $this->reservations->cancel($this->request('POST', '/reservation/cancel', ['token' => 'missing'])),
            404,
            'Cancellation link is invalid or expired.',
        );
    }

    public function testAvailabilityListsAndExportsFollowRoomScope(): void
    {
        $rooms = $this->rooms();
        $this->people($rooms['roomA'], $rooms['roomB']);
        [$start, $end] = $this->slot(4, 10);
        $visible = $this->createReservation(
            $this->reservationBody($rooms['roomA'], $start, $end, $rooms['classId'], ['studentName' => 'Li Lei', 'reason' => 'UniqueKeyword42']),
            ['x-csrf-token' => $this->csrf()],
        )['reservationId'];
        [$hiddenStart, $hiddenEnd] = $this->slot(5, 10);
        $hidden = $this->insertReservation($rooms['roomB'], $hiddenStart, $hiddenEnd, 'other@example.com', 'pending', $rooms['classId'], 'GJ20240002', 'Han Meimei', 'Other room');
        $this->insertReservation($rooms['roomA'], $start->modify('+2 hours'), $end->modify('+2 hours'), 'cancelled@example.com', 'cancelled', $rooms['classId']);
        $this->insertReservation($rooms['roomA'], $start->modify('+4 hours'), $end->modify('+4 hours'), 'rejected@example.com', 'rejected', $rooms['classId']);

        $availability = $this->reservations->availability($this->request('GET', '/reservation/availability', [], [
            'roomId' => (string) $rooms['roomA'],
            'date' => $start->format('Y-m-d'),
        ]));
        self::assertSame($rooms['roomA'], $availability['roomId']);
        self::assertCount(1, $availability['occupied']);
        self::assertSame('pending', $availability['occupied'][0]['status']);
        self::assertSame(Clock::api($start), $availability['occupied'][0]['startTime']);
        $this->expectHttp(
            fn () => $this->reservations->availability($this->request('GET', '/reservation/availability', [], [
                'roomId' => (string) $rooms['roomA'],
                'date' => '2026-02-31',
            ])),
            400,
            'Invalid date.',
        );

        $scoped = $this->reservations->list($this->asAdminRead('room-a@example.com'));
        self::assertSame(3, $scoped['total']);
        self::assertNotContains($hidden, $this->ids($scoped['reservations']));
        $filtered = $this->reservations->list($this->request('GET', '/reservation/list', [], [
            'keyword' => 'UniqueKeyword42',
            'status' => 'pending',
            'campusId' => (string) $rooms['campusId'],
            'roomId' => (string) $rooms['roomA'],
            'purposeType' => 'club',
            'needsMultimedia' => 'true',
        ]));
        self::assertSame([$visible], $this->ids($filtered['reservations']));
        $sorted = $this->reservations->list($this->asAdminRead('super@example.com', ['sort' => 'time']));
        self::assertSame($visible, $sorted['reservations'][0]['id']);

        for ($index = 0; $index < 20; $index++) {
            [$pageStart, $pageEnd] = $this->slot(8, 10);
            $this->insertReservation($rooms['roomB'], $pageStart, $pageEnd, 'page-' . $index . '@example.com', 'pending', $rooms['classId'], 'GJ202411' . str_pad((string) $index, 2, '0', STR_PAD_LEFT));
        }
        $page = $this->reservations->list($this->asAdminRead('super@example.com', ['page' => '1']));
        self::assertSame(24, $page['total']);
        self::assertCount(4, $page['reservations']);

        $this->expectHttp(
            fn () => $this->reservations->future($this->request('GET', '/reservation/future')),
            401,
            'User is not logged in.',
        );
        $future = $this->ids($this->reservations->future($this->asAdminRead('room-a@example.com')));
        self::assertContains($visible, $future);
        self::assertNotContains($hidden, $future);

        $this->expectHttp(
            fn () => $this->reservations->export($this->request('GET', '/reservation/export')),
            401,
            'User is not logged in.',
        );
        $this->expectHttp(
            fn () => $this->reservations->export($this->asAdminRead('room-b@example.com', [
                'startTime' => (string) Clock::now()->modify('+20 days')->getTimestamp(),
                'endTime' => (string) Clock::now()->modify('+21 days')->getTimestamp(),
            ])),
            404,
            'No reservations found.',
        );
        $this->expectHttp(
            fn () => $this->reservations->export($this->asAdminRead('super@example.com', [
                'startTime' => (string) Clock::now()->modify('+10 days')->getTimestamp(),
                'endTime' => (string) Clock::now()->modify('+9 days')->getTimestamp(),
            ])),
            400,
            'Invalid time range.',
        );
        $scopedExport = $this->reservations->export($this->asAdminRead('room-a@example.com', ['mode' => 'bad mode']));
        self::assertSame('by-room', $scopedExport['mode']);
        $scopedSheet = $this->sheetText($scopedExport['bytes']);
        self::assertStringContainsString('Li Lei', $scopedSheet);
        self::assertStringNotContainsString('Han Meimei', $scopedSheet);
        $superSheet = $this->sheetText($this->reservations->export($this->asAdminRead('super@example.com'))['bytes']);
        self::assertStringContainsString('Han Meimei', $superSheet);
    }

    /** @return array{campusId: int, officeCampusId: int, classId: int, officeClassId: int, roomA: int, roomB: int, disabledRoom: int, openRoom: int} */
    private function rooms(): array
    {
        $campusId = $this->insertCampus('Knowledge City');
        $officeCampusId = $this->insertCampus('Office Teachers', true);
        $classId = $this->insertClass('A1', $campusId);
        $officeClassId = $this->insertClass('Office', $officeCampusId);
        $roomA = $this->insertRoom('505', $campusId, 1);
        $roomB = $this->insertRoom('506', $campusId, 1);
        $disabledRoom = $this->insertRoom('507', $campusId, 0);
        $openRoom = $this->insertRoom('508', $campusId, null);
        foreach ([$roomA, $roomB, $disabledRoom, $openRoom] as $roomId) {
            $this->insertPolicy($roomId);
        }

        return compact('campusId', 'officeCampusId', 'classId', 'officeClassId', 'roomA', 'roomB', 'disabledRoom', 'openRoom');
    }

    /** @return array{super: int, quietSuper: int, approverA: int, quietA: int, approverB: int, both: int, office: int} */
    private function people(int $roomA, int $roomB): array
    {
        $super = $this->insertAdmin('super@example.com', 'Super', true);
        $quietSuper = $this->insertAdmin('quiet-super@example.com', 'Quiet Super', false);
        $approverA = $this->insertAdmin('room-a@example.com', 'Room A', true);
        $quietA = $this->insertAdmin('quiet-a@example.com', 'Quiet A', false);
        $approverB = $this->insertAdmin('room-b@example.com', 'Room B', true);
        $both = $this->insertAdmin('both@example.com', 'Both', true);
        $office = $this->insertAdmin('office@example.com', 'Office', false);
        $this->assignRoom($roomA, $approverA);
        $this->assignRoom($roomA, $quietA);
        $this->assignRoom($roomA, $both);
        $this->assignRoom($roomB, $approverB);
        $this->assignRoom($roomB, $both);
        $this->assignRoom($roomB, $office);

        return compact('super', 'quietSuper', 'approverA', 'quietA', 'approverB', 'both', 'office');
    }

    /** @return array<string, mixed> */
    private function reservationBody(int $roomId, \DateTimeImmutable $start, \DateTimeImmutable $end, ?int $classId, array $overrides = []): array
    {
        return array_merge([
            'room' => $roomId,
            'startTime' => $start->getTimestamp(),
            'endTime' => $end->getTimestamp(),
            'studentName' => 'Li Lei',
            'email' => 'student@example.com',
            'reason' => 'Club meeting',
            'classId' => $classId,
            'studentId' => 'GJ20240001',
            'purposeType' => 'club',
            'needsMultimedia' => true,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function editBody(int $id, int $roomId, \DateTimeImmutable $start, \DateTimeImmutable $end, string $reason = 'Updated by admin'): array
    {
        return [
            'id' => $id,
            'room' => $roomId,
            'reason' => $reason,
            'purposeType' => 'class',
            'needsMultimedia' => false,
            'startTime' => $start->getTimestamp(),
            'endTime' => $end->getTimestamp(),
        ];
    }

    /** @return array<string, mixed> */
    private function modifyBody(string $token, int $roomId, \DateTimeImmutable $start, \DateTimeImmutable $end, string $reason): array
    {
        return [
            'token' => $token,
            'room' => $roomId,
            'reason' => $reason,
            'purposeType' => 'class',
            'needsMultimedia' => false,
            'startTime' => $start->getTimestamp(),
            'endTime' => $end->getTimestamp(),
        ];
    }

    /** @param list<array<string, mixed>> $rows
     * @return list<int>
     */
    private function ids(array $rows): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /** @param list<array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function byId(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int) $row['id']] = $row;
        }

        return $indexed;
    }

    private function countRows(string $table): int
    {
        return (int) $this->db->fetch('SELECT COUNT(*) AS total FROM ' . $table)['total'];
    }

    private function sheetText(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        self::assertNotFalse($path);
        file_put_contents($path, $bytes);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path) === true);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);
        self::assertIsString($sheet);

        return $sheet;
    }
}
