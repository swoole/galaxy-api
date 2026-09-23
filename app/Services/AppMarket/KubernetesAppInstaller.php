<?php

namespace App\Services\AppMarket;

use App\Exception\AppException;
use App\Job\AppMarket\InstallKubernetesAppJob;
use App\Model\AppMarketKubernetesInstallation;
use App\Model\AppMarketTpl;
use App\Model\Cluster;
use App\Model\Notify as NotifyModel;
use App\Services\AsyncQueue\DefaultQueueService;
use App\Services\ContainerImageMappingService;
use App\Services\Encrypt\CredentialCipher;
use App\Services\Kubernetes\KubernetesApiClient;
use App\Services\Kubernetes\KubernetesClusterService;
use App\Services\Kubernetes\KubernetesEventDiagnostics;
use App\Services\Kubernetes\HelmServiceClient;
use App\Services\RegistryService;
use Hyperf\Redis\Redis;
use Ramsey\Uuid\Uuid;
use Swoole\Coroutine;
use Throwable;

final class KubernetesAppInstaller
{
    private const DRIVER_RANCHER = 'appmarket.kubernetes.rancher';
    private const DRIVER_KUBESPHERE = 'appmarket.kubernetes.kubesphere';

    public function __construct(
        private KubernetesClusterService $clusters,
        private KubernetesApiClient $kubernetes,
        private DefaultQueueService $queue,
        private Redis $redis,
        private CredentialCipher $cipher,
        private ContainerImageMappingService $imageMappings,
        private RegistryService $registries,
        private HelmServiceClient $helm,
        private KubernetesEventDiagnostics $eventDiagnostics
    ) {}

    public function clusters(int $orgId): array
    {
        return Cluster::where('org_id', $orgId)
            ->where('orchestrator_type', Cluster::ORCHESTRATOR_KUBERNETES)
            ->select('id', 'title', 'status', 'version', 'orchestrator_type')
            ->orderBy('id')
            ->get()
            ->toArray();
    }

    public function queue(int $uid, int $orgId, AppMarketTpl $tpl, array $form): string
    {
        $pipeline = (array) ($tpl['pipeline'] ?? []);
        if (($pipeline['orchestrator'] ?? '') !== 'kubernetes'
            || ($pipeline['status'] ?? '') !== 'ready'
            || ! in_array(($pipeline['driver'] ?? ''), [self::DRIVER_RANCHER, self::DRIVER_KUBESPHERE], true)) {
            throw new AppException(422, '该模板尚未支持 Kubernetes 自动安装');
        }
        $driver = (string) $pipeline['driver'];
        $appName = $driver === self::DRIVER_KUBESPHERE ? 'KubeSphere' : 'Rancher';
        if ($driver === self::DRIVER_KUBESPHERE && ! $this->helm->available()) {
            throw new AppException(503, 'Helm 服务当前不可用，无法安装 KubeSphere');
        }

        $clusterId = (int) ($form['cluster_id'] ?? 0);
        [$cluster, $connection, $credential] = $this->clusters->connectionWithCredential($orgId, $clusterId);
        if ((string) $connection->status !== 'online' || (int) $cluster->status !== Cluster::STATUS_READY) {
            throw new AppException(409, '目标 Kubernetes 集群当前不在线');
        }

        $values = is_array($form['values'] ?? null) ? $form['values'] : [];
        $hostname = strtolower(trim((string) ($values['hostname'] ?? '')));
        if (! $this->validHostname($hostname)) {
            throw new AppException(422, '访问域名格式不正确');
        }
        $replicas = (int) ($values['replicas'] ?? 1);
        if ($replicas < 1 || $replicas > 3) {
            throw new AppException(422, $appName . ' 副本数必须在 1 到 3 之间');
        }
        if ($driver === self::DRIVER_KUBESPHERE && ! in_array($replicas, [1, 3], true)) {
            throw new AppException(422, 'KubeSphere 控制面仅支持单副本或三副本高可用模式');
        }
        $ingressClass = trim((string) ($values['ingress_class'] ?? 'traefik'));
        if (! preg_match('/^[a-z0-9]([a-z0-9.-]{0,61}[a-z0-9])?$/', $ingressClass)) {
            throw new AppException(422, 'Ingress Class 格式不正确');
        }
        $tlsSecret = trim((string) ($values['tls_secret'] ?? ''));
        if ($tlsSecret !== '' && ! $this->validDnsLabel($tlsSecret)) {
            throw new AppException(422, 'TLS Secret 名称格式不正确');
        }
        $publicPort = (int) ($values['public_port'] ?? 443);
        if ($publicPort < 1 || $publicPort > 65535) {
            throw new AppException(422, 'Ingress 外部访问端口必须在 1 到 65535 之间');
        }

        $spec = (array) (($tpl['server_config']['kubernetes'] ?? []));
        $namespace = (string) ($spec['namespace'] ?? ($driver === self::DRIVER_KUBESPHERE ? 'kubesphere-system' : 'cattle-system'));
        $releaseName = (string) ($spec['release_name'] ?? ($driver === self::DRIVER_KUBESPHERE ? 'ks-core' : 'rancher'));
        if ($driver === self::DRIVER_KUBESPHERE) {
            $helmSpec = (array) ($spec['helm'] ?? []);
            $chartMetadata = $this->helm->inspect(
                (string) ($helmSpec['chart'] ?? 'ks-core'),
                (string) ($helmSpec['repository'] ?? ''),
                (string) ($helmSpec['chart_version'] ?? ''),
                (string) ($helmSpec['archive_url'] ?? ''),
                (string) ($helmSpec['archive_subpath'] ?? '')
            );
            if ((string) ($chartMetadata['name'] ?? '') !== 'ks-core'
                || (string) ($chartMetadata['version'] ?? '') !== (string) ($helmSpec['chart_version'] ?? '')) {
                throw new AppException(502, 'KubeSphere Helm Chart 的名称或版本与市场模板不一致');
            }
        }
        $title = trim((string) ($form['title'] ?? $tpl['title']));
        if ($title === '' || mb_strlen($title) > 100) {
            throw new AppException(422, '应用标题不能为空且不能超过 100 个字符');
        }
        if (! $this->validDnsLabel($namespace) || ! $this->validDnsLabel($releaseName)) {
            throw new AppException(500, $appName . ' 模板中的 Kubernetes 资源名称无效');
        }
        if ($driver === self::DRIVER_KUBESPHERE) {
            $this->ensureHelmReleaseAvailable($credential, $namespace, $releaseName);
        } else {
            $this->ensureResourceAvailable(
                $credential,
                $this->path('apis/apps/v1', $namespace, 'deployments', $releaseName),
                'Deployment ' . $namespace . '/' . $releaseName
            );
            $this->ensureResourceAvailable(
                $credential,
                '/apis/rbac.authorization.k8s.io/v1/clusterrolebindings/' . rawurlencode($releaseName),
                'ClusterRoleBinding ' . $releaseName
            );
        }

        $existing = AppMarketKubernetesInstallation::where('cluster_id', $clusterId)
            ->where('namespace', $namespace)
            ->where('release_name', $releaseName);
        if ((clone $existing)->whereNotIn('status', [AppMarketKubernetesInstallation::STATUS_FAILED])->exists()) {
            throw new AppException(422, '所选集群中已经存在 ' . $appName . ' 安装记录');
        }
        (clone $existing)->where('status', AppMarketKubernetesInstallation::STATUS_FAILED)->delete();

        $uuid = Uuid::uuid4()->toString();
        $bootstrapPassword = $this->randomSecret(24);
        $now = time();
        $installation = AppMarketKubernetesInstallation::create([
            'uuid' => $uuid,
            'org_id' => $orgId,
            'cluster_id' => $clusterId,
            'tpl_id' => (int) $tpl['id'],
            'uid' => $uid,
            'title' => $title,
            'namespace' => $namespace,
            'release_name' => $releaseName,
            'status' => AppMarketKubernetesInstallation::STATUS_PENDING,
            'resource_refs' => [],
            'form' => [
                'values' => [
                    'hostname' => $hostname,
                    'replicas' => $replicas,
                    'ingress_class' => $ingressClass,
                    'tls_secret' => $tlsSecret,
                    'public_port' => $publicPort,
                ],
            ],
            'secret_payload' => $this->cipher->encrypt(json_encode([
                'bootstrap_password' => $bootstrapPassword,
            ], JSON_THROW_ON_ERROR)),
            'error' => '',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->report($uuid, 'queued', $appName . ' 安装任务已进入队列', [
            'installation_id' => (int) $installation->id,
            'cluster_id' => $clusterId,
        ]);
        if (! $this->queue->push(new InstallKubernetesAppJob((int) $installation->id, $uuid))) {
            $installation->status = AppMarketKubernetesInstallation::STATUS_FAILED;
            $installation->error = '安装任务进入队列失败';
            $installation->updated_at = time();
            $installation->save();
            throw new AppException(500, '安装任务进入队列失败');
        }
        return $uuid;
    }

    public function install(int $installationId, string $jobId): void
    {
        /** @var AppMarketKubernetesInstallation|null $installation */
        $installation = AppMarketKubernetesInstallation::find($installationId);
        if ($installation === null) {
            throw new AppException(404, 'Kubernetes 应用安装记录不存在');
        }
        /** @var AppMarketTpl|null $tpl */
        $tpl = AppMarketTpl::find((int) $installation->tpl_id);
        if ($tpl === null) {
            throw new AppException(404, '应用市场模板不存在');
        }

        [$cluster, , $credential] = $this->clusters->connectionWithCredential(
            (int) $installation->org_id,
            (int) $installation->cluster_id
        );
        $installation->status = AppMarketKubernetesInstallation::STATUS_INSTALLING;
        $installation->updated_at = time();
        $installation->save();

        $driver = (string) (($tpl->pipeline['driver'] ?? ''));
        if ($driver === self::DRIVER_KUBESPHERE) {
            $this->installKubeSphere($installation, $tpl, $cluster, $credential, $jobId);
            return;
        }

        try {
            $secretPayload = json_decode(
                $this->cipher->decrypt((string) $installation->secret_payload),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            $values = (array) (($installation->form['values'] ?? []));
            $spec = (array) (($tpl['server_config']['kubernetes'] ?? []));
            $namespace = (string) $installation->namespace;
            $name = (string) $installation->release_name;
            $labels = $this->labels($installation);
            $image = $this->imageMappings->resolve($cluster, (string) ($spec['image'] ?? 'registry.cn-shanghai.aliyuncs.com/swoole-public/rancher:v2.14.2'));

            $this->report($jobId, 'validate', 'Kubernetes 集群连接与安装参数校验完成');
            $this->apply($credential, '/api/v1/namespaces/' . rawurlencode($namespace), [
                'apiVersion' => 'v1',
                'kind' => 'Namespace',
                'metadata' => ['name' => $namespace, 'labels' => $labels],
            ]);
            $this->report($jobId, 'namespace', '命名空间 cattle-system 已就绪');

            $kubeconfig = $this->kubeconfig($credential, $namespace);
            $this->apply($credential, $this->path('api/v1', $namespace, 'secrets', 'rancher-galaxy-kubeconfig'), [
                'apiVersion' => 'v1',
                'kind' => 'Secret',
                'metadata' => ['name' => 'rancher-galaxy-kubeconfig', 'namespace' => $namespace, 'labels' => $labels],
                'type' => 'Opaque',
                'data' => ['kubeconfig' => base64_encode($kubeconfig)],
            ]);
            $this->apply($credential, $this->path('api/v1', $namespace, 'secrets', 'bootstrap-secret'), [
                'apiVersion' => 'v1',
                'kind' => 'Secret',
                'metadata' => ['name' => 'bootstrap-secret', 'namespace' => $namespace, 'labels' => $labels],
                'type' => 'Opaque',
                'data' => ['bootstrapPassword' => base64_encode((string) ($secretPayload['bootstrap_password'] ?? ''))],
            ]);

            $pullSecretName = '';
            $dockerConfig = $this->registries->kubernetesDockerConfigJson((int) $cluster->org_id, $image);
            if ($dockerConfig !== null) {
                $pullSecretName = 'rancher-registry';
                $this->apply($credential, $this->path('api/v1', $namespace, 'secrets', $pullSecretName), [
                    'apiVersion' => 'v1',
                    'kind' => 'Secret',
                    'metadata' => ['name' => $pullSecretName, 'namespace' => $namespace, 'labels' => $labels],
                    'type' => 'kubernetes.io/dockerconfigjson',
                    'data' => ['.dockerconfigjson' => base64_encode($dockerConfig)],
                ]);
            }
            $this->report($jobId, 'secret', 'API 凭据、Bootstrap 密码与镜像凭据已写入 Kubernetes Secret');

            $this->apply($credential, $this->path('api/v1', $namespace, 'serviceaccounts', $name), [
                'apiVersion' => 'v1',
                'kind' => 'ServiceAccount',
                'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => $labels],
            ]);
            $this->apply($credential, '/apis/rbac.authorization.k8s.io/v1/clusterrolebindings/' . rawurlencode($name), [
                'apiVersion' => 'rbac.authorization.k8s.io/v1',
                'kind' => 'ClusterRoleBinding',
                'metadata' => ['name' => $name, 'labels' => $labels],
                'roleRef' => ['apiGroup' => 'rbac.authorization.k8s.io', 'kind' => 'ClusterRole', 'name' => 'cluster-admin'],
                'subjects' => [[
                    'kind' => 'ServiceAccount',
                    'name' => $name,
                    'namespace' => $namespace,
                ]],
            ]);
            $this->report($jobId, 'rbac', 'Rancher ServiceAccount 与集群管理权限已配置');

            $deployment = $this->deployment(
                $installation,
                $labels,
                $image,
                (int) ($values['replicas'] ?? 1),
                $pullSecretName,
                $this->publicUrl($values)
            );
            $this->apply($credential, $this->path('api/v1', $namespace, 'services', $name), $this->service($namespace, $name, $labels));
            $this->apply(
                $credential,
                $this->path('api/v1', $namespace, 'services', $name . '-internal'),
                $this->internalService($namespace, $name, $labels)
            );
            // Rancher reads rancher-internal while initializing server-url and
            // Fleet. Create both Services before the first Pod can start.
            $this->apply($credential, $this->path('apis/apps/v1', $namespace, 'deployments', $name), $deployment);
            $this->apply(
                $credential,
                $this->path('apis/networking.k8s.io/v1', $namespace, 'ingresses', $name),
                $this->ingress(
                    $namespace,
                    $name,
                    $labels,
                    (string) $values['hostname'],
                    (string) ($values['ingress_class'] ?? 'traefik'),
                    (string) ($values['tls_secret'] ?? '')
                )
            );
            $this->report($jobId, 'resources', 'Rancher Deployment、Service 与 Ingress 已提交');
            $this->waitForDeployment($credential, $namespace, $name);

            $installation->status = AppMarketKubernetesInstallation::STATUS_RUNNING;
            $installation->resource_refs = [
                'namespace' => $namespace,
                'deployment' => $name,
                'service' => $name,
                'internal_service' => $name . '-internal',
                'ingress' => $name,
                'kubeconfig_secret' => 'rancher-galaxy-kubeconfig',
                'bootstrap_secret' => 'bootstrap-secret',
                'image' => $image,
            ];
            $installation->updated_at = time();
            $installation->save();
            AppMarketTpl::where('id', $tpl->id)->increment('used');
            try {
                $this->sendCredentialsNotification($installation, $cluster, $secretPayload);
                $this->report($jobId, 'notify', 'Rancher 初始登录凭据已发送至站内信');
            } catch (Throwable $notificationError) {
                logger('app-market')->warning('发送 Rancher 初始凭据站内信失败', [
                    'installation_id' => (int) $installation->id,
                    'uid' => (int) $installation->uid,
                    'error' => $notificationError->getMessage(),
                ]);
                $this->report($jobId, 'notify', 'Rancher 已安装，但初始凭据站内信发送失败', [], 'warning');
            }
            $this->report($jobId, 'finish', 'Rancher 安装完成', [
                'installation_id' => (int) $installation->id,
                'cluster_id' => (int) $installation->cluster_id,
                'hostname' => (string) $values['hostname'],
                'username' => 'admin',
                'bootstrap_password' => (string) ($secretPayload['bootstrap_password'] ?? ''),
            ], 'success');
        } catch (Throwable $e) {
            $installation->status = AppMarketKubernetesInstallation::STATUS_FAILED;
            $installation->error = mb_substr($e->getMessage(), 0, 2000);
            $installation->updated_at = time();
            $installation->save();
            $this->report($jobId, 'error', 'Rancher 安装失败：' . $e->getMessage(), [], 'error');
            throw $e;
        }
    }

    private function installKubeSphere(
        AppMarketKubernetesInstallation $installation,
        AppMarketTpl $tpl,
        Cluster $cluster,
        array $credential,
        string $jobId
    ): void {
        try {
            $secretPayload = json_decode(
                $this->cipher->decrypt((string) $installation->secret_payload),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            $password = (string) ($secretPayload['bootstrap_password'] ?? '');
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            if (! is_string($passwordHash) || $passwordHash === '') {
                throw new AppException(500, '无法生成 KubeSphere 管理员密码');
            }
            $values = (array) (($installation->form['values'] ?? []));
            $spec = (array) (($tpl['server_config']['kubernetes'] ?? []));
            $helm = (array) ($spec['helm'] ?? []);
            $namespace = (string) $installation->namespace;
            $name = (string) $installation->release_name;
            $hostname = (string) ($values['hostname'] ?? '');
            $tlsSecret = (string) ($values['tls_secret'] ?? '');
            $replicas = (int) ($values['replicas'] ?? 1);
            $publicPort = (int) ($values['public_port'] ?? 443);

            $this->report($jobId, 'validate', 'Kubernetes 集群连接、Helm 服务与安装参数校验完成');
            $this->report($jobId, 'chart', sprintf(
                '正在获取 KubeSphere %s 官方 Chart',
                (string) ($helm['app_version'] ?? $tpl->version)
            ));
            $chartValues = [
                'global' => [
                    'imageRegistry' => (string) ($helm['image_registry'] ?? 'registry.cn-shanghai.aliyuncs.com/swoole-public'),
                    'tag' => (string) ($helm['app_version'] ?? $tpl->version),
                ],
                'multicluster' => ['role' => 'host'],
                'portal' => [
                    'hostname' => $hostname,
                    'https' => ['port' => $publicPort],
                ],
                'authentication' => [
                    'adminPassword' => $passwordHash,
                    'issuer' => ['jwtSecret' => $this->randomSecret(64)],
                ],
                'ha' => ['enabled' => $replicas > 1],
                'ingress' => [
                    'enabled' => true,
                    'ingressClassName' => (string) ($values['ingress_class'] ?? 'traefik'),
                    'tls' => [
                        'enabled' => true,
                        'source' => $tlsSecret === '' ? 'generation' : 'importation',
                        'secretName' => $tlsSecret === '' ? 'kubesphere-tls-certs' : $tlsSecret,
                    ],
                ],
                'telemetry' => ['enabled' => false],
            ];
            foreach ((array) ($helm['image_overrides'] ?? []) as $component => $image) {
                if (! in_array($component, [
                    'apiserver',
                    'console',
                    'controller',
                    'helmExecutor',
                    'kubectl',
                    'nodeShell',
                    'redis',
                    'ksExtensionRepository',
                ], true) || ! is_array($image)) {
                    continue;
                }
                $override = array_filter([
                    'registry' => trim((string) ($image['registry'] ?? '')),
                    'repository' => trim((string) ($image['repository'] ?? '')),
                    'tag' => trim((string) ($image['tag'] ?? '')),
                    'digest' => trim((string) ($image['digest'] ?? '')),
                ], static fn (string $value): bool => $value !== '');
                if ($override !== []) {
                    $chartValues[$component]['image'] = array_replace(
                        (array) ($chartValues[$component]['image'] ?? []),
                        $override
                    );
                }
            }
            $release = $this->helm->apply(
                $credential,
                $namespace,
                $name,
                (string) ($helm['chart'] ?? 'ks-core'),
                (string) ($helm['repository'] ?? ''),
                (string) ($helm['chart_version'] ?? ''),
                $chartValues,
                (int) ($helm['timeout_seconds'] ?? 1200),
                (string) ($helm['archive_url'] ?? ''),
                (string) ($helm['archive_subpath'] ?? '')
            );
            $this->report($jobId, 'helm', sprintf(
                'Helm Release %s/%s 已部署，Revision %d，状态 %s',
                $namespace,
                $name,
                (int) ($release['revision'] ?? 0),
                (string) ($release['status'] ?? 'unknown')
            ));

            $installation->status = AppMarketKubernetesInstallation::STATUS_RUNNING;
            $installation->resource_refs = [
                'namespace' => $namespace,
                'helm_release' => $name,
                'helm_revision' => (int) ($release['revision'] ?? 0),
                'helm_chart' => (string) ($release['chart'] ?? ''),
                'deployments' => ['ks-apiserver', 'ks-console', 'ks-controller-manager'],
                'service' => 'ks-console',
                'ingress' => 'ks-console',
                'host_cluster' => true,
            ];
            $installation->updated_at = time();
            $installation->save();
            AppMarketTpl::where('id', $tpl->id)->increment('used');
            try {
                $this->sendKubeSphereCredentialsNotification($installation, $cluster, $secretPayload);
                $this->report($jobId, 'notify', 'KubeSphere 初始登录凭据已发送至站内信');
            } catch (Throwable $notificationError) {
                logger('app-market')->warning('发送 KubeSphere 初始凭据站内信失败', [
                    'installation_id' => (int) $installation->id,
                    'uid' => (int) $installation->uid,
                    'error' => $notificationError->getMessage(),
                ]);
                $this->report($jobId, 'notify', 'KubeSphere 已安装，但初始凭据站内信发送失败', [], 'warning');
            }
            $this->report($jobId, 'finish', 'KubeSphere 安装完成，目标集群已作为 Host 集群接入', [
                'installation_id' => (int) $installation->id,
                'cluster_id' => (int) $installation->cluster_id,
                'hostname' => $hostname,
                'username' => 'admin',
                'bootstrap_password' => $password,
            ], 'success');
        } catch (Throwable $e) {
            $diagnostics = $this->eventDiagnostics->failureSummary(
                $credential,
                (string) $installation->namespace
            );
            $message = $e->getMessage() . ($diagnostics === '' ? '' : '；' . $diagnostics);
            $installation->status = AppMarketKubernetesInstallation::STATUS_FAILED;
            $installation->error = mb_substr($message, 0, 2000);
            $installation->updated_at = time();
            $installation->save();
            $this->report($jobId, 'error', 'KubeSphere 安装失败：' . $message, [
                'timeout_seconds' => (int) ($helm['timeout_seconds'] ?? 600),
            ], 'error');
            throw new AppException(502, $message, [], $e);
        }
    }

    public function resendCredentials(int $orgId, int $installationId, int $publicPort = 0): void
    {
        /** @var AppMarketKubernetesInstallation|null $installation */
        $installation = AppMarketKubernetesInstallation::where('id', $installationId)
            ->where('org_id', $orgId)
            ->first();
        if ($installation === null) {
            throw new AppException(404, 'Kubernetes 应用安装记录不存在');
        }
        if ((string) $installation->status !== AppMarketKubernetesInstallation::STATUS_RUNNING) {
            throw new AppException(409, '应用尚未安装完成，无法发送初始凭据');
        }
        /** @var Cluster|null $cluster */
        $cluster = Cluster::where('id', (int) $installation->cluster_id)
            ->where('org_id', $orgId)
            ->first();
        if ($cluster === null) {
            throw new AppException(404, '目标 Kubernetes 集群不存在');
        }
        if ($publicPort > 0) {
            if ($publicPort > 65535) {
                throw new AppException(422, 'Ingress 外部访问端口必须在 1 到 65535 之间');
            }
            $form = (array) ($installation->form ?? []);
            $values = (array) ($form['values'] ?? []);
            $values['public_port'] = $publicPort;
            $form['values'] = $values;
            $installation->form = $form;
            $installation->updated_at = time();
            $installation->save();
        }
        $secretPayload = json_decode(
            $this->cipher->decrypt((string) $installation->secret_payload),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        /** @var AppMarketTpl|null $tpl */
        $tpl = AppMarketTpl::find((int) $installation->tpl_id);
        $resendPipeline = $tpl === null ? [] : (array) $tpl->pipeline;
        $driver = (string) ($resendPipeline['driver'] ?? '');
        if ($driver === self::DRIVER_KUBESPHERE) {
            $this->sendKubeSphereCredentialsNotification($installation, $cluster, $secretPayload, true);
            return;
        }
        $this->sendCredentialsNotification($installation, $cluster, $secretPayload, true);
    }

    public function installations(int $orgId): array
    {
        $rows = AppMarketKubernetesInstallation::where('org_id', $orgId)->orderByDesc('id')->get();
        $clusters = Cluster::whereIn('id', $rows->pluck('cluster_id')->all())->pluck('title', 'id')->all();
        $templates = AppMarketTpl::whereIn('id', $rows->pluck('tpl_id')->all())->pluck('title', 'id')->all();
        return $rows->map(static fn (AppMarketKubernetesInstallation $row): array => [
            'id' => (int) $row->id,
            'uuid' => (string) $row->uuid,
            'title' => (string) $row->title,
            'orchestrator' => 'kubernetes',
            'resource_name' => (string) $row->release_name,
            'namespace' => (string) $row->namespace,
            'status' => (string) $row->status,
            'error' => (string) $row->error,
            'tpl_id' => (int) $row->tpl_id,
            'tpl_title' => (string) ($templates[$row->tpl_id] ?? ''),
            'cluster_id' => (int) $row->cluster_id,
            'cluster_title' => (string) ($clusters[$row->cluster_id] ?? ''),
            'resource_refs' => $row->resource_refs ?: [],
            'form' => $row->form ?: [],
            'created_at' => (int) $row->created_at,
            'updated_at' => (int) $row->updated_at,
        ])->all();
    }

    private function deployment(
        AppMarketKubernetesInstallation $installation,
        array $labels,
        string $image,
        int $replicas,
        string $pullSecretName,
        string $serverUrl
    ): array {
        $name = (string) $installation->release_name;
        $namespace = (string) $installation->namespace;
        $podSpec = [
            'serviceAccountName' => $name,
            'containers' => [[
                'name' => 'rancher',
                'image' => $image,
                'imagePullPolicy' => 'IfNotPresent',
                'args' => ['--no-cacerts', '--http-listen-port=80', '--https-listen-port=443', '--add-local=true'],
                'env' => [
                    ['name' => 'CATTLE_NAMESPACE', 'value' => $namespace],
                    ['name' => 'CATTLE_PEER_SERVICE', 'value' => $name],
                    ['name' => 'CATTLE_AGENT_TLS_MODE', 'value' => 'system-store'],
                    ['name' => 'CATTLE_SERVER_URL', 'value' => rtrim($serverUrl, '/')],
                    ['name' => 'IMPERATIVE_API_DIRECT', 'value' => 'true'],
                    ['name' => 'IMPERATIVE_API_APP_SELECTOR', 'value' => $name],
                    ['name' => 'CATTLE_BOOTSTRAP_PASSWORD', 'valueFrom' => [
                        'secretKeyRef' => ['name' => 'bootstrap-secret', 'key' => 'bootstrapPassword'],
                    ]],
                    ['name' => 'KUBECONFIG', 'value' => '/etc/rancher/galaxy/kubeconfig'],
                ],
                'ports' => [
                    ['name' => 'http', 'containerPort' => 80, 'protocol' => 'TCP'],
                    ['name' => 'https-internal', 'containerPort' => 443, 'protocol' => 'TCP'],
                    ['name' => 'imperative-api', 'containerPort' => 444, 'protocol' => 'TCP'],
                    ['name' => 'proxy', 'containerPort' => 6666, 'protocol' => 'TCP'],
                ],
                'volumeMounts' => [[
                    'name' => 'galaxy-kubeconfig',
                    'mountPath' => '/etc/rancher/galaxy',
                    'readOnly' => true,
                ]],
                'resources' => [
                    'requests' => ['cpu' => '250m', 'memory' => '512Mi'],
                    'limits' => ['cpu' => '1', 'memory' => '2Gi'],
                ],
                'livenessProbe' => ['httpGet' => ['path' => '/healthz', 'port' => 80], 'initialDelaySeconds' => 60, 'periodSeconds' => 30],
                'readinessProbe' => ['httpGet' => ['path' => '/healthz', 'port' => 80], 'initialDelaySeconds' => 5, 'periodSeconds' => 10],
            ]],
            'volumes' => [[
                'name' => 'galaxy-kubeconfig',
                'secret' => ['secretName' => 'rancher-galaxy-kubeconfig', 'defaultMode' => 256],
            ]],
        ];
        if ($pullSecretName !== '') {
            $podSpec['imagePullSecrets'] = [['name' => $pullSecretName]];
        }
        return [
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => $labels],
            'spec' => [
                'replicas' => $replicas,
                'selector' => ['matchLabels' => ['app.kubernetes.io/name' => $name]],
                'template' => [
                    'metadata' => ['labels' => array_merge($labels, [
                        'app' => $name,
                        'app.kubernetes.io/name' => $name,
                    ])],
                    'spec' => $podSpec,
                ],
            ],
        ];
    }

    private function sendCredentialsNotification(
        AppMarketKubernetesInstallation $installation,
        Cluster $cluster,
        array $secretPayload,
        bool $force = false
    ): void {
        $context = [
            'installation_id' => (int) $installation->id,
            'cluster_id' => (int) $installation->cluster_id,
            'resource' => 'rancher',
        ];
        $encodedContext = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        /** @var NotifyModel|null $notification */
        $notification = NotifyModel::where('uid', (int) $installation->uid)
            ->where('scene', 'app_market_rancher_installed')
            ->where('context', $encodedContext)
            ->orderByDesc('id')
            ->first();
        if (! $force && $notification !== null) {
            return;
        }

        $values = (array) (($installation->form['values'] ?? []));
        $url = $this->publicUrl($values);
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $attributes = [
            'uid' => (int) $installation->uid,
            'scene' => 'app_market_rancher_installed',
            'title' => 'Rancher 安装完成及初始登录凭据',
            'content' => 'Rancher 已安装完成，请妥善保存以下初始凭据。<br>'
                . '集群：' . $escape((string) $cluster->title) . '<br>'
                . '访问地址：' . $escape($url) . '<br>'
                . '用户名：admin<br>'
                . '初始密码：<code>' . $escape((string) ($secretPayload['bootstrap_password'] ?? '')) . '</code><br>'
                . '首次登录后请立即修改密码。',
            'context' => $encodedContext,
            'read_at' => 0,
            'created_at' => time(),
        ];
        if ($notification !== null) {
            $notification->fill($attributes);
            $notification->save();
            return;
        }
        NotifyModel::create($attributes);
    }

    private function sendKubeSphereCredentialsNotification(
        AppMarketKubernetesInstallation $installation,
        Cluster $cluster,
        array $secretPayload,
        bool $force = false
    ): void {
        $context = [
            'installation_id' => (int) $installation->id,
            'cluster_id' => (int) $installation->cluster_id,
            'resource' => 'kubesphere',
        ];
        $encodedContext = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        /** @var NotifyModel|null $notification */
        $notification = NotifyModel::where('uid', (int) $installation->uid)
            ->where('scene', 'app_market_kubesphere_installed')
            ->where('context', $encodedContext)
            ->orderByDesc('id')
            ->first();
        if (! $force && $notification !== null) {
            return;
        }
        $values = (array) (($installation->form['values'] ?? []));
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $attributes = [
            'uid' => (int) $installation->uid,
            'scene' => 'app_market_kubesphere_installed',
            'title' => 'KubeSphere 安装完成及初始登录凭据',
            'content' => 'KubeSphere 已安装完成，所选 Kubernetes 集群已作为 Host 集群接入。<br>'
                . '集群：' . $escape((string) $cluster->title) . '<br>'
                . '访问地址：' . $escape($this->publicUrl($values)) . '<br>'
                . '用户名：admin<br>'
                . '初始密码：<code>' . $escape((string) ($secretPayload['bootstrap_password'] ?? '')) . '</code><br>'
                . '首次登录后请立即修改密码。',
            'context' => $encodedContext,
            'read_at' => 0,
            'created_at' => time(),
        ];
        if ($notification !== null) {
            $notification->fill($attributes);
            $notification->save();
            return;
        }
        NotifyModel::create($attributes);
    }

    private function service(string $namespace, string $name, array $labels): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Service',
            'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => $labels],
            'spec' => [
                'selector' => ['app' => $name],
                'ports' => [
                    ['name' => 'http', 'port' => 80, 'targetPort' => 80, 'protocol' => 'TCP'],
                    ['name' => 'https-internal', 'port' => 443, 'targetPort' => 443, 'protocol' => 'TCP'],
                ],
            ],
        ];
    }

    private function internalService(string $namespace, string $name, array $labels): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Service',
            'metadata' => ['name' => $name . '-internal', 'namespace' => $namespace, 'labels' => $labels],
            'spec' => [
                'type' => 'ClusterIP',
                'selector' => ['app' => $name],
                'ports' => [[
                    'name' => 'https-internal',
                    'port' => 443,
                    'targetPort' => 444,
                    'protocol' => 'TCP',
                ]],
            ],
        ];
    }

    private function ingress(
        string $namespace,
        string $name,
        array $labels,
        string $hostname,
        string $ingressClass,
        string $tlsSecret
    ): array {
        $spec = [
            'ingressClassName' => $ingressClass,
            'rules' => [[
                'host' => $hostname,
                'http' => [
                    'paths' => [[
                        'path' => '/',
                        'pathType' => 'Prefix',
                        'backend' => ['service' => ['name' => $name, 'port' => ['name' => 'http']]],
                    ]],
                ],
            ]],
        ];
        $spec['tls'] = [['hosts' => [$hostname]]];
        if ($tlsSecret !== '') {
            $spec['tls'][0]['secretName'] = $tlsSecret;
        }
        return [
            'apiVersion' => 'networking.k8s.io/v1',
            'kind' => 'Ingress',
            'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => $labels],
            'spec' => $spec,
        ];
    }

    private function publicUrl(array $values): string
    {
        $hostname = trim((string) ($values['hostname'] ?? ''));
        $publicPort = (int) ($values['public_port'] ?? 443);
        return 'https://' . $hostname . ($publicPort !== 443 ? ':' . $publicPort : '') . '/';
    }

    private function kubeconfig(array $credential, string $namespace): string
    {
        // Rancher is deployed inside the selected cluster. The API endpoint saved
        // by Galaxy may be a host-only k3d address such as 127.0.0.1:35773, which
        // is not reachable from a Pod. Use Kubernetes' stable in-cluster DNS name
        // while preserving the CA and client credentials from the registered
        // kubeconfig.
        $cluster = ['server' => 'https://kubernetes.default.svc'];
        if (! empty($credential['insecure_skip_tls_verify'])) {
            $cluster['insecure-skip-tls-verify'] = true;
        } elseif ((string) ($credential['ca_certificate'] ?? '') !== '') {
            $cluster['certificate-authority-data'] = base64_encode((string) $credential['ca_certificate']);
        }
        $user = [];
        if ((string) ($credential['token'] ?? '') !== '') {
            $user['token'] = (string) $credential['token'];
        }
        if ((string) ($credential['client_certificate'] ?? '') !== '') {
            $user['client-certificate-data'] = base64_encode((string) $credential['client_certificate']);
            $user['client-key-data'] = base64_encode((string) $credential['client_key']);
        }
        return json_encode([
            'apiVersion' => 'v1',
            'kind' => 'Config',
            'clusters' => [['name' => 'galaxy-target', 'cluster' => $cluster]],
            'users' => [['name' => 'galaxy-api', 'user' => $user]],
            'contexts' => [[
                'name' => 'galaxy-target',
                'context' => ['cluster' => 'galaxy-target', 'user' => 'galaxy-api', 'namespace' => $namespace],
            ]],
            'current-context' => 'galaxy-target',
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    private function apply(array $credential, string $path, array $resource): array
    {
        return $this->kubernetes->request($credential, 'PATCH', $path, [
            'query' => ['fieldManager' => 'galaxy-app-market', 'force' => 'true'],
            'headers' => ['Content-Type' => 'application/apply-patch+yaml'],
            'body' => json_encode($resource, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    }

    private function ensureResourceAvailable(array $credential, string $path, string $label): void
    {
        try {
            $resource = $this->kubernetes->get($credential, $path);
        } catch (AppException $e) {
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                return;
            }
            throw $e;
        }
        $managedBy = (string) ($resource['metadata']['labels']['app.kubernetes.io/managed-by'] ?? '');
        if ($managedBy !== 'galaxy') {
            throw new AppException(409, $label . ' 已存在且不由 Galaxy 管理，已停止安装以避免覆盖');
        }
    }

    private function ensureHelmReleaseAvailable(array $credential, string $namespace, string $name): void
    {
        try {
            $secrets = $this->kubernetes->request(
                $credential,
                'GET',
                '/api/v1/namespaces/' . rawurlencode($namespace) . '/secrets',
                ['query' => ['labelSelector' => 'owner=helm,name=' . $name]]
            );
        } catch (AppException $e) {
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                return;
            }
            throw $e;
        }
        if ((array) ($secrets['items'] ?? []) !== []) {
            throw new AppException(
                409,
                sprintf('Helm Release %s/%s 已存在，已停止安装以避免接管非 Galaxy 资源', $namespace, $name)
            );
        }
    }

    private function waitForDeployment(array $credential, string $namespace, string $name): void
    {
        $lastError = '';
        for ($attempt = 0; $attempt < 60; $attempt++) {
            $deployment = $this->kubernetes->get(
                $credential,
                $this->path('apis/apps/v1', $namespace, 'deployments', $name)
            );
            if ((int) ($deployment['status']['availableReplicas'] ?? 0) >= 1) {
                return;
            }
            $pods = $this->kubernetes->request(
                $credential,
                'GET',
                '/api/v1/namespaces/' . rawurlencode($namespace) . '/pods',
                ['query' => ['labelSelector' => 'app.kubernetes.io/name=' . $name]]
            );
            foreach ((array) ($pods['items'] ?? []) as $pod) {
                foreach ((array) ($pod['status']['containerStatuses'] ?? []) as $status) {
                    $waiting = (array) ($status['state']['waiting'] ?? []);
                    $lastError = trim((string) ($waiting['message'] ?? $waiting['reason'] ?? $lastError));
                }
            }
            if ($lastError !== '' && preg_match('/(?:ErrImagePull|ImagePullBackOff|failed to pull)/i', $lastError)) {
                break;
            }
            Coroutine::sleep(2);
        }
        throw new AppException(502, 'Rancher Deployment 未能就绪' . ($lastError === '' ? '' : '：' . $lastError));
    }

    private function path(string $apiVersion, string $namespace, string $kind, string $name): string
    {
        return '/' . $apiVersion . '/namespaces/' . rawurlencode($namespace)
            . '/' . $kind . '/' . rawurlencode($name);
    }

    private function labels(AppMarketKubernetesInstallation $installation): array
    {
        return [
            'app.kubernetes.io/managed-by' => 'galaxy',
            'app.kubernetes.io/instance' => (string) $installation->release_name,
            'codegalaxy.com/resource' => 'app-market',
            'codegalaxy.com/installation-id' => (string) $installation->id,
        ];
    }

    private function validHostname(string $value): bool
    {
        return strlen($value) <= 253
            && str_contains($value, '.')
            && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $value) === 1;
    }

    private function validDnsLabel(string $value): bool
    {
        return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $value) === 1;
    }

    private function randomSecret(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    private function report(string $jobId, string $tag, string $message, array $context = [], string $level = 'info'): void
    {
        $key = 'cg.am.jobpg.' . $jobId;
        $score = microtime(true);
        $this->redis->zAdd($key, $score, json_encode([
            'tag' => $tag,
            'msg' => $message,
            'context' => $context,
            'level' => $level,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->redis->expire($key, 3600);
        $this->redis->publish($key, (string) $score);
    }
}
