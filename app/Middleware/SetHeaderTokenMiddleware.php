<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Middleware;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class SetHeaderTokenMiddleware implements MiddlewareInterface
{
    /**
     * @var ContainerInterface
     */
    protected $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        try {
            $contentsJson = $response->getBody()->getContents();
            $contents = json_decode($contentsJson, true);
            $token = $contents['data']['token'] ?? '';
            if ($token) {
                return $response->withHeader('authorization', 'bearer ' . $token);
            }
            return $response;
        } catch (\RuntimeException $e) {
            return $response;
        }
    }
}
