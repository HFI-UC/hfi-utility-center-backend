<?php

declare(strict_types=1);

namespace Hfiuc\Auth;

use Hfiuc\Config;
use Hfiuc\Database;
use Hfiuc\Http\HttpException;
use Hfiuc\Http\Input;
use Hfiuc\Log\Logger;
use Hfiuc\Support\Clock;
use Hfiuc\Support\Token;
use PDOException;
use Psr\Http\Message\ServerRequestInterface;

final class AuthService
{
    /** @var list<string> */
    public array $setCookies = [];

    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly ?TurnstileVerifier $turnstile = null,
    ) {
    }

    public function issueCsrf(): string
    {
        $this->db->execute('DELETE FROM csrftoken WHERE expiresAt <= NOW()');
        $token = Token::random();
        $expires = Clock::sql(Clock::now()->modify('+10 minutes'));
        $this->db->execute('INSERT INTO csrftoken (token, expiresAt) VALUES (?, ?)', [$token, $expires]);

        return $token;
    }

    public function consumeCsrf(ServerRequestInterface $request): void
    {
        $token = $request->getHeaderLine('x-csrf-token');
        if ($token === '') {
            $this->logger->audit('csrf.rejected', 'csrf', null, ['reason' => 'missing']);
            throw new HttpException(403, 'CSRF token missing or invalid.');
        }
        $deleted = $this->db->execute(
            'DELETE FROM csrftoken WHERE token = ? AND expiresAt > NOW()',
            [$token],
        );
        if ($deleted !== 1) {
            $this->logger->audit('csrf.rejected', 'csrf', null, ['reason' => 'invalid']);
            throw new HttpException(403, 'CSRF token missing or invalid.');
        }
    }

    /** @return array{id: int, email: string, name: string, password: string}|null */
    public function currentAdmin(ServerRequestInterface $request): ?array
    {
        foreach ($this->cookieValues($request, 'uc') as $session) {
            $row = $this->db->fetch(
                'SELECT a.id, a.email, a.name, a.password FROM adminlogin l JOIN admin a ON a.email = l.email WHERE l.cookie = ? AND l.expiry > NOW()',
                [$session],
            );
            if ($row !== null) {
                $admin = [
                    'id' => (int) $row['id'],
                    'email' => (string) $row['email'],
                    'name' => (string) $row['name'],
                    'password' => (string) $row['password'],
                ];
                $this->logger->setAdminId($admin['id']);

                return $admin;
            }
        }

        return null;
    }

    /** @return array{id: int, email: string, name: string, password: string} */
    public function requireAdmin(ServerRequestInterface $request): array
    {
        $admin = $this->currentAdmin($request);
        if ($admin === null) {
            throw new HttpException(401, 'User is not logged in.');
        }

        return $admin;
    }

    /** @return array{id: int, email: string, name: string, password: string} */
    public function requireAdminWrite(ServerRequestInterface $request): array
    {
        $admin = $this->requireAdmin($request);
        $this->consumeCsrf($request);

        return $admin;
    }

    public function login(ServerRequestInterface $request): string
    {
        $this->setCookies = [];
        if ($this->currentAdmin($request) !== null) {
            throw new HttpException(400, 'User already logged in.');
        }
        $this->consumeCsrf($request);
        $input = new Input($this->json($request));
        $loginToken = $input->string('token', false);
        if ($loginToken !== null) {
            return $this->loginWithToken($loginToken);
        }
        $email = $input->string('email', false);
        $password = $input->string('password', false);
        if ($email === null || $password === null) {
            throw new HttpException(400, 'Email and password are required.');
        }
        $turnstile = $input->string('turnstileToken', false);
        if ($turnstile === null || !$this->verifyTurnstile($turnstile)) {
            $this->logger->audit('admin.login.failure', 'admin', null, ['email' => $email, 'reason' => 'turnstile']);
            throw new HttpException(403, 'Turnstile verification failed.');
        }
        $admin = $this->db->fetch('SELECT id, email, name, password FROM admin WHERE email = ?', [$email]);
        if ($admin === null || !password_verify($password, (string) $admin['password'])) {
            $this->logger->audit('admin.login.failure', 'admin', null, ['email' => $email, 'reason' => 'credentials']);
            throw new HttpException(401, 'Invalid email or password.');
        }
        $session = $this->createSession((string) $admin['email']);
        $this->logger->setAdminId((int) $admin['id']);
        $this->logger->audit('admin.login.success', 'admin', (int) $admin['id'], ['email' => $admin['email']]);

        return $session;
    }

    public function logout(ServerRequestInterface $request): void
    {
        $this->setCookies = [];
        $sessions = $this->cookieValues($request, 'uc');
        if ($sessions === []) {
            throw new HttpException(401, 'User is not logged in.');
        }
        $placeholders = implode(',', array_fill(0, count($sessions), '?'));
        $deleted = $this->db->execute(
            "DELETE FROM adminlogin WHERE cookie IN ($placeholders)",
            $sessions,
        );
        if ($deleted === 0) {
            throw new HttpException(401, 'User is not logged in.');
        }
        $this->clearSessionCookies();
        $this->logger->audit('admin.logout', 'admin', null);
    }

    /** @return array{email: string, name: string} */
    public function check(ServerRequestInterface $request): array
    {
        $admin = $this->currentAdmin($request);
        if ($admin === null) {
            throw new HttpException(400, 'User is not logged in.');
        }

        return ['email' => $admin['email'], 'name' => $admin['name']];
    }

    /** @return list<string> */
    public function cookieValues(ServerRequestInterface $request, string $name): array
    {
        $values = [];
        foreach ($request->getHeader('Cookie') as $header) {
            foreach (explode(';', $header) as $part) {
                $pieces = explode('=', trim($part), 2);
                if (count($pieces) === 2 && $pieces[0] === $name && $pieces[1] !== '') {
                    $values[] = $pieces[1];
                }
            }
        }

        return $values;
    }

    private function loginWithToken(string $loginToken): string
    {
        try {
            return $this->db->transaction(function () use ($loginToken): string {
                $temp = $this->db->fetch(
                    'SELECT id, email FROM tempadminlogin WHERE token = ? AND createdAt > DATE_SUB(NOW(), INTERVAL 15 MINUTE) FOR UPDATE',
                    [$loginToken],
                );
                if ($temp === null) {
                    throw new HttpException(400, 'Invalid token or token expired.');
                }
                $session = Token::random();
                $expiry = Clock::sql(Clock::now()->modify('+1 hour'));
                $this->db->execute(
                    'INSERT INTO adminlogin (email, cookie, expiry) VALUES (?, ?, ?)',
                    [$temp['email'], $session, $expiry],
                );
                $deleted = $this->db->execute('DELETE FROM tempadminlogin WHERE id = ?', [(int) $temp['id']]);
                if ($deleted !== 1) {
                    throw new HttpException(400, 'Invalid token or token expired.');
                }
                $this->rememberSession($session);
                $this->logger->audit('admin.login.success', 'admin', null, ['email' => $temp['email'], 'method' => 'token']);

                return $session;
            });
        } catch (HttpException $error) {
            if ($error->status === 400) {
                $this->logger->audit('admin.login.failure', 'admin', null, ['reason' => 'token']);
            }
            throw $error;
        }
    }

    private function createSession(string $email): string
    {
        $session = Token::random();
        $expiry = Clock::sql(Clock::now()->modify('+1 hour'));
        try {
            $this->db->execute(
                'INSERT INTO adminlogin (email, cookie, expiry) VALUES (?, ?, ?)',
                [$email, $session, $expiry],
            );
        } catch (PDOException $error) {
            $this->logger->error('Unable to create session', ['error' => $error->getMessage()]);
            throw new HttpException(500, 'Unable to create session');
        }
        $this->rememberSession($session);

        return $session;
    }

    private function rememberSession(string $session): void
    {
        $this->setCookies = [
            'uc=; Path=/; Max-Age=0; HttpOnly; Secure; SameSite=None; Partitioned',
            sprintf(
                'uc=%s; Path=/; HttpOnly%s; SameSite=Lax',
                $session,
                $this->config->cookieSecure ? '; Secure' : '',
            ),
        ];
    }

    private function clearSessionCookies(): void
    {
        $this->setCookies = [
            'uc=; Path=/; Max-Age=0; HttpOnly; Secure; SameSite=Lax',
            'uc=; Path=/; Max-Age=0; HttpOnly; Secure; SameSite=None; Partitioned',
        ];
    }

    private function verifyTurnstile(string $token): bool
    {
        $verifier = $this->turnstile ?? new CloudflareTurnstile($this->config, $this->logger);

        return $verifier->verify($token);
    }

    /** @return array<string, mixed> */
    private function json(ServerRequestInterface $request): array
    {
        $data = $request->getAttribute('json');

        return is_array($data) ? $data : [];
    }
}
