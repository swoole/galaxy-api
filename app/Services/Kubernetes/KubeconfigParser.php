<?php

namespace App\Services\Kubernetes;

use App\Exception\AppException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

final class KubeconfigParser
{
    public function parse(string $contents): array
    {
        if (strlen($contents) > 1024 * 1024) {
            throw new AppException(422, 'kubeconfig 不能超过 1 MiB');
        }
        try {
            $config = Yaml::parse($contents);
        } catch (Throwable $e) {
            throw new AppException(422, 'kubeconfig YAML 解析失败：' . $e->getMessage());
        }
        if (! is_array($config)) {
            throw new AppException(422, 'kubeconfig 内容无效');
        }
        $contextName = trim((string) ($config['current-context'] ?? ''));
        if ($contextName === '') {
            throw new AppException(422, 'kubeconfig 缺少 current-context');
        }
        $context = $this->namedEntry((array) ($config['contexts'] ?? []), $contextName, 'context');
        $contextData = (array) ($context['context'] ?? []);
        $clusterName = trim((string) ($contextData['cluster'] ?? ''));
        $userName = trim((string) ($contextData['user'] ?? ''));
        if ($clusterName === '' || $userName === '') {
            throw new AppException(422, 'kubeconfig 当前 Context 缺少 cluster 或 user');
        }
        $cluster = (array) ($this->namedEntry((array) ($config['clusters'] ?? []), $clusterName, 'cluster')['cluster'] ?? []);
        $user = (array) ($this->namedEntry((array) ($config['users'] ?? []), $userName, 'user')['user'] ?? []);
        if (isset($user['exec']) || isset($user['auth-provider'])) {
            throw new AppException(422, '暂不支持 kubeconfig exec/auth-provider，请导入包含内嵌证书或 Token 的 kubeconfig');
        }
        foreach (['client-certificate', 'client-key', 'tokenFile'] as $fileCredential) {
            if (isset($user[$fileCredential])) {
                throw new AppException(422, sprintf('kubeconfig 的 %s 使用本机路径，必须改为内嵌凭据', $fileCredential));
            }
        }
        if (isset($cluster['certificate-authority'])) {
            throw new AppException(422, 'kubeconfig 的 certificate-authority 使用本机路径，必须改为 certificate-authority-data');
        }

        $server = rtrim(trim((string) ($cluster['server'] ?? '')), '/');
        $parts = parse_url($server);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new AppException(422, 'Kubernetes API Server 必须是无用户信息、Query 和 Fragment 的 HTTPS 地址');
        }
        $server = $this->normalizeServerAddress($parts);
        $token = trim((string) ($user['token'] ?? ''));
        $certificate = $this->decodeData((string) ($user['client-certificate-data'] ?? ''), 'client-certificate-data');
        $privateKey = $this->decodeData((string) ($user['client-key-data'] ?? ''), 'client-key-data');
        if ($token === '' && ($certificate === '' || $privateKey === '')) {
            throw new AppException(422, 'kubeconfig 必须包含 Token，或完整的 client-certificate-data/client-key-data');
        }
        return [
            'server' => $server,
            'context_name' => $contextName,
            'cluster_name' => $clusterName,
            'user_name' => $userName,
            'namespace' => trim((string) ($contextData['namespace'] ?? 'default')) ?: 'default',
            'ca_certificate' => $this->decodeData((string) ($cluster['certificate-authority-data'] ?? ''), 'certificate-authority-data'),
            'insecure_skip_tls_verify' => (bool) ($cluster['insecure-skip-tls-verify'] ?? false),
            'client_certificate' => $certificate,
            'client_key' => $privateKey,
            'token' => $token,
        ];
    }

    private function namedEntry(array $entries, string $name, string $type): array
    {
        foreach ($entries as $entry) {
            if (is_array($entry) && (string) ($entry['name'] ?? '') === $name) {
                return $entry;
            }
        }
        throw new AppException(422, sprintf('kubeconfig 找不到 %s %s', $type, $name));
    }

    private function decodeData(string $encoded, string $field): string
    {
        if ($encoded === '') {
            return '';
        }
        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            throw new AppException(422, sprintf('kubeconfig 字段 %s 不是有效 Base64', $field));
        }
        return $decoded;
    }

    /**
     * k3d may export 0.0.0.0 (or [::]) as the API server address. Those are
     * valid listener addresses, but not valid client destinations. The
     * imported kubeconfig is consumed on the Galaxy API host, so use the
     * matching loopback destination while preserving its generated port.
     */
    private function normalizeServerAddress(array $parts): string
    {
        $host = trim((string) $parts['host'], '[]');
        if ($host === '0.0.0.0') {
            $host = '127.0.0.1';
        } elseif ($host === '::') {
            $host = '::1';
        }
        $authority = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '');

        return 'https://' . $authority . $port . rtrim($path, '/');
    }
}
