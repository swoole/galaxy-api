<?php

declare(strict_types=1);
/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
return [
    'default' => [
        'handler' => [
            'class' => Monolog\Handler\StreamHandler::class,
            'constructor' => [
                'stream' => env('LOG_BASE_PATH', BASE_PATH . '/runtime/logs') . '/hyperf.log',
                'level' => Monolog\Logger::toMonologLevel(env('LOG_LEVEL_DEFAULT', 'WARNING')),
            ],
        ],
        'formatter' => [
            'class' => env('LOG_FORMATTER_CLASS_DEFAULT', Monolog\Formatter\JsonFormatter::class),
            'constructor' => [
                'format' => null,
                'dateFormat' => 'Y-m-d H:i:s',
                'allowInlineLineBreaks' => true,
            ],
        ],
    ],
    'request' => [
        'handler' => [
            'class' => Monolog\Handler\StreamHandler::class,
            'constructor' => [
                'filename' => env('LOG_BASE_PATH', BASE_PATH . '/runtime/logs') . '/request.log',
                'stream' => env('LOG_BASE_PATH', BASE_PATH . '/runtime/logs') . '/request.log',
                'level' => Monolog\Logger::toMonologLevel(env('LOG_LEVEL_REQUEST', 'WARNING')),
            ],
        ],
        'formatter' => [
            'class' => env('LOG_FORMATTER_CLASS_REQUEST', Monolog\Formatter\LineFormatter::class),
            'constructor' => [
                'format' => null,
                'dateFormat' => 'Y-m-d H:i:s',
                'allowInlineLineBreaks' => true,
            ],
        ],
    ],
    'queue' => [
        'handler' => [
            'class' => Monolog\Handler\StreamHandler::class,
            'constructor' => [
                'filename' => env('LOG_BASE_PATH', BASE_PATH . '/runtime/logs') . '/queue.log',
                'stream' => env('LOG_BASE_PATH', BASE_PATH . '/runtime/logs') . '/queue.log',
                'level' => Monolog\Logger::toMonologLevel(env('LOG_LEVEL_QUEUE', 'WARNING')),
            ],
        ],
        'formatter' => [
            'class' => env('LOG_FORMATTER_CLASS_QUEUE', Monolog\Formatter\JsonFormatter::class),
            'constructor' => [
                'format' => null,
                'dateFormat' => 'Y-m-d H:i:s',
                'allowInlineLineBreaks' => true,
            ],
        ],
    ],
    'debug' => [
        'handler' => [
            'class' => Monolog\Handler\StreamHandler::class,
            'constructor' => [
                'filename' => env('LOG_BASE_PATH', BASE_PATH . '/runtime/logs') . '/debug.log',
                'stream' => env('LOG_BASE_PATH', BASE_PATH . '/runtime/logs') . '/debug.log',
                'level' => Monolog\Logger::toMonologLevel(env('LOG_LEVEL_DEBUG', 'DEBUG')),
            ],
        ],
        'formatter' => [
            'class' => env('LOG_FORMATTER_CLASS_DEBUG', Monolog\Formatter\LineFormatter::class),
            'constructor' => [
                'format' => null,
                'dateFormat' => 'Y-m-d H:i:s',
                'allowInlineLineBreaks' => true,
            ],
        ],
    ],
    'notify' => [
        'handler' => [
            'class' => Monolog\Handler\StreamHandler::class,
            'constructor' => [
                'filename' => env('LOG_BASE_PATH', BASE_PATH . '/runtime/logs') . '/notify.log',
                'stream' => env('LOG_BASE_PATH', BASE_PATH . '/runtime/logs') . '/notify.log',
                'level' => Monolog\Logger::toMonologLevel(env('LOG_LEVEL_NOTIFY', 'WARNING')),
            ],
        ],
        'formatter' => [
            'class' => env('LOG_FORMATTER_CLASS_NOTIFY', Monolog\Formatter\JsonFormatter::class),
            'constructor' => [
                'format' => null,
                'dateFormat' => 'Y-m-d H:i:s',
                'allowInlineLineBreaks' => true,
            ],
        ],
    ],
];
