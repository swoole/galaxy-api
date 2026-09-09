<?php

namespace App\Process;

use App\Model\Cluster;
use App\Model\ClusterAgentNode;
use App\Services\Docker\SwarmImageInventoryCache;
use App\Services\Docker\SwarmOverviewService;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Process\AbstractProcess;
use Hyperf\Process\ProcessManager;
use Hyperf\Redis\Redis;
use Psr\Container\ContainerInterface;
use Throwable;

class SwarmImageInventoryProcess extends AbstractProcess
{
    public string $name = 'swarm-image-inventory';

    public function __construct(
        ContainerInterface $container,
        private SwarmOverviewService $swarm,
        private SwarmImageInventoryCache $cache,
        private Redis $redis,
        private StdoutLoggerInterface $logger
    ) {
        parent::__construct($container);
    }

    public function isEnable($server): bool
    {
        return (bool) config('docker.image_inventory_enabled', true);
    }

    public function handle(): void
    {
        $interval = max(30, (int) config('docker.image_inventory_interval', 60));
        while (ProcessManager::isRunning()) {
            $token = bin2hex(random_bytes(16));
            try {
                if ($this->redis->set(
                    'galaxy:swarm:image-inventory-lock',
                    $token,
                    ['nx', 'ex' => max(120, $interval * 2)]
                )) {
                    try {
                        $this->refresh();
                    } finally {
                        $this->redis->eval(
                            "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) end return 0",
                            ['galaxy:swarm:image-inventory-lock', $token],
                            1
                        );
                    }
                }
            } catch (Throwable $e) {
                $this->logger->error('Swarm 镜像缓存刷新失败：' . $e->getMessage());
            }
            Coroutine::sleep($interval);
        }
    }

    private function refresh(): void
    {
        $clusters = Cluster::where('orchestrator_type', Cluster::ORCHESTRATOR_DOCKER_SWARM)->get();
        foreach ($clusters as $cluster) {
            $nodes = ClusterAgentNode::where('cluster_id', (int) $cluster->id)
                ->where('status', 'online')
                ->orderBy('id')
                ->get();
            foreach ($nodes as $node) {
                $nodeId = (string) $node->node_id;
                try {
                    $images = $this->swarm->listImages($cluster, $nodeId);
                    $this->cache->store((int) $cluster->id, $nodeId, $images);
                } catch (Throwable $e) {
                    $this->logger->warning(sprintf(
                        '集群 #%d 节点 %s 镜像缓存刷新失败：%s',
                        (int) $cluster->id,
                        $nodeId,
                        $e->getMessage()
                    ));
                }
            }
        }
    }
}
