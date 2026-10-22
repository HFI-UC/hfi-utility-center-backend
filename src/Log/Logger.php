<?php

declare(strict_types=1);

namespace Hfiuc\Log;

use Hfiuc\Database;
use Hfiuc\Support\Clock;

final class Logger
{
    private ?string $requestId = null;

    private ?string $method = null;

    private ?string $path = null;

    private ?string $ip = null;

    private ?int $adminId = null;

    public function __construct(private readonly Database $db)
    {
    }

    public function setRequest(?string $requestId, ?string $method, ?string $path, ?string $ip): void
    {
        $this->requestId = $requestId;
        $this->method = $method;
        $this->path = $path;
        $this->ip = $ip;
    }

    public function setAdminId(?int $adminId): void
    {
        $this->adminId = $adminId;
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = [], string $level = 'error'): void
    {
        $this->write('errorlog', $level, $message, $context);
    }

    /** @param array<string, mixed> $detail */
    public function audit(string $action, string $entity, int|string|null $entityId = null, array $detail = []): void
    {
        try {
            $this->db->execute(
                'INSERT INTO auditlog (adminId, action, entity, entityId, detail, requestId, ip, createdAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $this->adminId,
                    $action,
                    $entity,
                    $entityId === null ? null : (string) $entityId,
                    $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $this->requestId,
                    $this->ip,
                    Clock::sql(Clock::now()),
                ],
            );
        } catch (\Throwable $error) {
            error_log('audit log failed: ' . $error->getMessage() . ' action=' . $action);
        }
    }

    /** @param array<string, mixed> $context */
    private function write(string $table, string $level, string $message, array $context): void
    {
        try {
            $this->db->execute(
                'INSERT INTO errorlog (level, message, context, method, path, requestId, createdAt) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $level,
                    $message,
                    $context === [] ? null : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $this->method,
                    $this->path === null ? null : substr($this->path, 0, 191),
                    $this->requestId,
                    Clock::sql(Clock::now()),
                ],
            );
        } catch (\Throwable $error) {
            error_log('error log failed: ' . $error->getMessage() . ' original=' . $message);
        }
    }
}
