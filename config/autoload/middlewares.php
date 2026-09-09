<?php

declare(strict_types=1);
/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
$middlewares = [
    'http' => [
    ],
];

if (env('MIDDLEWARE_ENABLE_CORS')) {
    $middlewares['http'][] = \App\Middleware\CorsMiddleware::class;
}

return $middlewares;
