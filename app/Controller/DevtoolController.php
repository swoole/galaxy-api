<?php

namespace App\Controller;

use App\Exception\AppException;
use App\Model\Cluster;
use App\Services\Docker\AgentRelayService;
use App\Services\Docker\SwarmApiClient;
use App\Services\RegistryService;
use Hyperf\Context\Context;
use Psr\Http\Message\ResponseInterface;

class DevtoolController extends AbstractController
{
    public function __construct(
        private SwarmApiClient $docker,
        private AgentRelayService $agent,
        private RegistryService $registries
    ) {}

    public function dockerRequest(): ResponseInterface
    {
        $this->assertScope('docker');
        $input = $this->validate([
            'cluster_id' => 'required|integer|min:1',
            'node_id' => 'nullable|string|max:128',
            'method' => 'required|string|in:GET,POST,PUT,PATCH,DELETE,HEAD',
            'path' => 'required|string|max:2048',
            'query' => 'nullable|array',
            'headers' => 'nullable|array',
            'registry_auth_image' => 'nullable|string|max:512',
            'body' => 'nullable|string',
            'body_base64' => 'nullable|string',
            'timeout' => 'nullable|integer|min:1|max:1800',
        ]);
        $path = (string) $input['path'];
        if (! str_starts_with($path, '/') || str_contains($path, "\r") || str_contains($path, "\n")) {
            throw new AppException(422, 'Docker API path 必须是以 / 开头的相对路径');
        }
        $cluster = Cluster::where('id', (int) $input['cluster_id'])
            ->where('orchestrator_type', Cluster::ORCHESTRATOR_DOCKER_SWARM)->first();
        if ($cluster === null) {
            throw new AppException(404, 'Docker Swarm 集群不存在');
        }

        $headers = (array) ($input['headers'] ?? []);
        $registryAuthImage = trim((string) ($input['registry_auth_image'] ?? ''));
        if ($registryAuthImage !== '') {
            $push = (string) $input['method'] === 'POST' && preg_match('#^/images/.+/push$#', $path);
            $serviceUpdate = (string) $input['method'] === 'POST'
                && preg_match('#^/services/[^/]+/update$#', $path);
            if (! $push && ! $serviceUpdate) {
                throw new AppException(422, 'Registry 凭据只允许用于镜像推送或 Swarm Service 更新');
            }
            $registryHeaders = $this->registries->dockerAuthHeader(
                (int) $cluster->org_id,
                $registryAuthImage
            );
            if ($registryHeaders === []) {
                throw new AppException(422, 'Galaxy 未找到该镜像对应的 Registry 凭据');
            }
            $headers = array_merge($headers, $registryHeaders);
        }

        $body = (string) ($input['body'] ?? '');
        if (! empty($input['body_base64'])) {
            $decoded = base64_decode((string) $input['body_base64'], true);
            if ($decoded === false) {
                throw new AppException(422, 'body_base64 不是有效的 Base64');
            }
            $body = $decoded;
        }
        $timeout = (int) ($input['timeout'] ?? 30);
        $request = function ($client) use ($input, $path, $body, $headers): array {
            $response = $client->request((string) $input['method'], $path, [
                'query' => (array) ($input['query'] ?? []),
                'headers' => $headers,
                'body' => $body,
            ]);
            $body = (string) $response->getBody();
            $json = json_decode($body, true);
            $result = [
                'status' => $response->getStatusCode(),
                'headers' => $response->getHeaders(),
            ];
            if (json_last_error() === JSON_ERROR_NONE) {
                $result['body'] = $json;
            } elseif (mb_check_encoding($body, 'UTF-8')) {
                $result['body'] = $body;
            } else {
                $result['body'] = '';
                $result['body_base64'] = base64_encode($body);
                $result['body_encoding'] = 'base64';
            }
            return $result;
        };
        $nodeId = trim((string) ($input['node_id'] ?? ''));
        $result = $nodeId === ''
            ? $this->docker->withCluster($cluster, $request, $timeout)
            : $this->docker->withNode($cluster, $nodeId, $request, $timeout);
        return $this->success($result);
    }

    public function agentCommand(): ResponseInterface
    {
        $this->assertScope('agent');
        $input = $this->validate([
            'cluster_id' => 'required|integer|min:1',
            'action' => 'required|string|max:128',
            'payload' => 'nullable|array',
            'body' => 'nullable|string',
            'timeout' => 'nullable|numeric|min:1|max:900',
        ]);
        $cluster = Cluster::where('id', (int) $input['cluster_id'])
            ->where('orchestrator_type', Cluster::ORCHESTRATOR_DOCKER_SWARM)->first();
        if ($cluster === null) {
            throw new AppException(404, 'Docker Swarm 集群不存在');
        }
        $result = $this->agent->command(
            (string) $cluster->endpoint,
            (string) $input['action'],
            (array) ($input['payload'] ?? []),
            (string) ($input['body'] ?? ''),
            (float) ($input['timeout'] ?? 180)
        );
        return $this->success($result);
    }

    private function assertScope(string $scope): void
    {
        $access = (array) Context::get('devtool_access', []);
        if (! in_array($scope, (array) ($access['scopes'] ?? []), true)) {
            throw new AppException(403, "Devtool Access Token 缺少 {$scope} scope");
        }
    }
}
