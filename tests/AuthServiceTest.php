<?php

declare(strict_types=1);

namespace Hfiuc\Tests;

use Hfiuc\Auth\AuthService;
use Hfiuc\Support\Clock;
use Hfiuc\Tests\Support\DatabaseTestCase;

final class AuthServiceTest extends DatabaseTestCase
{
    public function testCsrfTokensAreSingleUseAndExpire(): void
    {
        $token = $this->auth->issueCsrf();
        $request = $this->request('POST', '/reservation/create', [], [], ['x-csrf-token' => $token]);
        $this->auth->consumeCsrf($request);
        $this->expectHttp(
            fn () => $this->auth->consumeCsrf($request),
            403,
            'CSRF token missing or invalid.',
        );
        self::assertSame('invalid', json_decode((string) $this->db->fetch('SELECT detail FROM auditlog WHERE action = ? ORDER BY id DESC', ['csrf.rejected'])['detail'], true)['reason']);
        $this->expectHttp(
            fn () => $this->auth->consumeCsrf($this->request('POST', '/reservation/create')),
            403,
            'CSRF token missing or invalid.',
        );
        self::assertSame('missing', json_decode((string) $this->db->fetch('SELECT detail FROM auditlog WHERE action = ? ORDER BY id DESC', ['csrf.rejected'])['detail'], true)['reason']);

        $this->db->execute('INSERT INTO csrftoken (token, expiresAt) VALUES (?, ?)', ['expired-token', Clock::sql(Clock::now()->modify('-1 minute'))]);
        $this->auth->issueCsrf();
        self::assertNull($this->db->fetch('SELECT token FROM csrftoken WHERE token = ?', ['expired-token']));
        $this->db->execute('INSERT INTO csrftoken (token, expiresAt) VALUES (?, ?)', ['still-expired', Clock::sql(Clock::now()->modify('-1 minute'))]);
        $this->expectHttp(
            fn () => $this->auth->consumeCsrf($this->request('POST', '/', [], [], ['x-csrf-token' => 'still-expired'])),
            403,
            'CSRF token missing or invalid.',
        );
    }

    public function testTokenLoginCreatesASessionAndLogoutRemovesIt(): void
    {
        $this->insertAdmin('ada@example.com', 'Ada');
        $this->db->execute(
            'INSERT INTO tempadminlogin (token, email, createdAt) VALUES (?, ?, ?)',
            ['login-token', 'ada@example.com', Clock::sql(Clock::now()->modify('-1 minute'))],
        );
        $csrf = $this->csrf();
        $session = $this->auth->login($this->request('POST', '/admin/login', ['token' => 'login-token'], [], ['x-csrf-token' => $csrf]));
        self::assertNotSame('', $session);
        self::assertNull($this->db->fetch('SELECT id FROM tempadminlogin WHERE token = ?', ['login-token']));
        self::assertNotNull($this->db->fetch('SELECT id FROM adminlogin WHERE cookie = ? AND email = ?', [$session, 'ada@example.com']));
        self::assertSame(
            ['email' => 'ada@example.com', 'name' => 'Ada'],
            $this->auth->check($this->request('GET', '/admin/check', [], [], ['Cookie' => 'uc=' . $session])),
        );
        self::assertStringContainsString('uc=' . $session, $this->auth->setCookies[1]);

        $nextCsrf = $this->csrf();
        $this->expectHttp(
            fn () => $this->auth->login($this->request('POST', '/admin/login', ['token' => 'other'], [], [
                'Cookie' => 'uc=' . $session,
                'x-csrf-token' => $nextCsrf,
            ])),
            400,
            'User already logged in.',
        );
        self::assertNotNull($this->db->fetch('SELECT token FROM csrftoken WHERE token = ?', [$nextCsrf]));

        $this->auth->logout($this->request('GET', '/admin/logout', [], [], ['Cookie' => 'uc=' . $session]));
        self::assertNull($this->db->fetch('SELECT id FROM adminlogin WHERE cookie = ?', [$session]));
        $this->expectHttp(
            fn () => $this->auth->logout($this->request('GET', '/admin/logout', [], [], ['Cookie' => 'uc=' . $session])),
            401,
            'User is not logged in.',
        );
    }

    public function testExpiredTokenAndSessionAreRejected(): void
    {
        $this->insertAdmin('ada@example.com', 'Ada');
        $this->db->execute(
            'INSERT INTO tempadminlogin (token, email, createdAt) VALUES (?, ?, ?)',
            ['old-token', 'ada@example.com', Clock::sql(Clock::now()->modify('-16 minutes'))],
        );
        $this->expectHttp(
            fn () => $this->auth->login($this->request('POST', '/admin/login', ['token' => 'old-token'], [], ['x-csrf-token' => $this->csrf()])),
            400,
            'Invalid token or token expired.',
        );
        $this->db->execute(
            'INSERT INTO adminlogin (email, cookie, expiry) VALUES (?, ?, ?)',
            ['ada@example.com', 'expired-session', Clock::sql(Clock::now()->modify('-1 minute'))],
        );
        self::assertNull($this->auth->currentAdmin($this->request('GET', '/', [], [], ['Cookie' => 'uc=expired-session'])));
        $this->expectHttp(
            fn () => $this->auth->check($this->request('GET', '/admin/check', [], [], ['Cookie' => 'uc=expired-session'])),
            400,
            'User is not logged in.',
        );
    }

    public function testPasswordLoginChecksTurnstileBeforeCredentials(): void
    {
        $this->insertAdmin('ada@example.com', 'Ada', false, 'secret');
        $plain = new AuthService($this->db, $this->config, $this->logger);
        $this->expectHttp(
            fn () => $plain->login($this->request('POST', '/admin/login', [
                'email' => 'ada@example.com',
                'password' => 'secret',
                'turnstileToken' => 'pass',
            ], [], ['x-csrf-token' => $this->csrf()])),
            403,
            'Turnstile verification failed.',
        );
        self::assertSame('turnstile', json_decode((string) $this->db->fetch('SELECT detail FROM auditlog WHERE action = ? ORDER BY id DESC', ['admin.login.failure'])['detail'], true)['reason']);

        $this->expectHttp(
            fn () => $this->auth->login($this->request('POST', '/admin/login', [
                'email' => 'ada@example.com',
                'password' => 'secret',
                'turnstileToken' => 'nope',
            ], [], ['x-csrf-token' => $this->csrf()])),
            403,
            'Turnstile verification failed.',
        );
        $this->expectHttp(
            fn () => $this->auth->login($this->request('POST', '/admin/login', [
                'email' => 'ada@example.com',
                'password' => 'wrong-password',
                'turnstileToken' => 'pass',
            ], [], ['x-csrf-token' => $this->csrf()])),
            401,
            'Invalid email or password.',
        );
        $this->expectHttp(
            fn () => $this->auth->login($this->request('POST', '/admin/login', [], [], ['x-csrf-token' => $this->csrf()])),
            400,
            'Email and password are required.',
        );
        $session = $this->auth->login($this->request('POST', '/admin/login', [
            'email' => 'ada@example.com',
            'password' => 'secret',
            'turnstileToken' => 'pass',
        ], [], ['x-csrf-token' => $this->csrf()]));
        self::assertSame('ada@example.com', $this->auth->currentAdmin($this->request('GET', '/', [], [], ['Cookie' => 'uc=' . $session]))['email']);
        self::assertNotNull($this->db->fetch('SELECT id FROM auditlog WHERE action = ?', ['admin.login.success']));
    }
}
