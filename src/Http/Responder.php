<?php

declare(strict_types=1);

namespace Hfiuc\Http;

use Psr\Http\Message\ResponseInterface;

final class Responder
{
    public static function data(ResponseInterface $response, mixed $data, int $status = 200): ResponseInterface
    {
        return self::send($response, $status, true, $data, null);
    }

    public static function message(ResponseInterface $response, string $message, int $status = 200): ResponseInterface
    {
        return self::send($response, $status, false, null, $message);
    }

    /** @param array<string, mixed> $detail */
    public static function error(ResponseInterface $response, int $status, string $message, array $detail = []): ResponseInterface
    {
        return self::send($response, $status, false, null, $message, $detail);
    }

    private static function send(
        ResponseInterface $response,
        int $status,
        bool $includeData,
        mixed $data,
        ?string $message,
        array $detail = [],
    ): ResponseInterface {
        $body = ['success' => $status >= 200 && $status < 300];
        if ($includeData) {
            $body['data'] = $data;
        }
        if ($message !== null) {
            $body['message'] = $message;
        }
        if ($detail !== []) {
            $body['error'] = $detail;
        }
        $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            $encoded = '{"success":false,"message":"Internal server error."}';
            $status = 500;
        }
        $stream = $response->getBody();
        $stream->write($encoded);
        $stream->rewind();

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
