<?php

namespace App\Services\Kubernetes;

use App\Exception\AppException;
use GuzzleHttp\Client;
use Throwable;

final class KubernetesApiClient
{
    public function get(array $credential, string $path): array
    {
        return $this->request($credential, 'GET', $path);
    }

    public function request(array $credential, string $method, string $path, array $requestOptions = []): array
    {
        $result = $this->requestResponse($credential, $method, $path, $requestOptions);
        $decoded = $result['body'] === '' ? [] : json_decode($result['body'], true);
        if (! is_array($decoded)) {
            throw new AppException(502, 'Kubernetes API 返回了无效 JSON');
        }
        return $decoded;
    }

    public function requestRaw(array $credential, string $method, string $path, array $requestOptions = []): string
    {
        return $this->requestResponse($credential, $method, $path, $requestOptions)['body'];
    }

    /** @return array{status:int,body:string} */
    private function requestResponse(
        array $credential,
        string $method,
        string $path,
        array $requestOptions = []
    ): array
    {
        $temporaryFiles = [];
        try {
            $options = [
                'base_uri' => (string) $credential['server'],
                'connect_timeout' => 4,
                'timeout' => 15,
                'http_errors' => false,
                'headers' => ['Accept' => 'application/json'],
            ];
            if ($this->isLoopbackServer((string) $credential['server'])) {
                // Do not let process-wide HTTPS_PROXY intercept a local k3d
                // API endpoint. Guzzle treats null as "resolve from env"; an
                // explicit empty string pins CURLOPT_PROXY to direct mode.
                $options['proxy'] = '';
            }
            if ((string) ($credential['token'] ?? '') !== '') {
                $options['headers']['Authorization'] = 'Bearer ' . $credential['token'];
            }
            if ((bool) ($credential['insecure_skip_tls_verify'] ?? false)) {
                $options['verify'] = false;
            } elseif ((string) ($credential['ca_certificate'] ?? '') !== '') {
                $options['verify'] = $this->temporaryCredentialFile(
                    (string) $credential['ca_certificate'],
                    $temporaryFiles
                );
            }
            if ((string) ($credential['client_certificate'] ?? '') !== '') {
                $options['cert'] = $this->temporaryCredentialFile(
                    (string) $credential['client_certificate'],
                    $temporaryFiles
                );
                $options['ssl_key'] = $this->temporaryCredentialFile(
                    (string) $credential['client_key'],
                    $temporaryFiles
                );
            }
            $response = (new Client($options))->request($method, $path, $requestOptions);
            $status = $response->getStatusCode();
            $body = (string) $response->getBody();
            $decoded = $body === '' ? [] : json_decode($body, true);
            if ($status < 200 || $status >= 300) {
                $message = is_array($decoded) ? (string) ($decoded['message'] ?? '') : '';
                throw new AppException(502, sprintf(
                    'Kubernetes API %s 返回 HTTP %d%s',
                    $path,
                    $status,
                    $message === '' ? '' : '：' . $message
                ));
            }
            return ['status' => $status, 'body' => $body];
        } catch (AppException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new AppException(502, '无法连接 Kubernetes API：' . $e->getMessage());
        } finally {
            foreach ($temporaryFiles as $path) {
                @unlink($path);
            }
        }
    }

    private function temporaryCredentialFile(string $contents, array &$files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'galaxy-k8s-');
        if ($path === false || file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new AppException(500, '无法创建 Kubernetes 临时凭据文件');
        }
        chmod($path, 0600);
        $files[] = $path;
        return $path;
    }

    private function isLoopbackServer(string $server): bool
    {
        $host = trim((string) (parse_url($server, PHP_URL_HOST) ?: ''), '[]');
        return $host === 'localhost' || $host === '::1' || str_starts_with($host, '127.');
    }
}
