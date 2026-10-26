<?php

namespace App\Services\Gateway;

use App\Exception\AppException;

/** Match the decoded, normalized WAF path without forwarding query secrets. */
final class WafRequestPath
{
    public static function normalize(string $raw): string
    {
        $path = rawurldecode(explode('?', $raw)[0]);
        if (strlen($path) > 2048 || ! str_starts_with($path, '/') || preg_match('/[\\x00-\\x1f\\x7f\\\\]/', $path)) throw new AppException(400, 'Invalid request path');
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') array_pop($parts);
            elseif ($part !== '' && $part !== '.') $parts[] = $part;
        }
        return '/' . implode('/', $parts) . (str_ends_with($path, '/') && $parts !== [] ? '/' : '');
    }
}
