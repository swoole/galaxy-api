<?php

namespace App\Services;

use App\Exception\AppException;
use App\Model\Registry;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;

class RegistryService
{
    public function hasDockerAuth(int $orgId, string $reference): bool
    {
        $registry = Registry::matchByName($orgId, $reference, false);
        return $registry !== null
            && ((string) $registry->username !== '' || (string) $registry->password !== '');
    }

    public function dockerAuthHeader(int $orgId, string $reference, int $registryId = 0): array
    {
        /** @var Registry|null $registry */
        $registry = $registryId > 0
            ? Registry::where('id', $registryId)->where('org_id', $orgId)
                ->select('id', 'org_id', 'address', 'namespace', 'username', 'password', 'proto')->first()
            : Registry::matchByName($orgId, $reference, false);
        if ($registry === null) {
            return [];
        }
        $username = (string) $registry->username;
        $password = (string) $registry->decryptField('password');
        if ($username === '' && $password === '') {
            return [];
        }
        $serverAddress = trim((string) $registry->address);
        if ($serverAddress === '') {
            $serverAddress = 'https://index.docker.io/v1/';
        }
        return ['X-Registry-Auth' => base64_encode(json_encode([
            'username' => $username,
            'password' => $password,
            'serveraddress' => $serverAddress,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))];
    }

    public function kubernetesDockerConfigJson(int $orgId, string $reference, int $registryId = 0): ?string
    {
        /** @var Registry|null $registry */
        $registry = $registryId > 0
            ? Registry::where('id', $registryId)->where('org_id', $orgId)
                ->select('id', 'org_id', 'address', 'username', 'password')->first()
            : Registry::matchByName($orgId, $reference, false);
        if ($registry === null) {
            return null;
        }
        $username = (string) $registry->username;
        $password = (string) $registry->decryptField('password');
        if ($username === '' && $password === '') {
            return null;
        }
        $server = trim((string) $registry->address) ?: 'https://index.docker.io/v1/';
        return json_encode(['auths' => [
            $server => [
                'username' => $username,
                'password' => $password,
                'auth' => base64_encode($username . ':' . $password),
            ],
        ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function inspectManifest(Registry $reg, string $repository, string $reference): array
    {
        $address = trim((string) $reg->address);
        $dockerHub = $address === '';
        if ($dockerHub) {
            $address = 'registry-1.docker.io';
            if (! str_contains($repository, '/')) {
                $repository = 'library/' . $repository;
            }
        }
        $scheme = $dockerHub || (int) $reg->proto === Registry::PROTO_HTTPS ? 'https' : 'http';
        $path = $this->encodeRepository($repository);
        $url = sprintf('%s://%s/v2/%s/manifests/%s', $scheme, $address, $path, rawurlencode($reference));
        $accept = implode(', ', [
            'application/vnd.oci.image.index.v1+json',
            'application/vnd.oci.image.manifest.v1+json',
            'application/vnd.docker.distribution.manifest.list.v2+json',
            'application/vnd.docker.distribution.manifest.v2+json',
        ]);
        try {
            $client = new Client(['http_errors' => false, 'connect_timeout' => 10, 'timeout' => 30]);
            $requestOptions = ['headers' => ['Accept' => $accept]];
            $response = $client->get($url, $requestOptions);
            if ($response->getStatusCode() === 401) {
                $challenge = $response->getHeaderLine('WWW-Authenticate');
                if (! preg_match('/^Bearer\s+/i', $challenge)) {
                    $requestOptions = [
                        'headers' => ['Accept' => $accept],
                        'auth' => [(string) $reg->username, $reg->decryptField('password')],
                    ];
                    $response = $client->get($url, $requestOptions);
                } else {
                    $params = [];
                    preg_match_all('/([a-z]+)="([^"]*)"/i', $challenge, $matches, PREG_SET_ORDER);
                    foreach ($matches as $match) {
                        $params[strtolower($match[1])] = $match[2];
                    }
                    if (empty($params['realm'])) {
                        throw new AppException(502, 'Registry Bearer 认证响应缺少 realm');
                    }
                    $tokenOptions = [
                        'query' => array_filter([
                            'service' => $params['service'] ?? '',
                            'scope' => $params['scope'] ?? 'repository:' . $repository . ':pull',
                        ]),
                    ];
                    $username = (string) $reg->username;
                    $password = (string) $reg->decryptField('password');
                    if ($username !== '' || $password !== '') {
                        $tokenOptions['auth'] = [$username, $password];
                    }
                    $tokenResponse = $client->get($params['realm'], $tokenOptions);
                    if ($tokenResponse->getStatusCode() !== 200) {
                        throw new AppException(422, 'Registry 认证失败，无法获取镜像读取 Token');
                    }
                    $tokenData = json_decode((string) $tokenResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
                    $token = (string) ($tokenData['token'] ?? $tokenData['access_token'] ?? '');
                    if ($token === '') {
                        throw new AppException(422, 'Registry 认证响应未包含 Token');
                    }
                    $requestOptions = ['headers' => [
                        'Accept' => $accept,
                        'Authorization' => 'Bearer ' . $token,
                    ]];
                    $response = $client->get($url, $requestOptions);
                }
            }
            if ($response->getStatusCode() === 404) {
                throw new AppException(404, 'Registry 中不存在指定镜像或标签');
            }
            if ($response->getStatusCode() !== 200) {
                throw new AppException(502, sprintf(
                    'Registry Manifest API 返回 %d: %s',
                    $response->getStatusCode(),
                    $response->getReasonPhrase()
                ));
            }
            $body = (string) $response->getBody();
            $manifest = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            $digest = $response->getHeaderLine('Docker-Content-Digest') ?: 'sha256:' . hash('sha256', $body);
            $platforms = [];
            foreach ((array) ($manifest['manifests'] ?? []) as $descriptor) {
                $platform = (array) ($descriptor['platform'] ?? []);
                if (! empty($platform['os']) && ! empty($platform['architecture'])) {
                    $platforms[] = $platform['os'] . '/' . $platform['architecture']
                        . (! empty($platform['variant']) ? '/' . $platform['variant'] : '');
                }
            }
            $size = (int) ($manifest['config']['size'] ?? 0);
            foreach ((array) ($manifest['layers'] ?? []) as $descriptor) {
                $size += (int) ($descriptor['size'] ?? 0);
            }
            // OCI index descriptors only contain the child manifest size. Resolve each real
            // platform manifest so `size` represents compressed config + layers, not a tiny index.
            foreach ((array) ($manifest['manifests'] ?? []) as $descriptor) {
                $platform = (array) ($descriptor['platform'] ?? []);
                $childDigest = (string) ($descriptor['digest'] ?? '');
                if ($childDigest === '' || empty($platform['os']) || empty($platform['architecture'])
                    || (string) $platform['os'] === 'unknown') {
                    continue;
                }
                $childUrl = sprintf('%s://%s/v2/%s/manifests/%s', $scheme, $address, $path, rawurlencode($childDigest));
                $childResponse = $client->get($childUrl, $requestOptions);
                if ($childResponse->getStatusCode() !== 200) {
                    continue;
                }
                $child = json_decode((string) $childResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
                $size += (int) ($child['config']['size'] ?? 0);
                foreach ((array) ($child['layers'] ?? []) as $layer) {
                    $size += (int) ($layer['size'] ?? 0);
                }
            }
            return [
                'digest' => $digest,
                'size' => $size,
                'media_type' => (string) ($manifest['mediaType'] ?? $response->getHeaderLine('Content-Type')),
                'platforms' => array_values(array_unique($platforms)),
            ];
        } catch (AppException $e) {
            throw $e;
        } catch (GuzzleException $e) {
            throw new AppException(502, 'Registry 镜像校验失败：' . $e->getMessage());
        } catch (\JsonException $e) {
            throw new AppException(502, 'Registry Manifest 响应不是有效 JSON：' . $e->getMessage());
        }
    }

    /**
     * 发起带认证的 GET 请求，复用 validAvailability 的 Bearer/Basic 认证流程。
     */
    private function authenticatedGet(Registry $reg, string $path): array
    {
        $address = $reg['address'];
        if (empty($address)) {
            throw new AppException(422, 'DockerHub 不支持此操作');
        }

        $scheme = $reg['proto'] == Registry::PROTO_HTTPS ? 'https' : 'http';
        $url = "{$scheme}://{$address}{$path}";
        $username = $reg['username'];
        $password = $reg->decryptField('password');

        try {
            $client = new Client([
                'http_errors' => false,
                'connect_timeout' => 10,
                'timeout' => 30,
            ]);

            // Step 1: 不带认证请求
            $resp = $client->get($url);

            if ($resp->getStatusCode() === 200) {
                return json_decode((string) $resp->getBody(), true, 512, JSON_THROW_ON_ERROR);
            }

            if ($resp->getStatusCode() !== 401) {
                throw new AppException(
                    502,
                    sprintf('Registry API 返回 %d: %s', $resp->getStatusCode(), $resp->getReasonPhrase())
                );
            }

            // Step 2: 解析 WWW-Authenticate
            $authHeader = $resp->getHeaderLine('WWW-Authenticate');

            if (preg_match('/Bearer\s+realm="([^"]+)"/', $authHeader, $m)) {
                $realm = $m[1];
                preg_match('/service="([^"]+)"/', $authHeader, $sm);
                $service = $sm[1] ?? '';
                preg_match('/scope="([^"]+)"/', $authHeader, $scm);
                $scope = $scm[1] ?? '';

                $tokenUrl = $realm . '?' . http_build_query(array_filter([
                    'service' => $service,
                    'scope' => $scope,
                ]));

                // Step 3: Basic Auth 向 realm 获取 token
                $tokenResp = $client->get($tokenUrl, [
                    'auth' => [$username, $password],
                ]);

                if ($tokenResp->getStatusCode() !== 200) {
                    throw new AppException(422, 'Registry 认证失败：帐号密码不匹配');
                }

                $tokenData = json_decode((string) $tokenResp->getBody(), true, 512, JSON_THROW_ON_ERROR);
                $token = $tokenData['token'] ?? $tokenData['access_token'] ?? null;

                if (empty($token)) {
                    throw new AppException(422, 'Registry 认证失败：无法获取 token');
                }

                // Step 4: 用 token 重试
                $resp2 = $client->get($url, [
                    'headers' => ['Authorization' => 'Bearer ' . $token],
                ]);
            } else {
                // Basic 或其他认证流程
                $resp2 = $client->get($url, [
                    'auth' => [$username, $password],
                ]);
            }

            if ($resp2->getStatusCode() === 200) {
                return json_decode((string) $resp2->getBody(), true, 512, JSON_THROW_ON_ERROR);
            }

            if ($resp2->getStatusCode() === 404) {
                return [];
            }

            throw new AppException(
                502,
                sprintf('Registry API 返回 %d: %s', $resp2->getStatusCode(), $resp2->getReasonPhrase())
            );
        } catch (ConnectException $e) {
            throw new AppException(502, '无法连接 Registry: ' . $url);
        } catch (GuzzleException $e) {
            throw new AppException(502, 'Registry 请求失败: ' . $e->getMessage());
        }
    }

    public function catalog(Registry $reg, string $search = '', int $n = 100): array
    {
        try {
            $data = $this->authenticatedGet($reg, '/v2/_catalog?n=' . $n);
        } catch (AppException $e) {
            if (str_contains($e->getMessage(), '401')) {
                throw new AppException(
                    422,
                    '该 Registry 不允许通过标准 Docker API 枚举全部仓库；这在阿里云 ACR 等服务中是正常限制，请直接填写 Repository'
                );
            }
            throw $e;
        }
        $repos = $data['repositories'] ?? [];
        if ($search !== '') {
            $repos = array_values(array_filter($repos, fn (string $r) => stripos($r, $search) !== false));
        }
        return $repos;
    }

    public function tagsList(Registry $reg, string $repository): array
    {
        $data = $this->authenticatedGet($reg, '/v2/' . $this->encodeRepository($repository) . '/tags/list');
        if (empty($data)) {
            return [];
        }
        return $data['tags'] ?? [];
    }

    public function deleteImage(Registry $reg, string $repository, string $tag): void
    {
        $address = $reg['address'];
        if (empty($address)) {
            throw new AppException(422, 'DockerHub 不支持此操作');
        }

        $scheme = $reg['proto'] == Registry::PROTO_HTTPS ? 'https' : 'http';
        $username = $reg['username'];
        $password = $reg->decryptField('password');
        $encodedRepo = $this->encodeRepository($repository);
        $encodedTag = rawurlencode($tag);

        try {
            $client = new Client([
                'http_errors' => false,
                'connect_timeout' => 10,
                'timeout' => 30,
            ]);

            // Step 1: 获取 manifest 以拿到 digest
            $manifestUrl = "{$scheme}://{$address}/v2/{$encodedRepo}/manifests/{$encodedTag}";
            $manifestResp = $this->authenticatedRequest($client, $address, $scheme, $username, $password, $manifestUrl, [
                'Accept' => 'application/vnd.docker.distribution.manifest.v2+json',
            ]);

            if (empty($manifestResp)) {
                throw new AppException(422, '未找到该镜像标签');
            }

            $digest = $manifestResp['config']['digest'] ?? '';
            // 对于 schema v2 manifest，digest 在响应头 Docker-Content-Digest 中
            // 先尝试用 manifest 自身 digest

            // Step 2: 用 GET /v2/{repo}/manifests/{tag} 获取 Docker-Content-Digest 头
            $headUrl = "{$scheme}://{$address}/v2/{$encodedRepo}/manifests/{$encodedTag}";
            $headResult = $this->authenticatedRequestWithHeader($client, $address, $scheme, $username, $password, $headUrl, [
                'Accept' => 'application/vnd.docker.distribution.manifest.v2+json',
            ]);
            $digest = (string) (($headResult['header']['Docker-Content-Digest'][0] ?? ''));

            if (empty($digest)) {
                throw new AppException(422, '无法获取镜像 digest');
            }

            // Step 3: 删除 manifest
            $deleteUrl = "{$scheme}://{$address}/v2/{$encodedRepo}/manifests/" . rawurlencode($digest);
            $this->authenticatedDelete($client, $address, $scheme, $username, $password, $deleteUrl);
        } catch (AppException $e) {
            throw $e;
        } catch (ConnectException $e) {
            throw new AppException(502, '无法连接 Registry: ' . $address);
        } catch (GuzzleException $e) {
            throw new AppException(502, 'Registry 请求失败: ' . $e->getMessage());
        }
    }

    /**
     * 发起 GET 请求并返回解析后的 JSON body + response headers。
     */
    private function authenticatedRequestWithHeader(Client $client, string $address, string $scheme, string $username, string $password, string $url, array $headers = []): array
    {
        $mergedHeaders = $headers;

        $resp = $client->get($url, ['headers' => $mergedHeaders, 'http_errors' => false]);

        if ($resp->getStatusCode() === 200) {
            $body = json_decode((string) $resp->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return [
                'body' => $body,
                'header' => $resp->getHeaders(),
            ];
        }

        if ($resp->getStatusCode() !== 401) {
            return [];
        }

        // 需要认证
        $authHeader = $resp->getHeaderLine('WWW-Authenticate');

        if (preg_match('/Bearer\s+realm="([^"]+)"/', $authHeader, $m)) {
            $realm = $m[1];
            preg_match('/service="([^"]+)"/', $authHeader, $sm);
            $service = $sm[1] ?? '';
            preg_match('/scope="([^"]+)"/', $authHeader, $scm);
            $scope = $scm[1] ?? '';

            $tokenUrl = $realm . '?' . http_build_query(array_filter([
                'service' => $service,
                'scope' => $scope,
            ]));

            $tokenResp = $client->get($tokenUrl, ['auth' => [$username, $password]]);
            if ($tokenResp->getStatusCode() !== 200) {
                return [];
            }

            $tokenData = json_decode((string) $tokenResp->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $token = $tokenData['token'] ?? $tokenData['access_token'] ?? null;
            if (empty($token)) {
                return [];
            }

            $mergedHeaders['Authorization'] = 'Bearer ' . $token;
            $resp2 = $client->get($url, ['headers' => $mergedHeaders, 'http_errors' => false]);
        } else {
            $resp2 = $client->get($url, [
                'auth' => [$username, $password], 'headers' => $mergedHeaders, 'http_errors' => false,
            ]);
        }

        if ($resp2->getStatusCode() === 200) {
            $body = json_decode((string) $resp2->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return [
                'body' => $body,
                'header' => $resp2->getHeaders(),
            ];
        }

        return [];
    }

    private function authenticatedRequest(Client $client, string $address, string $scheme, string $username, string $password, string $url, array $headers = []): array
    {
        return $this->authenticatedRequestWithHeader($client, $address, $scheme, $username, $password, $url, $headers)['body'] ?? [];
    }

    private function authenticatedDelete(Client $client, string $address, string $scheme, string $username, string $password, string $url): void
    {
        $resp = $client->delete($url, ['http_errors' => false]);

        if ($resp->getStatusCode() === 202 || $resp->getStatusCode() === 200) {
            return;
        }

        if ($resp->getStatusCode() !== 401) {
            throw new AppException(502, sprintf('Registry API 返回 %d: %s', $resp->getStatusCode(), $resp->getReasonPhrase()));
        }

        $authHeader = $resp->getHeaderLine('WWW-Authenticate');

        if (preg_match('/Bearer\s+realm="([^"]+)"/', $authHeader, $m)) {
            $realm = $m[1];
            preg_match('/service="([^"]+)"/', $authHeader, $sm);
            $service = $sm[1] ?? '';
            preg_match('/scope="([^"]+)"/', $authHeader, $scm);
            $scope = $scm[1] ?? '';

            $tokenUrl = $realm . '?' . http_build_query(array_filter([
                'service' => $service,
                'scope' => $scope,
            ]));

            $tokenResp = $client->get($tokenUrl, ['auth' => [$username, $password]]);
            if ($tokenResp->getStatusCode() !== 200) {
                throw new AppException(422, 'Registry 认证失败：帐号密码不匹配');
            }

            $tokenData = json_decode((string) $tokenResp->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $token = $tokenData['token'] ?? $tokenData['access_token'] ?? null;
            if (empty($token)) {
                throw new AppException(422, 'Registry 认证失败：无法获取 token');
            }

            $resp2 = $client->delete($url, [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'http_errors' => false,
            ]);
        } else {
            $resp2 = $client->delete($url, [
                'auth' => [$username, $password],
                'http_errors' => false,
            ]);
        }

        if ($resp2->getStatusCode() !== 202 && $resp2->getStatusCode() !== 200) {
            throw new AppException(
                502,
                sprintf('删除失败，Registry API 返回 %d: %s', $resp2->getStatusCode(), $resp2->getReasonPhrase())
            );
        }
    }

    private function encodeRepository(string $repository): string
    {
        return implode('/', array_map('rawurlencode', explode('/', trim($repository, '/'))));
    }
}
