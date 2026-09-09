<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Support;

use stdClass;

class Response
{
    /**
     * 响应json.
     */
    public static function json(int $code = 0, string $msg = 'success', ?array $data = [], array $extend = []): array
    {
        return array_merge([
            'code' => $code,
            'msg' => $msg,
            'data' => $data ?: new stdClass(),
        ], $extend);
    }
}
