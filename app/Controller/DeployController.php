<?php

namespace App\Controller;

use App\Services\Project\ProjectReleaseService;
use App\Services\Project\ProjectMonitoringService;
use App\Services\Project\ProjectHttpMonitoringService;
use App\Exception\AppException;
use App\Support\Functions;

class DeployController extends AbstractController
{
    public function __construct(
        private ProjectReleaseService $releases,
        private ProjectMonitoringService $monitoring,
        private ProjectHttpMonitoringService $httpMonitoring
    ) {}

    public function options()
    {
        $orgId = (int) Functions::getContextValue('org_id');
        $projectId = (int) Functions::getContextValue('project_id');
        $params = Functions::arrNull2default($this->validate(['cluster_id' => 'nullable|integer']), ['cluster_id' => null]);
        return $this->success($this->releases->options(
            $orgId,
            (int) Functions::getContextValue('group_id'),
            $projectId,
            $params['cluster_id'] === null ? null : (int) $params['cluster_id']
        ));
    }

    public function createNetwork()
    {
        $params = $this->validate([
            'cluster_id' => 'required|integer|min:1',
            'name' => 'required|string|max:40',
        ]);
        return $this->success(['network' => $this->releases->createNetwork(
            (int) Functions::getContextValue('org_id'),
            (int) Functions::getContextValue('group_id'),
            (int) Functions::getContextValue('project_id'),
            (int) $params['cluster_id'],
            (string) $params['name']
        )]);
    }

    public function list()
    {
        $params = Functions::arrNull2default($this->validate([
            'env_id' => 'nullable|integer', 'cluster_id' => 'nullable|integer',
            'status' => 'nullable|string|max:32', 'page' => 'nullable|integer|min:1',
            'pagesize' => 'nullable|integer|min:1|max:100',
        ]), ['env_id' => null, 'cluster_id' => null, 'status' => null, 'page' => 1, 'pagesize' => 20]);
        return $this->success($this->releases->list(
            (int) Functions::getContextValue('org_id'),
            (int) Functions::getContextValue('group_id'),
            (int) Functions::getContextValue('project_id'),
            $params['env_id'] === null ? null : (int) $params['env_id'],
            $params['cluster_id'] === null ? null : (int) $params['cluster_id'],
            $params['status'],
            (int) $params['page'],
            (int) $params['pagesize']
        ));
    }

    public function profile()
    {
        $params = $this->validate(['release_id' => 'required|integer|min:1']);
        return $this->success(['release' => $this->releases->profile(
            (int) Functions::getContextValue('org_id'),
            (int) Functions::getContextValue('group_id'),
            (int) Functions::getContextValue('project_id'),
            (int) $params['release_id']
        )]);
    }

    public function newDeploy()
    {
        $params = $this->validate([
            'env_id' => 'required|integer|min:1', 'cluster_id' => 'required|integer|min:1',
            'artifact_id' => 'required|integer|min:1',
            'version' => 'required|string|max:128', 'remark' => 'required|string|max:500',
            'spec' => 'required|array',
        ]);
        $release = $this->releases->create(
            Functions::getLoginUser()->getId(),
            (int) Functions::getContextValue('org_id'),
            (int) Functions::getContextValue('group_id'),
            (int) Functions::getContextValue('project_id'),
            (int) $params['env_id'], (int) $params['cluster_id'], (int) $params['artifact_id'],
            (string) $params['version'], (string) $params['remark'], (array) $params['spec']
        );
        return $this->success(['release' => $release]);
    }

    public function rollback()
    {
        $params = $this->validate(['release_id' => 'required|integer|min:1']);
        $release = $this->releases->rollback(
            Functions::getLoginUser()->getId(),
            (int) Functions::getContextValue('org_id'),
            (int) Functions::getContextValue('group_id'),
            (int) Functions::getContextValue('project_id'),
            (int) $params['release_id']
        );
        return $this->success(['release' => $release]);
    }

    public function updateArtifact()
    {
        $params = $this->validate([
            'runtime_id' => 'required|integer|min:1',
            'artifact_id' => 'required|integer|min:1',
            'remark' => 'required|string|max:500',
        ]);
        $release = $this->releases->updateArtifact(
            Functions::getLoginUser()->getId(),
            (int) Functions::getContextValue('org_id'),
            (int) Functions::getContextValue('group_id'),
            (int) Functions::getContextValue('project_id'),
            (int) $params['runtime_id'],
            (int) $params['artifact_id'],
            (string) $params['remark']
        );
        return $this->success(['release' => $release]);
    }

    public function runtimes()
    {
        return $this->success(['runtimes' => $this->releases->runtimes(
            (int) Functions::getContextValue('org_id'),
            (int) Functions::getContextValue('group_id'),
            (int) Functions::getContextValue('project_id')
        )]);
    }

    public function renameRuntime()
    {
        $params = $this->validate([
            'runtime_id' => 'required|integer|min:1',
            'name' => 'required|string|max:128',
        ]);
        return $this->success(['runtime' => $this->releases->renameRuntime(
            (int) Functions::getContextValue('org_id'),
            (int) Functions::getContextValue('group_id'),
            (int) Functions::getContextValue('project_id'),
            (int) $params['runtime_id'],
            (string) $params['name']
        )]);
    }

    public function scaleRuntime()
    {
        $params = $this->validate(['runtime_id' => 'required|integer|min:1', 'replicas' => 'required|integer|min:0|max:100']);
        return $this->success(['release' => $this->releases->scale(
            Functions::getLoginUser()->getId(),
            (int) Functions::getContextValue('org_id'), (int) Functions::getContextValue('group_id'),
            (int) Functions::getContextValue('project_id'), (int) $params['runtime_id'], (int) $params['replicas']
        )]);
    }

    public function restartRuntime()
    {
        $params = $this->validate(['runtime_id' => 'required|integer|min:1']);
        $this->releases->restart(
            (int) Functions::getContextValue('org_id'), (int) Functions::getContextValue('group_id'),
            (int) Functions::getContextValue('project_id'), (int) $params['runtime_id']
        );
        return $this->success();
    }

    public function removeRuntime()
    {
        $params = $this->validate([
            'runtime_id' => 'nullable|integer|min:1',
            'service_id' => 'nullable|string|max:64',
        ]);
        $orgId = (int) Functions::getContextValue('org_id');
        $groupId = (int) Functions::getContextValue('group_id');
        $projectId = (int) Functions::getContextValue('project_id');
        if (! empty($params['runtime_id'])) {
            $this->releases->removeRuntime($orgId, $groupId, $projectId, (int) $params['runtime_id']);
        } elseif (! empty($params['service_id'])) {
            $this->releases->removeRuntimeByReference(
                $orgId, $groupId, $projectId, (string) $params['service_id']
            );
        } else {
            throw new AppException(422, 'runtime_id 或 service_id 字段必须提供一个');
        }
        return $this->success();
    }

    public function monitoring()
    {
        $params = Functions::arrNull2default($this->validate([
            'hours' => 'nullable|integer|min:1|max:168',
            'runtime_id' => 'nullable|integer|min:1',
        ]), ['hours' => 24, 'runtime_id' => null]);
        return $this->success($this->monitoring->overview(
            (int) Functions::getContextValue('org_id'), (int) Functions::getContextValue('group_id'),
            (int) Functions::getContextValue('project_id'), (int) $params['hours'],
            $params['runtime_id'] === null ? null : (int) $params['runtime_id']
        ));
    }

    public function httpMonitoring()
    {
        $params = Functions::arrNull2default($this->validate([
            'hours' => 'nullable|integer|min:1|max:168',
            'route_id' => 'nullable|integer|min:1',
            'route_source' => 'nullable|string|in:project,gateway',
            'runtime_id' => 'nullable|integer|min:1',
            'summary_only' => 'nullable|boolean',
        ]), [
            'hours' => 24,
            'route_id' => null,
            'route_source' => null,
            'runtime_id' => null,
            'summary_only' => false,
        ]);
        return $this->success($this->httpMonitoring->report(
            (int) Functions::getContextValue('org_id'), (int) Functions::getContextValue('group_id'),
            (int) Functions::getContextValue('project_id'), (int) $params['hours'],
            $params['route_id'] === null ? null : (int) $params['route_id'],
            $params['route_source'] === null ? null : (string) $params['route_source'],
            $params['runtime_id'] === null ? null : (int) $params['runtime_id'],
            (bool) $params['summary_only']
        ));
    }

}
