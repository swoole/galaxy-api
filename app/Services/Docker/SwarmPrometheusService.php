<?php

namespace App\Services\Docker;

use App\Exception\AppException;
use App\Model\Cluster;
use App\Model\ClusterPrometheus;
use App\Model\ClusterWebGateway;
use App\Services\RegistryService;
use App\Services\ContainerImageMappingService;
use GuzzleHttp\Client;
use Throwable;

class SwarmPrometheusService
{
    private const DEFAULT_IMAGE = 'registry.cn-shanghai.aliyuncs.com/swoole-public/prometheus:v3.2.1';
    private const DEFAULT_SERVICE = 'galaxy-prometheus';

    public function __construct(
        private SwarmApiClient $docker,
        private SwarmContainerExecService $exec,
        private RegistryService $registries,
        private ContainerImageMappingService $imageMappings
    ) {}

    public function profile(Cluster $cluster, bool $refresh = true): array
    {
        $prometheus = ClusterPrometheus::where('org_id', (int) $cluster->org_id)
            ->where('cluster_id', (int) $cluster->id)->first();
        if ($prometheus === null) {
            return ['installed' => false, 'prometheus' => null, 'runtime' => $this->emptyRuntime(),
                'defaults' => $this->defaults()];
        }
        $runtime = $this->emptyRuntime();
        if ($refresh && $prometheus->service_id !== '') {
            try {
                $runtime = $this->runtime($cluster, $prometheus);
                $prometheus->status = $runtime['running'] > 0
                    ? ClusterPrometheus::STATUS_RUNNING : ClusterPrometheus::STATUS_DEGRADED;
                $prometheus->error = $runtime['running'] > 0 ? null : 'Prometheus 尚无运行中的任务';
                $prometheus->synced_at = time();
                $prometheus->updated_at = time();
                $prometheus->save();
            } catch (Throwable $e) {
                $prometheus->status = ClusterPrometheus::STATUS_ERROR;
                $prometheus->error = mb_substr($e->getMessage(), 0, 2000);
                $prometheus->updated_at = time();
                $prometheus->save();
            }
        }
        return ['installed' => $prometheus->service_id !== '', 'prometheus' => $prometheus->fresh(),
            'runtime' => $runtime, 'defaults' => $this->defaults()];
    }

    public function deploy(int $uid, Cluster $cluster, array $input): array
    {
        $gateway = ClusterWebGateway::where('org_id', (int) $cluster->org_id)
            ->where('cluster_id', (int) $cluster->id)->first();
        if ($gateway === null || $gateway->service_id === '' || ! $gateway->metrics_enabled) {
            throw new AppException(422, '请先部署 Web 网关并启用 Prometheus 指标');
        }
        $image = $this->image($input['image'] ?? self::DEFAULT_IMAGE);
        $scrapeInterval = max(5, min(300, (int) ($input['scrape_interval'] ?? 15)));
        $retentionDays = max(1, min(365, (int) ($input['retention_days'] ?? 15)));
        $prometheus = ClusterPrometheus::firstOrNew(['cluster_id' => (int) $cluster->id]);
        if (! $prometheus->exists) {
            $prometheus->org_id = (int) $cluster->org_id;
            $prometheus->service_name = self::DEFAULT_SERVICE;
            $prometheus->volume_name = 'galaxy-' . $cluster->id . '-prometheus-data';
            $prometheus->creator = $uid;
            $prometheus->created_at = time();
        }
        $prometheus->image = $image;
        $prometheus->network_id = (string) $gateway->control_network_id;
        $prometheus->network_name = (string) $gateway->control_network_name;
        $prometheus->scrape_interval = $scrapeInterval;
        $prometheus->retention_days = $retentionDays;
        $prometheus->status = ClusterPrometheus::STATUS_PENDING;
        $prometheus->error = null;
        $prometheus->updated_at = time();
        $prometheus->save();

        try {
            $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api) use ($cluster, $prometheus, $gateway): void {
                $this->deployExporters($client, $api, $cluster, $gateway);
                $api->request($client, 'POST', '/volumes/create', ['json' => [
                    'Name' => (string) $prometheus->volume_name,
                    'Labels' => $this->labels((int) $prometheus->cluster_id),
                ]]);
                $yaml = $this->configuration((int) $prometheus->scrape_interval, (string) $gateway->service_name);
                $hash = substr(hash('sha256', $yaml), 0, 16);
                $configName = 'galaxy-prometheus-' . $prometheus->cluster_id . '-' . $hash;
                $configs = $api->request($client, 'GET', '/configs', ['query' => ['filters' => json_encode([
                    'name' => [$configName],
                ], JSON_THROW_ON_ERROR)]]);
                $configId = '';
                foreach ($configs as $config) {
                    if (($config['Spec']['Name'] ?? '') === $configName) {
                        $configId = (string) ($config['ID'] ?? '');
                    }
                }
                if ($configId === '') {
                    $created = $api->request($client, 'POST', '/configs/create', ['json' => [
                        'Name' => $configName, 'Data' => base64_encode($yaml),
                        'Labels' => $this->labels((int) $prometheus->cluster_id),
                    ]]);
                    $configId = (string) ($created['ID'] ?? '');
                }
                if ($configId === '') {
                    throw new AppException(502, 'Docker API 未返回 Prometheus Config ID');
                }
                $prometheus->config_id = $configId;
                $prometheus->config_name = $configName;
                $spec = $this->serviceSpec($prometheus, $cluster);
                $runtimeImage = (string) $spec['TaskTemplate']['ContainerSpec']['Image'];
                $registryHeaders = $this->registries->dockerAuthHeader(
                    (int) $cluster->org_id,
                    $runtimeImage
                );
                $raw = $api->requestRaw($client, 'POST', '/images/create', [
                    'headers' => $registryHeaders,
                    'query' => ['fromImage' => $runtimeImage],
                    'timeout' => 600,
                ]);
                foreach (preg_split('/\r?\n/', trim($raw)) ?: [] as $line) {
                    $message = json_decode($line, true);
                    $error = is_array($message)
                        ? trim((string) ($message['errorDetail']['message'] ?? $message['error'] ?? ''))
                        : '';
                    if ($error !== '') {
                        throw new AppException(502, '拉取 Prometheus 镜像失败：' . $error);
                    }
                }
                if ((string) $prometheus->service_id === '') {
                    $created = $api->request($client, 'POST', '/services/create', [
                        'headers' => $registryHeaders,
                        'json' => $spec,
                    ]);
                    $prometheus->service_id = (string) ($created['ID'] ?? '');
                } else {
                    $current = $api->request($client, 'GET', '/services/' . rawurlencode((string) $prometheus->service_id));
                    $spec['TaskTemplate']['ForceUpdate'] = (int) ($current['Spec']['TaskTemplate']['ForceUpdate'] ?? 0) + 1;
                    $api->request($client, 'POST', '/services/' . rawurlencode((string) $prometheus->service_id) . '/update', [
                        'query' => ['version' => (int) ($current['Version']['Index'] ?? 0), 'registryAuthFrom' => 'spec'],
                        'headers' => $registryHeaders,
                        'json' => $spec,
                    ]);
                }
                if ((string) $prometheus->service_id === '') {
                    throw new AppException(502, 'Docker API 未返回 Prometheus Service ID');
                }
                $prometheus->configuration = ['scrape_target' => $gateway->service_name . ':8080',
                    'metrics_path' => '/metrics', 'external_port' => null];
                $prometheus->status = ClusterPrometheus::STATUS_PENDING;
                $prometheus->synced_at = time();
                $prometheus->updated_at = time();
                $prometheus->save();
            }, 90);
        } catch (Throwable $e) {
            $prometheus->status = ClusterPrometheus::STATUS_ERROR;
            $prometheus->error = mb_substr($e->getMessage(), 0, 2000);
            $prometheus->updated_at = time();
            $prometheus->save();
            throw $e;
        }
        return $this->profile($cluster);
    }

    private function deployExporters(Client $client, SwarmApiClient $api, Cluster $cluster, ClusterWebGateway $gateway): void
    {
        $networkId = (string) $gateway->control_network_id;
        $labels = $this->labels((int) $cluster->id) + ['traefik.enable' => 'false'];

        $this->ensureGlobalService($client, $api, $cluster, 'galaxy-node-exporter', [
            'Name' => 'galaxy-node-exporter',
            'Labels' => $labels,
            'TaskTemplate' => [
                'ContainerSpec' => [
                    'Image' => $this->imageMappings->resolve($cluster, 'registry.cn-shanghai.aliyuncs.com/swoole-public/node-exporter:v1.8.2'),
                    'Args' => [
                        '--path.procfs=/host/proc',
                        '--path.sysfs=/host/sys',
                        '--path.rootfs=/rootfs',
                        '--collector.filesystem.mount-points-exclude=^/(sys|proc|dev|host|etc)($$|/)',
                        '--no-collector.hwmon',
                    ],
                    'Labels' => $labels,
                    'Mounts' => [
                        ['Type' => 'bind', 'Source' => '/proc', 'Target' => '/host/proc', 'ReadOnly' => true],
                        ['Type' => 'bind', 'Source' => '/sys', 'Target' => '/host/sys', 'ReadOnly' => true],
                        ['Type' => 'bind', 'Source' => '/', 'Target' => '/rootfs', 'ReadOnly' => true],
                    ],
                ],
                'RestartPolicy' => ['Condition' => 'any', 'Delay' => 5_000_000_000],
                'Networks' => [['Target' => $networkId]],
            ],
            'Mode' => ['Global' => (object) []],
            'EndpointSpec' => ['Mode' => 'vip'],
        ]);

        $this->ensureGlobalService($client, $api, $cluster, 'galaxy-cadvisor', [
            'Name' => 'galaxy-cadvisor',
            'Labels' => $labels,
            'TaskTemplate' => [
                'ContainerSpec' => [
                    'Image' => $this->imageMappings->resolve($cluster, 'registry.cn-shanghai.aliyuncs.com/swoole-public/cadvisor:v0.49.1'),
                    'Args' => ['--docker_only=true', '--housekeeping_interval=30s'],
                    'Labels' => $labels,
                    'Mounts' => [
                        ['Type' => 'bind', 'Source' => '/', 'Target' => '/rootfs', 'ReadOnly' => true],
                        ['Type' => 'bind', 'Source' => '/var/run', 'Target' => '/var/run', 'ReadOnly' => true],
                        ['Type' => 'bind', 'Source' => '/sys', 'Target' => '/sys', 'ReadOnly' => true],
                        ['Type' => 'bind', 'Source' => '/var/lib/docker/', 'Target' => '/var/lib/docker', 'ReadOnly' => true],
                    ],
                ],
                'RestartPolicy' => ['Condition' => 'any', 'Delay' => 5_000_000_000],
                'Networks' => [['Target' => $networkId]],
            ],
            'Mode' => ['Global' => (object) []],
            'EndpointSpec' => ['Mode' => 'vip'],
        ]);
    }

    private function ensureGlobalService(
        Client $client,
        SwarmApiClient $api,
        Cluster $cluster,
        string $name,
        array $spec
    ): void
    {
        $image = (string) ($spec['TaskTemplate']['ContainerSpec']['Image'] ?? '');
        $registryHeaders = $this->registries->dockerAuthHeader((int) $cluster->org_id, $image);
        $existing = $api->request($client, 'GET', '/services', ['query' => ['filters' => json_encode(
            ['name' => [$name]], JSON_THROW_ON_ERROR
        )]]);
        foreach ($existing as $service) {
            if (($service['Spec']['Name'] ?? '') === $name) {
                $currentImage = (string) ($service['Spec']['TaskTemplate']['ContainerSpec']['Image'] ?? '');
                $currentNetworks = array_column((array) ($service['Spec']['TaskTemplate']['Networks'] ?? []), 'Target');
                $desiredNetworks = array_column((array) ($spec['TaskTemplate']['Networks'] ?? []), 'Target');
                $currentContainer = (array) ($service['Spec']['TaskTemplate']['ContainerSpec'] ?? []);
                $desiredContainer = (array) ($spec['TaskTemplate']['ContainerSpec'] ?? []);
                if ($currentImage === $image
                    && $currentNetworks === $desiredNetworks
                    && (array) ($currentContainer['Args'] ?? []) === (array) ($desiredContainer['Args'] ?? [])
                    && (array) ($currentContainer['Mounts'] ?? []) === (array) ($desiredContainer['Mounts'] ?? [])) {
                    return;
                }
                $spec['TaskTemplate']['ForceUpdate'] = (int) (
                    $service['Spec']['TaskTemplate']['ForceUpdate'] ?? 0
                ) + 1;
                $api->request($client, 'POST', '/services/' . rawurlencode((string) $service['ID']) . '/update', [
                    'query' => [
                        'version' => (int) ($service['Version']['Index'] ?? 0),
                        'registryAuthFrom' => 'spec',
                    ],
                    'headers' => $registryHeaders,
                    'json' => $spec,
                ]);
                return;
            }
        }
        $api->request($client, 'POST', '/services/create', [
            'headers' => $registryHeaders,
            'json' => $spec,
        ]);
    }

    public function query(
        Cluster $cluster,
        string $query,
        ?int $start = null,
        ?int $end = null,
        ?int $step = null,
        ?int $at = null
    ): array
    {
        $prometheus = ClusterPrometheus::where('org_id', (int) $cluster->org_id)
            ->where('cluster_id', (int) $cluster->id)->first();
        if ($prometheus === null || $prometheus->service_id === '') {
            throw new AppException(409, '该集群尚未部署 Prometheus');
        }
        $path = $start === null ? '/api/v1/query' : '/api/v1/query_range';
        $params = ['query' => $query];
        if ($start !== null) {
            $params += ['start' => $start, 'end' => $end, 'step' => $step];
        } elseif ($at !== null) {
            $params['time'] = $at;
        }
        $url = 'http://127.0.0.1:9090' . $path . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $result = $this->exec->executeService($cluster, (string) $prometheus->service_id,
            ['/bin/wget', '-qO-', $url]);
        if (($result['exit_code'] ?? -1) !== 0) {
            throw new AppException(502, 'Prometheus 查询失败：' . trim((string) ($result['stderr'] ?? '')));
        }
        $payload = json_decode((string) ($result['stdout'] ?? ''), true);
        if (! is_array($payload) || ($payload['status'] ?? '') !== 'success') {
            throw new AppException(502, 'Prometheus 返回了无效响应');
        }
        return (array) ($payload['data'] ?? []);
    }

    private function serviceSpec(ClusterPrometheus $prometheus, Cluster $cluster): array
    {
        $labels = $this->labels((int) $prometheus->cluster_id) + ['traefik.enable' => 'false'];
        return [
            'Name' => (string) $prometheus->service_name,
            'Labels' => $labels,
            'TaskTemplate' => [
                'ContainerSpec' => [
                    'Image' => $this->imageMappings->resolve($cluster, (string) $prometheus->image),
                    'Args' => ['--config.file=/etc/prometheus/prometheus.yml',
                        '--storage.tsdb.path=/prometheus',
                        '--storage.tsdb.retention.time=' . $prometheus->retention_days . 'd',
                        '--web.enable-lifecycle'],
                    'Labels' => $labels,
                    'Mounts' => [['Type' => 'volume', 'Source' => (string) $prometheus->volume_name,
                        'Target' => '/prometheus']],
                    'Configs' => [[
                        'ConfigID' => (string) $prometheus->config_id,
                        'ConfigName' => (string) $prometheus->config_name,
                        'File' => ['Name' => '/etc/prometheus/prometheus.yml', 'UID' => '65534', 'GID' => '65534', 'Mode' => 0444],
                    ]],
                ],
                'Resources' => ['Limits' => ['NanoCPUs' => 1_000_000_000, 'MemoryBytes' => 1073741824],
                    'Reservations' => ['NanoCPUs' => 50_000_000, 'MemoryBytes' => 134217728]],
                'RestartPolicy' => ['Condition' => 'any', 'Delay' => 5_000_000_000],
                'Placement' => ['Constraints' => ['node.role==manager']],
                'Networks' => [['Target' => (string) $prometheus->network_id]],
            ],
            'Mode' => ['Replicated' => ['Replicas' => 1]],
            'UpdateConfig' => ['Parallelism' => 1, 'Delay' => 5_000_000_000,
                'FailureAction' => 'rollback', 'Order' => 'stop-first'],
            'EndpointSpec' => ['Mode' => 'vip'],
        ];
    }

    private function runtime(Cluster $cluster, ClusterPrometheus $prometheus): array
    {
        return $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api) use ($prometheus): array {
            $tasks = $api->request($client, 'GET', '/tasks', ['query' => ['filters' => json_encode([
                'service' => [(string) $prometheus->service_id],
            ], JSON_THROW_ON_ERROR)]]);
            $running = 0;
            $failed = 0;
            foreach ($tasks as $task) {
                $state = (string) ($task['Status']['State'] ?? '');
                $running += $state === 'running' ? 1 : 0;
                $failed += in_array($state, ['failed', 'rejected', 'orphaned'], true) ? 1 : 0;
            }
            return ['desired' => 1, 'running' => $running, 'failed' => $failed, 'tasks' => $tasks];
        });
    }

    private function configuration(int $interval, string $gatewayService): string
    {
        return "global:\n  scrape_interval: {$interval}s\n  evaluation_interval: {$interval}s\n"
            . "scrape_configs:\n"
            . "  - job_name: codegalaxy-traefik\n    metrics_path: /metrics\n"
            . "    static_configs:\n      - targets: ['{$gatewayService}:8080']\n"
            . "        labels:\n          component: web-gateway\n"
            . "  - job_name: codegalaxy-node-exporter\n    metrics_path: /metrics\n"
            . "    static_configs:\n      - targets: ['galaxy-node-exporter:9100']\n"
            . "        labels:\n          component: node\n"
            . "  - job_name: codegalaxy-cadvisor\n    metrics_path: /metrics\n"
            . "    static_configs:\n      - targets: ['galaxy-cadvisor:8080']\n"
            . "        labels:\n          component: container\n";
    }

    private function defaults(): array
    {
        return ['image' => self::DEFAULT_IMAGE, 'scrape_interval' => 15, 'retention_days' => 15,
            'service_name' => self::DEFAULT_SERVICE, 'external_port' => null];
    }

    private function emptyRuntime(): array
    {
        return ['desired' => 0, 'running' => 0, 'failed' => 0, 'tasks' => []];
    }

    private function labels(int $clusterId): array
    {
        return ['com.codegalaxy.component' => 'prometheus',
            'com.codegalaxy.cluster-id' => (string) $clusterId];
    }

    private function image(mixed $value): string
    {
        $image = trim((string) $value);
        if ($image === '' || strlen($image) > 1024 || preg_match('/[\x00-\x20]/', $image)) {
            throw new AppException(422, 'Prometheus 镜像格式不合法');
        }
        return $image;
    }
}
