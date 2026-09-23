<?php

declare(strict_types=1);

namespace Hfiuc\Support;

final class Clock
{
    public static function zone(): \DateTimeZone
    {
        return new \DateTimeZone('Asia/Shanghai');
    }

    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', self::zone());
    }

    public static function fromUnix(int $epoch): ?\DateTimeImmutable
    {
        $utc = \DateTimeImmutable::createFromFormat('U', (string) $epoch, new \DateTimeZone('UTC'));
        if ($utc === false) {
            return null;
        }

        return $utc->setTimezone(self::zone());
    }

    public static function api(\DateTimeImmutable $value): string
    {
        return $value->setTimezone(self::zone())->format('Y-m-d\TH:i:s');
    }

    public static function sql(\DateTimeImmutable $value): string
    {
        return $value->setTimezone(self::zone())->format('Y-m-d H:i:s');
    }

    public static function fromSql(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr(str_replace(' ', 'T', $value), 0, 19);
    }

    public static function parseSql(string $value): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr($value, 0, 19), self::zone());
        if ($parsed === false) {
            throw new \RuntimeException('Invalid stored datetime.');
        }

        return $parsed;
    }
}
