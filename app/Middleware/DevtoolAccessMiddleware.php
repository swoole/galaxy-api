<?php

namespace App\Middleware;

use App\Exception\UnauthorizedException;
use Hyperf\Context\Context;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class DevtoolAccessMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (! (bool) config('devtools.access_enabled', false) || ! Context::has('devtool_access')) {
            throw new UnauthorizedException('该接口只接受已启用的 Devtool Access Token');
        }
        return $handler->handle($request);
    }
}
