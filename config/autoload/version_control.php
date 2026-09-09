<?php

declare(strict_types=1);

use Hyperf\HttpServer\Contract\RequestInterface;

return [
    'enable' => (bool) env('VERSION_CONTROL_ENABLE', true),
    'versionParser' => function (RequestInterface $request) : ?float {
        $version = $request->getHeaderLine('x-cg-version');
        return $version ? (float) $version : null;
    },
];
