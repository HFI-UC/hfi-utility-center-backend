<?php

declare(strict_types=1);

namespace Hfiuc\Tests;

use Hfiuc\Support\Clock;
use Hfiuc\Tests\Support\DatabaseTestCase;
use Hfiuc\Worker\OutboxWorker;

final class OutboxWorkerTest extends DatabaseTestCase
{
    /** @var resource|null */
    private $server = null;

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
            $this->server = null;
        }
        parent::tearDown();
    }

    public function testEmailJobsCompleteWithoutSmtpAndLocksAreHonored(): void
    {
        $reservationId = $this->reservation();
        $completed = $this->insertJob('reservation_created', ['reservationId' => $reservationId]);
        $this->worker->processQueuedJob($completed);
        self::assertSame('completed', $this->jobStatus($completed));

        $ignored = $this->insertJob('ignored', ['reservationId' => $reservationId]);
        $this->worker->processQueuedJob($ignored);
        self::assertSame('completed', $this->jobStatus($ignored));

        $done = $this->insertJob('reservation_created', ['reservationId' => $reservationId], 'completed', 2);
        $this->worker->processQueuedJob($done);
        self::assertSame('completed', $this->jobStatus($done));
        self::assertSame(2, (int) $this->db->fetch('SELECT attempts FROM outboxjob WHERE id = ?', [$done])['attempts']);

        $this->expectHttp(
            fn () => $this->worker->processQueuedJob(999999),
            404,
            'Outbox job not found.',
        );

        $locked = $this->insertJob('reservation_created', ['reservationId' => $reservationId], 'processing', 3, Clock::sql(Clock::now()));
        $this->expectHttp(
            fn () => $this->worker->processQueuedJob($locked),
            409,
            'Outbox job is already being processed.',
        );
        self::assertSame(3, (int) $this->db->fetch('SELECT attempts FROM outboxjob WHERE id = ?', [$locked])['attempts']);

        $stale = $this->insertJob(
            'reservation_created',
            ['reservationId' => $reservationId],
            'processing',
            1,
            Clock::sql(Clock::now()->modify('-5 minutes')),
        );
        $this->worker->processQueuedJob($stale);
        self::assertSame('completed', $this->jobStatus($stale));

        $ai = $this->insertJob('ai_approval', ['reservationId' => $reservationId]);
        $this->worker->processQueuedJob($ai);
        self::assertSame('completed', $this->jobStatus($ai));
        self::assertSame('pending', $this->db->fetch('SELECT status FROM reservation WHERE id = ?', [$reservationId])['status']);
    }

    public function testAiApprovalUpdatesTheReservationAndRetriesFailures(): void
    {
        $adminId = $this->insertAdmin('ai@example.com', 'AI');
        $reservationId = $this->reservation();
        $base = $this->aiServer();

        $approvedJob = $this->insertJob('ai_approval', ['reservationId' => $reservationId]);
        $this->workerFor($base . '?want=approved', $adminId)->processQueuedJob($approvedJob);
        self::assertSame('completed', $this->jobStatus($approvedJob));
        self::assertSame('approved', $this->db->fetch('SELECT status FROM reservation WHERE id = ?', [$reservationId])['status']);
        self::assertSame($adminId, (int) $this->db->fetch('SELECT latestExecutorId FROM reservation WHERE id = ?', [$reservationId])['latestExecutorId']);
        self::assertSame(1, $this->countTokens($reservationId));
        self::assertSame('approved', $this->jobPayloads('reservation_status_changed')[0]['status']);
        self::assertNotSame('', (string) $this->jobPayloads('reservation_status_changed')[0]['cancelToken']);
        self::assertSame('ok', $this->db->fetch('SELECT reason FROM reservationoperationlog WHERE reservationId = ? AND operation = \'approved\'', [$reservationId])['reason']);

        $pendingId = $this->reservation();
        $pendingJob = $this->insertJob('ai_approval', ['reservationId' => $pendingId]);
        $this->workerFor($base . '?want=pending', $adminId)->processQueuedJob($pendingJob);
        self::assertSame('completed', $this->jobStatus($pendingJob));
        self::assertSame('pending', $this->db->fetch('SELECT status FROM reservation WHERE id = ?', [$pendingId])['status']);
        self::assertSame(0, $this->countTokens($pendingId));

        $rejectedId = $this->reservation();
        $rejectedJob = $this->insertJob('ai_approval', ['reservationId' => $rejectedId]);
        $this->workerFor($base . '?want=rejected', $adminId)->processQueuedJob($rejectedJob);
        self::assertSame('rejected', $this->db->fetch('SELECT status FROM reservation WHERE id = ?', [$rejectedId])['status']);
        self::assertSame(0, $this->countTokens($rejectedId));
        self::assertNull($this->jobPayloads('reservation_status_changed')[1]['cancelToken']);

        $settledId = $this->reservation('approved');
        $settledJob = $this->insertJob('ai_approval', ['reservationId' => $settledId]);
        $this->workerFor($base . '?want=approved', $adminId)->processQueuedJob($settledJob);
        self::assertSame('completed', $this->jobStatus($settledJob));
        self::assertSame(0, $this->countTokens($settledId));

        $failedId = $this->reservation();
        $failedJob = $this->insertJob('ai_approval', ['reservationId' => $failedId]);
        $failing = $this->workerFor('not-a-url', $adminId);
        $this->expectHttp(
            fn () => $failing->processQueuedJob($failedJob),
            500,
            'Outbox job failed.',
        );
        self::assertSame('pending', $this->jobStatus($failedJob));
        self::assertNotSame('', (string) $this->db->fetch('SELECT lastError FROM outboxjob WHERE id = ?', [$failedJob])['lastError']);
        self::assertNotNull($this->db->fetch('SELECT id FROM errorlog WHERE message = ?', ['Outbox job failed']));
        $this->db->execute('UPDATE outboxjob SET attempts = 7, status = \'pending\' WHERE id = ?', [$failedJob]);
        $failing->processQueuedJob($failedJob);
        self::assertSame('failed', $this->jobStatus($failedJob));
        self::assertSame(8, (int) $this->db->fetch('SELECT attempts FROM outboxjob WHERE id = ?', [$failedJob])['attempts']);

        $unsupportedId = $this->reservation();
        $unsupportedJob = $this->insertJob('ai_approval', ['reservationId' => $unsupportedId]);
        $this->expectHttp(
            fn () => $this->workerFor($base . '?want=maybe', $adminId)->processQueuedJob($unsupportedJob),
            500,
            'Outbox job failed.',
        );
        self::assertSame('pending', $this->db->fetch('SELECT status FROM reservation WHERE id = ?', [$unsupportedId])['status']);
    }

    private function workerFor(string $url, int $adminId): OutboxWorker
    {
        return new OutboxWorker($this->db, $this->makeConfig(true, $url, $adminId), $this->logger, $this->outbox);
    }

    private function reservation(string $status = 'pending'): int
    {
        $campusId = $this->insertCampus('Knowledge City ' . $status . bin2hex(random_bytes(2)));
        $roomId = $this->insertRoom('505', $campusId, 1);
        [$start, $end] = $this->slot(3, 10);

        return $this->insertReservation($roomId, $start, $end, 'student@example.com', $status);
    }

    /** @param array<string, mixed> $payload */
    private function insertJob(string $kind, array $payload, string $status = 'pending', int $attempts = 0, ?string $lockedAt = null): int
    {
        $this->db->execute(
            'INSERT INTO outboxjob (kind, payload, status, attempts, lockedAt) VALUES (?, ?, ?, ?, ?)',
            [$kind, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $status, $attempts, $lockedAt],
        );

        return $this->db->lastInsertId();
    }

    private function jobStatus(int $id): string
    {
        return (string) $this->db->fetch('SELECT status FROM outboxjob WHERE id = ?', [$id])['status'];
    }

    private function countTokens(int $reservationId): int
    {
        return (int) $this->db->fetch('SELECT COUNT(*) AS total FROM reservationcanceltoken WHERE reservationId = ?', [$reservationId])['total'];
    }

    private function aiServer(): string
    {
        $router = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hfiuc-ai-router.php';
        file_put_contents($router, <<<'PHP'
<?php
header('Content-Type: application/json');
echo json_encode([
    'status' => $_GET['want'] ?? 'approved',
    'message' => 'ok',
]);
PHP);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = random_int(20000, 45000);
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
                [0 => ['pipe', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']],
                $pipes,
            );
            if (!is_resource($process)) {
                continue;
            }
            $ready = false;
            for ($wait = 0; $wait < 30; $wait++) {
                $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if (is_resource($socket)) {
                    fwrite($socket, "GET /?want=approved HTTP/1.0\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
                    $response = stream_get_contents($socket);
                    fclose($socket);
                    if (is_string($response) && str_contains($response, '"status":"approved"')) {
                        $ready = true;
                        break;
                    }
                }
                usleep(100000);
            }
            if ($ready) {
                if (isset($pipes[0]) && is_resource($pipes[0])) {
                    fclose($pipes[0]);
                }
                $this->server = $process;

                return 'http://127.0.0.1:' . $port . '/';
            }
            proc_terminate($process);
            proc_close($process);
        }
        self::markTestSkipped('Unable to start a local AI approval server.');
    }
}
