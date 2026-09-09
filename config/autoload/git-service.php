<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
use App\Support\SafeHttpMessageFormatter;
use GuzzleHttp\Middleware;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Context\ApplicationContext;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

return [
    /*
     * GuzzleHttp配置
     */
    'guzzle' => [
        // guzzle原生配置选项
        'options' => [
            'http_errors' => false,
            'connect_timeout' => 5,
            'timeout' => 5,
            // hyperf集成guzzle的swoole配置选项
            'swoole' => [
                'connect_timeout' => 5,
                'timeout' => 5,
                'socket_buffer_size' => 1024 * 1024 * 2,
            ],
        ],
        // guzzle中间件配置
        'middlewares' => [
            // 失败重试中间件
            'retry' => [
                'enable' => (bool) env('GIT_SERVICE_GUZZLE_MIDDLEWARE_RETRY_ENABLE', true),
                'handler' => function () {
                    return Middleware::retry(function ($retries, RequestInterface $request, ?ResponseInterface $response = null) {
                        if (
                            (! $response || $response->getStatusCode() >= 500)
                            && $retries < 1
                        ) {
                            return true;
                        }
                        return false;
                    }, function () {
                        return 1;
                    });
                },
            ],
            // 请求日志记录中间件
            'logger' => [
                'enable' => (bool) env('GIT_SERVICE_GUZZLE_MIDDLEWARE_LOGGER_ENABLE', true),
                'handler' => function () {
                    $logger = ApplicationContext::getContainer()->get(LoggerFactory::class)->get('GitService');

                    return Middleware::log($logger, new SafeHttpMessageFormatter('Git API'), 'debug');
                },
            ],
        ],
        // hyperf集成guzzle的连接池配置选项，非hyperf框架忽略
        'pool' => [
            'option' => [
                'max_connections' => 200,
            ],
        ],
    ],
];
