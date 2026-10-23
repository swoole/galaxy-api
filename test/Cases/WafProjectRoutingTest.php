<?php

namespace HyperfTest\Cases;

use App\Model\ProjectRuntime;
use App\Services\Project\ProjectRouteService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Database\Connection;
use Hyperf\Database\ConnectionResolver;
use Hyperf\Database\ConnectionResolverInterface;
use PDO;
use PHPUnit\Framework\TestCase;

final class WafProjectRoutingTest extends TestCase
{
    public function testOnlyBoundProjectRoutesUseWafAndAllRouterVariantsStayProtected(): void
    {
        $container = ApplicationContext::getContainer();
        $oldResolver = $container->get(ConnectionResolverInterface::class);
        $config = $container->get(ConfigInterface::class);
        $oldEnabled = $config->get('waf.enabled', false);
        $connection = (new Connection(new PDO('sqlite::memory:'), ':memory:', 'galaxy_'))->setTablePrefix('galaxy_');
        $container->set(ConnectionResolverInterface::class, new ConnectionResolver(['default' => $connection]));
        try {
            $connection->statement('CREATE TABLE galaxy_project_route (id INTEGER PRIMARY KEY, runtime_id INTEGER, org_id INTEGER, cluster_id INTEGER, enabled INTEGER, hostname TEXT, path_prefix TEXT, target_port INTEGER, entrypoint TEXT, network_id TEXT, network_name TEXT, certificate_id INTEGER, tls_enabled INTEGER, https_redirect INTEGER)');
            $connection->statement('CREATE TABLE galaxy_tls_certificate (id INTEGER PRIMARY KEY)');
            $connection->statement('CREATE TABLE galaxy_waf_policy (org_id INTEGER, cluster_id INTEGER, route_source TEXT, route_id INTEGER)');
            $connection->statement("INSERT INTO galaxy_project_route VALUES (7, 9, 1, 1, 1, 'app.example.test', '/', 9501, 'web', 'network-id', 'network-name', 0, 1, 1)");
            $runtime = new ProjectRuntime(['org_id' => 1, 'cluster_id' => 1]);
            $runtime->id = 9;
            $service = (new \ReflectionClass(ProjectRouteService::class))->newInstanceWithoutConstructor();
            $config->set('waf.enabled', true);
            $original = $service->applyToServiceSpec($runtime, []);
            self::assertSame('cg-7', $original['Labels']['traefik.http.routers.cg-7.service']);
            $connection->statement("INSERT INTO galaxy_waf_policy VALUES (1, 1, 'project', 7)");
            $protected = $service->applyToServiceSpec($runtime, []);
            foreach (['cg-7', 'cg-7-sse', 'cg-7-http'] as $name) {
                self::assertSame('cg-project-route-7-svc@file', $protected['Labels']['traefik.http.routers.' . $name . '.service']);
            }
            // Global module disable works even before its schema is installed.
            $config->set('waf.enabled', false);
            $connection->statement('DROP TABLE galaxy_waf_policy');
            $disabled = $service->applyToServiceSpec($runtime, []);
            self::assertSame($original['Labels'], $disabled['Labels']);
        } finally {
            $config->set('waf.enabled', $oldEnabled);
            $container->set(ConnectionResolverInterface::class, $oldResolver);
        }
    }
}
