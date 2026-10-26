<?php

namespace HyperfTest\Stub;

use RuntimeException;

final class WafDataFixtures
{
    public static function country(?int $epoch = null): string
    {
        $body = file_get_contents(dirname(__DIR__) . '/Fixtures/waf-data/GeoIP2-Country-Test.mmdb');
        $offset = strrpos($body, 'build_epoch') + strlen('build_epoch');
        if (substr($body, $offset, 2) !== "\x04\x02") throw new RuntimeException('Fixture timestamp encoding changed');
        return substr_replace($body, pack('N', $epoch ?? time() - 60), $offset + 2, 4);
    }
}
