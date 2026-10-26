<?php

namespace HyperfTest\Stub;

use App\Services\Gateway\WafAutoBanService;
use App\Services\Gateway\WafClientIp;
use App\Services\Gateway\WafControlConfig;
use App\Services\Gateway\WafControlCredential;
use App\Services\Gateway\WafRuntimeConfigRegistry;
use App\Services\Gateway\WafSchemaState;
use Hyperf\Redis\Redis;

/** Explicit fixture dependencies: tests never resolve the application's real Redis. */
final class WafDynamicServices
{
    public static function registry(?Redis $cache = null): WafRuntimeConfigRegistry
    {
        $config = new WafControlConfig();
        $cache ??= new WafMemoryRedis();
        return new WafRuntimeConfigRegistry($config, new WafControlCredential($config), new WafSchemaState(), $cache, self::network($cache));
    }

    public static function bans(Redis $cache, ?WafRuntimeConfigRegistry $registry = null): WafAutoBanService
    { return new WafAutoBanService($registry ?? self::registry(), new WafClientIp(), $cache); }

    public static function browser(Redis $cache): \App\Services\Gateway\WafBrowserChallenge
    { return new \App\Services\Gateway\WafBrowserChallenge(new \App\Services\Gateway\WafBrowserToken(new WafControlConfig(), new WafClientIp()),
        new \App\Services\Gateway\WafChallengeStore($cache), new WafClientIp()); }

    public static function storage(): \App\Services\Gateway\WafDataStorage
    { return new \App\Services\Gateway\WafDataStorage(new \App\Services\Gateway\WafIpIndex(new WafClientIp())); }

    public static function data(Redis $cache): \App\Services\Gateway\WafDataCatalog
    { return new \App\Services\Gateway\WafDataCatalog(new WafSchemaState(), new \App\Services\Gateway\WafDataConfig(),
        self::storage(), new \App\Services\Gateway\WafDataLease($cache), $cache); }

    public static function network(Redis $cache): \App\Services\Gateway\WafNetworkPolicy
    { return new \App\Services\Gateway\WafNetworkPolicy(self::data($cache), self::storage(), new \App\Services\Gateway\WafIpIndex(new WafClientIp()), new WafClientIp()); }
}
