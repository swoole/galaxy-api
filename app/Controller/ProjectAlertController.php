<?php

namespace App\Controller;

use App\Services\Project\ProjectAlertService;
use App\Support\Functions;

class ProjectAlertController extends AbstractController
{
    public function __construct(private ProjectAlertService $alerts) {}

    public function profile()
    {
        $params = Functions::arrNull2default($this->validate([
            'runtime_id' => 'nullable|integer|min:1',
        ]), ['runtime_id' => null]);
        [$orgId, $groupId, $projectId] = $this->scope();
        return $this->success($this->alerts->profile(
            $orgId,
            $groupId,
            $projectId,
            $params['runtime_id'] === null ? null : (int) $params['runtime_id']
        ));
    }

    public function save()
    {
        $input = $this->validate([
            'enabled' => 'required|boolean', 'recipients' => 'nullable|array|max:50',
            'recipients.*' => 'email|max:255', 'failure_threshold' => 'required|integer|min:1|max:20',
            'cooldown_seconds' => 'required|integer|min:300|max:86400', 'notify_recovery' => 'required|boolean',
            'slo_availability_target' => 'required|numeric|min:90|max:100',
            'slo_error_rate_max' => 'required|numeric|min:0|max:100',
            'slo_p95_ms_max' => 'required|integer|min:1|max:600000',
        ]);
        [$orgId, $groupId, $projectId] = $this->scope();
        return $this->success(['rule' => $this->alerts->save(
            (int) Functions::getLoginUser()->getId(), $orgId, $groupId, $projectId, $input
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
