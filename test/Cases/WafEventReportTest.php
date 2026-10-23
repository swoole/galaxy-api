<?php

namespace HyperfTest\Cases;

use App\Model\Cluster;
use App\Services\Gateway\WafEventService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Database\Connection;
use Hyperf\Database\ConnectionResolver;
use Hyperf\Database\ConnectionResolverInterface;
use PDO;
use PHPUnit\Framework\TestCase;

final class WafEventReportTest extends TestCase
{
    public function testEventReportScopesTenantAndClusterAndFillsHourlyGaps(): void
    {
        $container = ApplicationContext::getContainer();
        $oldResolver = $container->get(ConnectionResolverInterface::class);
        $config = $container->get(ConfigInterface::class);
        $oldEnabled = $config->get('waf.enabled', false);
        $connection = (new Connection(new PDO('sqlite::memory:'), ':memory:', 'galaxy_'))->setTablePrefix('galaxy_');
        $container->set(ConnectionResolverInterface::class, new ConnectionResolver(['default' => $connection]));
        $config->set('waf.enabled', true);
        try {
            $connection->statement('CREATE TABLE galaxy_waf_event (id INTEGER PRIMARY KEY, org_id INTEGER, cluster_id INTEGER, policy_id INTEGER, occurred_at INTEGER, client_ip TEXT, action TEXT, messages TEXT)');
            $hour = (int) floor(time() / 3600) * 3600;
            foreach ([[1, 1, 1, 'block', $hour - 1], [2, 1, 1, 'monitor', $hour - 1800],
                [3, 2, 1, 'block', $hour - 1], [4, 1, 2, 'block', $hour - 1],
                [5, 1, 1, 'block', $hour]] as [$id, $org, $clusterId, $action, $at]) {
                $connection->insert('INSERT INTO galaxy_waf_event VALUES (?, ?, ?, 7, ?, ?, ?, ?)', [$id, $org, $clusterId, $at, '192.0.2.' . $id, $action, '[]']);
            }
            $cluster = new Cluster(['org_id' => 1]); $cluster->id = 1;
            $service = (new \ReflectionClass(WafEventService::class))->newInstanceWithoutConstructor();
            $report = $service->report($cluster, ['hours' => 24]);
            self::assertSame(['events' => 3, 'blocked' => 2, 'monitored' => 1, 'ips' => 3], $report['summary']);
            self::assertCount(3, $report['events']);
            self::assertCount(25, $report['trend']);
            self::assertSame(3, array_sum(array_column($report['trend'], 'total')));
            $hourly = array_column($report['trend'], null, 'bucket');
            self::assertSame(2, (int) $hourly[$hour - 3600]['total']);
            self::assertSame(1, (int) $hourly[$hour - 3600]['blocked']);
            self::assertSame(1, (int) $hourly[$hour]['total']);
            $blocked = $service->report($cluster, ['hours' => 24, 'action' => 'block']);
            self::assertSame(2, $blocked['total']);
            $connection->statement('CREATE TABLE galaxy_waf_policy (id INTEGER, org_id INTEGER, cluster_id INTEGER, route_source TEXT, route_id INTEGER)');
            $connection->statement("INSERT INTO galaxy_waf_policy VALUES (7,1,1,'project',71), (8,1,1,'gateway',71), (9,2,1,'project',71)");
            $connection->insert('INSERT INTO galaxy_waf_event VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [6, 1, 1, 8, time(), '192.0.2.6', 'block', '[]']);
            self::assertSame(4, $service->report($cluster, ['hours' => 24])['total']);
            self::assertSame(3, $service->report($cluster, ['hours' => 24], [71])['total']);
            self::assertSame(0, $service->report($cluster, ['hours' => 24], [])['total']);
            self::assertSame(0, $service->report($cluster, ['hours' => 24, 'policy_id' => 8], [71])['total']);
            self::assertSame(0, $service->report($cluster, ['hours' => 24], [72])['total']);
        } finally {
            $config->set('waf.enabled', $oldEnabled);
            $container->set(ConnectionResolverInterface::class, $oldResolver);
        }
    }
}
