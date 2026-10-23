<?php

namespace App\Services\Gateway;

use App\Exception\AppException;
use App\Model\Cluster;
use App\Model\ClusterWebGateway;
use App\Model\WafPolicy;
use App\Services\Docker\SwarmApiClient;
use App\Services\Docker\SwarmContainerExecService;
use App\Services\Project\ProjectServiceIdentity;
use GuzzleHttp\Client;
use function Hyperf\Config\config;

/** Immutable WAF generations keep the last working route alive during deployment. */
final class WafRuntimeService
{
    public function __construct(
        private SwarmApiClient $docker,
        private SwarmContainerExecService $exec,
        private WafPolicyConfig $policies,
        private WafConfigRenderer $renderer
    ) {}

    public function prepare(Cluster $cluster, ClusterWebGateway $gateway, iterable $vhosts, iterable $projects): array
    {
        if (! config('waf.enabled', false)) return [];
        $routes = [];
        foreach ($vhosts as $route) $routes['gateway:' . $route->id] = $route;
        foreach ($projects as $route) $routes['project:' . $route->id] = $route;
        $policies = WafPolicy::where('org_id', $cluster->org_id)->where('cluster_id', $cluster->id)->get();
        $active = [];
        foreach ($policies as $policy) {
            $key = $policy->route_source . ':' . $policy->route_id;
            if (isset($routes[$key]) && ($policy->settings['mode'] ?? 'off') !== 'off') {
                $active[$key] = [$policy, $routes[$key]];
            }
        }
        if ($active === []) return [];
        if (count($active) > (int) config('waf.max_policies', 20)) {
            throw new AppException(422, '当前集群最多启用 20 条 WAF 策略');
        }
        return $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api) use ($cluster, $gateway, $active): array {
            $info = $api->request($client, 'GET', '/info');
            $managerNode = (string) ($info['Swarm']['NodeID'] ?? '');
            if ($managerNode === '') throw new AppException(409, '无法确定当前 Manager，拒绝部署 WAF');
            $network = $api->request($client, 'GET', '/networks/' . rawurlencode((string) $gateway->network_id));
            $subnet = (string) ($network['IPAM']['Config'][0]['Subnet'] ?? '');
            if (! preg_match('#^[0-9a-fA-F:./]+$#D', $subnet) || ! str_contains($subnet, '/')) {
                throw new AppException(409, '无法确定网关 Overlay 子网，拒绝信任未知代理');
            }
            $result = [];
            foreach ($active as $key => [$policy, $route]) {
                $target = $policy->route_source === 'project'
                    ? ($route->runtime ? ProjectServiceIdentity::runtimeDockerName($route->runtime) : '')
                    : (string) $route->target_service;
                if ($target === '' || ! preg_match('/^[a-zA-Z0-9_.:-]+$/D', $target)) {
                    throw new AppException(409, 'WAF 路由的目标 Service 无效');
                }
                $scheme = $route->upstream_scheme ?: 'http';
                if (! in_array($scheme, ['http', 'https'], true)) throw new AppException(422, 'WAF 仅支持 HTTP / HTTPS 回源');
                $host = str_contains($target, ':') ? '[' . $target . ']' : $target;
                $p = $this->policies->normalize((array) $policy->settings);
                $files = $this->renderer->render($p, sprintf('%s://%s:%d', $scheme, $host, $route->target_port), (bool) $route->pass_host_header, $subnet);
                $env = $this->renderer->environment($p);
                $image = (string) config('waf.image');
                $hash = substr(hash('sha256', json_encode([$files, $env, $image, $gateway->network_id], JSON_THROW_ON_ERROR)), 0, 16);
                $name = sprintf('galaxy-waf-%d-%s-%d-%s', $cluster->id, $policy->route_source, $route->id, $hash);
                $labels = ['com.codegalaxy.component' => 'waf', 'com.codegalaxy.org.id' => (string) $cluster->org_id,
                    'com.codegalaxy.cluster.id' => (string) $cluster->id, 'com.codegalaxy.waf.policy' => (string) $policy->id];
                $services = $api->request($client, 'GET', '/services', ['query' => ['filters' => json_encode(['name' => [$name]], JSON_THROW_ON_ERROR)]]);
                $serviceId = '';
                foreach ($services as $service) {
                    if (($service['Spec']['Name'] ?? '') !== $name) continue;
                    $this->assertOwned($service['Spec']['Labels'] ?? [], $labels);
                    $serviceId = (string) $service['ID'];
                }
                if ($serviceId === '') {
                    $configs = [];
                    foreach ($files as $file => $content) {
                        $configName = $name . '-' . str_replace('.', '-', $file);
                        $existing = $api->request($client, 'GET', '/configs', ['query' => ['filters' => json_encode(['name' => [$configName]], JSON_THROW_ON_ERROR)]]);
                        $configId = '';
                        foreach ($existing as $item) {
                            if (($item['Spec']['Name'] ?? '') !== $configName) continue;
                            $this->assertOwned($item['Spec']['Labels'] ?? [], $labels);
                            $configId = (string) $item['ID'];
                        }
                        if ($configId === '') {
                            $created = $api->request($client, 'POST', '/configs/create', ['json' => [
                                'Name' => $configName, 'Labels' => $labels, 'Data' => base64_encode($content),
                            ]]);
                            $configId = (string) ($created['ID'] ?? '');
                        }
                        if ($configId === '') throw new AppException(502, '创建 WAF Config 失败');
                        $configs[] = ['ConfigID' => $configId, 'ConfigName' => $configName,
                            'File' => ['Name' => '/etc/galaxy-waf/' . $file, 'UID' => '0', 'GID' => '0', 'Mode' => 292]];
                    }
                    $created = $api->request($client, 'POST', '/services/create', ['json' => [
                        'Name' => $name, 'Labels' => $labels,
                        'TaskTemplate' => [
                            'ContainerSpec' => [
                                'Image' => $image, 'Env' => $env, 'Configs' => $configs,
                                'Args' => ['nginx', '-c', '/etc/galaxy-waf/nginx.conf', '-g', 'daemon off;'],
                                'Healthcheck' => ['Test' => ['CMD', 'curl', '-fsS', 'http://127.0.0.1:8080/__galaxy_waf_health'],
                                    'Interval' => 10_000_000_000, 'Timeout' => 3_000_000_000, 'Retries' => 3, 'StartPeriod' => 30_000_000_000],
                            ],
                            'Networks' => [['Target' => (string) $gateway->network_id]],
                            'Placement' => ['Constraints' => ['node.id==' . $managerNode]],
                            'Resources' => ['Limits' => ['MemoryBytes' => 268435456, 'NanoCPUs' => 1_000_000_000]],
                            'RestartPolicy' => ['Condition' => 'on-failure', 'MaxAttempts' => 3, 'Delay' => 5_000_000_000],
                            'LogDriver' => ['Name' => 'json-file', 'Options' => ['max-size' => '10m', 'max-file' => '3']],
                        ],
                        'Mode' => ['Replicated' => ['Replicas' => 1]],
                        'EndpointSpec' => ['Mode' => 'vip'],
                    ]]);
                    $serviceId = (string) ($created['ID'] ?? '');
                    if ($serviceId === '') throw new AppException(502, '创建 WAF Service 失败');
                }
                $this->exec->waitForRunningContainerIds($client, $api, $serviceId, 60);
                // The HTTP listener starts only after Nginx parses the full CRS configuration.
                $health = $this->exec->executeService($cluster, $serviceId, ['curl', '-fsS', '--retry', '15', '--retry-connrefused', '--retry-delay', '1', 'http://127.0.0.1:8080/__galaxy_waf_health']);
                if (($health['exit_code'] ?? -1) !== 0) throw new AppException(502, 'WAF 检测引擎未就绪，保留此前网关配置');
                $result[$key] = ['url' => 'http://' . $name . ':8080', 'service_id' => $serviceId,
                    'service_name' => $name, 'policy_id' => $policy->id, 'mode' => $p['mode']];
            }
            return $result;
        }, 90);
    }

    public function commit(Cluster $cluster, array $active): void
    {
        if (! config('waf.enabled', false)) return;
        foreach ($active as $runtime) {
            WafPolicy::where('id', $runtime['policy_id'])->where('org_id', $cluster->org_id)->update([
                'service_id' => $runtime['service_id'], 'service_name' => $runtime['service_name'],
                'applied_mode' => $runtime['mode'],
                'status' => 'synced', 'error' => null, 'synced_at' => time(),
            ]);
        }
        foreach (WafPolicy::where('org_id', $cluster->org_id)->where('cluster_id', $cluster->id)->get() as $policy) {
            if (isset($active[$policy->route_source . ':' . $policy->route_id])) continue;
            $policy->service_id = '';
            $policy->service_name = '';
            $policy->applied_mode = 'off';
            $policy->status = 'synced';
            $policy->synced_at = time();
            $policy->save();
        }
        // Once the gateway generation has switched, only the new services can receive
        // new traffic. Docker grants old tasks a grace period for in-flight requests.
        $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api) use ($cluster, $active): void {
            $filters = ['label' => ['com.codegalaxy.component=waf', 'com.codegalaxy.org.id=' . $cluster->org_id,
                'com.codegalaxy.cluster.id=' . $cluster->id]];
            $keep = array_column($active, 'service_name');
            $services = $api->request($client, 'GET', '/services', ['query' => ['filters' => json_encode($filters, JSON_THROW_ON_ERROR)]]);
            foreach ($services as $service) {
                $labels = $service['Spec']['Labels'] ?? [];
                if (($labels['com.codegalaxy.component'] ?? '') !== 'waf'
                    || (int) ($labels['com.codegalaxy.org.id'] ?? 0) !== (int) $cluster->org_id
                    || (int) ($labels['com.codegalaxy.cluster.id'] ?? 0) !== (int) $cluster->id
                    || in_array($service['Spec']['Name'] ?? '', $keep, true)) continue;
                $api->request($client, 'DELETE', '/services/' . rawurlencode((string) $service['ID']));
            }
            $configs = $api->request($client, 'GET', '/configs', ['query' => ['filters' => json_encode($filters, JSON_THROW_ON_ERROR)]]);
            foreach ($configs as $item) {
                $name = (string) ($item['Spec']['Name'] ?? '');
                foreach ($keep as $serviceName) {
                    if (str_starts_with($name, $serviceName . '-')) continue 2;
                }
                // Docker may still hold a retiring task's Config. Retry on the next sync.
                try { $api->request($client, 'DELETE', '/configs/' . rawurlencode((string) $item['ID'])); }
                catch (\Throwable) {}
            }
        });
    }

    public function status(Cluster $cluster, array $serviceIds): array
    {
        if ($serviceIds === []) return [];
        return $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api) use ($serviceIds): array {
            $tasks = $api->request($client, 'GET', '/tasks', ['query' => ['filters' => json_encode([
                'service' => array_values($serviceIds), 'desired-state' => ['running'],
            ], JSON_THROW_ON_ERROR)]]);
            $result = [];
            foreach ($serviceIds as $id) $result[$id] = ['running' => 0, 'error' => ''];
            foreach ($tasks as $task) {
                $id = (string) ($task['ServiceID'] ?? '');
                if (! isset($result[$id])) continue;
                if (($task['Status']['State'] ?? '') === 'running') ++$result[$id]['running'];
                if (! empty($task['Status']['Err'])) $result[$id]['error'] = mb_substr($task['Status']['Err'], 0, 512);
            }
            return $result;
        }, 10);
    }

    private function assertOwned(array $actual, array $expected): void
    {
        foreach ($expected as $key => $value) {
            if (($actual[$key] ?? null) !== $value) throw new AppException(409, 'WAF 资源名称被其他资源占用，拒绝覆盖');
        }
    }
}
