<?php

declare(strict_types=1);

namespace Hfiuc\Http;

use Hfiuc\Log\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RequestIdMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Logger $logger)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $incoming = $request->getHeaderLine('x-request-id');
        $requestId = preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $incoming) === 1
            ? $incoming
            : self::uuid();
        $request = $request->withAttribute('requestId', $requestId);
        $server = $request->getServerParams();
        $this->logger->setRequest(
            $requestId,
            $request->getMethod(),
            $request->getUri()->getPath(),
            isset($server['REMOTE_ADDR']) ? (string) $server['REMOTE_ADDR'] : null,
        );
        $response = $handler->handle($request);
        if (!$response->hasHeader('x-request-id')) {
            $response = $response->withHeader('x-request-id', $requestId);
        }

        return $response;
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
