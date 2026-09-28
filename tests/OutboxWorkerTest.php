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

    private ?string $aiRequestFile = null;

    private ?string $aiRouterFile = null;

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
            $this->server = null;
        }
        foreach ([$this->aiRequestFile, $this->aiRouterFile, $this->aiRouterFile === null ? null : $this->aiRouterFile . '.log'] as $path) {
            if ($path !== null && is_file($path)) {
                @unlink($path);
            }
        }
        $this->aiRequestFile = null;
        $this->aiRouterFile = null;
        parent::tearDown();
    }

    public function testEmailJobsAreIdempotentAndLocksAreHonored(): void
    {
        $reservationId = $this->reservation();
        $completed = $this->insertJob('reservation_created', ['reservationId' => $reservationId]);
        $this->worker->processQueuedJob($completed);
        self::assertSame('completed', $this->jobStatus($completed));

        $ignored = $this->insertJob('ignored', ['reservationId' => $reservationId]);
        $this->expectHttp(
            fn () => $this->worker->processQueuedJob($ignored),
            500,
            'Outbox job failed.',
        );
        self::assertSame('pending', $this->jobStatus($ignored));

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
        self::assertNull($this->db->fetch('SELECT reason FROM reservationoperationlog WHERE reservationId = ? AND operation = \'approved\'', [$reservationId])['reason']);
        self::assertNotNull($this->aiRequestFile);
        $aiRequest = json_decode((string) file_get_contents((string) $this->aiRequestFile), true);
        self::assertIsArray($aiRequest);
        self::assertSame('/gemini-3.7-flash:generateContent', $aiRequest['path'] ?? null);
        self::assertStringContainsString('Chess Club meeting - weekly practice', (string) ($aiRequest['body']['systemInstruction']['parts'][0]['text'] ?? ''));
        self::assertSame([['role' => 'user', 'parts' => [['text' => 'Study group']]]], $aiRequest['body']['contents'] ?? null);
        self::assertSame(['approved', 'rejected', 'pending'], $aiRequest['body']['generationConfig']['responseSchema']['properties']['status']['enum'] ?? null);
        self::assertStringNotContainsString('test-secret', (string) file_get_contents((string) $this->aiRequestFile));

        $pendingId = $this->reservation();
        $pendingJob = $this->insertJob('ai_approval', ['reservationId' => $pendingId]);
        $this->expectHttp(
            fn () => $this->workerFor($base . '?want=pending', $adminId)->processQueuedJob($pendingJob),
            500,
            'Outbox job failed.',
        );
        self::assertSame('pending', $this->jobStatus($pendingJob));
        self::assertSame('ai_reviewing', $this->db->fetch('SELECT status FROM reservation WHERE id = ?', [$pendingId])['status']);
        self::assertSame(0, $this->countTokens($pendingId));

        $rejectedId = $this->reservation();
        $rejectedJob = $this->insertJob('ai_approval', ['reservationId' => $rejectedId]);
        $this->workerFor($base . '?want=rejected', $adminId)->processQueuedJob($rejectedJob);
        self::assertSame('rejected', $this->db->fetch('SELECT status FROM reservation WHERE id = ?', [$rejectedId])['status']);
        self::assertSame(0, $this->countTokens($rejectedId));
        self::assertNull($this->jobPayloads('reservation_status_changed')[1]['cancelToken']);
        self::assertSame(
            'Game-related activities are not permitted.',
            $this->db->fetch("SELECT reason FROM reservationoperationlog WHERE reservationId = ? AND operation = 'rejected'", [$rejectedId])['reason'],
        );

        $settledId = $this->reservation('approved');
        $settledJob = $this->insertJob('ai_approval', ['reservationId' => $settledId]);
        $this->workerFor($base . '?want=approved', $adminId)->processQueuedJob($settledJob);
        self::assertSame('completed', $this->jobStatus($settledJob));
        self::assertSame(0, $this->countTokens($settledId));

        $supersededId = $this->reservation();
        $supersededJob = $this->insertJob('ai_approval', ['reservationId' => $supersededId, 'reviewVersion' => 0]);
        $this->db->execute('UPDATE reservation SET reviewVersion = 1 WHERE id = ?', [$supersededId]);
        $this->workerFor($base . '?want=approved', $adminId)->processQueuedJob($supersededJob);
        self::assertSame('completed', $this->jobStatus($supersededJob));
        self::assertSame('pending', $this->db->fetch('SELECT status FROM reservation WHERE id = ?', [$supersededId])['status']);
        self::assertSame(0, $this->countTokens($supersededId));

        $failedId = $this->reservation();
        $failedJob = $this->insertJob('ai_approval', ['reservationId' => $failedId]);
        $failing = $this->workerFor('not-a-url', $adminId);
        $this->expectHttp(
            fn () => $failing->processQueuedJob($failedJob),
            500,
            'Outbox job failed.',
        );
        self::assertSame('pending', $this->jobStatus($failedJob));
        self::assertSame('ai_reviewing', $this->db->fetch('SELECT status FROM reservation WHERE id = ?', [$failedId])['status']);
        self::assertNotSame('', (string) $this->db->fetch('SELECT lastError FROM outboxjob WHERE id = ?', [$failedJob])['lastError']);
        self::assertNotNull($this->db->fetch('SELECT id FROM errorlog WHERE message = ?', ['Outbox job failed']));
        $this->db->execute('UPDATE outboxjob SET attempts = 7, status = \'pending\', availableAt = NOW() WHERE id = ?', [$failedJob]);
        $this->expectHttp(
            fn () => $failing->processQueuedJob($failedJob),
            500,
            'Outbox job failed.',
        );
        self::assertSame('pending', $this->jobStatus($failedJob));
        self::assertSame(8, (int) $this->db->fetch('SELECT attempts FROM outboxjob WHERE id = ?', [$failedJob])['attempts']);

        $unsupportedId = $this->reservation();
        $unsupportedJob = $this->insertJob('ai_approval', ['reservationId' => $unsupportedId]);
        $this->expectHttp(
            fn () => $this->workerFor($base . '?want=maybe', $adminId)->processQueuedJob($unsupportedJob),
            500,
            'Outbox job failed.',
        );
        self::assertSame('ai_reviewing', $this->db->fetch('SELECT status FROM reservation WHERE id = ?', [$unsupportedId])['status']);

        $invalidMessageId = $this->reservation();
        $invalidMessageJob = $this->insertJob('ai_approval', ['reservationId' => $invalidMessageId]);
        $this->expectHttp(
            fn () => $this->workerFor($base . '?want=bad-message', $adminId)->processQueuedJob($invalidMessageJob),
            500,
            'Outbox job failed.',
        );
        self::assertSame('pending', $this->jobStatus($invalidMessageJob));
        self::assertSame('ai_reviewing', $this->db->fetch('SELECT status FROM reservation WHERE id = ?', [$invalidMessageId])['status']);

        $unfinishedId = $this->reservation();
        $unfinishedJob = $this->insertJob('ai_approval', ['reservationId' => $unfinishedId]);
        $this->expectHttp(
            fn () => $this->workerFor($base . '?want=not-stop', $adminId)->processQueuedJob($unfinishedJob),
            500,
            'Outbox job failed.',
        );
        self::assertSame('pending', $this->jobStatus($unfinishedJob));
        self::assertSame('ai_reviewing', $this->db->fetch('SELECT status FROM reservation WHERE id = ?', [$unfinishedId])['status']);
        self::assertSame(0, $this->countTokens($unfinishedId));
        self::assertStringContainsString('SAFETY', (string) $this->db->fetch('SELECT lastError FROM outboxjob WHERE id = ?', [$unfinishedJob])['lastError']);
    }

    public function testAiTransportFailureRecordsDiagnosticWithoutCredentials(): void
    {
        $reservationId = $this->reservation();
        $jobId = $this->insertJob('ai_approval', ['reservationId' => $reservationId]);

        $this->expectHttp(
            fn () => $this->workerFor('http://127.0.0.1:1/', 0)->processQueuedJob($jobId),
            500,
            'Outbox job failed.',
        );

        $error = (string) $this->db->fetch('SELECT lastError FROM outboxjob WHERE id = ?', [$jobId])['lastError'];
        self::assertMatchesRegularExpression('/^Gemini cURL [1-9][0-9]*: /', $error);
        self::assertStringNotContainsString('test-secret', $error);
        self::assertSame('ai_reviewing', $this->db->fetch('SELECT status FROM reservation WHERE id = ?', [$reservationId])['status']);
    }

    public function testDispatchTokenRejectsStaleQueueDeliveryAndDuplicateCompletion(): void
    {
        $reservationId = $this->reservation();
        $jobId = $this->insertJob('reservation_created', ['reservationId' => $reservationId]);
        $claimed = $this->outbox->claimDue(1, $jobId);
        self::assertCount(1, $claimed);
        self::assertSame($jobId, $claimed[0]['taskId']);
        $this->worker->processQueuedJob($jobId, 'stale-token');
        self::assertSame('leased', $this->jobStatus($jobId));
        self::assertSame(0, (int) $this->db->fetch('SELECT attempts FROM outboxjob WHERE id = ?', [$jobId])['attempts']);

        $this->worker->processQueuedJob($jobId, $claimed[0]['dispatchToken']);
        self::assertSame('completed', $this->jobStatus($jobId));
        $this->worker->processQueuedJob($jobId, $claimed[0]['dispatchToken']);
        self::assertSame(1, (int) $this->db->fetch('SELECT attempts FROM outboxjob WHERE id = ?', [$jobId])['attempts']);
    }

    public function testDelayedAiJobCannotBeClaimedOrExecutedEarly(): void
    {
        $reservationId = $this->reservation();
        $jobId = $this->insertJob('ai_approval', ['reservationId' => $reservationId, 'reviewVersion' => 0]);
        $this->db->execute('UPDATE outboxjob SET availableAt = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?', [$jobId]);

        self::assertSame([], $this->outbox->claimDue(1, $jobId));
        $this->worker->processQueuedJob($jobId);
        self::assertSame('pending', $this->jobStatus($jobId));
        self::assertSame(0, (int) $this->db->fetch('SELECT attempts FROM outboxjob WHERE id = ?', [$jobId])['attempts']);
        self::assertSame('pending', $this->db->fetch('SELECT status FROM reservation WHERE id = ?', [$reservationId])['status']);
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
        $router = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hfiuc-ai-router-' . bin2hex(random_bytes(6)) . '.php';
        $this->aiRouterFile = $router;
        $this->aiRequestFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hfiuc-ai-request-' . bin2hex(random_bytes(6)) . '.json';
        $script = <<<'PHP'
<?php
header('Content-Type: application/json');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || $path !== '/gemini-3.7-flash:generateContent'
    || ($_SERVER['HTTP_X_GOOG_API_KEY'] ?? '') !== 'test-secret') {
    http_response_code(401);
    echo '{"error":"POST to configured model with Gemini API key required"}';
    return;
}
$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)
    || !is_string($input['systemInstruction']['parts'][0]['text'] ?? null)
    || !is_string($input['contents'][0]['parts'][0]['text'] ?? null)
    || count($input['contents'] ?? []) !== 1
    || count($input['contents'][0]['parts'] ?? []) !== 1
    || ($input['generationConfig']['responseMimeType'] ?? null) !== 'application/json') {
    http_response_code(400);
    echo '{"error":"Gemini request required"}';
    return;
}
file_put_contents(__REQUEST_FILE__, json_encode(['path' => $path, 'body' => $input]));
$want = $_GET['want'] ?? 'approved';
$decision = [
    'status' => $want === 'bad-message' ? 'rejected' : ($want === 'not-stop' ? 'approved' : $want),
    'message' => $want === 'rejected' ? 'Game-related activities are not permitted.' : ($want === 'bad-message' ? 'Invented rejection.' : null),
];
echo json_encode([
    'candidates' => [
        ['finishReason' => $want === 'not-stop' ? 'SAFETY' : 'STOP', 'content' => ['parts' => [['text' => json_encode($decision)]]]],
    ],
]);
PHP;
        file_put_contents($router, str_replace('__REQUEST_FILE__', var_export($this->aiRequestFile, true), $script));
        $lastResponse = '';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = random_int(20000, 45000);
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
                [0 => ['pipe', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', $router . '.log', 'a']],
                $pipes,
            );
            if (!is_resource($process)) {
                continue;
            }
            $ready = false;
            for ($wait = 0; $wait < 30; $wait++) {
                $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if (is_resource($socket)) {
                    $body = (string) json_encode([
                        'systemInstruction' => ['parts' => [['text' => 'probe prompt']]],
                        'contents' => [['role' => 'user', 'parts' => [['text' => 'probe']]]],
                        'generationConfig' => ['responseMimeType' => 'application/json'],
                    ]);
                    fwrite($socket, "POST /gemini-3.7-flash:generateContent?want=approved HTTP/1.0\r\nHost: 127.0.0.1\r\nx-goog-api-key: test-secret\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
                    $response = stream_get_contents($socket);
                    fclose($socket);
                    if (is_string($response) && str_contains($response, '"candidates"')) {
                        $ready = true;
                        break;
                    }
                    $lastResponse = is_string($response) ? substr($response, 0, 500) : 'Unable to read probe response';
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
        if ($lastResponse !== '') {
            self::fail('Local Gemini mock returned an invalid response: ' . $lastResponse . ' Log: ' . substr((string) @file_get_contents($router . '.log'), 0, 1000));
        }
        self::markTestSkipped('Unable to start a local Gemini mock server.');
    }
}
