<?php

namespace App\Support;

use GuzzleHttp\MessageFormatterInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Logs an HTTP request without credentials, headers, query strings or bodies.
 */
class SafeHttpMessageFormatter implements MessageFormatterInterface
{
    public function __construct(private string $prefix = 'HTTP') {}

    public function format(
        RequestInterface $request,
        ?ResponseInterface $response = null,
        ?\Throwable $error = null
    ): string {
        $uri = $request->getUri()->withUserInfo('')->withQuery('')->withFragment('');
        if ($response !== null) {
            $result = 'HTTP ' . $response->getStatusCode();
        } elseif ($error !== null) {
            // Exception messages can contain the original URL. Log only the type.
            $result = 'transport-error ' . get_debug_type($error);
        } else {
            $result = 'no-response';
        }

        return sprintf('%s %s %s -> %s', $this->prefix, $request->getMethod(), (string) $uri, $result);
    }
}
