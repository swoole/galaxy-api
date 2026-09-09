<?php

namespace App\Services\Docker;

use App\Exception\AppException;
use GuzzleHttp\Psr7\Response;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Process\ProcessCollector;
use Hyperf\Server\ServerFactory;
use Psr\Http\Message\RequestInterface;
use Swoole\Coroutine\Channel;
use Swoole\Server;
use Throwable;

class AgentRelayService
{
    private const IPC_CHUNK_BYTES = 48 * 1024;

    /** @var array<string, array{channel: Channel, cluster_id: int, node_id: string, fd?: int}> */
    private array $pending = [];

    /** @var array<string, array{reply: array, cluster_id: int, node_id: string, fd: int, expires_at: int, stream: bool}> */
    private array $forwarded = [];

    /** @var array<string, array{chunks: array<int, string>, total: int, expires_at: int}> */
    private array $ipcChunks = [];

    #[Inject]
    private ?ServerFactory $serverFactory = null;

    #[Inject]
    private ?LoggerFactory $loggerFactory = null;

    public function __construct(
        private AgentSessionRegistry $sessions,
        private ?Server $server = null
    ) {}

    /** @return array<int, array{cluster_id: int, node_id: string, role: string}> */
    public function unregister(int $fd): array
    {
        return $this->sessions->unregister($fd);
    }

    public function isOnline(int $clusterId, ?string $nodeId = null): bool
    {
        return $this->sessions->isOnline($clusterId, $nodeId);
    }

    public function disconnect(int $clusterId, string $reason = 'agent token rotated'): void
    {
        foreach ($this->sessions->clusterRoutes($clusterId) as $connection) {
            if (isset($connection['server'], $connection['fd'])) {
                if ($connection['server']->isEstablished($connection['fd'])) {
                    $connection['server']->disconnect($connection['fd'], 1000, $reason);
                }
                continue;
            }
            $this->sendToOwner($connection, [
                'op' => 'disconnect',
                'route' => $this->portableRoute($connection),
                'reason' => $reason,
            ]);
        }
    }

    public function resolve(array $message, int $fd): void
    {
        $id = (string) ($message['id'] ?? '');
        if ($id === '') {
            return;
        }
        $pending = $this->pending[$id] ?? null;
        if (
            $pending !== null
            && (! isset($pending['fd']) || $pending['fd'] === $fd)
            && $this->sessions->owns($pending['cluster_id'], $pending['node_id'], $fd)
        ) {
            $pending['channel']->push($message);
            return;
        }

        $forwarded = $this->forwarded[$id] ?? null;
        if (
            $forwarded === null
            || $forwarded['fd'] !== $fd
            || ! $this->sessions->owns($forwarded['cluster_id'], $forwarded['node_id'], $fd)
        ) {
            return;
        }
        $this->sendReply($forwarded['reply'], ['op' => 'response', 'message' => $message]);
        $type = (string) ($message['type'] ?? '');
        if (
            in_array($type, ['response', 'domain_response', 'stream_close'], true)
            || ($type === 'stream_ready' && ! empty($message['error']))
        ) {
            unset($this->forwarded[$id]);
        }
    }

    public function openStream(string $endpoint, string $method, string $path, array $headers, string $body): AgentDockerStream
    {
        $agent = $this->parseEndpoint($endpoint);
        if ($agent === null) {
            throw new AppException(422, '该集群未使用 Docker Agent');
        }
        [$clusterId, $nodeId] = $agent;
        $connection = $nodeId === null
            ? $this->sessions->manager($clusterId)
            : $this->sessions->node($clusterId, $nodeId);
        $id = bin2hex(random_bytes(16));
        $channel = new Channel(64);
        $this->pending[$id] = $this->pendingRequest($channel, $clusterId, $connection);
        $payload = [
            'type' => 'stream_open',
            'id' => $id,
            'method' => $method,
            'path' => $path,
            'headers' => $headers,
            'body' => base64_encode($body),
        ];
        try {
            $this->forward($connection, $payload, 86400, true);
            $ready = $channel->pop(15);
            if (! is_array($ready) || ($ready['type'] ?? '') !== 'stream_ready') {
                $this->closeStream($id, $connection);
                throw new AppException(504, 'Docker Agent 流连接超时');
            }
            if (! empty($ready['error'])) {
                $this->closeStream($id, $connection);
                throw new AppException(502, 'Docker Agent：' . $ready['error']);
            }
            return new AgentDockerStream($this, $connection, $id, $channel);
        } catch (Throwable $e) {
            if (! isset($this->pending[$id])) {
                throw $e;
            }
            if (! ($e instanceof AppException) || $e->getMessage() !== 'Docker Agent 流连接超时') {
                unset($this->pending[$id]);
                $channel->close();
            }
            throw $e;
        }
    }

    public function domainCommand(int $clusterId, string $command, array $payload = [], float $timeout = 30): array
    {
        if (! in_array($command, ['swarm.version', 'swarm.info', 'swarm.inspect'], true)) {
            throw new AppException(422, '不支持的 Agent 领域命令');
        }
        $message = $this->roundTrip($this->sessions->manager($clusterId), [
            'type' => 'domain_command',
            'id' => bin2hex(random_bytes(16)),
            'command' => $command,
            'payload' => $payload,
        ], $timeout);
        if (! empty($message['error'])) {
            throw new AppException(502, 'Docker Agent：' . $message['error']);
        }
        $body = base64_decode((string) ($message['body'] ?? ''), true);
        if ($body === false) {
            throw new AppException(502, 'Agent 领域命令返回了无效正文');
        }
        $decoded = $body === '' ? [] : json_decode($body, true);
        if (! is_array($decoded)) {
            throw new AppException(502, 'Agent 领域命令返回了无效 JSON');
        }
        return $decoded;
    }

    public function sendStream(array $connection, string $id, string $data): bool
    {
        try {
            $this->sendFrame($connection, [
                'type' => 'stream_data',
                'id' => $id,
                'data' => base64_encode($data),
            ]);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function closeStream(string $id, ?array $connection = null): void
    {
        if ($connection !== null) {
            try {
                $this->sendFrame($connection, ['type' => 'stream_close', 'id' => $id]);
            } catch (Throwable) {
            }
        }
        if (isset($this->pending[$id])) {
            $this->pending[$id]['channel']->close();
            unset($this->pending[$id]);
        }
    }

    public function request(int $clusterId, RequestInterface $request, float $timeout = 30): Response
    {
        return $this->requestNode($clusterId, null, $request, $timeout);
    }

    public function requestNode(
        int $clusterId,
        ?string $nodeId,
        RequestInterface $request,
        float $timeout = 30
    ): Response {
        $connection = $nodeId === null
            ? $this->sessions->manager($clusterId)
            : $this->sessions->node($clusterId, $nodeId);
        $message = $this->roundTrip($connection, [
            'type' => 'request',
            'id' => bin2hex(random_bytes(16)),
            'method' => $request->getMethod(),
            'path' => $request->getRequestTarget(),
            'headers' => $request->getHeaders(),
            'body' => base64_encode((string) $request->getBody()),
        ], $timeout);
        if (! empty($message['error'])) {
            throw new AppException(502, 'Docker Agent：' . $message['error']);
        }
        $body = base64_decode((string) ($message['body'] ?? ''), true);
        if ($body === false) {
            throw new AppException(502, 'Docker Agent 返回了无效的响应正文');
        }
        return new Response((int) ($message['status'] ?? 502), (array) ($message['headers'] ?? []), $body);
    }

    public function parseEndpoint(string $endpoint): ?array
    {
        $parts = parse_url($endpoint);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'agent' || empty($parts['host'])) {
            return null;
        }
        if (! ctype_digit((string) $parts['host']) || (int) $parts['host'] < 1) {
            throw new AppException(422, 'Docker Agent 地址无效');
        }
        $path = trim((string) ($parts['path'] ?? ''), '/');
        if ($path !== '' && ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/', $path)) {
            throw new AppException(422, 'Docker Agent 节点地址无效');
        }
        return [(int) $parts['host'], $path === '' ? null : $path];
    }

    public function receiveIpcChunk(AgentIpcMessage $chunk, ?Server $server = null): void
    {
        $this->server ??= $server ?? $this->server();
        $now = time();
        foreach ($this->ipcChunks as $id => $buffer) {
            if ($buffer['expires_at'] < $now) {
                unset($this->ipcChunks[$id]);
            }
        }
        $buffer = $this->ipcChunks[$chunk->transferId] ?? [
            'chunks' => [],
            'total' => $chunk->total,
            'expires_at' => $now + 60,
        ];
        if ($chunk->total !== $buffer['total'] || $chunk->index < 0 || $chunk->index >= $chunk->total) {
            unset($this->ipcChunks[$chunk->transferId]);
            return;
        }
        $buffer['chunks'][$chunk->index] = $chunk->payload;
        $this->ipcChunks[$chunk->transferId] = $buffer;
        if (count($buffer['chunks']) !== $buffer['total']) {
            return;
        }
        ksort($buffer['chunks']);
        unset($this->ipcChunks[$chunk->transferId]);
        try {
            $payload = json_decode(implode('', $buffer['chunks']), true, 512, JSON_THROW_ON_ERROR);
            if (is_array($payload)) {
                $this->handleIpcPayload($payload);
            }
        } catch (Throwable) {
        }
    }

    private function roundTrip(array $connection, array $payload, float $timeout): array
    {
        $id = (string) $payload['id'];
        $channel = new Channel(1);
        $this->pending[$id] = $this->pendingRequest($channel, (int) $connection['cluster_id'], $connection);
        try {
            $this->forward($connection, $payload, $timeout);
            $message = $channel->pop($timeout);
            if (! is_array($message)) {
                throw new AppException(504, str_starts_with((string) $payload['type'], 'domain_')
                    ? 'Agent 领域命令执行超时'
                    : 'Docker Agent 请求超时');
            }
            return $message;
        } finally {
            unset($this->pending[$id]);
            $channel->close();
        }
    }

    private function forward(array $connection, array $message, float $timeout, bool $stream = false): void
    {
        if (isset($connection['server'], $connection['fd'])) {
            $this->pending[(string) $message['id']]['fd'] = (int) $connection['fd'];
            if (! $connection['server']->push($connection['fd'], json_encode(
                $message,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ))) {
                throw new AppException(503, '无法向 Docker Agent 发送请求');
            }
            return;
        }
        $this->sendToOwner($connection, [
            'op' => 'forward',
            'route' => $this->portableRoute($connection),
            'message' => $message,
            'reply' => $this->replyAddress(),
            'expires_at' => time() + max(1, (int) ceil($timeout)),
            'stream' => $stream,
        ]);
    }

    private function handleIpcPayload(array $payload): void
    {
        $op = (string) ($payload['op'] ?? '');
        if ($op === 'response') {
            if (isset($payload['target_pid']) && (int) $payload['target_pid'] !== getmypid()) {
                return;
            }
            $message = (array) ($payload['message'] ?? []);
            $id = (string) ($message['id'] ?? '');
            if ($id !== '' && isset($this->pending[$id])) {
                $this->pending[$id]['channel']->push($message);
            }
            return;
        }

        $route = (array) ($payload['route'] ?? []);
        $connection = $this->sessions->localConnection(
            (int) ($route['cluster_id'] ?? 0),
            (string) ($route['node_id'] ?? ''),
            (string) ($route['connection_id'] ?? '')
        );
        if ($op === 'disconnect') {
            if ($connection !== null && $connection['server']->isEstablished($connection['fd'])) {
                $connection['server']->disconnect($connection['fd'], 1000, (string) ($payload['reason'] ?? 'agent disconnected'));
            }
            return;
        }
        if ($op === 'frame') {
            if ($connection !== null) {
                $message = (array) ($payload['message'] ?? []);
                try {
                    $connection['server']->push($connection['fd'], json_encode(
                        $message,
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                    ));
                } catch (Throwable) {
                }
                if (($message['type'] ?? '') === 'stream_close') {
                    unset($this->forwarded[(string) ($message['id'] ?? '')]);
                }
            }
            return;
        }
        if ($connection === null) {
            if (isset($payload['reply'])) {
                $message = (array) ($payload['message'] ?? []);
                $this->sendReply((array) $payload['reply'], [
                    'op' => 'response',
                    'message' => $this->errorMessage($message, '目标节点 Agent 当前不在线'),
                ]);
            }
            return;
        }
        if ($op !== 'forward') {
            return;
        }

        $message = (array) ($payload['message'] ?? []);
        $id = (string) ($message['id'] ?? '');
        if ($id === '') {
            return;
        }
        $this->cleanupForwarded();
        $this->forwarded[$id] = [
            'reply' => (array) $payload['reply'],
            'cluster_id' => (int) $route['cluster_id'],
            'node_id' => (string) $route['node_id'],
            'fd' => (int) $connection['fd'],
            'expires_at' => (int) ($payload['expires_at'] ?? time() + 30),
            'stream' => (bool) ($payload['stream'] ?? false),
        ];
        try {
            $sent = $connection['server']->push($connection['fd'], json_encode(
                $message,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ));
        } catch (Throwable $e) {
            $sent = false;
        }
        if (! $sent) {
            unset($this->forwarded[$id]);
            $this->sendReply((array) $payload['reply'], [
                'op' => 'response',
                'message' => $this->errorMessage($message, '无法向 Docker Agent 发送请求'),
            ]);
        }
    }

    private function sendToOwner(array $connection, array $payload): void
    {
        $server = $this->server();
        if (($connection['instance_id'] ?? '') !== $this->sessions->instanceId($server)) {
            throw new AppException(503, '目标 Agent 连接属于其他 Galaxy API 实例，当前 IPC 无法路由');
        }
        $workerId = (int) ($connection['worker_id'] ?? -1);
        if ($workerId < 0 || $server === null) {
            throw new AppException(503, '目标 Agent Worker 路由无效');
        }
        $this->sendChunksToWorker($workerId, $payload);
    }

    private function sendFrame(array $connection, array $message): void
    {
        if (isset($connection['server'], $connection['fd'])) {
            if (! $connection['server']->push($connection['fd'], json_encode(
                $message,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ))) {
                throw new AppException(503, '无法向 Docker Agent 发送流数据');
            }
            return;
        }
        $this->sendToOwner($connection, [
            'op' => 'frame',
            'route' => $this->portableRoute($connection),
            'message' => $message,
        ]);
    }

    private function sendReply(array $reply, array $payload): void
    {
        if (($reply['instance_id'] ?? '') !== $this->sessions->instanceId($this->server())) {
            return;
        }
        if (($reply['kind'] ?? '') === 'worker') {
            $workerId = (int) ($reply['id'] ?? -1);
            if ($workerId === $this->currentWorkerId()) {
                $this->handleIpcPayload($payload);
            } elseif ($workerId >= 0) {
                $this->sendChunksToWorker($workerId, $payload);
            }
            return;
        }
        if (($reply['kind'] ?? '') !== 'process') {
            return;
        }
        $pid = (int) ($reply['id'] ?? 0);
        $payload['target_pid'] = $pid;
        $chunks = $this->chunks($payload);
        $written = 0;
        foreach (ProcessCollector::all() as $process) {
            foreach ($chunks as $chunk) {
                if ($process->write(serialize($chunk)) !== false) {
                    ++$written;
                }
            }
        }
        if ($written === 0) {
            $this->loggerFactory?->get('DockerAgent')->warning('Agent IPC response could not be written to custom processes', [
                'target_pid' => $pid,
            ]);
        }
    }

    private function sendChunksToWorker(int $workerId, array $payload): void
    {
        $server = $this->server();
        if ($server === null) {
            throw new AppException(503, 'Swoole IPC Server 尚未就绪');
        }
        foreach ($this->chunks($payload) as $chunk) {
            if (! $server->sendMessage($chunk, $workerId)) {
                throw new AppException(503, 'Swoole Agent IPC 消息发送失败');
            }
        }
    }

    /** @return array<int, AgentIpcMessage> */
    private function chunks(array $payload): array
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $parts = str_split($encoded, self::IPC_CHUNK_BYTES);
        $transferId = bin2hex(random_bytes(16));
        $total = count($parts);
        return array_map(
            static fn (string $part, int $index): AgentIpcMessage => new AgentIpcMessage($transferId, $index, $total, $part),
            $parts,
            array_keys($parts)
        );
    }

    private function replyAddress(): array
    {
        $workerId = $this->currentWorkerId();
        return [
            'kind' => $workerId >= 0 ? 'worker' : 'process',
            'id' => $workerId >= 0 ? $workerId : getmypid(),
            'instance_id' => $this->sessions->instanceId($this->server()),
        ];
    }

    private function currentWorkerId(): int
    {
        $server = $this->server();
        if ($server === null) {
            return -1;
        }
        $workerId = $server->getWorkerId();
        return is_int($workerId) ? $workerId : -1;
    }

    private function pendingRequest(Channel $channel, int $clusterId, array $connection): array
    {
        return [
            'channel' => $channel,
            'cluster_id' => $clusterId,
            'node_id' => (string) $connection['node_id'],
        ];
    }

    private function portableRoute(array $connection): array
    {
        return [
            'cluster_id' => (int) $connection['cluster_id'],
            'node_id' => (string) $connection['node_id'],
            'connection_id' => (string) $connection['connection_id'],
            'instance_id' => (string) $connection['instance_id'],
            'worker_id' => (int) $connection['worker_id'],
        ];
    }

    private function errorMessage(array $request, string $error): array
    {
        $type = match ((string) ($request['type'] ?? '')) {
            'domain_command' => 'domain_response',
            'stream_open' => 'stream_ready',
            default => 'response',
        };
        return ['type' => $type, 'id' => (string) ($request['id'] ?? ''), 'error' => $error];
    }

    private function cleanupForwarded(): void
    {
        $now = time();
        foreach ($this->forwarded as $id => $request) {
            if ($request['expires_at'] < $now) {
                unset($this->forwarded[$id]);
            }
        }
    }

    private function server(): ?Server
    {
        if ($this->server !== null) {
            return $this->server;
        }
        try {
            $server = $this->serverFactory?->getServer()->getServer();
            return $server instanceof Server ? $server : null;
        } catch (Throwable) {
            return null;
        }
    }
}
