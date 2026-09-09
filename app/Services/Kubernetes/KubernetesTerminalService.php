<?php

declare(strict_types=1);

namespace App\Services\Kubernetes;

use App\Exception\AppException;
use App\Model\Cluster;
use Hyperf\Redis\Redis;

use function preg_match;

/**
 * Bridges a one-time browser terminal ticket to a Kubernetes Pod `exec` stream.
 * Layout deliberately mirrors App\Services\Docker\SwarmTerminalService so the
 * two terminal implementations stay reviewable side by side.
 */
final class KubernetesTerminalService
{
    private const TICKET_PREFIX = 'galaxy:k8s:terminal:';
    private const TICKET_TTL = 30;
    private const DEFAULT_COMMAND = ['/bin/sh'];

    public function __construct(
        private KubernetesClusterService $clusters,
        private Redis $redis
    ) {}

    /**
     * @param array<int, string> $command
     */
    public function issueTicket(
        int $uid,
        int $orgId,
        int $clusterId,
        string $namespace,
        string $pod,
        string $container,
        array $command = []
    ): array {
        $cluster = Cluster::where('id', $clusterId)
            ->where('org_id', $orgId)
            ->where('orchestrator_type', Cluster::ORCHESTRATOR_KUBERNETES)
            ->first();
        if ($cluster === null) {
            throw new AppException(404, 'Kubernetes 集群不存在');
        }
        $this->validateName($namespace, '命名空间');
        $this->validateName($pod, 'Pod');
        $this->validateName($container, '容器');
        if ($command === []) {
            $command = self::DEFAULT_COMMAND;
        }

        $ticket = bin2hex(random_bytes(32));
        $payload = json_encode([
            'uid' => $uid,
            'org_id' => $orgId,
            'cluster_id' => $clusterId,
            'namespace' => $namespace,
            'pod' => $pod,
            'container' => $container,
            'command' => $command,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->redis->setex(self::TICKET_PREFIX . $ticket, self::TICKET_TTL, $payload);

        return [
            'ticket' => $ticket,
            'expires_in' => self::TICKET_TTL,
            'websocket_path' => 'cluster/k8s/terminal',
        ];
    }

    public function consumeTicket(string $ticket): array
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $ticket)) {
            throw new AppException(401, 'Web 终端票据无效');
        }
        $payload = $this->redis->eval(
            "local value = redis.call('GET', KEYS[1]); if value then redis.call('DEL', KEYS[1]); end; return value",
            [self::TICKET_PREFIX . $ticket],
            1
        );
        if (! is_string($payload) || $payload === '') {
            throw new AppException(401, 'Web 终端票据已过期或已经使用');
        }
        return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{client: KubernetesExecStream, initialOutput: string}
     */
    public function open(array $ticket, int $columns = 120, int $rows = 40): array
    {
        $orgId = (int) ($ticket['org_id'] ?? 0);
        $clusterId = (int) ($ticket['cluster_id'] ?? 0);
        $namespace = (string) ($ticket['namespace'] ?? '');
        $pod = (string) ($ticket['pod'] ?? '');
        $container = (string) ($ticket['container'] ?? '');
        $command = (array) ($ticket['command'] ?? self::DEFAULT_COMMAND);

        [, , $credential] = $this->clusters->connectionWithCredential($orgId, $clusterId);
        $this->assertExecutable($orgId, $clusterId, $namespace, $pod, $container);

        $stream = new KubernetesExecStream(
            $credential,
            $namespace,
            $pod,
            $container,
            $command,
            $columns,
            $rows
        );
        $stream->open();

        return ['client' => $stream, 'initialOutput' => ''];
    }

    public function resize(KubernetesExecStream $stream, int $columns, int $rows): void
    {
        $stream->resize($columns, $rows);
    }

    /**
     * @param array{client?: KubernetesExecStream} $session
     */
    public function close(array $session): void
    {
        if (isset($session['client']) && $session['client'] instanceof KubernetesExecStream) {
            $session['client']->close();
        }
    }

    private function assertExecutable(
        int $orgId,
        int $clusterId,
        string $namespace,
        string $pod,
        string $container
    ): void {
        $detail = $this->clusters->pod($orgId, $clusterId, $namespace, $pod);
        $phase = (string) ($detail['status']['phase'] ?? '');
        if ($phase !== 'Running') {
            throw new AppException(422, '只有处于 Running 状态的 Pod 可以进入终端（当前：' . $phase . '）');
        }
        $containers = (array) ($detail['spec']['containers'] ?? []);
        $names = array_map(static fn ($c): string => (string) ($c['name'] ?? ''), $containers);
        if (! in_array($container, $names, true)) {
            throw new AppException(422, '容器 ' . $container . ' 不存在于该 Pod');
        }
    }

    private function validateName(string $value, string $label): void
    {
        if ($value === '' || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,252}$/', $value)) {
            throw new AppException(422, $label . '名称不合法');
        }
    }
}
