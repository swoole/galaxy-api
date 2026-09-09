<?php

namespace App\Controller;

use App\Exception\AppException;
use App\Model\AppMarketTag;
use App\Model\AppMarketTpl;
use App\Services\AppMarket\KubernetesAppInstaller;
use App\Services\AppMarket\SwarmAppInstaller;
use App\Support\Functions;

class AppMarketController extends AbstractController
{
    public function __construct(
        private AppMarketTpl $marketTpl,
        private AppMarketTag $marketTag,
        private SwarmAppInstaller $swarmInstaller,
        private KubernetesAppInstaller $kubernetesInstaller
    ) {}

    public function queryProgress()
    {
        $params = $this->validate(['job_id' => 'required|string', 'begin' => 'nullable|numeric']);
        return $this->success(['progresses' => $this->marketTpl->queryProgress(
            (int) Functions::getContextValue('org_id'),
            (string) $params['job_id'],
            (float) ($params['begin'] ?? 0.0)
        )]);
    }

    public function tpls()
    {
        $params = $this->validate([
            'type' => 'nullable|in:' . implode(',', array_keys(AppMarketTpl::$types)),
            'service' => 'nullable|integer', 'lang' => 'nullable|integer', 'framework' => 'nullable|integer',
            'category' => 'nullable|integer',
            'tags' => 'nullable|array', 'tags.*' => 'distinct|integer', 'keyword' => 'nullable|string',
            'sort' => 'nullable|array', 'page' => 'nullable|integer|min:1',
            'pagesize' => 'nullable|integer|min:1|max:100', 'result' => 'nullable|string|in:simple,full',
        ]);
        $tags = (array) ($params['tags'] ?? []);
        foreach (['service', 'lang', 'framework', 'category'] as $filter) {
            if (! empty($params[$filter])) {
                $tags[] = (int) $params[$filter];
            }
        }
        return $this->success($this->marketTpl->list(
            isset($params['type']) ? (int) $params['type'] : null,
            $tags,
            isset($params['keyword']) ? (string) $params['keyword'] : null,
            (array) ($params['sort'] ?? ['sort' => 'asc', 'id' => 'asc']),
            (int) ($params['page'] ?? 1),
            (int) ($params['pagesize'] ?? 20),
            $params['result'] ?? null
        ));
    }

    public function filterMetadata()
    {
        return $this->success($this->marketTpl->filterMetadata());
    }

    public function tplProfile()
    {
        $params = $this->validate(['tpl_id' => 'required|integer']);
        return $this->success(['profile' => $this->marketTpl->profile((int) $params['tpl_id'])]);
    }

    public function tplUse()
    {
        $params = $this->validate(['tpl_id' => 'required|integer', 'form' => 'required|array']);
        /** @var AppMarketTpl|null $tpl */
        $tpl = AppMarketTpl::find((int) $params['tpl_id']);
        if ($tpl === null) {
            throw new AppException(404, '模板不存在');
        }
        $orchestrator = (string) ($tpl['pipeline']['orchestrator'] ?? '');
        $installer = match ($orchestrator) {
            'docker-swarm' => $this->swarmInstaller,
            'kubernetes' => $this->kubernetesInstaller,
            default => throw new AppException(422, '该模板尚未提供自动安装器'),
        };
        $jobId = $installer->queue(
            (int) Functions::getLoginUser()->getId(),
            (int) Functions::getContextValue('org_id'),
            $tpl,
            (array) $params['form']
        );
        return $this->success(['job_id' => $jobId]);
    }

    public function swarmClusters()
    {
        return $this->success(['clusters' => $this->swarmInstaller->clusters(
            (int) Functions::getContextValue('org_id')
        )]);
    }

    public function swarmNetworks()
    {
        $params = $this->validate(['cluster_id' => 'required|integer']);
        return $this->success(['networks' => $this->swarmInstaller->networks(
            (int) Functions::getContextValue('org_id'), (int) $params['cluster_id']
        )]);
    }

    public function swarmNetworkCreate()
    {
        $params = $this->validate(['cluster_id' => 'required|integer', 'name' => 'required|string']);
        return $this->success(['network' => $this->swarmInstaller->createNetwork(
            (int) Functions::getContextValue('org_id'), (int) $params['cluster_id'], (string) $params['name']
        )]);
    }

    public function swarmContainers()
    {
        $params = $this->validate(['cluster_id' => 'required|integer']);
        return $this->success(['containers' => $this->swarmInstaller->containers(
            (int) Functions::getContextValue('org_id'), (int) $params['cluster_id']
        )]);
    }

    public function swarmInstallations()
    {
        return $this->success(['installations' => $this->swarmInstaller->installations(
            (int) Functions::getContextValue('org_id')
        )]);
    }

    public function swarmFindInstallation()
    {
        $params = $this->validate([
            'cluster_id' => 'required|integer',
            'service_name' => 'required|string',
        ]);
        $installation = $this->swarmInstaller->findInstallation(
            (int) Functions::getContextValue('org_id'),
            (int) $params['cluster_id'],
            (string) $params['service_name']
        );
        return $this->success(['installation' => $installation]);
    }

    public function swarmReconfigure()
    {
        $params = $this->validate([
            'installation_id' => 'required|integer',
            'values' => 'required|array',
        ]);
        $this->swarmInstaller->reconfigure(
            (int) Functions::getContextValue('org_id'),
            (int) $params['installation_id'],
            (array) $params['values']
        );
        return $this->success();
    }

    public function kubernetesClusters()
    {
        return $this->success(['clusters' => $this->kubernetesInstaller->clusters(
            (int) Functions::getContextValue('org_id')
        )]);
    }

    public function kubernetesInstallations()
    {
        return $this->success(['installations' => $this->kubernetesInstaller->installations(
            (int) Functions::getContextValue('org_id')
        )]);
    }

    public function kubernetesResendCredentials()
    {
        $params = $this->validate([
            'installation_id' => 'required|integer|min:1',
            'public_port' => 'nullable|integer|min:1|max:65535',
        ]);
        $this->kubernetesInstaller->resendCredentials(
            (int) Functions::getContextValue('org_id'),
            (int) $params['installation_id'],
            (int) ($params['public_port'] ?? 0)
        );
        return $this->success();
    }

    public function tags()
    {
        $params = $this->validate(['type' => 'nullable|integer', 'keyword' => 'nullable|string']);
        return $this->success(['tags' => $this->marketTag->simpleList(
            (int) Functions::getContextValue('org_id'),
            isset($params['type']) ? (int) $params['type'] : null,
            isset($params['keyword']) ? (string) $params['keyword'] : null
        )]);
    }
}
