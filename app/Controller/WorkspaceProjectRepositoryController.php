<?php

namespace App\Controller;

use App\Services\Workspace\WorkspaceProjectRepositoryService;
use App\Support\Functions;

class WorkspaceProjectRepositoryController extends AbstractController
{
    public function __construct(private WorkspaceProjectRepositoryService $repositories) {}

    public function profile()
    {
        return $this->success($this->repositories->profile(...$this->scope()));
    }

    public function open()
    {
        $params = $this->validate([
            'workspace_id' => 'required|integer|min:1',
            'branch' => 'nullable|string|max:255',
        ]);
        $scope = $this->scope();
        return $this->success($this->repositories->open(
            $scope[0],
            $scope[1],
            $scope[2],
            $scope[3],
            (int) $params['workspace_id'],
            (string) ($params['branch'] ?? '')
        ));
    }

    private function scope(): array
    {
        return [
            (int) Functions::getLoginUser()->getId(),
            (int) Functions::getContextValue('org_id'),
            (int) Functions::getContextValue('group_id'),
            (int) Functions::getContextValue('project_id'),
        ];
    }
}
