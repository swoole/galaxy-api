<?php

namespace App\Services\NetworkTunnel;

use App\Exception\AppException;
use App\Model\Cluster;
use App\Services\Docker\SwarmApiClient;
use App\Services\Kubernetes\KubernetesClusterService;
use GuzzleHttp\Client;

final class NetworkTunnelCatalogService
{
    public function __construct(
        private SwarmApiClient $swarm,
        private KubernetesClusterService $kubernetes
    ) {}

    public function services(int $orgId, int $clusterId): array
    {
        $cluster = $this->cluster($orgId, $clusterId);

        return match ((string) $cluster->orchestrator_type) {
            Cluster::ORCHESTRATOR_DOCKER_SWARM => $this->swarmServices($cluster),
            Cluster::ORCHESTRATOR_KUBERNETES => $this->kubernetesServices($orgId, $clusterId),
            default => [],
        };
    }

    public function resolveService(
        int $orgId,
        int $clusterId,
        string $reference,
        string $name,
        string $namespace
    ): array {
        $services = $this->services($orgId, $clusterId);
        foreach ($services as $service) {
            if (($reference !== '' && (string) $service['ref'] === $reference)
                || ($name !== ''
                    && (string) $service['name'] === $name
                    && (string) $service['namespace'] === $namespace)) {
                return $service;
            }
        }

        throw new AppException(404, '来源 Service 不存在，请刷新列表后重试');
    }

    private function swarmServices(Cluster $cluster): array
    {
        return $this->swarm->withCluster($cluster, function (Client $client, SwarmApiClient $api): array {
            $services = $api->request($client, 'GET', '/services');

            return array_values(array_map(static function (array $service): array {
                $spec = (array) ($service['Spec'] ?? []);
                $endpoint = (array) ($service['Endpoint'] ?? []);
                $ports = [];
                foreach ((array) ($endpoint['Ports'] ?? $spec['EndpointSpec']['Ports'] ?? []) as $port) {
                    $target = (int) ($port['TargetPort'] ?? 0);
                    if ($target > 0) {
                        $ports[$target] = $target;
                    }
                }

                $name = (string) ($spec['Name'] ?? '');
                return [
                    'ref' => (string) ($service['ID'] ?? $name),
                    'name' => $name,
                    'namespace' => '',
                    'local_host' => $name,
                    'ports' => array_values($ports),
                    'orchestrator_type' => Cluster::ORCHESTRATOR_DOCKER_SWARM,
                ];
            }, $services));
        }, 30);
    }

    private function kubernetesServices(int $orgId, int $clusterId): array
    {
        return array_values(array_map(static function (array $service): array {
            $name = (string) ($service['name'] ?? '');
            $namespace = (string) ($service['namespace'] ?? 'default');
            return [
                'ref' => (string) ($service['uid'] ?? ($namespace . '/' . $name)),
                'name' => $name,
                'namespace' => $namespace,
                'local_host' => $name . '.' . $namespace . '.svc.cluster.local',
                'ports' => array_values(array_unique(array_filter(array_map(
                    static fn (array $port): int => (int) ($port['port'] ?? 0),
                    (array) ($service['ports'] ?? [])
                )))),
                'orchestrator_type' => Cluster::ORCHESTRATOR_KUBERNETES,
            ];
        }, $this->kubernetes->services($orgId, $clusterId)));
    }

    private function cluster(int $orgId, int $clusterId): Cluster
    {
        $cluster = Cluster::where('org_id', $orgId)
            ->where('id', $clusterId)
            ->whereIn('orchestrator_type', Cluster::knownOrchestrators())
            ->first();
        if (! $cluster instanceof Cluster) {
            throw new AppException(404, '来源集群不存在');
        }

        return $cluster;
    }
}
