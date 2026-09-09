<?php

declare(strict_types=1);

namespace HyperfTest\Cases;

use App\Model\Cluster;
use App\Model\ProjectRoute;
use App\Model\ProjectRuntime;
use App\Services\Project\Route\KubernetesProjectRouteService;
use App\Services\Project\PipelineDefinitionService;
use App\Services\Project\ProjectServiceIdentity;
use App\Services\Project\ReleaseDefinitionService;
use App\Services\Project\Runtime\KubernetesProjectRuntimeDriver;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 * @coversNothing
 */
class MultiOrchestratorRuntimeTest extends TestCase
{
    public function testKubernetesAndSwarmAreDeployableAndBuildableProviders(): void
    {
        self::assertContains(Cluster::ORCHESTRATOR_KUBERNETES, Cluster::knownOrchestrators());
        self::assertSame(
            [Cluster::ORCHESTRATOR_DOCKER_SWARM, Cluster::ORCHESTRATOR_KUBERNETES],
            Cluster::deployableOrchestrators()
        );
        self::assertSame(
            [Cluster::ORCHESTRATOR_DOCKER_SWARM, Cluster::ORCHESTRATOR_KUBERNETES],
            Cluster::buildableOrchestrators()
        );
        self::assertSame('swarm', Cluster::routeNamespace(Cluster::ORCHESTRATOR_DOCKER_SWARM));
        self::assertSame('k8s', Cluster::routeNamespace(Cluster::ORCHESTRATOR_KUBERNETES));
    }

    public function testRuntimeExposesAProviderNeutralReference(): void
    {
        $runtime = new ProjectRuntime();
        $runtime->orchestrator_type = Cluster::ORCHESTRATOR_DOCKER_SWARM;
        $runtime->workload_kind = ProjectRuntime::WORKLOAD_SWARM_SERVICE;
        $runtime->runtime_namespace = '';
        $runtime->runtime_ref = 'service-id';
        $runtime->provider_metadata = ['service_name' => 'demo_web'];

        self::assertSame([
            'orchestrator_type' => Cluster::ORCHESTRATOR_DOCKER_SWARM,
            'workload_kind' => ProjectRuntime::WORKLOAD_SWARM_SERVICE,
            'namespace' => '',
            'reference' => 'service-id',
            'metadata' => ['service_name' => 'demo_web'],
        ], $runtime->providerReference());
    }

    public function testKubernetesCpuResourcesUseExactMillicores(): void
    {
        $reflection = new ReflectionClass(KubernetesProjectRuntimeDriver::class);
        $driver = $reflection->newInstanceWithoutConstructor();
        $resources = $reflection->getMethod('resources')->invoke($driver, [
            'limits' => ['cpus' => 1, 'memory_mb' => 512],
            'reservations' => ['cpus' => 0.1, 'memory_mb' => 128],
        ]);

        self::assertSame('1', $resources['limits']['cpu']);
        self::assertSame('100m', $resources['requests']['cpu']);
        self::assertSame('512Mi', $resources['limits']['memory']);
        self::assertSame('128Mi', $resources['requests']['memory']);
    }

    public function testKubernetesHostRouteProducesStandardIngress(): void
    {
        $runtime = new ProjectRuntime();
        $runtime->id = 7;
        $runtime->org_id = 1;
        $runtime->project_id = 9;

        $route = new ProjectRoute();
        $route->id = 3;
        $route->hostname = 'app.example.com';
        $route->path_prefix = '/';
        $route->path_match = 'prefix';
        $route->target_port = 80;
        $route->entrypoint = 'web';
        $route->priority = 1;

        $reflection = new ReflectionClass(KubernetesProjectRouteService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $manifest = $reflection->getMethod('manifest')->invoke(
            $service,
            $runtime,
            $route,
            'galaxy-o1',
            'cg-1-9-1-web',
            'cg-route-3'
        );

        self::assertSame('networking.k8s.io/v1', $manifest['apiVersion']);
        self::assertSame('Ingress', $manifest['kind']);
        self::assertSame('traefik', $manifest['spec']['ingressClassName']);
        self::assertSame('app.example.com', $manifest['spec']['rules'][0]['host']);
        self::assertSame(
            [
                'name' => 'cg-1-9-1-web',
                'port' => ['number' => 80],
            ],
            $manifest['spec']['rules'][0]['http']['paths'][0]['backend']['service']
        );
        self::assertSame('Prefix', $manifest['spec']['rules'][0]['http']['paths'][0]['pathType']);
    }

    public function testInstanceTitleIsSeparateFromGeneratedResourceName(): void
    {
        [$definition] = (new ReleaseDefinitionService())->normalize([
            'instance_name' => '线上 Web / 华东',
            'replicas' => 1,
        ]);

        self::assertSame('线上 Web / 华东', $definition['instance_name']);
        self::assertArrayNotHasKey('service_name', $definition);

        $resourceName = ProjectServiceIdentity::newResourceName(1, 9);
        self::assertMatchesRegularExpression('/^cg-1-9-[a-f0-9]{12}$/', $resourceName);
        self::assertStringNotContainsString('线上', $resourceName);

        self::assertSame($resourceName, ProjectServiceIdentity::releaseResourceName([
            'instance_name' => '线上 Web / 华东',
            'service_name' => $resourceName,
            'resource_identity_version' => 'opaque-v1',
        ], 1, 9, 1, true));
        self::assertSame('cg-1-9-1-web', ProjectServiceIdentity::releaseResourceName([
            'instance_name' => '旧实例标题',
            'service_name' => 'web',
        ], 1, 9, 1, true));
    }

    public function testImportedContainerPlacementNodeIsPreservedInDefinition(): void
    {
        $nodeId = 'nn8hwbpiqlmu3gxlqbvy36ryr';
        [$definition] = (new ReleaseDefinitionService())->normalize([
            'instance_name' => '导入容器',
            'replicas' => 1,
            'placement_node_id' => $nodeId,
            'runtime_import_source' => [
                'type' => 'docker_container',
                'cluster_id' => 2,
                'node_id' => $nodeId,
                'reference' => 'f6847094f655f6847094f655',
            ],
            'bind_risk_acknowledged' => true,
            'mounts' => [[
                'type' => 'bind',
                'source' => '/srv/imported-app',
                'target' => '/app',
            ]],
        ]);

        self::assertSame($nodeId, $definition['placement_node_id']);
        self::assertSame('docker_container', $definition['runtime_import_source']['type']);
        self::assertSame($nodeId, $definition['runtime_import_source']['node_id']);
    }

    public function testDefaultPipelineUsesSevenCharacterCommitTagVariable(): void
    {
        $service = new PipelineDefinitionService();
        $definition = $service->parse($service->defaultYaml());

        self::assertSame(['${COMMIT_SHORT_SHA}'], $definition['output']['tags']);
    }
}
