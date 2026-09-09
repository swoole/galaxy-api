<?php

namespace App\Services\Kubernetes;

use App\Exception\AppException;
use GuzzleHttp\Client;
use Throwable;

final class HelmServiceClient
{
    public function available(): bool
    {
        if (! (bool) config('helm_service.enabled', false)) {
            return false;
        }
        try {
            $result = $this->request('GET', '/healthz', null, false, 3);
            return (bool) ($result['ok'] ?? false);
        } catch (Throwable) {
            return false;
        }
    }

    public function apply(
        array $credential,
        string $namespace,
        string $name,
        string $chart,
        string $repository,
        string $version,
        array $values,
        int $timeout = 900,
        string $archiveUrl = '',
        string $archiveSubpath = ''
    ): array {
        return $this->data($this->request('POST', '/v1/releases/apply', [
            'kubeconfig' => $this->kubeconfig($credential, $namespace),
            'namespace' => $namespace,
            'name' => $name,
            'chart' => $chart,
            'repository' => $repository,
            'version' => $version,
            'archive_url' => $archiveUrl,
            'archive_subpath' => $archiveSubpath,
            'values' => (object) $values,
            'wait' => true,
            'timeout_seconds' => $timeout,
        ], true, min(1860, $timeout + 60)));
    }

    public function inspect(
        string $chart,
        string $repository = '',
        string $version = '',
        string $archiveUrl = '',
        string $archiveSubpath = ''
    ): array {
        return $this->data($this->request('POST', '/v1/charts/inspect', [
            'chart' => $chart,
            'repository' => $repository,
            'version' => $version,
            'archive_url' => $archiveUrl,
            'archive_subpath' => $archiveSubpath,
        ], true, 180));
    }

    public function status(array $credential, string $namespace, string $name): array
    {
        return $this->data($this->request('POST', '/v1/releases/status', [
            'kubeconfig' => $this->kubeconfig($credential, $namespace),
            'namespace' => $namespace,
            'name' => $name,
            'timeout_seconds' => 60,
        ]));
    }

    public function uninstall(array $credential, string $namespace, string $name, int $timeout = 600): array
    {
        return $this->data($this->request('POST', '/v1/releases/uninstall', [
            'kubeconfig' => $this->kubeconfig($credential, $namespace),
            'namespace' => $namespace,
            'name' => $name,
            'timeout_seconds' => $timeout,
        ], true, min(1860, $timeout + 60)));
    }

    private function request(
        string $method,
        string $path,
        ?array $payload = null,
        bool $authenticated = true,
        int $timeout = 30
    ): array {
        $baseUrl = rtrim((string) config('helm_service.url', 'http://127.0.0.1:9530'), '/');
        $token = (string) config('helm_service.internal_token', '');
        if ($authenticated && (! (bool) config('helm_service.enabled', false) || $token === '')) {
            throw new AppException(503, 'Helm 服务尚未启用，请配置 HELM_SERVICE_ENABLED 和 HELM_SERVICE_INTERNAL_TOKEN');
        }
        try {
            $headers = ['Accept' => 'application/json'];
            if ($authenticated) {
                $headers['Authorization'] = 'Bearer ' . $token;
            }
            $options = [
                'headers' => $headers,
                'connect_timeout' => 3,
                'timeout' => $timeout,
                'http_errors' => false,
                'proxy' => '',
            ];
            if ($payload !== null) {
                $options['json'] = $payload;
            }
            $response = (new Client(['base_uri' => $baseUrl]))->request($method, $path, $options);
            $decoded = json_decode((string) $response->getBody(), true);
            if (! is_array($decoded)) {
                throw new AppException(502, 'Helm 服务返回了无效 JSON');
            }
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300 || ! ($decoded['ok'] ?? false)) {
                throw new AppException(502, 'Helm 操作失败：' . ((string) ($decoded['error'] ?? $response->getReasonPhrase())));
            }
            return $decoded;
        } catch (AppException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new AppException(502, '无法连接 Helm 服务：' . $e->getMessage());
        }
    }

    private function data(array $response): array
    {
        return is_array($response['data'] ?? null) ? $response['data'] : [];
    }

    private function kubeconfig(array $credential, string $namespace): string
    {
        $cluster = ['server' => (string) ($credential['server'] ?? '')];
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
                'context' => [
                    'cluster' => 'galaxy-target',
                    'user' => 'galaxy-api',
                    'namespace' => $namespace,
                ],
            ]],
            'current-context' => 'galaxy-target',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
