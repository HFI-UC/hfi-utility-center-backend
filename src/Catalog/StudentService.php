<?php

declare(strict_types=1);

namespace Hfiuc\Catalog;

use Hfiuc\Auth\AuthService;
use Hfiuc\Database;
use Hfiuc\Http\HttpException;
use Hfiuc\Http\Input;
use Hfiuc\Log\Logger;
use PDOException;
use Psr\Http\Message\ServerRequestInterface;

final class StudentService
{
    public function __construct(
        private readonly Database $db,
        private readonly AuthService $auth,
        private readonly Logger $logger,
    ) {
    }

    /** @return list<array{email: string, name: string, classId: ?int, className: ?string}> */
    public function list(ServerRequestInterface $request): array
    {
        $this->requireGlobal($request);
        $rows = $this->db->fetchAll('SELECT s.email, s.name, s.classId, c.name AS className FROM student s LEFT JOIN class c ON c.id = s.classId ORDER BY s.email');

        return array_map(static fn (array $row): array => [
            'email' => (string) $row['email'],
            'name' => (string) $row['name'],
            'classId' => $row['classId'] === null ? null : (int) $row['classId'],
            'className' => $row['className'] === null ? null : (string) $row['className'],
        ], $rows);
    }

    public function create(ServerRequestInterface $request): void
    {
        $actor = $this->requireGlobalWrite($request);
        $input = new Input($this->json($request));
        [$email, $name, $classId] = $this->fields($input);
        try {
            $this->db->execute('INSERT INTO student (email, name, classId) VALUES (?, ?, ?)', [$email, $name, $classId]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                throw new HttpException(409, 'Student email already exists.', ['field' => 'email'], $error);
            }
            throw $error;
        }
        $this->logger->setAdminId($actor['id']);
        $this->logger->audit('student.create', 'student', null, ['email' => $email, 'name' => $name, 'classId' => $classId]);
    }

    public function edit(ServerRequestInterface $request): void
    {
        $actor = $this->requireGlobalWrite($request);
        $input = new Input($this->json($request));
        [$email, $name, $classId] = $this->fields($input);
        $previous = $this->db->fetch('SELECT name, classId FROM student WHERE email = ?', [$email]);
        if ($previous === null) {
            throw new HttpException(404, 'Student email not found.', ['field' => 'email']);
        }
        $this->db->execute('UPDATE student SET name = ?, classId = ? WHERE email = ?', [$name, $classId, $email]);
        $this->logger->setAdminId($actor['id']);
        $this->logger->audit('student.edit', 'student', null, ['email' => $email, 'previousName' => (string) $previous['name'], 'previousClassId' => $previous['classId'] === null ? null : (int) $previous['classId'], 'name' => $name, 'classId' => $classId]);
    }

    public function delete(ServerRequestInterface $request): void
    {
        $actor = $this->requireGlobalWrite($request);
        $email = $this->validEmail((string) (new Input($this->json($request)))->string('email'));
        $previous = $this->db->fetch('SELECT name, classId FROM student WHERE email = ?', [$email]);
        if ($previous === null) {
            throw new HttpException(404, 'Student email not found.', ['field' => 'email']);
        }
        if ($this->db->execute('DELETE FROM student WHERE email = ?', [$email]) === 0) {
            throw new HttpException(404, 'Student email not found.', ['field' => 'email']);
        }
        $this->logger->setAdminId($actor['id']);
        $this->logger->audit('student.delete', 'student', null, ['email' => $email, 'name' => (string) $previous['name'], 'classId' => $previous['classId'] === null ? null : (int) $previous['classId']]);
    }

    /** @return array{0: string, 1: string, 2: ?int} */
    private function fields(Input $input): array
    {
        $email = $this->validEmail((string) $input->string('email'));
        $name = trim((string) $input->string('name'));
        $classId = $input->int('classId', false);
        $nameLength = preg_match_all('/./u', $name);
        if ($name === '' || $nameLength === false || $nameLength > 191) {
            throw new HttpException(400, 'Invalid student name.', ['field' => 'name']);
        }
        if ($classId !== null && $this->db->fetch('SELECT c.id FROM class c LEFT JOIN campus cp ON cp.id = c.campusId WHERE c.id = ? AND c.deletedAt IS NULL AND (c.campusId IS NULL OR cp.deletedAt IS NULL)', [$classId]) === null) {
            throw new HttpException(400, 'Class not found.', ['field' => 'classId']);
        }

        return [$email, $name, $classId];
    }

    private function validEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if (strlen($email) > 191 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new HttpException(400, 'Invalid email format.', ['field' => 'email']);
        }

        return $email;
    }

    /** @return array{id: int, email: string, name: string, password: string, role: string} */
    private function requireGlobal(ServerRequestInterface $request): array
    {
        $actor = $this->auth->requireAdmin($request);
        if ($actor['role'] !== 'global') {
            throw new HttpException(403, 'Global administrator required.');
        }

        return $actor;
    }

    /** @return array{id: int, email: string, name: string, password: string, role: string} */
    private function requireGlobalWrite(ServerRequestInterface $request): array
    {
        $actor = $this->auth->requireAdminWrite($request);
        if ($actor['role'] !== 'global') {
            throw new HttpException(403, 'Global administrator required.');
        }

        return $actor;
    }

    /** @return array<string, mixed> */
    private function json(ServerRequestInterface $request): array
    {
        $body = $request->getAttribute('json');

        return is_array($body) ? $body : [];
    }
}
