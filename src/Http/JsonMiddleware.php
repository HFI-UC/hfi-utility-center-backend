<?php

declare(strict_types=1);

namespace Hfiuc\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class JsonMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return $handler->handle($request);
        }
        $contentType = strtolower($request->getHeaderLine('Content-Type'));
        if (!str_contains($contentType, 'application/json')) {
            return Responder::error(new Response(), 415, 'Content-Type must be application/json.');
        }
        $raw = (string) $request->getBody();
        if (trim($raw) === '') {
            return $handler->handle($request->withAttribute('json', []));
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return Responder::error(new Response(), 400, 'Invalid JSON.');
        }
        if (!is_array($decoded)) {
            return Responder::error(new Response(), 400, 'Invalid JSON.');
        }

        return $handler->handle($request->withAttribute('json', $decoded));
    }
}
