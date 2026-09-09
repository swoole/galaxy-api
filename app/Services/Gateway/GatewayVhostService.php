<?php

namespace App\Services\Gateway;

use App\Exception\AppException;
use App\Model\Cluster;
use App\Model\ClusterWebGateway;
use App\Model\ProjectRuntime;
use App\Model\GatewayVhost;
use App\Model\GatewayVhostRewrite;
use App\Model\ProjectRoute;
use App\Model\TlsCertificate;
use App\Services\Docker\SwarmApiClient;
use App\Services\Docker\SwarmContainerExecService;
use App\Services\Docker\SwarmServiceReferenceLock;
use App\Services\Project\ProjectServiceIdentity;
use GuzzleHttp\Client;
use Hyperf\DbConnection\Db;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class GatewayVhostService
{
    public function __construct(
        private SwarmApiClient $docker,
        private SwarmGatewayMutationLock $gatewayLock,
        private SwarmContainerExecService $containerExec,
        private TlsCertificateService $certificates,
        private SwarmGatewayCertificateDeployer $certificateDeployer,
        private SwarmServiceReferenceLock $serviceReferenceLock
    ) {}

    public function list(
        int $orgId, int $clusterId, int $page = 1, int $pageSize = 20,
        int $projectId = 0, int $groupId = 0
    ): array
    {
        $query = GatewayVhost::where('org_id', $orgId)->where('cluster_id', $clusterId)
            ->with(['certificate', 'rewrites'])->orderBy('id', 'desc');
        // In a project context only expose routes belonging to that project.
        // Cluster context (projectId=0) retains the administrator's full view.
        if ($projectId > 0) {
            $serviceNames = ProjectRuntime::where('org_id', $orgId)
                ->where('group_id', $groupId)->where('project_id', $projectId)
                ->where('cluster_id', $clusterId)
                ->where('name', '<>', '')->get()
                ->flatMap(static fn ($runtime): array => array_values(array_filter([
                    trim((string) $runtime->runtime_ref),
                    ProjectServiceIdentity::runtimeDockerName($runtime),
                ])))->unique()->values()->all();
            $query->whereIn('target_service', $serviceNames ?: ['__no_project_service__']);
        }
        $total = $query->count();
        $rows = $query->forPage($page, $pageSize)->get();
        foreach ($rows as $row) {
            $row->setAttribute('route_source', 'gateway');
        }
        $projectRoutes = ProjectRoute::where('org_id', $orgId)->where('cluster_id', $clusterId)
            ->when($projectId > 0, fn ($q) => $q->where('group_id', $groupId)->where('project_id', $projectId))
            ->with('runtime')->with('project')->with('env')->with('certificate')->orderByDesc('id')->get();
        foreach ($projectRoutes as $route) {
            $route->setAttribute('route_source', 'project');
            $route->setAttribute('target_service', $route->runtime === null
                ? '' : ProjectServiceIdentity::runtimeDockerName($route->runtime));
        }
        return ['data' => $rows, 'project_routes' => $projectRoutes, 'total' => $total,
            'page' => $page, 'pagesize' => $pageSize];
    }

    public function create(int $orgId, int $clusterId, int $uid, array $input): GatewayVhost
    {
        $hasRewriteRelation = array_key_exists('rewrites', $input);
        $data = $this->normalize($input, true);
        unset($data['rewrites']);
        $rewrites = $hasRewriteRelation ? $this->normalizeRewrites($input) : $this->legacyRewrites($data);
        if ($hasRewriteRelation) {
            // The relation is authoritative once the multi-rule API is used.  Do
            // not leave a legacy rule behind that could reappear as a fallback.
            $data['rewrite_type'] = 'none';
            $data['rewrite_pattern'] = '';
            $data['rewrite_replacement'] = '';
        }
        $data['org_id'] = $orgId;
        $data['cluster_id'] = $clusterId;
        $data['vhost_key'] = $this->vhostKey(
            $data['hostname'], $data['path_prefix'], $data['path_match'], $data['methods']
        );
        $data['creator'] = $uid;
        $data['status'] = GatewayVhost::STATUS_PENDING;
        $data['error'] = null;
        $data['synced_at'] = 0;
        $data['created_at'] = time();
        $data['updated_at'] = time();

        return $this->serviceReferenceLock->synchronized(
            $orgId,
            $clusterId,
            [$data['target_service']],
            function () use ($orgId, $clusterId, $data, $rewrites): GatewayVhost {
                $this->assertTargetServiceExists($orgId, $clusterId, (string) $data['target_service']);
                $this->validateCertificate($orgId, $clusterId, $data);
                $this->assertHostnameAvailable($clusterId, $data['vhost_key']);
                $this->assertMethodMatcherAvailable(
                    $clusterId,
                    $data['hostname'],
                    $data['path_prefix'],
                    $data['path_match'],
                    $data['methods']
                );

                $vhost = Db::transaction(function () use ($data, $rewrites): GatewayVhost {
                    $vhost = GatewayVhost::create($data);
                    $this->persistRewrites($vhost, $rewrites);
                    return $vhost;
                });
                try {
                    $this->certificateDeployer->syncGateway($this->cluster($orgId, $clusterId));
                    $this->deploy($orgId, $clusterId);
                    return $this->markSynced($vhost);
                } catch (Throwable $e) {
                    $this->markError($vhost, $e);
                    throw $e;
                }
            }
        );
    }

    public function update(int $orgId, int $clusterId, int $id, array $input): GatewayVhost
    {
        $vhost = $this->findOrFail($orgId, $clusterId, $id);
        $hasRewriteRelation = array_key_exists('rewrites', $input);
        $data = $this->normalize($input, false);
        unset($data['rewrites']);
        // Keep the original single-rule fields functional for integrations that
        // have not moved to `rewrites` yet.  Once the relation is supplied it
        // deliberately takes precedence, including an explicit empty array.
        $rewrites = $hasRewriteRelation ? $this->normalizeRewrites($input)
            : (array_key_exists('rewrite_type', $data) ? $this->legacyRewrites($data) : null);
        if ($hasRewriteRelation) {
            $data['rewrite_type'] = 'none';
            $data['rewrite_pattern'] = '';
            $data['rewrite_replacement'] = '';
        }
        $data['updated_at'] = time();
        $data['status'] = GatewayVhost::STATUS_PENDING;
        $data['error'] = null;
        $data['vhost_key'] = $this->vhostKey(
            $data['hostname'] ?? $vhost->hostname,
            $data['path_prefix'] ?? $vhost->path_prefix,
            $data['path_match'] ?? $vhost->path_match,
            $data['methods'] ?? (array) $vhost->methods
        );
        return $this->serviceReferenceLock->synchronized(
            $orgId,
            $clusterId,
            [(string) $vhost->target_service, (string) ($data['target_service'] ?? $vhost->target_service)],
            function () use ($orgId, $clusterId, $id, $vhost, $data, $rewrites): GatewayVhost {
                $this->assertTargetServiceExists(
                    $orgId,
                    $clusterId,
                    (string) ($data['target_service'] ?? $vhost->target_service)
                );
                $this->validateCertificate($orgId, $clusterId, $data + $vhost->toArray());

                if ($data['vhost_key'] !== $vhost->vhost_key) {
                    $this->assertHostnameAvailable($vhost->cluster_id, $data['vhost_key'], $id);
                }
                $this->assertMethodMatcherAvailable(
                    $clusterId,
                    $data['hostname'] ?? $vhost->hostname,
                    $data['path_prefix'] ?? $vhost->path_prefix,
                    $data['path_match'] ?? $vhost->path_match,
                    $data['methods'] ?? (array) $vhost->methods,
                    $id
                );

                Db::transaction(function () use ($vhost, $data, $rewrites): void {
                    $vhost->update($data);
                    if ($rewrites !== null) {
                        $this->persistRewrites($vhost, $rewrites);
                    }
                });
                try {
                    $this->certificateDeployer->syncGateway($this->cluster($orgId, $clusterId));
                    $this->deploy($vhost->org_id, $vhost->cluster_id);
                    return $this->markSynced($vhost);
                } catch (Throwable $e) {
                    $this->markError($vhost, $e);
                    throw $e;
                }
            }
        );
    }

    public function delete(int $orgId, int $clusterId, int $id): void
    {
        $vhost = $this->findOrFail($orgId, $clusterId, $id);
        $this->serviceReferenceLock->synchronized(
            $orgId,
            $clusterId,
            [(string) $vhost->target_service],
            function () use ($orgId, $clusterId, $vhost): void {
                $vhost->enabled = false;
                $vhost->status = GatewayVhost::STATUS_PENDING;
                $vhost->error = null;
                $vhost->updated_at = time();
                $vhost->save();
                try {
                    $this->certificateDeployer->syncGateway($this->cluster($orgId, $clusterId));
                    $this->deploy($orgId, $clusterId);
                    Db::transaction(function () use ($vhost): void {
                        GatewayVhostRewrite::where('vhost_id', $vhost->id)->delete();
                        $vhost->delete();
                    });
                } catch (Throwable $e) {
                    $this->markError($vhost, $e);
                    throw $e;
                }
            }
        );
    }

    /**
     * Replace certificates for several administrator VHosts and materialize
     * one Traefik snapshot. This avoids one disruptive Swarm rolling update
     * per route when adopting ACME certificates.
     *
     * @param array<int, array{vhost_id:int, certificate_id:int}> $assignments
     */
    public function assignCertificates(int $orgId, int $clusterId, array $assignments): array
    {
        $vhosts = [];
        foreach ($assignments as $assignment) {
            $id = (int) ($assignment['vhost_id'] ?? 0);
            $certificateId = (int) ($assignment['certificate_id'] ?? 0);
            $vhost = $this->findOrFail($orgId, $clusterId, $id);
            $this->validateCertificate($orgId, $clusterId, [
                'hostname' => (string) $vhost->hostname,
                'tls_enabled' => true,
                'certificate_id' => $certificateId,
            ]);
            $vhosts[$id] = ['vhost' => $vhost, 'certificate_id' => $certificateId];
        }
        Db::transaction(function () use ($vhosts): void {
            foreach ($vhosts as $item) {
                /** @var GatewayVhost $vhost */
                $vhost = $item['vhost'];
                $vhost->tls_enabled = true;
                $vhost->certificate_id = $item['certificate_id'];
                $vhost->status = GatewayVhost::STATUS_PENDING;
                $vhost->error = null;
                $vhost->synced_at = 0;
                $vhost->updated_at = time();
                $vhost->save();
            }
        });
        try {
            // Materialize certResolver on every affected router while the old
            // manual certificate is still mounted. The following gateway sync
            // then removes obsolete manual Secrets/Configs and forces a rolling
            // update, allowing Traefik to perform the first ACME issuance.
            //
            // Keeping a matching manual certificate mounted indefinitely is
            // not a safe fallback: Traefik considers that domain covered and
            // never starts ACME, leaving the managed asset pending forever.
            $this->deploy($orgId, $clusterId);
            $this->certificateDeployer->syncGateway($this->cluster($orgId, $clusterId));
            foreach ($vhosts as $item) {
                $this->markSynced($item['vhost']);
            }
        } catch (Throwable $e) {
            foreach ($vhosts as $item) {
                $this->markError($item['vhost'], $e);
            }
            throw $e;
        }
        return array_values(array_map(
            static fn (array $item): GatewayVhost => $item['vhost']->fresh(['certificate', 'rewrites']),
            $vhosts
        ));
    }

    public function deploy(int $orgId, int $clusterId): void
    {
        $this->gatewayLock->synchronized($orgId, $clusterId, function () use ($orgId, $clusterId): void {
            $this->deployUnlocked($orgId, $clusterId);
        }, 30, 300);
    }

    private function assertTargetServiceExists(int $orgId, int $clusterId, string $serviceName): void
    {
        // Legacy gateway migration may temporarily route to a host-published
        // backend on the selected Manager. Keeping the IP in the persistent
        // VHost lets Traefik replace Nginx before every legacy Compose project
        // has itself moved to Swarm. Hostnames remain Service-only to avoid
        // turning the gateway API into an unrestricted SSRF primitive.
        if (filter_var($serviceName, FILTER_VALIDATE_IP) !== false) {
            return;
        }
        $cluster = $this->cluster($orgId, $clusterId);
        try {
            $this->docker->withCluster(
                $cluster,
                static fn (Client $client, SwarmApiClient $api): array => $api->request(
                    $client,
                    'GET',
                    '/services/' . rawurlencode($serviceName)
                ),
                20
            );
        } catch (AppException $e) {
            if (str_contains($e->getMessage(), '404')) {
                throw new AppException(422, '目标 Swarm Service 不存在或已被删除');
            }
            throw $e;
        }
    }

    private function persistRewrites(GatewayVhost $vhost, array $rewrites): void
    {
        GatewayVhostRewrite::where('vhost_id', $vhost->id)->delete();
        $now = time();
        foreach ($rewrites as $index => $rewrite) {
            GatewayVhostRewrite::create([
                'vhost_id' => $vhost->id, 'sort_order' => $index,
                'rewrite_type' => $rewrite['rewrite_type'], 'rewrite_pattern' => $rewrite['rewrite_pattern'],
                'rewrite_replacement' => $rewrite['rewrite_replacement'], 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    /**
     * Validate and canonicalize the ordered rewrite relation.  This is kept in
     * the service because callers other than the HTTP controller must not be
     * able to persist an invalid Traefik middleware.
     */
    private function normalizeRewrites(array $input): array
    {
        if (!array_key_exists('rewrites', $input)) {
            return [];
        }
        if (!is_array($input['rewrites']) || count($input['rewrites']) > 20) {
            throw new AppException(422, 'URL Rewrite 规则最多 20 条');
        }

        $normalized = [];
        foreach (array_values($input['rewrites']) as $index => $rewrite) {
            if (!is_array($rewrite)) {
                throw new AppException(422, 'URL Rewrite 规则格式不合法');
            }
            $type = (string) ($rewrite['rewrite_type'] ?? '');
            $pattern = trim((string) ($rewrite['rewrite_pattern'] ?? ''));
            $replacement = trim((string) ($rewrite['rewrite_replacement'] ?? ''));
            if (!in_array($type, ['strip_prefix', 'replace_path_regex'], true) || $pattern === '') {
                throw new AppException(422, 'URL Rewrite 类型或匹配规则无效（第 ' . ($index + 1) . ' 条）');
            }
            if ($type === 'strip_prefix') {
                $pattern = '/' . ltrim($pattern, '/');
                $replacement = '';
            } elseif ($replacement === '') {
                throw new AppException(422, '正则路径改写必须填写替换路径（第 ' . ($index + 1) . ' 条）');
            }
            if (strlen($pattern) > 1024 || strlen($replacement) > 1024
                || preg_match('/[`\x00-\x1f\x7f]/', $pattern . $replacement)) {
                throw new AppException(422, 'URL Rewrite 内容不合法（第 ' . ($index + 1) . ' 条）');
            }
            $normalized[] = [
                'rewrite_type' => $type,
                'rewrite_pattern' => $pattern,
                'rewrite_replacement' => $replacement,
            ];
        }
        return $normalized;
    }

    /** Convert the retained one-rule API shape to its relational equivalent. */
    private function legacyRewrites(array $data): array
    {
        if (($data['rewrite_type'] ?? 'none') === 'none') {
            return [];
        }
        return [[
            'rewrite_type' => (string) $data['rewrite_type'],
            'rewrite_pattern' => trim((string) ($data['rewrite_pattern'] ?? '')),
            'rewrite_replacement' => trim((string) ($data['rewrite_replacement'] ?? '')),
        ]];
    }

    private function markSynced(GatewayVhost $vhost): GatewayVhost
    {
        $now = time();
        $vhost->status = GatewayVhost::STATUS_SYNCED;
        $vhost->error = null;
        $vhost->synced_at = $now;
        $vhost->updated_at = $now;
        $vhost->save();
        return $vhost->fresh(['certificate', 'rewrites']);
    }

    private function markError(GatewayVhost $vhost, Throwable $error): void
    {
        try {
            $vhost->status = GatewayVhost::STATUS_ERROR;
            $vhost->error = mb_substr($error->getMessage(), 0, 2000);
            $vhost->updated_at = time();
            $vhost->save();
        } catch (Throwable) {
            // Preserve the original gateway reconciliation error.
        }
    }

    private function deployUnlocked(int $orgId, int $clusterId): void
    {
        /** @var ClusterWebGateway|null $gateway */
        $gateway = ClusterWebGateway::where('org_id', $orgId)
            ->where('cluster_id', $clusterId)->where('service_id', '<>', '')->first();
        if ($gateway === null) {
            throw new AppException(409, '集群尚未安装 Web 网关，无法同步域名路由');
        }
        $cluster = $this->cluster($orgId, $clusterId);

        $vhosts = GatewayVhost::where('org_id', $orgId)->where('cluster_id', $clusterId)
            ->where('enabled', 1)->with(['certificate', 'rewrites'])->orderBy('id')->get();
        $projectRoutes = ProjectRoute::where('org_id', $orgId)->where('cluster_id', $clusterId)
            ->where('enabled', 1)->orderBy('id')->get();
        $configuration = $this->buildYaml($vhosts);
        $projectTransports = $this->buildProjectRouteTransports($projectRoutes);
        if ($projectTransports !== []) {
            $configuration['http']['serversTransports'] = array_merge(
                (array) ($configuration['http']['serversTransports'] ?? []),
                $projectTransports
            );
        }
        $yaml = $vhosts->isEmpty() && $projectRoutes->isEmpty()
            ? "# empty\n"
            : Yaml::dump(
                $configuration,
                5,
                2,
                Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK
            );

        $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api) use ($gateway, $yaml, $vhosts): void {
            // Ensure each target service is attached to the gateway entry network,
            // otherwise Traefik cannot resolve or reach it via Docker DNS.
            $gatewayNetworkId = (string) $gateway->network_id;
            $targetServices = [];
            foreach ($vhosts as $vhost) {
                $name = trim((string) $vhost->target_service);
                if ($name !== '' && $name !== $gateway->service_name
                    && filter_var($name, FILTER_VALIDATE_IP) === false) {
                    $targetServices[$name] = true;
                }
            }
            foreach (array_keys($targetServices) as $serviceName) {
                $this->ensureServiceAttachedToNetwork($client, $api, $serviceName, $gatewayNetworkId);
            }

            // Docker Configs are immutable. Writing only into the running
            // container makes the route disappear as soon as Swarm replaces
            // the Traefik task. Rotate the mounted Config and update the
            // Service so every current and future task receives this snapshot.
            $config = $this->ensureVhostConfig($client, $api, $gateway, $yaml);
            $service = $api->request(
                $client,
                'GET',
                '/services/' . rawurlencode((string) $gateway->service_id)
            );
            $spec = (array) ($service['Spec'] ?? []);
            $container = (array) ($spec['TaskTemplate']['ContainerSpec'] ?? []);
            $container['Configs'] = array_values(array_filter(
                (array) ($container['Configs'] ?? []),
                static fn (array $reference): bool =>
                    (string) ($reference['File']['Name'] ?? '') !== '/dynamic/vhosts.yml'
                    && ! str_starts_with(
                        (string) ($reference['ConfigName'] ?? ''),
                        'galaxy-web-vhosts-'
                    )
            ));
            $container['Configs'][] = [
                'ConfigID' => $config['id'],
                'ConfigName' => $config['name'],
                'File' => ['Name' => '/dynamic/vhosts.yml', 'UID' => '0', 'GID' => '0', 'Mode' => 292],
            ];
            $spec['TaskTemplate']['ContainerSpec'] = $container;
            $spec['TaskTemplate']['ForceUpdate'] =
                (int) ($service['Spec']['TaskTemplate']['ForceUpdate'] ?? 0) + 1;
            $spec = $this->normalizeServiceSpecForUpdate($spec);
            $api->request(
                $client,
                'POST',
                '/services/' . rawurlencode((string) $gateway->service_id) . '/update',
                [
                    'query' => [
                        'version' => (int) ($service['Version']['Index'] ?? 0),
                        'registryAuthFrom' => 'spec',
                    ],
                    'json' => $spec,
                ]
            );
            $this->containerExec->waitForRunningContainerIds(
                $client,
                $api,
                (string) $gateway->service_id,
                60,
                (int) $spec['TaskTemplate']['ForceUpdate']
            );
        }, 60);

        $now = time();
        GatewayVhost::where('org_id', $orgId)->where('cluster_id', $clusterId)
            ->where('enabled', 1)->update([
                'status' => GatewayVhost::STATUS_SYNCED,
                'error' => null,
                'synced_at' => $now,
                'updated_at' => $now,
            ]);
    }

    /** @return array{id: string, name: string} */
    private function ensureVhostConfig(
        Client $client,
        SwarmApiClient $api,
        ClusterWebGateway $gateway,
        string $yaml
    ): array {
        $name = sprintf(
            'galaxy-web-vhosts-%d-%s',
            (int) $gateway->cluster_id,
            substr(hash('sha256', $yaml), 0, 16)
        );
        $configs = $api->request($client, 'GET', '/configs', ['query' => [
            'filters' => json_encode(['name' => [$name]], JSON_THROW_ON_ERROR),
        ]]);
        foreach ($configs as $config) {
            if ((string) ($config['Spec']['Name'] ?? '') === $name) {
                return ['id' => (string) ($config['ID'] ?? ''), 'name' => $name];
            }
        }
        $created = $api->request($client, 'POST', '/configs/create', ['json' => [
            'Name' => $name,
            'Data' => base64_encode($yaml),
            'Labels' => [
                'com.codegalaxy.component' => 'web-gateway-vhosts',
                'com.codegalaxy.cluster-id' => (string) $gateway->cluster_id,
            ],
        ]]);
        $id = (string) ($created['ID'] ?? '');
        if ($id === '') {
            throw new AppException(502, 'Docker API 未返回 Web 网关路由 Config ID');
        }
        return ['id' => $id, 'name' => $name];
    }

    /**
     * Attach a Swarm service to the gateway entry network if it is not already attached.
     * Silently skips services that do not exist; the router will surface a 502 instead.
     */
    private function ensureServiceAttachedToNetwork(
        Client $client,
        SwarmApiClient $api,
        string $serviceName,
        string $networkId
    ): void {
        if ($networkId === '') {
            return;
        }
        $services = $api->request($client, 'GET', '/services', ['query' => [
            'filters' => json_encode(['name' => [$serviceName]], JSON_THROW_ON_ERROR),
        ]]);
        $service = null;
        foreach ($services as $candidate) {
            if (($candidate['Spec']['Name'] ?? '') === $serviceName) {
                $service = $candidate;
                break;
            }
        }
        if ($service === null) {
            return;
        }
        $networks = (array) ($service['Spec']['TaskTemplate']['Networks'] ?? []);
        foreach ($networks as $network) {
            if (($network['Target'] ?? '') === $networkId) {
                return; // already attached
            }
        }
        $networks[] = ['Target' => $networkId];
        $service['Spec']['TaskTemplate']['Networks'] = $networks;
        $spec = $this->normalizeServiceSpecForUpdate((array) $service['Spec']);
        $api->request($client, 'POST', '/services/' . rawurlencode((string) $service['ID']) . '/update', [
            'query' => [
                'version' => (int) ($service['Version']['Index'] ?? 0),
                'registryAuthFrom' => 'spec',
            ],
            'json' => $spec,
        ]);
    }

    /**
     * Docker serializes empty Go structs as `{}`, while json_decode turns both
     * `{}` and `[]` into an empty PHP array. Sending that value back changes
     * the JSON type to `[]`, which the Service update API rejects. Empty
     * optional structs are equivalent to omission, so remove them before the
     * inspect → modify → update round trip.
     */
    private function normalizeServiceSpecForUpdate(array $spec): array
    {
        foreach (['Resources', 'RestartPolicy', 'Placement', 'LogDriver'] as $field) {
            if (($spec['TaskTemplate'][$field] ?? null) === []) {
                unset($spec['TaskTemplate'][$field]);
            }
        }
        foreach (['UpdateConfig', 'RollbackConfig', 'EndpointSpec'] as $field) {
            if (($spec[$field] ?? null) === []) {
                unset($spec[$field]);
            }
        }
        if (isset($spec['TaskTemplate']['Resources'])
            && is_array($spec['TaskTemplate']['Resources'])) {
            foreach (['Limits', 'Reservations'] as $field) {
                if (($spec['TaskTemplate']['Resources'][$field] ?? null) === []) {
                    unset($spec['TaskTemplate']['Resources'][$field]);
                }
            }
            if ($spec['TaskTemplate']['Resources'] === []) {
                unset($spec['TaskTemplate']['Resources']);
            }
        }
        return $spec;
    }

    public function buildYaml(iterable $vhosts): array
    {
        $routers = [];
        $services = [];
        $middlewares = [];
        $serversTransports = [];

        foreach ($vhosts as $vhost) {
            /** @var GatewayVhost $vhost */
            $id = $vhost->id;
            $routerName = 'cg-vhost-' . $id;
            $serviceName = $routerName . '-svc';
            $rule = sprintf('Host(`%s`)', $vhost->hostname);
            if ($vhost->path_prefix !== '/' && $vhost->path_prefix !== '') {
                $matcher = match ((string) $vhost->path_match) {
                    'exact' => 'Path',
                    'regex' => 'PathRegexp',
                    default => 'PathPrefix',
                };
                $rule .= sprintf(' && %s(`%s`)', $matcher, $vhost->path_prefix);
            }
            if ((array) $vhost->methods !== []) {
                $rule .= ' && Method(' . implode(',', array_map(
                    static fn (string $method): string => '`' . $method . '`',
                    (array) $vhost->methods
                )) . ')';
            }
            foreach ((array) $vhost->ip_denylist as $cidr) {
                $rule .= sprintf(' && !ClientIP(`%s`)', $cidr);
            }

            $entryPoints = $vhost->tls_enabled ? ['websecure'] : [$vhost->entrypoint ?: 'web'];
            $routerMiddlewares = [];

            if ((array) $vhost->ip_allowlist !== []) {
                $middlewareName = $routerName . '-allow-ip';
                $middlewares[$middlewareName] = ['ipAllowList' => [
                    'sourceRange' => array_values((array) $vhost->ip_allowlist),
                ]];
                $routerMiddlewares[] = $middlewareName;
            }
            if ($vhost->security_headers_enabled) {
                $middlewareName = $routerName . '-security-headers';
                $middlewares[$middlewareName] = ['headers' => [
                    'contentTypeNosniff' => true,
                    'frameDeny' => true,
                    'referrerPolicy' => 'strict-origin-when-cross-origin',
                    'permissionsPolicy' => 'camera=(), microphone=(), geolocation=()',
                ]];
                $routerMiddlewares[] = $middlewareName;
            }
            if ((array) $vhost->custom_request_headers !== []
                || (array) $vhost->custom_response_headers !== []
                || $vhost->cors_enabled) {
                $middlewareName = $routerName . '-headers';
                $headers = [];
                if ((array) $vhost->custom_request_headers !== []) {
                    $headers['customRequestHeaders'] = (array) $vhost->custom_request_headers;
                }
                if ((array) $vhost->custom_response_headers !== []) {
                    $headers['customResponseHeaders'] = (array) $vhost->custom_response_headers;
                }
                if ($vhost->cors_enabled) {
                    $headers['accessControlAllowOriginList'] = array_values((array) $vhost->cors_allow_origins);
                    $headers['accessControlAllowMethods'] = array_values((array) $vhost->cors_allow_methods);
                    $headers['accessControlAllowHeaders'] = array_values((array) $vhost->cors_allow_headers);
                    $headers['accessControlAllowCredentials'] = (bool) $vhost->cors_allow_credentials;
                    $headers['accessControlMaxAge'] = (int) $vhost->cors_max_age_seconds;
                    $headers['addVaryHeader'] = true;
                }
                $middlewares[$middlewareName] = ['headers' => $headers];
                $routerMiddlewares[] = $middlewareName;
            }
            if ($vhost->compress_enabled) {
                $middlewareName = $routerName . '-compress';
                // Compression may retain small SSE frames. Keep regular
                // response compression, but pass event streams unchanged.
                $middlewares[$middlewareName] = ['compress' => [
                    'excludedContentTypes' => ['text/event-stream'],
                ]];
                $routerMiddlewares[] = $middlewareName;
            }
            if ((int) $vhost->request_body_limit_bytes > 0) {
                $middlewareName = $routerName . '-body-limit';
                $middlewares[$middlewareName] = ['buffering' => [
                    'maxRequestBodyBytes' => (int) $vhost->request_body_limit_bytes,
                    'memRequestBodyBytes' => min((int) $vhost->request_body_limit_bytes, 2097152),
                ]];
                $routerMiddlewares[] = $middlewareName;
            }
            if ((int) $vhost->rate_limit_average > 0) {
                $middlewareName = $routerName . '-rate-limit';
                $middlewares[$middlewareName] = ['rateLimit' => [
                    'average' => (int) $vhost->rate_limit_average,
                    'burst' => (int) $vhost->rate_limit_burst,
                    'period' => max(1, (int) $vhost->rate_limit_period_seconds) . 's',
                ]];
                $routerMiddlewares[] = $middlewareName;
            }
            if ((int) $vhost->max_inflight_requests > 0) {
                $middlewareName = $routerName . '-inflight';
                $middlewares[$middlewareName] = ['inFlightReq' => [
                    'amount' => (int) $vhost->max_inflight_requests,
                ]];
                $routerMiddlewares[] = $middlewareName;
            }

            // URL rewrite middlewares (ordered; retain legacy single-rule columns).
            $rewrites = $vhost->rewrites->all();
            if ($rewrites === [] && $vhost->rewrite_type !== 'none' && $vhost->rewrite_pattern !== '') {
                $rewrites = [(object) [
                    'rewrite_type' => $vhost->rewrite_type,
                    'rewrite_pattern' => $vhost->rewrite_pattern,
                    'rewrite_replacement' => $vhost->rewrite_replacement,
                ]];
            }
            foreach ($rewrites as $rewriteIndex => $rewrite) {
                $middlewareName = $routerName . '-rewrite-' . ($rewriteIndex + 1);
                if ($rewrite->rewrite_type === 'strip_prefix' && $rewrite->rewrite_pattern !== '') {
                    $middlewares[$middlewareName] = ['stripPrefix' => ['prefixes' => [$rewrite->rewrite_pattern]]];
                } elseif ($rewrite->rewrite_type === 'replace_path_regex' && $rewrite->rewrite_pattern !== '') {
                    $middlewares[$middlewareName] = ['replacePathRegex' => ['regex' => $rewrite->rewrite_pattern, 'replacement' => $rewrite->rewrite_replacement ?: '']];
                } else { continue; }
                $routerMiddlewares[] = $middlewareName;
            }
            if ((int) $vhost->retry_attempts > 0) {
                $middlewareName = $routerName . '-retry';
                $middlewares[$middlewareName] = ['retry' => [
                    'attempts' => (int) $vhost->retry_attempts,
                    'initialInterval' => max(10, (int) $vhost->retry_initial_interval_ms) . 'ms',
                ]];
                $routerMiddlewares[] = $middlewareName;
            }
            if ((string) $vhost->circuit_breaker_expression !== '') {
                $middlewareName = $routerName . '-circuit-breaker';
                $middlewares[$middlewareName] = ['circuitBreaker' => [
                    'expression' => (string) $vhost->circuit_breaker_expression,
                ]];
                $routerMiddlewares[] = $middlewareName;
            }

            // HTTPS redirect (creates a second HTTP router)
            if ($vhost->tls_enabled && $vhost->https_redirect) {
                $httpRedirectMiddleware = $routerName . '-https';
                $middlewares[$httpRedirectMiddleware] = [
                    'redirectScheme' => ['scheme' => 'https', 'permanent' => true],
                ];
                $redirectMiddlewares = [];
                if ((array) $vhost->ip_allowlist !== []) {
                    $redirectMiddlewares[] = $routerName . '-allow-ip';
                }
                $redirectMiddlewares[] = $httpRedirectMiddleware;
                $routers[$routerName . '-http'] = [
                    'rule' => $rule,
                    'service' => $serviceName,
                    'entryPoints' => ['web'],
                    'middlewares' => $redirectMiddlewares,
                ];
                if ($vhost->priority > 0) {
                    $routers[$routerName . '-http']['priority'] = $vhost->priority;
                }
            }

            $router = [
                'rule' => $rule,
                'service' => $serviceName,
                'entryPoints' => $entryPoints,
            ];
            if ($vhost->priority > 0) {
                $router['priority'] = $vhost->priority;
            }
            if ($vhost->tls_enabled) {
                if ($vhost->certificate?->source === TlsCertificate::SOURCE_LETS_ENCRYPT) {
                    $router['tls'] = ['certResolver' => 'letsencrypt'];
                    $domains = array_values((array) $vhost->certificate->domains);
                    if ($domains !== []) {
                        $router['tls']['domains'] = [['main' => (string) $domains[0]]];
                        if (count($domains) > 1) {
                            $router['tls']['domains'][0]['sans'] = array_values(array_slice($domains, 1));
                        }
                    }
                } else {
                    $router['tls'] = new \stdClass();
                }
            }
            if ($routerMiddlewares !== []) {
                $router['middlewares'] = $routerMiddlewares;
            }
            $routers[$routerName] = $router;
            // Every VHost gets a higher-priority SSE path. Traefik's
            // Buffering middleware buffers responses too, and event streams
            // must also bypass compression regardless of response headers.
            $streamRouter = $router;
            $streamRouter['rule'] = $rule . ' && Header(`Accept`, `text/event-stream`)';
            $streamRouter['middlewares'] = array_values(array_filter(
                $routerMiddlewares,
                static fn (string $middleware): bool => ! in_array($middleware, [
                    $routerName . '-compress',
                    $routerName . '-body-limit',
                ], true)
            ));
            if ($streamRouter['middlewares'] === []) {
                unset($streamRouter['middlewares']);
            }
            if ($vhost->priority > 0) {
                $streamRouter['priority'] = $vhost->priority + 1;
            }
            $routers[$routerName . '-sse'] = $streamRouter;

            $transportName = $routerName . '-transport';
            $serversTransports[$transportName] = ['forwardingTimeouts' => [
                'dialTimeout' => max(0, (int) $vhost->dial_timeout_ms) . 'ms',
                'responseHeaderTimeout' => max(0, (int) $vhost->response_header_timeout_ms) . 'ms',
                'idleConnTimeout' => max(0, (int) $vhost->idle_connection_timeout_ms) . 'ms',
            ]];

            // Service — resolve via Docker DNS.
            $loadBalancer = [
                    'servers' => [
                        ['url' => sprintf('%s://%s:%d', $vhost->upstream_scheme ?: 'http',
                            filter_var((string) $vhost->target_service, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
                                ? '[' . $vhost->target_service . ']'
                                : $vhost->target_service,
                            $vhost->target_port)],
                    ],
                    'passHostHeader' => (bool) $vhost->pass_host_header,
                    'serversTransport' => $transportName,
                    // A negative interval asks Traefik to flush every write.
                    'responseForwarding' => ['flushInterval' => '-1ms'],
            ];
            if ((string) $vhost->healthcheck_path !== '') {
                $loadBalancer['healthCheck'] = [
                    'path' => (string) $vhost->healthcheck_path,
                    'interval' => max(1000, (int) $vhost->healthcheck_interval_ms) . 'ms',
                    'timeout' => max(100, (int) $vhost->healthcheck_timeout_ms) . 'ms',
                ];
            }
            if ($vhost->sticky_cookie_enabled) {
                $loadBalancer['sticky'] = ['cookie' => [
                    'name' => (string) $vhost->sticky_cookie_name,
                    'httpOnly' => true,
                    'secure' => (bool) $vhost->tls_enabled,
                    'sameSite' => 'lax',
                ]];
            }
            $services[$serviceName] = ['loadBalancer' => $loadBalancer];
        }

        return ['http' => array_filter([
            'routers' => $routers,
            'services' => $services,
            'middlewares' => $middlewares,
            'serversTransports' => $serversTransports,
        ])];
    }

    /**
     * Docker labels cannot define a ServersTransport. Project routes keep
     * their router labels for metric continuity and reference these file-provider
     * transports for per-route upstream timeouts.
     */
    public function buildProjectRouteTransports(iterable $routes): array
    {
        $transports = [];
        foreach ($routes as $route) {
            $transports['cg-project-route-' . $route->id . '-transport'] = ['forwardingTimeouts' => [
                'dialTimeout' => max(0, (int) $route->dial_timeout_ms) . 'ms',
                'responseHeaderTimeout' => max(0, (int) $route->response_header_timeout_ms) . 'ms',
                'idleConnTimeout' => max(0, (int) $route->idle_connection_timeout_ms) . 'ms',
            ]];
        }
        return $transports;
    }

    private function normalize(array $input, bool $isCreate): array
    {
        // Validator output may contain every nullable field with a null value.
        // A partial update must treat those as "not supplied"; otherwise null
        // is coerced to 0/empty string and silently resets unrelated policies.
        $input = array_filter($input, static fn (mixed $value): bool => $value !== null);

        if ($isCreate || array_key_exists('hostname', $input)) {
            $hostname = trim((string) ($input['hostname'] ?? ''));
            if ($hostname === '' || strlen($hostname) > 253) {
                throw new AppException(422, '请输入有效域名');
            }
            if (! preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)*\.?[a-zA-Z]{2,}$/', $hostname)) {
                throw new AppException(422, '域名格式不正确');
            }
            $input['hostname'] = $hostname;
        }

        if (array_key_exists('path_prefix', $input)) {
            $path = trim((string) ($input['path_prefix'] ?: '/'));
            if (($input['path_match'] ?? 'prefix') !== 'regex') {
                $path = $path === '' ? '/' : '/' . ltrim($path, '/');
                $path = $path === '/' ? '/' : rtrim($path, '/');
            }
            if (strlen($path) > 1024 || preg_match('/[`\x00-\x1f\x7f]/', $path)) {
                throw new AppException(422, '路径前缀不合法');
            }
            $input['path_prefix'] = $path;
        }

        if (array_key_exists('target_service', $input)) {
            $svc = trim((string) ($input['target_service'] ?? ''));
            if ($svc === '' || strlen($svc) > 255 || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $svc)) {
                throw new AppException(422, '目标 Service 名称不合法');
            }
            $input['target_service'] = $svc;
        }

        if (array_key_exists('target_port', $input)) {
            $port = (int) ($input['target_port'] ?? 0);
            if ($port < 1 || $port > 65535) {
                throw new AppException(422, '目标端口范围为 1-65535');
            }
            $input['target_port'] = $port;
        }

        if (array_key_exists('tls_enabled', $input) && ! (bool) $input['tls_enabled']) {
            $input['certificate_id'] = 0;
            $input['https_redirect'] = false;
        }

        if (array_key_exists('entrypoint', $input)) {
            $entrypoint = trim((string) $input['entrypoint']);
            if (! preg_match('/^[a-zA-Z0-9_.-]{1,64}$/', $entrypoint)) {
                throw new AppException(422, 'Traefik EntryPoint 名称不合法');
            }
            $input['entrypoint'] = $entrypoint;
        }

        if (array_key_exists('rewrite_type', $input)) {
            if (! in_array($input['rewrite_type'], ['none', 'strip_prefix', 'replace_path_regex'], true)) {
                throw new AppException(422, 'URL Rewrite 类型无效');
            }
            if ($input['rewrite_type'] === 'none') {
                $input['rewrite_pattern'] = '';
                $input['rewrite_replacement'] = '';
            } elseif (trim((string) ($input['rewrite_pattern'] ?? '')) === '') {
                throw new AppException(422, '请输入 URL Rewrite 匹配规则');
            } elseif ($input['rewrite_type'] === 'replace_path_regex'
                && trim((string) ($input['rewrite_replacement'] ?? '')) === '') {
                throw new AppException(422, '正则路径改写必须填写替换路径');
            }
            $rewrite = (string) ($input['rewrite_pattern'] ?? '')
                . (string) ($input['rewrite_replacement'] ?? '');
            if (preg_match('/[`\x00-\x1f\x7f]/', $rewrite)) {
                throw new AppException(422, 'URL Rewrite 内容不合法');
            }
        }

        $defaults = [
            'upstream_scheme' => 'http', 'pass_host_header' => true,
            'path_match' => 'prefix', 'methods' => [],
            'ip_allowlist' => [], 'ip_denylist' => [],
            'rate_limit_average' => 0, 'rate_limit_burst' => 0, 'rate_limit_period_seconds' => 1,
            'max_inflight_requests' => 0, 'retry_attempts' => 0, 'retry_initial_interval_ms' => 100,
            'dial_timeout_ms' => 30000, 'response_header_timeout_ms' => 0,
            'idle_connection_timeout_ms' => 90000, 'security_headers_enabled' => true,
            'compress_enabled' => true, 'request_body_limit_bytes' => 0,
            'custom_request_headers' => [], 'custom_response_headers' => [],
            'cors_enabled' => false, 'cors_allow_origins' => [],
            'cors_allow_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            'cors_allow_headers' => ['Content-Type', 'Authorization'],
            'cors_allow_credentials' => false, 'cors_max_age_seconds' => 600,
            'circuit_breaker_expression' => '', 'healthcheck_path' => '',
            'healthcheck_interval_ms' => 10000, 'healthcheck_timeout_ms' => 3000,
            'sticky_cookie_enabled' => false, 'sticky_cookie_name' => 'cg_session',
        ];
        foreach ($defaults as $key => $default) {
            if ($isCreate && ! array_key_exists($key, $input)) {
                $input[$key] = $default;
            }
        }
        if (array_key_exists('upstream_scheme', $input)
            && ! in_array($input['upstream_scheme'], ['http', 'https'], true)) {
            throw new AppException(422, '上游协议仅支持 HTTP 或 HTTPS');
        }
        if (array_key_exists('path_match', $input)
            && ! in_array($input['path_match'], ['prefix', 'exact', 'regex'], true)) {
            throw new AppException(422, '路径匹配方式无效');
        }
        if (array_key_exists('methods', $input)) {
            $input['methods'] = $this->normalizeMethods((array) $input['methods']);
        }
        foreach (['ip_allowlist', 'ip_denylist'] as $key) {
            if (array_key_exists($key, $input)) {
                $input[$key] = $this->normalizeCidrs((array) $input[$key]);
            }
        }
        foreach (['custom_request_headers', 'custom_response_headers'] as $key) {
            if (array_key_exists($key, $input)) {
                $input[$key] = $this->normalizeHeaders((array) $input[$key]);
            }
        }
        foreach (['cors_allow_origins', 'cors_allow_headers'] as $key) {
            if (array_key_exists($key, $input)) {
                $input[$key] = $this->normalizeStringList((array) $input[$key], 100, 255);
            }
        }
        if (array_key_exists('cors_allow_methods', $input)) {
            $input['cors_allow_methods'] = $this->normalizeMethods((array) $input['cors_allow_methods']);
        }
        foreach (['rate_limit_average' => 1000000, 'rate_limit_burst' => 1000000,
            'rate_limit_period_seconds' => 86400, 'max_inflight_requests' => 1000000,
            'retry_attempts' => 10, 'retry_initial_interval_ms' => 60000,
            'dial_timeout_ms' => 600000, 'response_header_timeout_ms' => 3600000,
            'idle_connection_timeout_ms' => 3600000] as $key => $maximum) {
            if (array_key_exists($key, $input)) {
                $input[$key] = max(0, min($maximum, (int) $input[$key]));
            }
        }
        foreach (['request_body_limit_bytes' => 10737418240, 'cors_max_age_seconds' => 86400,
            'healthcheck_interval_ms' => 3600000, 'healthcheck_timeout_ms' => 600000] as $key => $maximum) {
            if (array_key_exists($key, $input)) {
                $input[$key] = max(0, min($maximum, (int) $input[$key]));
            }
        }
        foreach (['circuit_breaker_expression' => 255, 'healthcheck_path' => 1024,
            'sticky_cookie_name' => 64] as $key => $maximum) {
            if (array_key_exists($key, $input)) {
                $input[$key] = trim((string) $input[$key]);
                if (strlen($input[$key]) > $maximum || preg_match('/[\x00-\x1f\x7f]/', $input[$key])) {
                    throw new AppException(422, '网关策略字段内容不合法：' . $key);
                }
            }
        }
        if (($input['healthcheck_path'] ?? '') !== '' && ! str_starts_with($input['healthcheck_path'], '/')) {
            throw new AppException(422, '健康检查路径必须以 / 开头');
        }
        $stickyCookieName = (string) ($input['sticky_cookie_name'] ?? 'cg_session');
        if ($stickyCookieName !== ''
            && ! preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $stickyCookieName)) {
            throw new AppException(422, '会话保持 Cookie 名称不合法');
        }
        if (($input['cors_enabled'] ?? false) && ($input['cors_allow_origins'] ?? []) === []) {
            throw new AppException(422, '启用 CORS 时至少需要一个允许来源');
        }
        if (($input['rate_limit_average'] ?? 0) > 0
            && ($input['rate_limit_burst'] ?? 0) < $input['rate_limit_average']) {
            throw new AppException(422, '限流突发容量不能小于平均请求数');
        }

        return $input;
    }

    private function normalizeCidrs(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            [$ip, $prefix] = array_pad(explode('/', $value, 2), 2, null);
            $isV4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
            $isV6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
            if (! $isV4 && ! $isV6) {
                throw new AppException(422, '无效的 IP/CIDR：' . $value);
            }
            if ($prefix !== null && (! ctype_digit($prefix)
                || (int) $prefix < 0 || (int) $prefix > ($isV4 ? 32 : 128))) {
                throw new AppException(422, '无效的 CIDR 前缀：' . $value);
            }
            $result[] = $prefix === null ? $ip : $ip . '/' . (int) $prefix;
        }
        return array_values(array_unique($result));
    }

    private function normalizeMethods(array $values): array
    {
        $allowed = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'CONNECT', 'TRACE'];
        $methods = array_values(array_unique(array_map(static fn ($value) => strtoupper(trim((string) $value)), $values)));
        foreach ($methods as $method) {
            if (! in_array($method, $allowed, true)) {
                throw new AppException(422, '不支持的 HTTP Method：' . $method);
            }
        }
        sort($methods);
        return $methods;
    }

    private function normalizeHeaders(array $headers): array
    {
        if (count($headers) > 100) {
            throw new AppException(422, '自定义 Header 最多 100 项');
        }
        $result = [];
        foreach ($headers as $name => $value) {
            $name = trim((string) $name);
            $value = trim((string) $value);
            if (! preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]{1,128}$/', $name)
                || strlen($value) > 2048 || preg_match('/[\r\n]/', $value)) {
                throw new AppException(422, '自定义 Header 名称或内容不合法');
            }
            $result[$name] = $value;
        }
        return $result;
    }

    private function normalizeStringList(array $values, int $limit, int $length): array
    {
        if (count($values) > $limit) {
            throw new AppException(422, '策略列表项过多');
        }
        $result = [];
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            if (strlen($value) > $length || preg_match('/[\r\n\x00]/', $value)) {
                throw new AppException(422, '策略列表内容不合法');
            }
            $result[] = $value;
        }
        return array_values(array_unique($result));
    }

    private function validateCertificate(int $orgId, int $clusterId, array $data): void
    {
        if (! (bool) ($data['tls_enabled'] ?? false)) {
            return;
        }
        $certificateId = (int) ($data['certificate_id'] ?? 0);
        if ($certificateId <= 0) {
            throw new AppException(422, 'HTTPS 路由必须选择 SSL 证书');
        }
        $certificate = $this->certificates->assertUsableForHostname(
            $orgId, $certificateId, (string) ($data['hostname'] ?? '')
        );
        if ($certificate->source === TlsCertificate::SOURCE_LETS_ENCRYPT) {
            $gateway = ClusterWebGateway::where('org_id', $orgId)->where('cluster_id', $clusterId)->first();
            if ($gateway === null || ! $gateway->acme_enabled) {
                throw new AppException(422, '该集群网关尚未启用 Let’s Encrypt ACME');
            }
        }
    }

    private function assertHostnameAvailable(int $clusterId, string $vhostKey, ?int $excludeId = null): void
    {
        $query = GatewayVhost::where('cluster_id', $clusterId)->where('vhost_key', $vhostKey);
        if ($excludeId !== null) {
            $query->where('id', '<>', $excludeId);
        }
        if ($query->exists()) {
            throw new AppException(422, '该域名与路径前缀的组合已存在');
        }
        if (ProjectRoute::where('cluster_id', $clusterId)->where('route_key', $vhostKey)->exists()) {
            throw new AppException(422, '该域名与路径已被项目路由占用，请在本页编辑现有规则');
        }
    }

    private function assertMethodMatcherAvailable(
        int $clusterId,
        string $hostname,
        string $path,
        string $pathMatch,
        array $methods,
        ?int $excludeVhostId = null
    ): void {
        $vhosts = GatewayVhost::where('cluster_id', $clusterId)->where('hostname', $hostname)
            ->where('path_prefix', $path)->where('path_match', $pathMatch)->get();
        foreach ($vhosts as $candidate) {
            if ($excludeVhostId !== null && (int) $candidate->id === $excludeVhostId) {
                continue;
            }
            if ($this->methodsOverlap($methods, (array) $candidate->methods)) {
                throw new AppException(409, '相同域名、路径和 Method 已存在网关规则');
            }
        }
        $routes = ProjectRoute::where('cluster_id', $clusterId)->where('hostname', $hostname)
            ->where('path_prefix', $path)->where('path_match', $pathMatch)->get();
        foreach ($routes as $candidate) {
            if ($this->methodsOverlap($methods, (array) $candidate->methods)) {
                throw new AppException(409, '相同域名、路径和 Method 已存在项目路由');
            }
        }
    }

    private function methodsOverlap(array $left, array $right): bool
    {
        return $left === [] || $right === [] || array_intersect($left, $right) !== [];
    }

    private function vhostKey(string $hostname, string $pathPrefix, string $pathMatch, array $methods): string
    {
        sort($methods);
        return hash('sha256', $hostname . "\0" . $pathPrefix . "\0" . $pathMatch . "\0"
            . json_encode($methods, JSON_UNESCAPED_SLASHES));
    }

    private function findOrFail(int $orgId, int $clusterId, int $id): GatewayVhost
    {
        $vhost = GatewayVhost::where('id', $id)->where('org_id', $orgId)
            ->where('cluster_id', $clusterId)->first();
        if ($vhost === null) {
            throw new AppException(404, '路由规则不存在');
        }
        return $vhost;
    }

    private function cluster(int $orgId, int $clusterId): Cluster
    {
        $cluster = Cluster::where('id', $clusterId)->where('org_id', $orgId)->first();
        if ($cluster === null) {
            throw new AppException(404, '集群不存在');
        }
        return $cluster;
    }

}
