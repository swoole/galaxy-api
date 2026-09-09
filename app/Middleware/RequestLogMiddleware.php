<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Middleware;

use App\Exception\Handler\AppExceptionHandler;
use GuzzleHttp\MessageFormatter;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Context\Context;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class RequestLogMiddleware implements MiddlewareInterface
{
    /**
     * @var LoggerInterface
     */
    protected $logger;

    protected AppExceptionHandler $appExceptionHandler;

    public function __construct(LoggerFactory $loggerFactory, AppExceptionHandler $appExceptionHandler)
    {
        $this->logger = $loggerFactory->get('middleware', 'request');
        $this->appExceptionHandler = $appExceptionHandler;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $response = $handler->handle($request);
        } catch (Throwable $e) {
            $response = $this->appExceptionHandler->handle($e, Context::get(ResponseInterface::class));

            throw $e;
        } finally {
            $format = ">>>>>>>>\n{request}\n<<<<<<<<\n{response}\n--------\n";
            $formatter = new MessageFormatter($format);
            $this->logger->debug($formatter->format($request, $response));
        }

        return $response;
    }
}
