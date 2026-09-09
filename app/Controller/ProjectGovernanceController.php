<?php

namespace App\Controller;

use App\Services\Project\ProjectGovernanceService;
use App\Support\Functions;

class ProjectGovernanceController extends AbstractController
{
    public function __construct(private ProjectGovernanceService $governance) {}

    public function audit()
    {
        $params = Functions::arrNull2default($this->validate([
            'action' => 'nullable|string|max:128', 'status' => 'nullable|string|in:succeeded,failed',
            'uid' => 'nullable|integer|min:1', 'begin' => 'nullable|integer|min:1', 'end' => 'nullable|integer|min:1',
            'page' => 'nullable|integer|min:1', 'pagesize' => 'nullable|integer|min:1|max:100',
        ]), ['action' => null, 'status' => null, 'uid' => null, 'begin' => null, 'end' => null, 'page' => 1, 'pagesize' => 20]);
        [$orgId, $groupId, $projectId] = $this->scope();
        return $this->success($this->governance->auditList(
            $orgId, $groupId, $projectId, $params['action'], $params['status'], $params['uid'], $params['begin'], $params['end'],
            (int) $params['page'], (int) $params['pagesize']
        ));
    }

    public function policy()
    {
        return $this->success(['policy' => $this->governance->policy(...$this->scope())]);
    }

    public function savePolicy()
    {
        $params = $this->validate([
            'enabled' => 'required|boolean',
            'metric_days' => 'required|integer|min:1|max:3650',
            'event_days' => 'required|integer|min:1|max:3650',
            'resolved_alert_days' => 'required|integer|min:1|max:3650',
            'build_log_days' => 'required|integer|min:1|max:3650',
            'audit_days' => 'required|integer|min:30|max:3650',
        ]);
        [$orgId, $groupId, $projectId] = $this->scope();
        return $this->success(['policy' => $this->governance->savePolicy(
            (int) Functions::getLoginUser()->getId(), $orgId, $groupId, $projectId, $params
        )]);
    }

    private function scope(): array
    {
        return [
            (int) Functions::getContextValue('org_id'),
            (int) Functions::getContextValue('group_id'),
            (int) Functions::getContextValue('project_id'),
        ];
    }
}
