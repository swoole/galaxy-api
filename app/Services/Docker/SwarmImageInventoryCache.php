<?php

namespace App\Services\Docker;

use App\Exception\AppException;
use App\Model\ClusterAgentNode;
use Hyperf\Redis\Redis;
use JsonException;

class SwarmImageInventoryCache
{
    private const KEY_PREFIX = 'galaxy:swarm:image-inventory:';

    public function __construct(private Redis $redis) {}

    public function store(int $clusterId, string $nodeId, array $images): void
    {
        $payload = json_encode([
            'cluster_id' => $clusterId,
            'node_id' => $nodeId,
            'node_hostname' => (string) ($images[0]['node_hostname'] ?? ''),
            'refreshed_at' => time(),
            'images' => array_values($images),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->redis->setex($this->key($clusterId, $nodeId), $this->ttl(), $payload);
    }

    public function forget(int $clusterId, string $nodeId): void
    {
        if ($nodeId !== '') {
            $this->redis->del($this->key($clusterId, $nodeId));
        }
    }

    public function resolveNodeId(int $clusterId, string $requestedNodeId = ''): string
    {
        $query = ClusterAgentNode::where('cluster_id', $clusterId);
        if ($requestedNodeId !== '') {
            $node = (clone $query)->where('node_id', $requestedNodeId)->first();
            if ($node === null) {
                throw new AppException(404, '目标节点不属于当前集群或尚未注册 Agent');
            }
            return (string) $node->node_id;
        }

        foreach ([
            ['role' => 'manager', 'status' => 'online'],
            ['role' => 'manager'],
            ['status' => 'online'],
            [],
        ] as $conditions) {
            $candidate = clone $query;
            foreach ($conditions as $field => $value) {
                $candidate->where($field, $value);
            }
            $node = $candidate->orderByDesc('last_seen_at')->first();
            if ($node !== null) {
                return (string) $node->node_id;
            }
        }

        throw new AppException(503, '当前集群尚无已注册的 Galaxy Agent 节点');
    }

    public function imageInfo(int $clusterId, string $nodeId, string $reference): array
    {
        $inventory = $this->get($clusterId, $nodeId);
        if ($inventory === null) {
            return [
                'image' => null,
                'pulled' => null,
                'node_id' => $nodeId,
                'node_hostname' => '',
                'refreshed_at' => 0,
                'cache_status' => 'warming',
            ];
        }

        $matched = null;
        foreach ((array) ($inventory['images'] ?? []) as $image) {
            if (is_array($image) && $this->matches($image, $reference)) {
                $matched = $image;
                break;
            }
        }
        $refreshedAt = (int) ($inventory['refreshed_at'] ?? 0);

        return [
            'image' => $matched,
            'pulled' => $matched !== null,
            'node_id' => $nodeId,
            'node_hostname' => (string) ($matched['node_hostname'] ?? $inventory['node_hostname'] ?? ''),
            'refreshed_at' => $refreshedAt,
            'cache_status' => $refreshedAt < time() - $this->staleAfter() ? 'stale' : 'ready',
        ];
    }

    private function get(int $clusterId, string $nodeId): ?array
    {
        $value = $this->redis->get($this->key($clusterId, $nodeId));
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            $payload = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            return is_array($payload) ? $payload : null;
        } catch (JsonException) {
            $this->redis->del($this->key($clusterId, $nodeId));
            return null;
        }
    }

    private function matches(array $image, string $reference): bool
    {
        $reference = strtolower(trim($reference));
        $digest = '';
        if (preg_match('/@(sha256:[a-f0-9]{64})$/', $reference, $match)) {
            $digest = $match[1];
        } elseif (preg_match('/^sha256:[a-f0-9]{64}$/', $reference)) {
            $digest = $reference;
        }
        if ($digest !== '') {
            if (strtolower((string) ($image['id'] ?? '')) === $digest) {
                return true;
            }
            foreach ((array) ($image['repo_digests'] ?? []) as $repoDigest) {
                $repoDigest = strtolower((string) $repoDigest);
                if ($repoDigest === $digest || str_ends_with($repoDigest, '@' . $digest)) {
                    return true;
                }
            }
        }

        $expected = $this->canonicalReference($reference);
        foreach ((array) ($image['repo_tags'] ?? []) as $tag) {
            if ($this->canonicalReference((string) $tag) === $expected) {
                return true;
            }
        }
        return false;
    }

    private function canonicalReference(string $reference): string
    {
        $reference = strtolower(trim(preg_replace('/@sha256:[a-f0-9]{64}$/i', '', $reference) ?? $reference));
        $reference = preg_replace('#^(?:registry-1|index)\.docker\.io/#', '', $reference) ?? $reference;
        $reference = preg_replace('#^docker\.io/#', '', $reference) ?? $reference;
        $reference = preg_replace('#^library/#', '', $reference) ?? $reference;
        if (! preg_match('/:[^\/]+$/', $reference)) {
            $reference .= ':latest';
        }
        return $reference;
    }

    private function key(int $clusterId, string $nodeId): string
    {
        return self::KEY_PREFIX . $clusterId . ':' . hash('sha256', $nodeId);
    }

    private function ttl(): int
    {
        return max(300, (int) config('docker.image_inventory_cache_ttl', 600));
    }

    private function staleAfter(): int
    {
        return max(60, (int) config('docker.image_inventory_stale_after', 180));
    }
}
