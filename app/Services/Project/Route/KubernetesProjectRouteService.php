<?php

namespace App\Services\Project\Route;

use App\Exception\AppException;
use App\Model\Cluster;
use App\Model\ProjectRoute;
use App\Model\ProjectRuntime;
use App\Services\Kubernetes\KubernetesApiClient;
use App\Services\Kubernetes\KubernetesClusterService;
use Throwable;

final class KubernetesProjectRouteService
{
    public function __construct(
        private KubernetesClusterService $clusters,
        private KubernetesApiClient $api
    ) {}

    public function syncRuntime(ProjectRuntime $runtime): void
    {
        if ((string) $runtime->orchestrator_type !== Cluster::ORCHESTRATOR_KUBERNETES) {
            throw new AppException(422, '该运行实例不是 Kubernetes Runtime');
        }
        $namespace = trim((string) $runtime->runtime_namespace);
        $serviceName = trim((string) $runtime->service_name);
        if ($namespace === '' || $serviceName === '') {
            throw new AppException(409, 'Kubernetes Runtime 缺少 Namespace 或 Service 定位信息');
        }
        [, , $credential] = $this->clusters->connectionWithCredential(
            (int) $runtime->org_id,
            (int) $runtime->cluster_id
        );
        $service = $this->api->get(
            $credential,
            $this->resourcePath('api/v1', $namespace, 'services', $serviceName)
        );
        $labels = (array) ($service['metadata']['labels'] ?? []);
        if (($labels['app.kubernetes.io/managed-by'] ?? '') !== 'galaxy'
            || (int) ($labels['codegalaxy.com/org-id'] ?? 0) !== (int) $runtime->org_id
            || (int) ($labels['codegalaxy.com/project-id'] ?? 0) !== (int) $runtime->project_id) {
            throw new AppException(403, '目标 Kubernetes Service 不属于当前 Galaxy 项目');
        }

        $routes = ProjectRoute::where('runtime_id', (int) $runtime->id)
            ->where('orchestrator_type', Cluster::ORCHESTRATOR_KUBERNETES)
            ->where('enabled', 1)
            ->orderBy('id')
            ->get();
        $desired = [];
        $desiredMiddlewares = [];
        try {
            foreach ($routes as $route) {
                $name = $this->ingressName((int) $route->id);
                $desired[$name] = true;
                $tlsSecretName = null;
                if ((bool) $route->tls_enabled) {
                    $tlsSecretName = $this->clusters->syncTlsSecret(
                        (int) $runtime->org_id,
                        $credential,
                        $namespace,
                        (int) $route->certificate_id,
                        [(string) $route->hostname]
                    );
                    $desired[$name . '-http'] = true;
                    if ((bool) $route->https_redirect) {
                        $desiredMiddlewares[$name . '-https-redirect'] = true;
                    }
                }
                $manifest = $this->manifest(
                    $runtime,
                    $route,
                    $namespace,
                    $serviceName,
                    $name,
                    $tlsSecretName
                );
                $this->apply(
                    $credential,
                    $this->resourcePath('apis/networking.k8s.io/v1', $namespace, 'ingresses', $name),
                    $manifest
                );
                $this->clusters->syncIngressHttpAccess($credential, [
                    'namespace' => $namespace,
                    'name' => $name,
                    'labels' => array_map(
                        static fn (string $key, string $value): array => [
                            'key' => $key,
                            'value' => $value,
                        ],
                        array_keys((array) $manifest['metadata']['labels']),
                        array_values((array) $manifest['metadata']['labels'])
                    ),
                    'tls_enabled' => (bool) $route->tls_enabled,
                    'certificate_id' => (int) $route->certificate_id,
                    'https_redirect' => (bool) $route->https_redirect,
                    'https_redirect_port' => (int) $route->https_redirect_port,
                    'tls_hosts' => [(string) $route->hostname],
                    'tls_secret_name' => (string) $tlsSecretName,
                    'rules' => [[
                        'host' => (string) $route->hostname,
                        'paths' => [[
                            'path' => (string) $route->path_prefix,
                            'pathType' => (string) $route->path_match === 'exact'
                                ? 'Exact'
                                : 'Prefix',
                            'serviceName' => $serviceName,
                            'servicePort' => (int) $route->target_port,
                        ]],
                    ]],
                ]);
                $route->provider_metadata = [
                    'api_version' => 'networking.k8s.io/v1',
                    'kind' => 'Ingress',
                    'namespace' => $namespace,
                    'name' => $name,
                    'ingress_class' => (string) config('kubernetes.ingress_class', 'traefik'),
                    'service_name' => $serviceName,
                ];
                $route->status = ProjectRoute::STATUS_SYNCED;
                $route->error = null;
                $route->synced_at = time();
                $route->updated_at = time();
                $route->save();
            }

            $existing = $this->api->request(
                $credential,
                'GET',
                '/apis/networking.k8s.io/v1/namespaces/' . rawurlencode($namespace) . '/ingresses',
                ['query' => ['labelSelector' => 'codegalaxy.com/runtime-id=' . (int) $runtime->id]]
            );
            foreach ((array) ($existing['items'] ?? []) as $ingress) {
                $name = (string) ($ingress['metadata']['name'] ?? '');
                if ($name !== '' && ! isset($desired[$name])) {
                    $this->deleteIgnoringMissing(
                        $credential,
                        $this->resourcePath('apis/networking.k8s.io/v1', $namespace, 'ingresses', $name)
                    );
                }
            }
            $middlewares = $this->api->request(
                $credential,
                'GET',
                '/apis/traefik.io/v1alpha1/namespaces/'
                    . rawurlencode($namespace) . '/middlewares',
                ['query' => ['labelSelector' => 'codegalaxy.com/runtime-id=' . (int) $runtime->id]]
            );
            foreach ((array) ($middlewares['items'] ?? []) as $middleware) {
                $middlewareName = (string) ($middleware['metadata']['name'] ?? '');
                if ($middlewareName !== '' && ! isset($desiredMiddlewares[$middlewareName])) {
                    $this->deleteIgnoringMissing(
                        $credential,
                        $this->resourcePath(
                            'apis/traefik.io/v1alpha1',
                            $namespace,
                            'middlewares',
                            $middlewareName
                        )
                    );
                }
            }
        } catch (Throwable $e) {
            ProjectRoute::where('runtime_id', (int) $runtime->id)
                ->where('orchestrator_type', Cluster::ORCHESTRATOR_KUBERNETES)
                ->update([
                    'status' => ProjectRoute::STATUS_ERROR,
                    'error' => mb_substr($e->getMessage(), 0, 2000),
                    'updated_at' => time(),
                ]);
            throw $e;
        }
    }

    private function manifest(
        ProjectRuntime $runtime,
        ProjectRoute $route,
        string $namespace,
        string $serviceName,
        string $name,
        ?string $tlsSecretName
    ): array {
        $pathType = (string) $route->path_match === 'exact' ? 'Exact' : 'Prefix';
        $rule = [
            'http' => ['paths' => [[
                'path' => (string) $route->path_prefix,
                'pathType' => $pathType,
                'backend' => ['service' => [
                    'name' => $serviceName,
                    'port' => ['number' => (int) $route->target_port],
                ]],
            ]]],
        ];
        $rule['host'] = (string) $route->hostname;
        $annotations = [
            'traefik.ingress.kubernetes.io/router.entrypoints' => (bool) $route->tls_enabled
                ? 'web,websecure'
                : (string) $route->entrypoint,
        ];
        if ((bool) $route->tls_enabled) {
            $annotations['codegalaxy.com/tls-certificate-id'] = (string) $route->certificate_id;
            $annotations['codegalaxy.com/https-redirect'] = (bool) $route->https_redirect
                ? 'true'
                : 'false';
            $annotations['codegalaxy.com/https-redirect-port'] =
                (string) $route->https_redirect_port;
        }
        if ((int) $route->priority > 0) {
            $annotations['traefik.ingress.kubernetes.io/router.priority'] = (string) $route->priority;
        }
        $spec = [
            'ingressClassName' => (string) config('kubernetes.ingress_class', 'traefik'),
            'rules' => [$rule],
        ];
        if ($tlsSecretName !== null) {
            $spec['tls'] = [[
                'hosts' => [(string) $route->hostname],
                'secretName' => $tlsSecretName,
            ]];
        }
        return [
            'apiVersion' => 'networking.k8s.io/v1',
            'kind' => 'Ingress',
            'metadata' => [
                'name' => $name,
                'namespace' => $namespace,
                'labels' => [
                    'app.kubernetes.io/managed-by' => 'galaxy',
                    'app.kubernetes.io/name' => $serviceName,
                    'codegalaxy.com/org-id' => (string) $runtime->org_id,
                    'codegalaxy.com/project-id' => (string) $runtime->project_id,
                    'codegalaxy.com/runtime-id' => (string) $runtime->id,
                    'codegalaxy.com/route-id' => (string) $route->id,
                ],
                'annotations' => $annotations,
            ],
            'spec' => $spec,
        ];
    }

    private function ingressName(int $routeId): string
    {
        return 'cg-route-' . $routeId;
    }

    private function apply(array $credential, string $path, array $manifest): array
    {
        return $this->api->request($credential, 'PATCH', $path, [
            'query' => ['fieldManager' => 'galaxy', 'force' => 'true'],
            'headers' => ['Content-Type' => 'application/apply-patch+yaml'],
            'body' => json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'timeout' => 30,
        ]);
    }

    private function deleteIgnoringMissing(array $credential, string $path): void
    {
        try {
            $this->api->request($credential, 'DELETE', $path, [
                'json' => [
                    'apiVersion' => 'v1',
                    'kind' => 'DeleteOptions',
                    'propagationPolicy' => 'Background',
                ],
                'timeout' => 30,
            ]);
        } catch (AppException $e) {
            if (! str_contains($e->getMessage(), 'HTTP 404')) {
                throw $e;
            }
        }
    }

    private function resourcePath(string $api, string $namespace, string $resource, string $name): string
    {
        return '/' . trim($api, '/') . '/namespaces/' . rawurlencode($namespace)
            . '/' . rawurlencode($resource) . '/' . rawurlencode($name);
    }
}
