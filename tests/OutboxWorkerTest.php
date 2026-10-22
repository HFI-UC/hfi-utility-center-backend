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
        self::assertSame('/chat/completions', $aiRequest['path'] ?? null);
        self::assertStringContainsString('Chess Club meeting - weekly practice', (string) ($aiRequest['body']['messages'][0]['content'] ?? ''));
        self::assertSame([
            ['role' => 'system', 'content' => $aiRequest['body']['messages'][0]['content'] ?? null],
            ['role' => 'user', 'content' => 'Study group'],
        ], $aiRequest['body']['messages'] ?? null);
        self::assertSame(['type' => 'json_object'], $aiRequest['body']['response_format'] ?? null);
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
        $rejectionMail = array_values(array_filter(
            $this->jobPayloads('reservation_status_changed'),
            static fn (array $payload): bool => (int) ($payload['reservationId'] ?? 0) === $rejectedId,
        ));
        self::assertSame('Game-related activities are not permitted.', $rejectionMail[0]['reason'] ?? null);

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
        $failing = $this->workerFor($base . '?want=unavailable', $adminId);
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
        self::assertStringContainsString('length', (string) $this->db->fetch('SELECT lastError FROM outboxjob WHERE id = ?', [$unfinishedJob])['lastError']);
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
        self::assertMatchesRegularExpression('/^OpenAI cURL [1-9][0-9]*: /', $error);
        self::assertStringNotContainsString('test-secret', $error);
        self::assertSame('ai_reviewing', $this->db->fetch('SELECT status FROM reservation WHERE id = ?', [$reservationId])['status']);
    }

    public function testMissingOpenAiKeyCompletesAiJobsWithoutCallingOpenAiOrChangingOtherReservations(): void
    {
        $base = $this->aiServer();
        self::assertNotNull($this->aiRequestFile);
        // aiServer probes the mock once; only a new file would represent a worker request.
        unlink($this->aiRequestFile);
        $worker = new OutboxWorker(
            $this->db,
            $this->makeConfig(true, $base, 0, false, ''),
            $this->logger,
            $this->outbox,
        );

        $reviewingId = $this->reservation('ai_reviewing');
        $reviewingJob = $this->insertJob('ai_approval', ['reservationId' => $reviewingId, 'reviewVersion' => 0]);
        $worker->processQueuedJob($reviewingJob);
        self::assertSame('completed', $this->jobStatus($reviewingJob));
        self::assertSame('pending', $this->db->fetch('SELECT status FROM reservation WHERE id = ?', [$reviewingId])['status']);
        self::assertNotNull($this->db->fetch(
            "SELECT id FROM reservationoperationlog WHERE reservationId = ? AND operation = 'ai_unavailable'",
            [$reviewingId],
        ));
        self::assertNotNull($this->db->fetch(
            "SELECT id FROM auditlog WHERE action = 'ai.unconfigured' AND entityId = ?",
            [$reviewingId],
        ));

        $pendingId = $this->reservation();
        $pendingJob = $this->insertJob('ai_approval', ['reservationId' => $pendingId, 'reviewVersion' => 0]);
        $worker->processQueuedJob($pendingJob);
        self::assertSame('completed', $this->jobStatus($pendingJob));
        self::assertSame('pending', $this->db->fetch('SELECT status FROM reservation WHERE id = ?', [$pendingId])['status']);

        $staleId = $this->reservation('ai_reviewing');
        $this->db->execute('UPDATE reservation SET reviewVersion = 1 WHERE id = ?', [$staleId]);
        $staleJob = $this->insertJob('ai_approval', ['reservationId' => $staleId, 'reviewVersion' => 0]);
        $worker->processQueuedJob($staleJob);
        self::assertSame('completed', $this->jobStatus($staleJob));
        self::assertSame('ai_reviewing', $this->db->fetch('SELECT status FROM reservation WHERE id = ?', [$staleId])['status']);

        $approvedId = $this->reservation('approved');
        $approvedJob = $this->insertJob('ai_approval', ['reservationId' => $approvedId, 'reviewVersion' => 0]);
        $worker->processQueuedJob($approvedJob);
        self::assertSame('completed', $this->jobStatus($approvedJob));
        self::assertSame('approved', $this->db->fetch('SELECT status FROM reservation WHERE id = ?', [$approvedId])['status']);

        self::assertFileDoesNotExist($this->aiRequestFile);
        $returned = array_values(array_filter(
            $this->jobPayloads('reservation_status_changed'),
            static fn (array $payload): bool => (int) ($payload['reservationId'] ?? 0) === $reviewingId,
        ));
        self::assertSame('AI configuration unavailable; returned to pending', $returned[0]['reason'] ?? null);
        self::assertSame(0, $this->countTokens($reviewingId));
        self::assertSame(0, $this->countTokens($pendingId));
        self::assertSame(0, $this->countTokens($staleId));
        self::assertSame(0, $this->countTokens($approvedId));
    }

    public function testWrongOpenAiKeyReturnsReviewingReservationToHumanQueueWithoutRetry(): void
    {
        $base = $this->aiServer();
        $worker = new OutboxWorker(
            $this->db,
            $this->makeConfig(true, $base, 0, false, 'wrong-secret'),
            $this->logger,
            $this->outbox,
        );
        $reservationId = $this->reservation('ai_reviewing');
        $jobId = $this->insertJob('ai_approval', ['reservationId' => $reservationId, 'reviewVersion' => 0]);

        $worker->processQueuedJob($jobId);

        self::assertSame('completed', $this->jobStatus($jobId));
        self::assertSame('pending', $this->db->fetch('SELECT status FROM reservation WHERE id = ?', [$reservationId])['status']);
        $operation = $this->db->fetch(
            "SELECT reason FROM reservationoperationlog WHERE reservationId = ? AND operation = 'ai_unavailable'",
            [$reservationId],
        );
        self::assertNotNull($operation);
        self::assertStringNotContainsString('wrong-secret', (string) $operation['reason']);
        $audit = $this->db->fetch(
            "SELECT detail FROM auditlog WHERE action = 'ai.provider_unavailable' AND entityId = ?",
            [$reservationId],
        );
        self::assertNotNull($audit);
        self::assertStringNotContainsString('wrong-secret', (string) $audit['detail']);
        $returned = array_values(array_filter(
            $this->jobPayloads('reservation_status_changed'),
            static fn (array $payload): bool => (int) ($payload['reservationId'] ?? 0) === $reservationId,
        ));
        self::assertSame('AI provider rejected configuration; returned to pending', $returned[0]['reason'] ?? null);
        self::assertSame(0, $this->countTokens($reservationId));

        $worker->processQueuedJob($jobId);
        self::assertSame(1, (int) $this->db->fetch('SELECT attempts FROM outboxjob WHERE id = ?', [$jobId])['attempts']);
    }

    public function testOpenAiHttpErrorLogsProviderReasonWithoutCredentialsOrReservationText(): void
    {
        $base = $this->aiServer();
        $reservationId = $this->reservation();
        $jobId = $this->insertJob('ai_approval', ['reservationId' => $reservationId, 'reviewVersion' => 0]);

        $this->workerFor($base . '?want=provider-error', 0)->processQueuedJob($jobId);

        $row = $this->db->fetch("SELECT context FROM errorlog WHERE message = 'OpenAI rejected AI approval configuration' ORDER BY id DESC LIMIT 1");
        self::assertNotNull($row);
        $context = json_decode((string) $row['context'], true);
        self::assertIsArray($context);
        self::assertSame(400, $context['httpStatus'] ?? null);
        self::assertStringContainsString('invalid_request_error', (string) ($context['providerError'] ?? ''));
        self::assertStringContainsString('response_format', (string) ($context['providerError'] ?? ''));
        foreach (['test-secret', 'Study group', 'student@example.com'] as $sensitive) {
            self::assertStringNotContainsString($sensitive, (string) $row['context']);
        }
        self::assertSame('completed', $this->jobStatus($jobId));
        self::assertSame('pending', $this->db->fetch('SELECT status FROM reservation WHERE id = ?', [$reservationId])['status']);
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
    || $path !== '/chat/completions'
    || ($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer test-secret') {
    http_response_code(401);
    echo '{"error":{"type":"authentication_error","message":"POST to configured model with OpenAI API key required"}}';
    return;
}
$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)
    || !is_string($input['messages'][0]['content'] ?? null)
    || !is_string($input['messages'][1]['content'] ?? null)
    || ($input['messages'][0]['role'] ?? null) !== 'system'
    || ($input['messages'][1]['role'] ?? null) !== 'user'
    || ($input['response_format']['type'] ?? null) !== 'json_object') {
    http_response_code(400);
    echo '{"error":{"type":"invalid_request_error","message":"OpenAI request required"}}';
    return;
}
file_put_contents(__REQUEST_FILE__, json_encode(['path' => $path, 'body' => $input]));
$want = $_GET['want'] ?? 'approved';
if ($want === 'unavailable') {
    http_response_code(503);
    echo '{"error":{"type":"server_error","message":"unavailable"}}';
    return;
}
if ($want === 'provider-error') {
    http_response_code(400);
    echo json_encode(['error' => [
        'code' => 400,
        'type' => 'invalid_request_error',
        'param' => 'response_format',
        'message' => 'Unsupported response_format; test-secret Study group student@example.com',
    ]]);
    return;
}
$decision = [
    'status' => $want === 'bad-message' ? 'rejected' : ($want === 'not-stop' ? 'approved' : $want),
    'message' => $want === 'rejected' ? 'Game-related activities are not permitted.' : ($want === 'bad-message' ? 'Invented rejection.' : null),
];
echo json_encode([
    'choices' => [
        [
            'finish_reason' => $want === 'not-stop' ? 'length' : 'stop',
            'message' => ['role' => 'assistant', 'content' => json_encode($decision)],
        ],
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
                        'model' => 'gpt-4o-mini',
                        'messages' => [
                            ['role' => 'system', 'content' => 'probe prompt'],
                            ['role' => 'user', 'content' => 'probe'],
                        ],
                        'response_format' => ['type' => 'json_object'],
                    ]);
                    fwrite($socket, "POST /chat/completions?want=approved HTTP/1.0\r\nHost: 127.0.0.1\r\nAuthorization: Bearer test-secret\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
                    $response = stream_get_contents($socket);
                    fclose($socket);
                    if (is_string($response) && str_contains($response, '"choices"')) {
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
            self::fail('Local OpenAI mock returned an invalid response: ' . $lastResponse . ' Log: ' . substr((string) @file_get_contents($router . '.log'), 0, 1000));
        }
        self::markTestSkipped('Unable to start a local OpenAI mock server.');
    }
}
