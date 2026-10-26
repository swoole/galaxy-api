<?php

namespace HyperfTest\Stub;

use App\Controller\WafControlController;
use App\Services\Gateway\WafAutoBanService;
use App\Services\Gateway\WafRuntimeConfigRegistry;
use Psr\Http\Message\ResponseInterface;

/** Exercise the production controller without global request context or a running API server. */
final class WafControlHttpFixture
{
    public static function request(WafRuntimeConfigRegistry $registry, WafAutoBanService $bans, array $headers,
        string $operation = 'check', array $input = [], ?\App\Services\Gateway\WafBrowserChallenge $browser = null): ResponseInterface
    {
        $request = new class($headers, $input) extends \Hyperf\HttpServer\Request {
            public function __construct(private array $headers, private array $input) { $this->headers = array_change_key_case($headers, CASE_LOWER); }
            public function header(string $key, ?string $default = null): ?string { return $this->headers[strtolower($key)] ?? $default; }
            public function input(string $key, mixed $default = null): mixed { return $this->input[$key] ?? $default; }
        };
        $response = new \Hyperf\HttpServer\Response(new \Hyperf\HttpMessage\Server\Response());
        $controller = new WafControlController($registry, $bans, $browser ?? WafDynamicServices::browser(new WafMemoryRedis()), new \App\Services\Gateway\WafChallengePage(), WafDynamicServices::network(new WafMemoryRedis()));
        (new \ReflectionProperty($controller, 'request'))->setValue($controller, $request);
        (new \ReflectionProperty($controller, 'response'))->setValue($controller, $response);
        return $controller->{$operation}();
    }
}
