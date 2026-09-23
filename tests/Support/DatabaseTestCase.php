<?php

declare(strict_types=1);

namespace Hfiuc\Tests\Support;

use Hfiuc\Analytics\AnalyticsService;
use Hfiuc\Announcement\AnnouncementService;
use Hfiuc\Auth\AuthService;
use Hfiuc\Catalog\CatalogService;
use Hfiuc\Config;
use Hfiuc\Database;
use Hfiuc\Http\HttpException;
use Hfiuc\Log\Logger;
use Hfiuc\Reservation\ReservationService;
use Hfiuc\Support\Clock;
use Hfiuc\Support\Token;
use Hfiuc\Worker\Outbox;
use Hfiuc\Worker\OutboxWorker;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

abstract class DatabaseTestCase extends TestCase
{
    private const TABLES = [
        'reservationoperationlog',
        'reservationcanceltoken',
        'reservation',
        'roomapprover',
        'roompolicy',
        'adminlogin',
        'tempadminlogin',
        'outboxjob',
        'class',
        'room',
        'campus',
        'admin',
        'announcement',
        'analytic',
        'csrftoken',
        'catalogcache',
        'errorlog',
        'auditlog',
    ];

    protected static ?Database $shared = null;

    protected Database $db;

    protected Config $config;

    protected Logger $logger;

    protected RecordingQueue $queue;

    protected Outbox $outbox;

    protected AuthService $auth;

    protected ReservationService $reservations;

    protected CatalogService $catalog;

    protected AnnouncementService $announcements;

    protected AnalyticsService $analytics;

    protected OutboxWorker $worker;

    public static function setUpBeforeClass(): void
    {
        if (self::$shared !== null) {
            return;
        }
        $config = self::configFromEnv();
        self::$shared = new Database($config);
        $pdo = self::$shared->pdo();
        $current = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($current !== 'uc_test') {
            throw new \RuntimeException('Refusing to run database tests against ' . $current);
        }
        self::applySchema($pdo);
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$shared === null) {
            self::markTestSkipped('Database tests were not initialized.');
        }
        $this->db = self::$shared;
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $this->truncate();
        $this->boot();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        parent::tearDown();
    }

    protected function boot(): void
    {
        $this->config = self::configFromEnv();
        $this->logger = new Logger($this->db);
        $this->queue = new RecordingQueue();
        $this->outbox = new Outbox($this->db, $this->queue);
        $this->auth = new AuthService($this->db, $this->config, $this->logger, new FakeTurnstile());
        $this->reservations = new ReservationService($this->db, $this->auth, $this->config, $this->logger, $this->outbox);
        $this->catalog = new CatalogService($this->db, $this->auth, $this->logger);
        $this->announcements = new AnnouncementService($this->db, $this->auth, $this->logger);
        $this->analytics = new AnalyticsService($this->db, $this->auth);
        $this->worker = new OutboxWorker($this->db, $this->config, $this->logger, $this->outbox);
    }

    protected function makeConfig(bool $aiEnabled = false, string $aiUrl = '', int $aiAdminId = 0): Config
    {
        return self::configFromEnv($aiEnabled, $aiUrl, $aiAdminId);
    }

    protected function truncate(): void
    {
        $pdo = $this->db->pdo();
        $current = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($current !== 'uc_test') {
            throw new \RuntimeException('Refusing to truncate database ' . $current);
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (self::TABLES as $table) {
            $pdo->exec('DELETE FROM `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    /** @param array<string, mixed> $json
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     */
    protected function request(string $method, string $path, array $json = [], array $query = [], array $headers = []): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, 'http://localhost' . $path)
            ->withQueryParams($query)
            ->withAttribute('json', $json);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    protected function csrf(): string
    {
        return $this->auth->issueCsrf();
    }

    protected function session(string $email): string
    {
        $token = 'session-' . bin2hex(random_bytes(8));
        $this->db->execute(
            'INSERT INTO adminlogin (email, cookie, expiry) VALUES (?, ?, ?)',
            [$email, $token, Clock::sql(Clock::now()->modify('+1 hour'))],
        );

        return $token;
    }

    /** @param array<string, mixed> $body
     * @param array<string, mixed> $query
     */
    protected function asAdmin(string $email, array $body = [], array $query = [], bool $csrf = true): ServerRequestInterface
    {
        $headers = ['Cookie' => 'uc=' . $this->session($email)];
        if ($csrf) {
            $headers['x-csrf-token'] = $this->csrf();
        }

        return $this->request('POST', '/', $body, $query, $headers);
    }

    /** @param array<string, mixed> $query */
    protected function asAdminRead(string $email, array $query = []): ServerRequestInterface
    {
        return $this->request('GET', '/', [], $query, ['Cookie' => 'uc=' . $this->session($email)]);
    }

    protected function expectHttp(callable $action, int $status, string $message): void
    {
        try {
            $action();
            self::fail('Expected HTTP ' . $status . ': ' . $message);
        } catch (HttpException $error) {
            self::assertSame($status, $error->status);
            self::assertSame($message, $error->getMessage());
        }
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} */
    protected function slot(int $daysAhead, int $hour, int $minute = 0, int $durationMinutes = 60): array
    {
        $start = Clock::now()->modify('+' . $daysAhead . ' days')->setTime($hour, $minute, 0);

        return [$start, $start->modify('+' . $durationMinutes . ' minutes')];
    }

    protected function insertCampus(string $name, bool $privileged = false): int
    {
        $this->db->execute('INSERT INTO campus (name, isPrivileged) VALUES (?, ?)', [$name, $privileged ? 1 : 0]);

        return $this->db->lastInsertId();
    }

    protected function insertClass(string $name, int $campusId): int
    {
        $this->db->execute('INSERT INTO class (name, campusId) VALUES (?, ?)', [$name, $campusId]);

        return $this->db->lastInsertId();
    }

    protected function insertRoom(string $name, int $campusId, ?int $enabled = 1): int
    {
        $this->db->execute('INSERT INTO room (name, campusId, enabled) VALUES (?, ?, ?)', [$name, $campusId, $enabled]);

        return $this->db->lastInsertId();
    }

    /** @param list<int> $days
     * @param list<int> $start
     * @param list<int> $end
     */
    protected function insertPolicy(int $roomId, array $days = [0, 1, 2, 3, 4, 5, 6], array $start = [8, 0], array $end = [21, 30]): int
    {
        $this->db->execute(
            'INSERT INTO roompolicy (roomId, days, startTime, endTime, enabled) VALUES (?, ?, ?, ?, 1)',
            [$roomId, json_encode($days), json_encode($start), json_encode($end)],
        );

        return $this->db->lastInsertId();
    }

    protected function insertAdmin(string $email, string $name, bool $notify = false, string $password = 'secret'): int
    {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);
        self::assertNotFalse($hash);
        $this->db->execute(
            'INSERT INTO admin (name, email, password, receiveReservationNotifications) VALUES (?, ?, ?, ?)',
            [$name, $email, $hash, $notify ? 1 : 0],
        );

        return $this->db->lastInsertId();
    }

    protected function assignRoom(int $roomId, int $adminId): void
    {
        $this->db->execute('INSERT INTO roomapprover (roomId, adminId) VALUES (?, ?)', [$roomId, $adminId]);
    }

    protected function insertReservation(
        int $roomId,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        string $email,
        string $status = 'pending',
        ?int $classId = null,
        string $studentId = 'GJ20240001',
        string $name = 'Li Lei',
        string $reason = 'Study group',
        ?string $purpose = 'personal',
        bool $multimedia = false,
        ?\DateTimeImmutable $createdAt = null,
        ?int $executorId = null,
        int $editCount = 0,
    ): int {
        $this->db->execute(
            'INSERT INTO reservation (roomId, classId, startTime, endTime, studentName, studentId, email, reason, status, purposeType, needsMultimedia, editCount, latestExecutorId, createdAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $roomId,
                $classId,
                Clock::sql($start),
                Clock::sql($end),
                $name,
                $studentId,
                $email,
                $reason,
                $status,
                $purpose,
                $multimedia ? 1 : 0,
                $editCount,
                $executorId,
                Clock::sql($createdAt ?? Clock::now()),
            ],
        );

        return $this->db->lastInsertId();
    }

    protected function insertToken(int $reservationId, string $raw, \DateTimeImmutable $expires): void
    {
        $this->db->execute(
            'INSERT INTO reservationcanceltoken (reservationId, tokenHash, expiresAt) VALUES (?, ?, ?)',
            [$reservationId, Token::hash($raw), Clock::sql($expires)],
        );
    }

    /** @return list<array<string, mixed>> */
    protected function jobPayloads(string $kind): array
    {
        $rows = $this->db->fetchAll('SELECT payload FROM outboxjob WHERE kind = ? ORDER BY id', [$kind]);
        $payloads = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['payload'], true);
            $payloads[] = is_array($decoded) ? $decoded : [];
        }

        return $payloads;
    }

    /** @param array<string, mixed> $json
     * @param array<string, string> $headers
     */
    protected function createReservation(array $json, array $headers): array
    {
        return $this->reservations->create($this->request('POST', '/reservation/create', $json, [], $headers));
    }

    private static function configFromEnv(bool $aiEnabled = false, string $aiUrl = '', int $aiAdminId = 0): Config
    {
        $values = [];
        foreach (['TEST_DB_HOST', 'TEST_DB_PORT', 'TEST_DB_NAME', 'TEST_DB_USER', 'TEST_DB_PASSWORD'] as $key) {
            $value = $_ENV[$key] ?? getenv($key);
            if ($value === false || $value === null || $value === '') {
                self::markTestSkipped('Copy tests/.env.example to tests/.env before running database tests.');
            }
            $values[$key] = (string) $value;
        }
        if ($values['TEST_DB_NAME'] !== 'uc_test') {
            throw new \RuntimeException('Database tests refuse to use ' . $values['TEST_DB_NAME']);
        }

        return new Config(
            $values['TEST_DB_HOST'],
            (int) $values['TEST_DB_PORT'],
            $values['TEST_DB_NAME'],
            $values['TEST_DB_USER'],
            $values['TEST_DB_PASSWORD'],
            'https://www.hfiuc.org',
            '',
            587,
            '',
            '',
            '',
            true,
            $aiEnabled,
            $aiUrl,
            'test-secret',
            $aiAdminId,
            false,
            ['https://www.hfiuc.org'],
            '',
            '',
            '',
            '',
            false,
        );
    }

    private static function applySchema(\PDO $pdo): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/sql/001_schema.sql');
        $stripped = preg_replace('/^--.*$/m', '', $sql);
        foreach (explode(';', $stripped ?? $sql) as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            $pdo->exec($statement);
        }
    }
}
