<?php

declare(strict_types=1);

namespace Hfiuc\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class JsonMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return $handler->handle($request);
        }
        $contentType = strtolower($request->getHeaderLine('Content-Type'));
        if (!str_contains($contentType, 'application/json')) {
            throw new HttpException(415, 'Content-Type must be application/json.', [
                'contentType' => $contentType,
            ]);
        }
        $raw = (string) $request->getBody();
        if (trim($raw) === '') {
            return $handler->handle($request->withAttribute('json', []));
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new HttpException(400, 'Invalid JSON.', ['reason' => $error->getMessage()], $error);
        }
        if (!is_array($decoded)) {
            throw new HttpException(400, 'Invalid JSON.', ['reason' => 'JSON body must be an object.']);
        }

        return $handler->handle($request->withAttribute('json', $decoded));
    }
}
