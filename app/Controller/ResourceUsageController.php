<?php

declare(strict_types=1);
/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */

namespace App\Controller;

use App\Model\Cluster;
use App\Services\ResourceUsageRankingService;
use App\Support\Functions;

final class ResourceUsageController extends AbstractController
{
    public function __construct(private ResourceUsageRankingService $ranking) {}

    public function ranking()
    {
        $params = Functions::arrNull2default($this->validate([
            'metric' => 'nullable|string|in:cpu,memory,network,disk',
            'scope' => 'nullable|string|in:project,runtime,pod,workload,namespace,service,node',
            'cluster_id' => 'nullable|integer|min:1',
            'orchestrator_type' => 'nullable|string|in:' . implode(',', Cluster::knownOrchestrators()),
            'limit' => 'nullable|integer|min:1|max:100',
        ]), [
            'metric' => 'cpu',
            'scope' => 'project',
            'cluster_id' => null,
            'orchestrator_type' => null,
            'limit' => 50,
        ]);

        return $this->success($this->ranking->ranking(
            (int) Functions::getContextValue('org_id'),
            (string) $params['metric'],
            (string) $params['scope'],
            $params['cluster_id'] === null ? null : (int) $params['cluster_id'],
            $params['orchestrator_type'] === null ? null : (string) $params['orchestrator_type'],
            (int) $params['limit']
        ));
    }
}
