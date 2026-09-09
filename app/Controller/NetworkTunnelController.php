<?php

namespace App\Controller;

use App\Services\NetworkTunnel\NetworkTunnelService;
use App\Support\Functions;

final class NetworkTunnelController extends AbstractController
{
    public function __construct(private NetworkTunnelService $tunnels) {}

    public function topology()
    {
        return $this->success($this->tunnels->topology($this->orgId()));
    }

    public function sourceServices()
    {
        $params = $this->validate(['cluster_id' => 'required|integer|min:1']);
        return $this->success([
            'services' => $this->tunnels->sourceServices(
                $this->orgId(),
                (int) $params['cluster_id']
            ),
        ]);
    }

    public function createServer()
    {
        $params = $this->validate($this->serverRules(true));
        $server = $this->tunnels->createServer(
            (int) Functions::getLoginUser()->getId(),
            $this->orgId(),
            $params
        );
        return $this->success(['server_id' => (int) $server->id]);
    }

    public function updateServer()
    {
        $params = $this->validate(['server_id' => 'required|integer|min:1'] + $this->serverRules(false));
        $server = $this->tunnels->updateServer(
            $this->orgId(),
            (int) $params['server_id'],
            $params
        );
        return $this->success(['server_id' => (int) $server->id]);
    }

    public function deleteServer()
    {
        $params = $this->validate(['server_id' => 'required|integer|min:1']);
        $this->tunnels->deleteServer($this->orgId(), (int) $params['server_id']);
        return $this->success();
    }

    public function createClient()
    {
        $params = $this->validate($this->clientRules(true));
        $client = $this->tunnels->createClient(
            (int) Functions::getLoginUser()->getId(),
            $this->orgId(),
            $params
        );

        return $this->success(['client_id' => (int) $client->id]);
    }

    public function updateClient()
    {
        $params = $this->validate(['client_id' => 'required|integer|min:1'] + $this->clientRules(false));
        $client = $this->tunnels->updateClient(
            $this->orgId(),
            (int) $params['client_id'],
            $params
        );

        return $this->success(['client_id' => (int) $client->id]);
    }

    public function deleteClient()
    {
        $params = $this->validate(['client_id' => 'required|integer|min:1']);
        $this->tunnels->deleteClient($this->orgId(), (int) $params['client_id']);
        return $this->success();
    }

    public function saveTunnel()
    {
        $params = $this->validate([
            'tunnel_id' => 'nullable|integer|min:1',
            'client_id' => 'required|integer|min:1',
            'title' => 'required|string|max:120',
            'proxy_name' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/'],
            'type' => 'required|string|in:tcp,udp,http,https',
            'local_host' => 'required|string|max:255',
            'local_port' => 'required|integer|min:1|max:65535',
            'remote_port' => 'nullable|integer|min:0|max:65535',
            'custom_domains' => 'nullable|array|max:20',
            'custom_domains.*' => 'string|max:255',
            'locations' => 'nullable|array|max:20',
            'locations.*' => 'string|max:255',
            'host_header_rewrite' => 'nullable|string|max:255',
            'transport_encryption' => 'nullable|boolean',
            'transport_compression' => 'nullable|boolean',
            'bandwidth_limit' => ['nullable', 'string', 'max:32', 'regex:/^$|^[1-9][0-9]*(KB|MB|GB)$/i'],
            'enabled' => 'nullable|boolean',
        ]);
        if (in_array((string) $params['type'], ['tcp', 'udp'], true)
            && (int) ($params['remote_port'] ?? 0) < 1) {
            $this->validationError('remote_port', 'TCP/UDP 穿透必须设置外部端口');
        }
        $tunnel = $this->tunnels->saveTunnel(
            $this->orgId(),
            isset($params['tunnel_id']) ? (int) $params['tunnel_id'] : null,
            $params
        );
        $this->tunnels->sync($this->orgId(), (int) $tunnel->client->server_id);

        return $this->success(['tunnel_id' => (int) $tunnel->id]);
    }

    public function saveAutomaticTunnel()
    {
        $params = $this->validate([
            'tunnel_id' => 'nullable|integer|min:1',
            'title' => 'required|string|max:120',
            'source_cluster_id' => 'required|integer|min:1',
            'source_service_ref' => 'required|string|max:255',
            'source_service_name' => 'required|string|max:255',
            'source_service_namespace' => 'nullable|string|max:255',
            'source_port' => 'required|integer|min:1|max:65535',
            'destination_cluster_id' => 'nullable|integer|min:1',
            'destination_server_id' => 'nullable|integer|min:1',
            'destination_port' => 'required|integer|min:1|max:65535',
            'protocol' => 'nullable|in:tcp,udp',
            'frps_bind_port' => 'nullable|integer|min:1|max:65535',
            'frps_dashboard_port' => 'nullable|integer|min:0|max:65535',
            'use_encryption' => 'nullable|boolean',
            'use_compression' => 'nullable|boolean',
            'pool_count' => 'nullable|integer|min:1|max:100',
            'bandwidth_limit' => ['nullable', 'string', 'max:32', 'regex:/^$|^[1-9][0-9]*(KB|MB|GB)$/i'],
            'enabled' => 'nullable|boolean',
        ]);
        $tunnel = $this->tunnels->saveAutomaticTunnel(
            (int) Functions::getLoginUser()->getId(),
            $this->orgId(),
            isset($params['tunnel_id']) ? (int) $params['tunnel_id'] : null,
            $params
        );

        return $this->success(['tunnel_id' => (int) $tunnel->id]);
    }

    public function deleteTunnel()
    {
        $params = $this->validate(['tunnel_id' => 'required|integer|min:1']);
        $this->tunnels->deleteTunnel($this->orgId(), (int) $params['tunnel_id']);
        return $this->success();
    }

    public function sync()
    {
        $params = $this->validate(['server_id' => 'required|integer|min:1']);
        $this->tunnels->sync($this->orgId(), (int) $params['server_id']);
        return $this->success();
    }

    public function syncAll()
    {
        $params = $this->validate(['cluster_id' => 'nullable|integer|min:1']);
        return $this->success([
            'results' => $this->tunnels->syncAll(
                $this->orgId(),
                isset($params['cluster_id']) ? (int) $params['cluster_id'] : null
            ),
        ]);
    }

    private function serverRules(bool $creating): array
    {
        return [
            'title' => 'required|string|max:120',
            'deployment_mode' => 'nullable|string|in:managed,external',
            'cluster_id' => 'nullable|integer|min:1',
            'namespace' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/'],
            'image' => 'required|string|max:512',
            'advertise_host' => 'required|string|max:255',
            'bind_port' => 'required|integer|min:1|max:65535',
            'vhost_http_port' => 'nullable|integer|min:0|max:65535',
            'vhost_https_port' => 'nullable|integer|min:0|max:65535',
            'dashboard_port' => 'nullable|integer|min:0|max:65535',
            'dashboard_user' => 'nullable|string|max:120',
            'dashboard_password' => 'nullable|string|max:512',
            'auth_token' => ($creating ? 'nullable' : 'nullable') . '|string|max:512',
        ];
    }

    private function clientRules(bool $creating): array
    {
        return [
            'server_id' => ($creating ? 'required' : 'nullable') . '|integer|min:1',
            'title' => 'required|string|max:120',
            'cluster_id' => 'nullable|integer|min:1',
            'namespace' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/'],
            'image' => 'required|string|max:512',
            'frp_user' => ['nullable', 'string', 'max:64', 'regex:/^$|^[a-zA-Z0-9][a-zA-Z0-9_-]*$/'],
        ];
    }

    private function orgId(): int
    {
        return (int) Functions::getContextValue('org_id');
    }

    private function validationError(string $field, string $message): never
    {
        throw new \App\Exception\AppException(422, $message, [$field => [$message]]);
    }
}
