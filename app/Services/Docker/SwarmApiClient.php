<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */

namespace App\Services\Docker;

use App\Exception\AppException;
use App\Model\Cluster;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;

class SwarmApiClient
{
    public function __construct(private AgentRelayService $agentRelay) {}

    public function withCluster(Cluster $cluster, callable $callback, int $timeout = 30): mixed
    {
        [$client, $temporaryFiles] = $this->clientForCluster($cluster, $timeout);

        try {
            return $callback($client, $this);
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    public function clientForCluster(Cluster $cluster, int $timeout = 30, bool $httpErrors = false): array
    {
        $agent = $this->agentRelay->parseEndpoint((string) $cluster['endpoint']);
        if ($agent !== null) {
            [$clusterId, $nodeId] = $agent;
            $handler = function ($request, array $options) use ($clusterId, $nodeId, $timeout) {
                try {
                    return Create::promiseFor($this->agentRelay->requestNode(
                        $clusterId,
                        $nodeId,
                        $request,
                        (float) ($options['timeout'] ?? $timeout)
                    ));
                } catch (\Throwable $e) {
                    return Create::rejectionFor($e);
                }
            };
            return [new Client(['base_uri' => 'http://docker', 'handler' => $handler, 'http_errors' => $httpErrors, 'timeout' => $timeout]), []];
        }
        throw new AppException(503, '该 Swarm 集群尚未通过 Galaxy Agent 注册');
    }

    public function withNode(Cluster $cluster, string $nodeId, callable $callback, int $timeout = 30): mixed
    {
        $nodeCluster = clone $cluster;
        $nodeCluster->endpoint = 'agent://' . (int) $cluster->id . '/' . rawurlencode($nodeId);
        return $this->withCluster($nodeCluster, $callback, $timeout);
    }

    public function request(Client $client, string $method, string $path, array $options = []): array
    {
        if (
            strtoupper($method) === 'POST'
            && preg_match('#^/services/[^/]+/update$#', $path)
            && isset($options['json'])
            && is_array($options['json'])
        ) {
            $options['json'] = $this->normalizeServiceSpec($options['json']);
        }
        try {
            $response = $client->request($method, $path, $options);
        } catch (\Throwable $e) {
            throw new AppException(502, 'Docker Manager API 请求失败：' . $e->getMessage(), [], $e);
        }

        $body = (string) $response->getBody();
        $data = $body === '' ? [] : json_decode($body, true);
        if ($response->getStatusCode() >= 400) {
            $message = is_array($data) ? ($data['message'] ?? $body) : $body;
            throw new AppException(502, sprintf(
                'Docker Manager API 返回 %d：%s',
                $response->getStatusCode(),
                $message ?: 'unknown error'
            ));
        }
        if ($body !== '' && ! is_array($data)) {
            throw new AppException(502, 'Docker Manager API 返回了无法解析的 JSON');
        }

        return $data ?: [];
    }

    public function normalizeServiceSpec(array $spec): array
    {
        if (array_key_exists('Global', (array) ($spec['Mode'] ?? []))) {
            // Docker serializes empty structs as {}, while json_decode(..., true)
            // turns them into []. Service update rejects the array form.
            $spec['Mode']['Global'] = new \stdClass();
            unset(
                $spec['Mode']['Replicated'],
                $spec['Mode']['ReplicatedJob'],
                $spec['Mode']['GlobalJob']
            );
        }
        return $spec;
    }

    public function requestRaw(Client $client, string $method, string $path, array $options = []): string
    {
        try {
            $response = $client->request($method, $path, $options);
        } catch (\Throwable $e) {
            throw new AppException(502, 'Docker Manager API 请求失败：' . $e->getMessage(), [], $e);
        }
        $body = (string) $response->getBody();
        if ($response->getStatusCode() >= 400) {
            $data = json_decode($body, true);
            throw new AppException(502, sprintf(
                'Docker Manager API 返回 %d：%s',
                $response->getStatusCode(),
                is_array($data) ? ($data['message'] ?? $body) : ($body ?: 'unknown error')
            ));
        }
        return $body;
    }

    public function putArchive(Client $client, string $containerId, string $path, string $tar): void
    {
        try {
            $response = $client->request('PUT', '/containers/' . rawurlencode($containerId) . '/archive', [
                'query' => ['path' => $path, 'allowOverwriteDirWithFile' => 'true'],
                'body' => $tar,
                'headers' => ['Content-Type' => 'application/x-tar'],
            ]);
        } catch (\Throwable $e) {
            throw new AppException(502, 'Docker Manager API 请求失败：' . $e->getMessage(), [], $e);
        }
        if ($response->getStatusCode() >= 400) {
            $body = (string) $response->getBody();
            $data = json_decode($body, true);
            throw new AppException(500, '写入文件失败：' . (is_array($data) ? ($data['message'] ?? '') : ($body ?: 'unknown error')));
        }
    }

    public function getArchive(Client $client, string $containerId, string $path): string
    {
        return $this->requestRaw(
            $client,
            'GET',
            '/containers/' . rawurlencode($containerId) . '/archive',
            ['query' => ['path' => $path]]
        );
    }

    /** @deprecated Galaxy API never exposes or connects a Docker endpoint. */
    public function directEndpoint(Cluster $cluster): string
    {
        throw new AppException(410, 'Docker API 直连已废弃，请安装 Agent 并重新注册集群');
    }
}
