<?php

declare(strict_types=1);

namespace Hfiuc\Announcement;

use Hfiuc\Auth\AuthService;
use Hfiuc\Database;
use Hfiuc\Http\HttpException;
use Hfiuc\Http\Input;
use Hfiuc\Log\Logger;
use Hfiuc\Support\Clock;
use Psr\Http\Message\ServerRequestInterface;

final class AnnouncementService
{
    public function __construct(
        private readonly Database $db,
        private readonly AuthService $auth,
        private readonly Logger $logger,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function current(): ?array
    {
        $row = $this->load();
        if ($row === null || !self::flag($row['enabled']) || trim((string) $row['content']) === '') {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'content' => (string) $row['content'],
            'enabled' => true,
            'updatedAt' => Clock::fromSql((string) $row['updatedAt']),
        ];
    }

    /** @return array<string, mixed> */
    public function admin(ServerRequestInterface $request): array
    {
        $this->auth->requireAdmin($request);
        $row = $this->load();
        if ($row === null) {
            return ['id' => null, 'title' => '', 'content' => '', 'enabled' => false, 'updatedAt' => null];
        }

        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'content' => (string) $row['content'],
            'enabled' => self::flag($row['enabled']),
            'updatedAt' => Clock::fromSql((string) $row['updatedAt']),
        ];
    }

    public function update(ServerRequestInterface $request): void
    {
        $admin = $this->auth->requireAdmin($request);
        $this->auth->consumeCsrf($request);
        $input = new Input($this->json($request));
        $title = trim((string) $input->string('title'));
        $content = trim((string) $input->string('content'));
        $enabled = $input->bool('enabled');
        if (strlen($title) > 120 || strlen($content) > 4000 || ($enabled && $content === '')) {
            throw new HttpException(400, 'Invalid announcement content.');
        }
        $this->db->execute(
            'INSERT INTO announcement (id, title, content, enabled, updatedAt, updatedBy) VALUES (1, ?, ?, ?, NOW(), ?) ON DUPLICATE KEY UPDATE title = VALUES(title), content = VALUES(content), enabled = VALUES(enabled), updatedAt = NOW(), updatedBy = VALUES(updatedBy)',
            [$title, $content, $enabled ? 1 : 0, $admin['id']],
        );
        $this->logger->audit('announcement.update', 'announcement', 1, ['enabled' => $enabled, 'title' => $title]);
    }

    /** @return array<string, mixed>|null */
    private function load(): ?array
    {
        return $this->db->fetch('SELECT id, title, content, enabled, updatedAt FROM announcement ORDER BY id LIMIT 1');
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
