<?php

declare(strict_types=1);

namespace Hfiuc\Reservation;

use Hfiuc\Auth\AuthService;
use Hfiuc\Config;
use Hfiuc\Database;
use Hfiuc\Http\HttpException;
use Hfiuc\Http\Input;
use Hfiuc\Http\QueryInput;
use Hfiuc\Log\Logger;
use Hfiuc\Support\Clock;
use Hfiuc\Support\Token;
use Hfiuc\Worker\Outbox;
use Hfiuc\Xlsx\SimpleXlsx;
use Psr\Http\Message\ServerRequestInterface;

final class ReservationService
{
    private const SELECT_ROW = 'r.id, r.roomId, r.startTime, r.endTime, r.studentName, r.email, r.reason, r.status, r.createdAt, rm.name AS room_name, c.name AS class_name, cp.name AS campus_name, r.purposeType, r.needsMultimedia, r.editCount';

    private const FROM_ROW = ' FROM reservation r LEFT JOIN room rm ON rm.id = r.roomId LEFT JOIN class c ON c.id = r.classId LEFT JOIN campus cp ON cp.id = rm.campusId ';

    private readonly ReservationAuthorization $authorization;

    private readonly ReservationPolicy $policy;

    public function __construct(
        private readonly Database $db,
        private readonly AuthService $auth,
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly Outbox $outbox,
    ) {
        $this->authorization = new ReservationAuthorization($db);
        $this->policy = new ReservationPolicy($db);
    }

    /** @return array{roomId: int, date: string, occupied: list<array<string, mixed>>} */
    public function availability(ServerRequestInterface $request): array
    {
        $query = new QueryInput($request->getQueryParams());
        $roomId = $query->requireInt('roomId');
        $date = $query->requireString('date');
        $room = $this->db->fetch('SELECT rm.id FROM room rm LEFT JOIN campus cp ON cp.id = rm.campusId WHERE rm.id = ? AND rm.deletedAt IS NULL AND (rm.campusId IS NULL OR cp.deletedAt IS NULL)', [$roomId]);
        if ($room === null) {
            throw new HttpException(404, 'Room not found.', ['field' => 'roomId']);
        }
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, Clock::zone());
        $errors = \DateTimeImmutable::getLastErrors();
        if ($day === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new HttpException(400, 'Invalid date.');
        }
        $start = Clock::sql($day->setTime(0, 0, 0));
        $end = Clock::sql($day->setTime(0, 0, 0)->modify('+1 day'));
        $rows = $this->db->fetchAll(
            'SELECT startTime, endTime, status FROM reservation WHERE roomId = ? AND startTime < ? AND endTime > ? AND status NOT IN (\'rejected\', \'cancelled\') ORDER BY startTime',
            [$roomId, $end, $start],
        );
        $occupied = array_map(fn (array $row): array => [
            'startTime' => Clock::fromSql((string) $row['startTime']),
            'endTime' => Clock::fromSql((string) $row['endTime']),
            'status' => (string) $row['status'],
        ], $rows);

        return ['roomId' => $roomId, 'date' => $date, 'occupied' => $occupied];
    }

    /** @return array<string, mixed> */
    public function preflight(ServerRequestInterface $request): array
    {
        $query = new QueryInput($request->getQueryParams());
        $email = strtolower(trim($query->requireString('email')));
        $date = $query->requireString('date');
        if (!str_contains($email, '@')) {
            throw new HttpException(400, 'Invalid email format.', ['field' => 'email']);
        }
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, Clock::zone());
        $errors = \DateTimeImmutable::getLastErrors();
        if ($day === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new HttpException(400, 'Invalid date.', ['field' => 'date']);
        }
        $profile = $this->profileForEmail($email);
        $start = Clock::sql($day->setTime(0, 0, 0));
        $end = Clock::sql($day->setTime(0, 0, 0)->modify('+1 day'));
        $rows = $this->db->fetchAll(
            'SELECT r.id, r.roomId, rm.name AS roomName, r.startTime, r.endTime, r.status, r.purposeType FROM reservation r LEFT JOIN room rm ON rm.id = r.roomId WHERE LOWER(TRIM(r.email)) = ? AND r.startTime < ? AND r.endTime > ? ORDER BY r.startTime, r.id',
            [$email, $end, $start],
        );

        return [
            'email' => $email,
            'date' => $date,
            'mode' => $profile['administratorId'] === null ? 'normal' : 'priority',
            'student' => ['name' => $profile['name'], 'classId' => $profile['classId'], 'className' => $profile['className']],
            'reservations' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'roomId' => $row['roomId'] === null ? null : (int) $row['roomId'],
                'roomName' => $row['roomName'] === null ? null : (string) $row['roomName'],
                'startTime' => Clock::fromSql((string) $row['startTime']),
                'endTime' => Clock::fromSql((string) $row['endTime']),
                'status' => (string) $row['status'],
                'purposeType' => $row['purposeType'] === null ? null : (string) $row['purposeType'],
            ], $rows),
        ];
    }

    /** @return array<string, mixed> */
    public function create(ServerRequestInterface $request): array
    {
        $this->auth->consumeCsrf($request);
        $input = new Input($this->json($request));
        $roomId = $input->int('room');
        $startEpoch = $input->int('startTime');
        $endEpoch = $input->int('endTime');
        $email = strtolower(trim((string) $input->string('email')));
        $reason = (string) $input->string('reason');
        $purpose = $input->string('purposeType', false);
        $needsMultimedia = $input->bool('needsMultimedia', false);
        $preview = $input->bool('preview', false);
        $confirmPriority = $input->bool('confirmPriority', false);
        if (!str_contains($email, '@')) {
            throw new HttpException(400, 'Invalid email format.', ['field' => 'email']);
        }
        $start = Clock::fromUnix($startEpoch);
        $end = Clock::fromUnix($endEpoch);
        if ($start === null) {
            throw new HttpException(400, 'Invalid start time.', ['field' => 'startTime']);
        }
        if ($end === null) {
            throw new HttpException(400, 'Invalid end time.', ['field' => 'endTime']);
        }
        $this->policy->assertCreateRange($start, $end);
        $result = $this->db->transaction(function () use ($roomId, $start, $end, $email, $reason, $purpose, $needsMultimedia, $preview, $confirmPriority, $input): array {
                $profile = $this->profileForEmail($email, true);
                $studentName = $profile['name'];
                $classId = $profile['classId'];
                $administratorId = $profile['administratorId'];
                if ($administratorId === null) {
                    if (trim($reason) === '') {
                        throw new HttpException(400, 'Reservation reason is required.', ['field' => 'reason']);
                    }
                    $this->policy->assertOrdinaryCreateTimeAndPurpose($start, $end, $purpose);
                }
                $this->lockRooms([$roomId]);
                $room = $this->db->fetch('SELECT rm.id, rm.name, rm.enabled, rm.deletedAt, cp.deletedAt AS campusDeletedAt FROM room rm LEFT JOIN campus cp ON cp.id = rm.campusId WHERE rm.id = ?', [$roomId]);
                if ($room === null || $room['deletedAt'] !== null || $room['campusDeletedAt'] !== null) {
                    throw new HttpException(404, 'Room not found.', ['field' => 'room']);
                }
                if ($administratorId === null && $room['enabled'] !== null && !self::flag($room['enabled'])) {
                    throw new HttpException(400, 'Room not found or disabled.', ['field' => 'room']);
                }
                if ($classId !== null) {
                    $class = $this->db->fetch('SELECT c.id, c.deletedAt, cp.deletedAt AS campusDeletedAt FROM class c LEFT JOIN campus cp ON cp.id = c.campusId WHERE c.id = ?', [$classId]);
                    if ($class === null || $class['deletedAt'] !== null || $class['campusDeletedAt'] !== null) {
                        throw new HttpException(400, 'Class not found.', ['field' => 'classId']);
                    }
                }
                $conflicts = [];
                if ($administratorId !== null) {
                    $conflicts = $this->policy->priorityConflicts($roomId, $start, $end, (string) $room['name']);
                    if ($preview) {
                        return ['mode' => 'priority', 'conflicts' => $conflicts, 'cancelledCount' => count($conflicts)];
                    }
                    if (!$confirmPriority) {
                        throw new HttpException(409, 'Priority reservation requires confirmation.');
                    }
                    $this->policy->assertPriorityConfirmation($input->intList('expectedConflictIds'), $conflicts);
                }
                if ($administratorId === null) {
                    $this->policy->assertOrdinaryCreateAvailability($roomId, $start, $end, $email);
                    if ($preview) {
                        return ['mode' => 'normal', 'conflicts' => [], 'cancelledCount' => 0];
                    }
                }
                $status = $administratorId === null ? 'pending' : 'approved';
                $this->db->execute(
                    'INSERT INTO reservation (roomId, startTime, endTime, studentName, email, reason, classId, status, purposeType, needsMultimedia, latestExecutorId) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $roomId,
                        Clock::sql($start),
                        Clock::sql($end),
                        $studentName,
                        $email,
                        trim($reason),
                        $classId,
                        $status,
                        $purpose,
                        $needsMultimedia ? 1 : 0,
                        $administratorId,
                    ],
                );
                $reservationId = $this->db->lastInsertId();
                $rawCancel = Token::random();
                $this->db->execute(
                    'INSERT INTO reservationcanceltoken (reservationId, tokenHash, expiresAt) VALUES (?, ?, ?)',
                    [$reservationId, Token::hash($rawCancel), Clock::sql($start)],
                );
                $this->enqueue($administratorId === null ? 'reservation_created' : 'reservation_status_changed', [
                    'reservationId' => $reservationId,
                    'email' => $email,
                    'studentName' => $studentName,
                    'room' => (string) $room['name'],
                    'start' => Clock::api($start),
                    'end' => Clock::api($end),
                    'cancelToken' => $rawCancel,
                    'reason' => trim($reason),
                    'status' => $status,
                ]);
                if ($administratorId !== null) {
                    $this->cancelOverlaps($reservationId, $roomId, $start, $end, $administratorId);
                } else {
                    $this->notifyRoomManagers($reservationId, $roomId);
                    if ($this->config->aiEnabled && $this->config->aiUrl !== '') {
                        $this->enqueue('ai_approval', ['reservationId' => $reservationId, 'reviewVersion' => 0], Clock::now()->modify('+15 minutes'));
                    }
                }
                $this->db->execute(
                    'INSERT INTO reservationoperationlog (reservationId, operation, reason) VALUES (?, ?, ?)',
                    [$reservationId, 'created', $status],
                );

                return ['reservationId' => $reservationId, 'mode' => $administratorId === null ? 'normal' : 'priority', 'cancelledCount' => count($conflicts)];
        });
        if ($preview) {
            return $result;
        }
        $this->logger->audit('reservation.create', 'reservation', $result['reservationId'], ['roomId' => $roomId]);

        return $result;
    }

    /** @return array{reservations: list<array<string, mixed>>, total: int} */
    public function list(ServerRequestInterface $request): array
    {
        $admin = $this->auth->currentAdmin($request);
        $query = new QueryInput($request->getQueryParams());
        [$filterSql, $params] = $this->filters($query, $admin);
        $page = max(0, $query->optionalInt('page') ?? 0);
        $total = $this->count('SELECT COUNT(*) AS total' . self::FROM_ROW . 'WHERE 1=1' . $filterSql, $params);
        $order = $query->optionalString('sort') === 'time'
            ? ' ORDER BY r.startTime ASC, r.id ASC'
            : ' ORDER BY r.id DESC';
        $rows = $this->db->fetchAll(
            'SELECT ' . self::SELECT_ROW . self::FROM_ROW . 'WHERE 1=1' . $filterSql . $order . ' LIMIT 20 OFFSET ' . ($page * 20),
            $params,
        );

        return [
            'reservations' => array_map(fn (array $row): array => $this->reservationJson($row, $admin !== null), $rows),
            'total' => $total,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function future(ServerRequestInterface $request): array
    {
        $admin = $this->auth->requireAdmin($request);
        [$scope, $params] = $this->authorization->scope($admin['id']);
        $rows = $this->db->fetchAll(
            'SELECT ' . self::SELECT_ROW . self::FROM_ROW . 'WHERE r.endTime > NOW()' . $scope . ' ORDER BY r.startTime',
            $params,
        );

        return array_map(fn (array $row): array => $this->reservationJson($row, true), $rows);
    }

    /** @return array{mode: string, bytes: string} */
    public function export(ServerRequestInterface $request): array
    {
        $admin = $this->auth->requireAdmin($request);
        $query = new QueryInput($request->getQueryParams());
        $start = $query->optionalInt('startTime');
        $end = $query->optionalInt('endTime');
        $startLocal = $start === null ? null : Clock::fromUnix($start);
        $endLocal = $end === null ? null : Clock::fromUnix($end);
        if ($startLocal !== null && $endLocal !== null && $startLocal >= $endLocal) {
            throw new HttpException(400, 'Invalid time range.');
        }
        [$scope, $params] = $this->authorization->scope($admin['id']);
        $sql = 'SELECT r.id, r.startTime, r.endTime, r.studentName, r.email, r.reason, r.status, rm.name AS room, c.name AS class, cp.name AS campus, r.purposeType, r.needsMultimedia'
            . self::FROM_ROW . 'WHERE 1=1' . $scope;
        if ($startLocal !== null) {
            $sql .= ' AND r.startTime >= ?';
            $params[] = Clock::sql($startLocal);
        }
        if ($endLocal !== null) {
            $sql .= ' AND r.endTime <= ?';
            $params[] = Clock::sql($endLocal);
        }
        $sql .= ' ORDER BY r.startTime';
        $rows = $this->db->fetchAll($sql, $params);
        if ($rows === []) {
            throw new HttpException(404, 'No reservations found.');
        }
        $mode = $query->optionalString('mode') ?? 'by-room';
        if (preg_match('/^[A-Za-z0-9._-]{1,40}$/', $mode) !== 1) {
            $mode = 'by-room';
        }
        $body = SimpleXlsx::build(
            ['ID', 'Campus', 'Room', 'Class', 'Start', 'End', 'Name', 'Email', 'Reason', 'Status', 'Purpose', 'Multimedia'],
            array_map(fn (array $row): array => [
                (string) $row['id'],
                (string) ($row['campus'] ?? ''),
                (string) ($row['room'] ?? ''),
                (string) ($row['class'] ?? ''),
                (string) Clock::fromSql((string) $row['startTime']),
                (string) Clock::fromSql((string) $row['endTime']),
                (string) $row['studentName'],
                (string) $row['email'],
                (string) $row['reason'],
                (string) $row['status'],
                (string) ($row['purposeType'] ?? ''),
                self::flag($row['needsMultimedia']) ? 'true' : 'false',
            ], $rows),
        );
        $this->logger->audit('reservation.export', 'reservation', null, ['mode' => $mode, 'rows' => count($rows)]);

        return ['mode' => $mode, 'bytes' => $body];
    }

    /** @return array<string, mixed> */
    public function cancelPreview(ServerRequestInterface $request): array
    {
        $token = (new QueryInput($request->getQueryParams()))->requireString('token');
        $row = $this->db->fetch(
            'SELECT r.id, r.roomId, r.status, r.startTime, r.endTime, rm.name AS room_name, r.studentName AS student_name, r.reason, r.purposeType, r.needsMultimedia, t.expiresAt, r.editCount FROM reservationcanceltoken t JOIN reservation r ON r.id = t.reservationId LEFT JOIN room rm ON rm.id = r.roomId WHERE t.tokenHash = ? AND t.usedAt IS NULL',
            [Token::hash($token)],
        );
        if ($row === null) {
            throw new HttpException(404, 'Cancellation link is invalid or expired.');
        }
        if (Clock::parseSql((string) $row['expiresAt']) < Clock::now()) {
            throw new HttpException(410, 'Cancellation link is expired.');
        }

        return [
            'reservationId' => (int) $row['id'],
            'roomId' => $row['roomId'] === null ? null : (int) $row['roomId'],
            'status' => (string) $row['status'],
            'roomName' => $row['room_name'] === null ? null : (string) $row['room_name'],
            'studentName' => (string) $row['student_name'],
            'reason' => (string) $row['reason'],
            'startTime' => Clock::fromSql((string) $row['startTime']),
            'endTime' => Clock::fromSql((string) $row['endTime']),
            'purposeType' => $row['purposeType'] === null ? null : (string) $row['purposeType'],
            'needsMultimedia' => self::flag($row['needsMultimedia']),
            'editCount' => (int) $row['editCount'],
            'remainingEdits' => max(0, 2 - (int) $row['editCount']),
        ];
    }

    public function cancel(ServerRequestInterface $request): string
    {
        $token = (string) (new Input($this->json($request)))->string('token');
        $hash = Token::hash($token);
        $result = $this->db->transaction(function () use ($hash): string {
            $row = $this->db->fetch(
                'SELECT t.id, t.reservationId, t.expiresAt, t.usedAt, r.status, r.startTime FROM reservationcanceltoken t JOIN reservation r ON r.id = t.reservationId WHERE t.tokenHash = ? FOR UPDATE',
                [$hash],
            );
            if ($row === null) {
                throw new HttpException(404, 'Cancellation link is invalid or expired.');
            }
            if ((string) $row['status'] === 'cancelled') {
                return 'Reservation is already cancelled.';
            }
            $now = Clock::now();
            if ($row['usedAt'] !== null || Clock::parseSql((string) $row['expiresAt']) < $now) {
                throw new HttpException(410, 'Cancellation link is expired.');
            }
            if ((string) $row['status'] === 'rejected') {
                throw new HttpException(409, 'Rejected reservations cannot be cancelled.');
            }
            if (Clock::parseSql((string) $row['startTime']) <= $now) {
                throw new HttpException(409, 'Past reservations cannot be cancelled.');
            }
            $reservationId = (int) $row['reservationId'];
            $this->db->execute(
                'UPDATE reservation SET status = \'cancelled\', cancelledAt = NOW() WHERE id = ? AND status IN (\'pending\', \'approved\', \'ai_reviewing\')',
                [$reservationId],
            );
            $this->db->execute('UPDATE reservationcanceltoken SET usedAt = NOW() WHERE id = ?', [(int) $row['id']]);
            $this->db->execute(
                'INSERT INTO reservationoperationlog (reservationId, operation, reason) VALUES (?, \'cancelled_by_requester\', \'Cancelled using email link\')',
                [$reservationId],
            );
            $this->enqueue('reservation_cancelled', ['reservationId' => $reservationId]);

            return 'Reservation cancelled successfully.';
        });
        $this->logger->audit('reservation.cancel', 'reservation', null);

        return $result;
    }

    /** @return array{reservationId: int, editCount: int, remainingEdits: int} */
    public function modify(ServerRequestInterface $request): array
    {
        $input = new Input($this->json($request));
        $token = (string) $input->string('token');
        $roomId = $input->int('room');
        $reason = trim((string) $input->string('reason'));
        $purpose = $input->string('purposeType', false);
        $needsMultimedia = $input->bool('needsMultimedia', false);
        if ($reason === '') {
            throw new HttpException(400, 'Reason and purpose are required.', ['field' => 'reason']);
        }
        if (!Rules::validPurpose($purpose)) {
            throw new HttpException(400, 'Reason and purpose are required.', ['field' => 'purposeType']);
        }
        [$start, $end] = $this->policy->editTimes($input->int('startTime'), $input->int('endTime'));
        $result = $this->db->transaction(function () use ($token, $roomId, $reason, $purpose, $needsMultimedia, $start, $end): array {
                $row = $this->db->fetch(
                    'SELECT t.id AS token_id, t.reservationId, t.expiresAt, t.usedAt, r.status, r.startTime, r.editCount, r.reviewVersion FROM reservationcanceltoken t JOIN reservation r ON r.id = t.reservationId WHERE t.tokenHash = ? FOR UPDATE',
                    [Token::hash($token)],
                );
                if ($row === null) {
                    throw new HttpException(404, 'Reservation management link is invalid.');
                }
                $now = Clock::now();
                if ($row['usedAt'] !== null || Clock::parseSql((string) $row['expiresAt']) <= $now || Clock::parseSql((string) $row['startTime']) <= $now) {
                    throw new HttpException(410, 'Reservation management link is expired.');
                }
                $status = (string) $row['status'];
                if (!in_array($status, ['pending', 'approved'], true)) {
                    throw new HttpException(409, 'This reservation cannot be modified.');
                }
                $editCount = (int) $row['editCount'];
                if ($editCount >= 2) {
                    throw new HttpException(409, 'This reservation has already been modified twice.');
                }
                $reservationId = (int) $row['reservationId'];
                $this->lockRooms([$roomId]);
                $this->policy->assertEditedRoom($reservationId, $roomId, $start, $end);
                $next = $editCount + 1;
                $nextReviewVersion = (int) $row['reviewVersion'] + 1;
                $this->db->execute(
                    'UPDATE reservation SET roomId = ?, startTime = ?, endTime = ?, reason = ?, purposeType = ?, needsMultimedia = ?, editCount = ?, reviewVersion = ?, status = \'pending\', latestExecutorId = NULL WHERE id = ?',
                    [$roomId, Clock::sql($start), Clock::sql($end), $reason, $purpose, $needsMultimedia ? 1 : 0, $next, $nextReviewVersion, $reservationId],
                );
                $this->db->execute('UPDATE reservationcanceltoken SET expiresAt = ? WHERE id = ?', [Clock::sql($start), (int) $row['token_id']]);
                $this->db->execute(
                    'INSERT INTO reservationoperationlog (reservationId, operation, reason) VALUES (?, \'modified_by_requester\', ?)',
                    [$reservationId, 'Requester modification ' . $next . '/2'],
                );
                $this->enqueue('reservation_modified', ['reservationId' => $reservationId]);
                if ($this->config->aiEnabled && $this->config->aiUrl !== '') {
                    $this->enqueue('ai_approval', ['reservationId' => $reservationId, 'reviewVersion' => $nextReviewVersion], Clock::now()->modify('+15 minutes'));
                }

                return ['reservationId' => $reservationId, 'editCount' => $next, 'remainingEdits' => 2 - $next];
        });
        $this->logger->audit('reservation.modify', 'reservation', $result['reservationId'], ['editCount' => $result['editCount']]);

        return $result;
    }

    public function adminEdit(ServerRequestInterface $request): void
    {
        $admin = $this->auth->requireAdminWrite($request);
        $input = new Input($this->json($request));
        $id = $input->int('id');
        $roomId = $input->int('room');
        $reason = trim((string) $input->string('reason'));
        $purpose = $input->string('purposeType', false);
        $needsMultimedia = $input->bool('needsMultimedia', false);
        if ($reason === '') {
            throw new HttpException(400, 'Reason and purpose are required.', ['field' => 'reason']);
        }
        if (!Rules::validPurpose($purpose)) {
            throw new HttpException(400, 'Reason and purpose are required.', ['field' => 'purposeType']);
        }
        [$start, $end] = $this->policy->editTimes($input->int('startTime'), $input->int('endTime'));
        $this->db->transaction(function () use ($admin, $id, $roomId, $reason, $purpose, $needsMultimedia, $start, $end): void {
                $row = $this->db->fetch('SELECT status, endTime, roomId FROM reservation WHERE id = ? FOR UPDATE', [$id]);
                if ($row === null) {
                    throw new HttpException(404, 'Reservation not found.');
                }
                $currentRoom = $row['roomId'] === null ? null : (int) $row['roomId'];
                if (!$this->authorization->canManage($admin['id'], $currentRoom) || !$this->authorization->canManage($admin['id'], $roomId)) {
                    throw new HttpException(403, 'You do not manage this room.');
                }
                $now = Clock::now();
                if ((string) $row['status'] !== 'approved' || Clock::parseSql((string) $row['endTime']) <= $now) {
                    throw new HttpException(409, 'Only approved, unexpired reservations can be edited by an admin.');
                }
                $this->lockRooms([$currentRoom, $roomId]);
                $this->policy->assertEditedRoom($id, $roomId, $start, $end);
                $changed = $this->db->execute(
                    'UPDATE reservation SET roomId = ?, startTime = ?, endTime = ?, reason = ?, purposeType = ?, needsMultimedia = ?, latestExecutorId = ? WHERE id = ? AND status = \'approved\'',
                    [$roomId, Clock::sql($start), Clock::sql($end), $reason, $purpose, $needsMultimedia ? 1 : 0, $admin['id'], $id],
                );
                if ($changed === 0) {
                    throw new HttpException(409, 'Reservation could not be edited.');
                }
                $this->db->execute(
                    'UPDATE reservationcanceltoken SET expiresAt = ? WHERE reservationId = ? AND usedAt IS NULL',
                    [Clock::sql($start), $id],
                );
                $this->db->execute(
                    'INSERT INTO reservationoperationlog (adminId, reservationId, operation, reason) VALUES (?, ?, \'modified_by_admin\', \'Approved reservation edited by administrator\')',
                    [$admin['id'], $id],
                );
                $this->enqueue('reservation_modified', ['reservationId' => $id, 'status' => 'approved']);
        });
        $this->logger->audit('reservation.admin_edit', 'reservation', $id, ['roomId' => $roomId]);
    }

    public function approve(ServerRequestInterface $request): void
    {
        $admin = $this->auth->requireAdminWrite($request);
        $input = new Input($this->json($request));
        $id = $input->int('id');
        $approved = $input->bool('approved');
        $reason = $input->string('reason', false);
        if (!$approved && trim((string) $reason) === '') {
            throw new HttpException(400, 'Reason is required for rejection.');
        }
        $status = $approved ? 'approved' : 'rejected';
        $this->db->transaction(function () use ($admin, $id, $approved, $reason, $status): void {
            $row = $this->db->fetch('SELECT roomId, status, startTime FROM reservation WHERE id = ? FOR UPDATE', [$id]);
            if ($row === null) {
                throw new HttpException(404, 'Reservation not found.');
            }
            $roomId = $row['roomId'] === null ? null : (int) $row['roomId'];
            if (!$this->authorization->canManage($admin['id'], $roomId)) {
                throw new HttpException(403, 'You do not manage this room.');
            }
            if ((string) $row['status'] !== 'pending' || Clock::parseSql((string) $row['startTime']) <= Clock::now()) {
                throw new HttpException(409, 'Reservation has already been processed or is no longer editable.');
            }
            $this->db->execute(
                'UPDATE reservation SET status = ?, latestExecutorId = ? WHERE id = ? AND status = \'pending\'',
                [$status, $admin['id'], $id],
            );
            $this->db->execute(
                'INSERT INTO reservationoperationlog (adminId, reservationId, operation, reason) VALUES (?, ?, ?, ?)',
                [$admin['id'], $id, $status, $reason],
            );
            $rawToken = null;
            if ($approved) {
                $rawToken = Token::random();
                $this->db->execute(
                    'INSERT INTO reservationcanceltoken (reservationId, tokenHash, expiresAt) VALUES (?, ?, ?)',
                    [$id, Token::hash($rawToken), (string) $row['startTime']],
                );
            }
            $this->enqueue('reservation_status_changed', [
                'reservationId' => $id,
                'status' => $status,
                'cancelToken' => $rawToken,
            ]);
        });
        $this->logger->audit('reservation.approval', 'reservation', $id, ['status' => $status]);
    }

    public function unlockAiReview(ServerRequestInterface $request): void
    {
        $admin = $this->auth->requireAdminWrite($request);
        if (!$this->authorization->isGlobal($admin['id'])) {
            throw new HttpException(403, 'Global administrator required.');
        }
        $input = new Input($this->json($request));
        $id = $input->int('id');
        $reason = trim((string) $input->string('reason'));
        if ($reason === '') {
            throw new HttpException(400, 'Unlock reason is required.');
        }
        $this->db->transaction(function () use ($id, $admin, $reason): void {
            $row = $this->db->fetch('SELECT status, reviewVersion FROM reservation WHERE id = ? FOR UPDATE', [$id]);
            if ($row === null) {
                throw new HttpException(404, 'Reservation not found.');
            }
            if ((string) $row['status'] !== 'ai_reviewing') {
                throw new HttpException(409, 'Reservation is not being reviewed by AI.');
            }
            $version = (int) $row['reviewVersion'] + 1;
            $this->db->execute(
                'UPDATE reservation SET status = \'pending\', reviewVersion = ?, latestExecutorId = NULL WHERE id = ?',
                [$version, $id],
            );
            $this->db->execute(
                'INSERT INTO reservationoperationlog (adminId, reservationId, operation, reason) VALUES (?, ?, \'ai_unlocked\', ?)',
                [$admin['id'], $id, $reason],
            );
            if ($this->config->aiEnabled && $this->config->aiUrl !== '') {
                $this->enqueue('ai_approval', ['reservationId' => $id, 'reviewVersion' => $version], Clock::now()->modify('+15 minutes'));
            }
        });
        $this->logger->audit('reservation.ai_unlock', 'reservation', $id, ['reason' => $reason]);
    }

    private function cancelOverlaps(int $reservationId, int $roomId, \DateTimeImmutable $start, \DateTimeImmutable $end, int $adminId): void
    {
        $rows = $this->db->fetchAll(
            'SELECT id FROM reservation WHERE id <> ? AND roomId = ? AND status IN (\'pending\', \'approved\', \'ai_reviewing\') AND startTime < ? AND endTime > ? FOR UPDATE',
            [$reservationId, $roomId, Clock::sql($end), Clock::sql($start)],
        );
        foreach ($rows as $row) {
            $displaced = (int) $row['id'];
            $this->db->execute(
                'UPDATE reservation SET status = \'cancelled\', cancelledAt = NOW(), latestExecutorId = ? WHERE id = ?',
                [$adminId, $displaced],
            );
            $this->db->execute(
                'INSERT INTO reservationoperationlog (adminId, reservationId, operation, reason) VALUES (?, ?, \'cancelled_by_priority\', \'Cancelled because an administrator priority reservation occupies this time\')',
                [$adminId, $displaced],
            );
            $this->enqueue('reservation_cancelled', ['reservationId' => $displaced, 'reason' => 'higher_priority']);
        }
    }

    private function notifyRoomManagers(int $reservationId, int $roomId): void
    {
        foreach ($this->authorization->notificationAdminIds($roomId) as $adminId) {
            $this->enqueue('admin_reservation_notification', [
                'reservationId' => $reservationId,
                'adminId' => $adminId,
            ]);
        }
    }

    /** @param array<string, mixed> $payload */
    private function enqueue(string $kind, array $payload, ?\DateTimeImmutable $availableAt = null): void
    {
        $this->outbox->enqueue($kind, $payload, $availableAt);
    }

    /** @param array{id: int, email: string, name: string, password: string}|null $admin
     * @return array{0: string, 1: list<mixed>}
     */
    private function filters(QueryInput $query, ?array $admin): array
    {
        $sql = '';
        $params = [];
        if ($admin !== null) {
            [$scope, $scopeParams] = $this->authorization->scope($admin['id']);
            $sql .= $scope;
            $params = array_merge($params, $scopeParams);
        }
        $campus = $query->optionalInt('campusId');
        if ($campus !== null) {
            $sql .= ' AND rm.campusId = ?';
            $params[] = $campus;
        }
        $room = $query->optionalInt('roomId');
        if ($room !== null) {
            $sql .= ' AND r.roomId = ?';
            $params[] = $room;
        }
        $status = $query->optionalString('status');
        if ($status !== null) {
            $sql .= ' AND r.status = ?';
            $params[] = $status;
        }
        $purpose = $query->optionalString('purposeType');
        if ($purpose !== null) {
            $sql .= ' AND r.purposeType = ?';
            $params[] = $purpose;
        }
        $needs = $query->optionalBool('needsMultimedia');
        if ($needs !== null) {
            $sql .= ' AND r.needsMultimedia = ?';
            $params[] = $needs ? 1 : 0;
        }
        $start = $query->optionalInt('startTime');
        if ($start !== null) {
            $local = Clock::fromUnix($start);
            if ($local !== null) {
                $sql .= ' AND r.startTime >= ?';
                $params[] = Clock::sql($local);
            }
        }
        $end = $query->optionalInt('endTime');
        if ($end !== null) {
            $local = Clock::fromUnix($end);
            if ($local !== null) {
                $sql .= ' AND r.endTime <= ?';
                $params[] = Clock::sql($local);
            }
        }
        $keyword = $query->optionalString('keyword');
        if ($keyword !== null) {
            $pattern = '%' . $keyword . '%';
            $sql .= ' AND (r.email LIKE ? OR r.reason LIKE ? OR r.studentName LIKE ? OR rm.name LIKE ? OR c.name LIKE ?)';
            array_push($params, $pattern, $pattern, $pattern, $pattern, $pattern);
        }

        return [$sql, $params];
    }

    /** @return array<string, mixed> */
    private function reservationJson(array $row, bool $admin): array
    {
        return [
            'id' => (int) $row['id'],
            'roomId' => $row['roomId'] === null ? null : (int) $row['roomId'],
            'startTime' => Clock::fromSql((string) $row['startTime']),
            'endTime' => Clock::fromSql((string) $row['endTime']),
            'studentName' => (string) $row['studentName'],
            'email' => $admin ? (string) $row['email'] : null,
            'reason' => (string) $row['reason'],
            'status' => (string) $row['status'],
            'createdAt' => Clock::fromSql((string) $row['createdAt']),
            'roomName' => $row['room_name'] === null ? null : (string) $row['room_name'],
            'className' => $row['class_name'] === null ? null : (string) $row['class_name'],
            'campusName' => $row['campus_name'] === null ? null : (string) $row['campus_name'],
            'purposeType' => $row['purposeType'] === null ? null : (string) $row['purposeType'],
            'needsMultimedia' => self::flag($row['needsMultimedia']),
            'editCount' => (int) $row['editCount'],
        ];
    }

    /** @param list<int|null> $roomIds */
    private function lockRooms(array $roomIds): void
    {
        $ids = [];
        foreach ($roomIds as $roomId) {
            if ($roomId !== null && $roomId > 0) {
                $ids[$roomId] = $roomId;
            }
        }
        $ids = array_values($ids);
        sort($ids);
        if ($ids === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->db->fetchAll(
            'SELECT id FROM room WHERE id IN (' . $placeholders . ') ORDER BY id FOR UPDATE',
            $ids,
        );
    }

    /** @return array{name: string, classId: ?int, className: ?string, administratorId: ?int} */
    private function profileForEmail(string $email, bool $lock = false): array
    {
        $suffix = $lock ? ' FOR UPDATE' : '';
        $admin = $this->db->fetch('SELECT id, name FROM admin WHERE email = ? LIMIT 1' . $suffix, [$email]);
        if ($admin !== null) {
            return ['name' => (string) $admin['name'], 'classId' => null, 'className' => null, 'administratorId' => (int) $admin['id']];
        }
        $student = $this->db->fetch(
            'SELECT s.name, s.classId, c.name AS className FROM student s LEFT JOIN class c ON c.id = s.classId WHERE s.email = ? LIMIT 1' . $suffix,
            [$email],
        );
        if ($student === null) {
            throw new HttpException(422, 'Student email is not registered.', ['field' => 'email', 'code' => 'student_not_registered']);
        }

        return [
            'name' => (string) $student['name'],
            'classId' => $student['classId'] === null ? null : (int) $student['classId'],
            'className' => $student['className'] === null ? null : (string) $student['className'],
            'administratorId' => null,
        ];
    }

    /** @param list<mixed> $params */
    private function count(string $sql, array $params): int
    {
        $row = $this->db->fetch($sql, $params);

        return (int) ($row['total'] ?? 0);
    }

    private static function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }

    /** @return array<string, mixed> */
    private function json(ServerRequestInterface $request): array
    {
        $data = $request->getAttribute('json');

        return is_array($data) ? $data : [];
    }
}
