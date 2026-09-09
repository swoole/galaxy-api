<?php

declare(strict_types=1);
/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */

namespace HyperfTest\Cases;

use App\Model\Cluster;
use App\Model\Group;
use App\Model\KubernetesResourceSnapshot;
use App\Model\Project;
use App\Model\ProjectRuntime;
use App\Model\ProjectRuntimeMetric;
use App\Services\ResourceUsageRankingService;
use Hyperf\Collection\Collection;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
final class ResourceUsageRankingServiceTest extends TestCase
{
    public function testMetricValueUsesCombinedIoRates(): void
    {
        $service = new ResourceUsageRankingService();
        $method = (new \ReflectionClass($service))->getMethod('metricValue');
        $row = [
            'cpu_percent' => 125.5,
            'memory_usage' => 1024,
            'network_rx_bps' => 100,
            'network_tx_bps' => 25,
            'disk_read_bps' => 60,
            'disk_write_bps' => 40,
        ];

        self::assertSame(125.5, $method->invoke($service, $row, 'cpu'));
        self::assertSame(1024.0, $method->invoke($service, $row, 'memory'));
        self::assertSame(125.0, $method->invoke($service, $row, 'network'));
        self::assertSame(100.0, $method->invoke($service, $row, 'disk'));
    }

    public function testUnavailableMetricIsNotInferredFromZeroValue(): void
    {
        $service = new ResourceUsageRankingService();
        $method = (new \ReflectionClass($service))->getMethod('metricAvailable');

        self::assertFalse($method->invoke($service, ['network_available' => false], 'network'));
        self::assertTrue($method->invoke($service, ['network_available' => true], 'network'));
    }

    public function testRuntimeIoUsesRateBetweenTwoFreshSnapshots(): void
    {
        $runtime = new ProjectRuntime([
            'id' => 7,
            'project_id' => 9,
            'group_id' => 3,
            'cluster_id' => 2,
            'name' => '线上实例',
            'orchestrator_type' => Cluster::ORCHESTRATOR_DOCKER_SWARM,
            'workload_kind' => ProjectRuntime::WORKLOAD_SWARM_SERVICE,
            'service_name' => 'demo_web',
        ]);
        $runtime->setRelation('project', new Project(['id' => 9, 'title' => 'Demo', 'alias' => 'demo']));
        $runtime->setRelation('group', new Group(['id' => 3, 'title' => '默认项目组', 'alias' => 'default']));
        $runtime->setRelation('cluster', new Cluster(['id' => 2, 'title' => '线上集群']));
        $latest = new ProjectRuntimeMetric([
            'collected_at' => time() - 10,
            'network_rx' => 7000,
            'network_tx' => 5000,
            'disk_read' => 9000,
            'disk_write' => 6000,
            'metric_coverage' => 100,
            'disk_io_coverage' => 100,
        ]);
        $previous = new ProjectRuntimeMetric([
            'collected_at' => time() - 70,
            'network_rx' => 1000,
            'network_tx' => 2000,
            'disk_read' => 3000,
            'disk_write' => 3000,
        ]);

        $service = new ResourceUsageRankingService();
        $method = (new \ReflectionClass($service))->getMethod('runtimeRow');
        $row = $method->invoke($service, $runtime, $latest, $previous);

        self::assertSame(100.0, $row['network_rx_bps']);
        self::assertSame(50.0, $row['network_tx_bps']);
        self::assertSame(100.0, $row['disk_read_bps']);
        self::assertSame(50.0, $row['disk_write_bps']);
        self::assertTrue($row['network_available']);
        self::assertTrue($row['disk_available']);
    }

    public function testKubernetesWorkloadAggregationCombinesPodsAndNodes(): void
    {
        $now = time();
        $pods = [
            new KubernetesResourceSnapshot([
                'resource_uid' => 'pod-1', 'name' => 'web-a', 'namespace' => 'shop',
                'cluster_id' => 2, 'node_name' => 'node-a', 'workload_kind' => 'Deployment',
                'workload_name' => 'web', 'phase' => 'Running', 'cpu_percent' => 75,
                'memory_usage' => 1024, 'memory_limit' => 4096, 'metric_available' => true,
                'collected_at' => $now,
            ]),
            new KubernetesResourceSnapshot([
                'resource_uid' => 'pod-2', 'name' => 'web-b', 'namespace' => 'shop',
                'cluster_id' => 2, 'node_name' => 'node-b', 'workload_kind' => 'Deployment',
                'workload_name' => 'web', 'phase' => 'Running', 'cpu_percent' => 25,
                'memory_usage' => 2048, 'memory_limit' => 4096, 'metric_available' => true,
                'collected_at' => $now,
            ]),
        ];
        $clusters = (new Collection([new Cluster(['id' => 2, 'title' => '生产集群'])]))->keyBy('id');
        $service = new ResourceUsageRankingService();
        $method = (new \ReflectionClass($service))->getMethod('aggregateKubernetesRows');
        $rows = $method->invoke($service, $pods, $clusters, 'workload');

        self::assertCount(1, $rows);
        self::assertSame('web', $rows[0]['title']);
        self::assertSame(2, $rows[0]['pod_count']);
        self::assertSame(2, $rows[0]['node_count']);
        self::assertSame(100.0, $rows[0]['cpu_percent']);
        self::assertSame(3072, $rows[0]['memory_usage']);
        self::assertSame(2, $rows[0]['covered_resource_count']);
        self::assertTrue($rows[0]['cpu_available']);
    }

    public function testKubernetesAggregationDoesNotUseStaleMetrics(): void
    {
        $pod = new KubernetesResourceSnapshot([
            'resource_uid' => 'old-pod', 'name' => 'old', 'namespace' => 'default',
            'cluster_id' => 2, 'node_name' => 'node-a', 'workload_kind' => 'Pod',
            'workload_name' => 'old', 'cpu_percent' => 99, 'memory_usage' => 2048,
            'metric_available' => true, 'collected_at' => time() - 301,
        ]);
        $clusters = (new Collection([new Cluster(['id' => 2, 'title' => '生产集群'])]))->keyBy('id');
        $service = new ResourceUsageRankingService();
        $method = (new \ReflectionClass($service))->getMethod('aggregateKubernetesRows');
        $rows = $method->invoke($service, [$pod], $clusters, 'namespace');

        self::assertSame(0.0, $rows[0]['cpu_percent']);
        self::assertSame(0, $rows[0]['memory_usage']);
        self::assertSame(0, $rows[0]['covered_resource_count']);
        self::assertFalse($rows[0]['cpu_available']);
        self::assertTrue($rows[0]['stale']);
    }
}
