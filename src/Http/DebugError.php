<?php

declare(strict_types=1);

namespace Hfiuc\Http;

final class DebugError
{
    /** @return array<string, mixed> */
    public static function payload(\Throwable $error): array
    {
        $payload = [
            'type' => $error::class,
            'message' => $error->getMessage(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'trace' => self::frames($error),
        ];
        if ($error instanceof HttpException) {
            $payload['status'] = $error->status;
            if ($error->detail !== []) {
                $payload['detail'] = $error->detail;
            }
        }
        $previous = $error->getPrevious();
        if ($previous !== null) {
            $payload['previous'] = self::payload($previous);
        }

        return $payload;
    }

    /** @return list<string> */
    private static function frames(\Throwable $error): array
    {
        $frames = [];
        foreach ($error->getTrace() as $frame) {
            $function = (string) ($frame['function'] ?? '');
            $class = (string) ($frame['class'] ?? '');
            $type = (string) ($frame['type'] ?? '');
            $frames[] = ($frame['file'] ?? 'unknown') . ':' . (string) ($frame['line'] ?? 0) . ' ' . $class . $type . $function;
            if (count($frames) >= 12) {
                break;
            }
        }

        return $frames;
    }
}
