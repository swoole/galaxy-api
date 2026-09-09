<?php

namespace App\Controller;

use App\Exception\AppException;
use App\Services\Workspace\WorkspaceService;
use App\Support\Functions;

class WorkspaceController extends AbstractController
{
    public function __construct(private WorkspaceService $workspace) {}

    public function options()
    {
        $scope = $this->scope();
        return $this->success($this->workspace->options($scope[1], $scope[2]));
    }

    public function list()
    {
        return $this->success($this->workspace->list(
            (int) Functions::getLoginUser()->getId(),
            (int) Functions::getContextValue('org_id')
        ));
    }

    public function profile()
    {
        return $this->success(['workspace' => $this->workspace->profile(...$this->scope())]);
    }

    public function create()
    {
        $params = $this->validate([
            'cluster_id' => 'required|integer',
            'title' => 'nullable|string|max:100',
            'mode' => 'nullable|string|in:web-ide,terminal',
            'image' => 'nullable|string|max:1024',
            'cpu' => 'nullable|integer|min:100|max:8000',
            'memory' => 'nullable|integer|min:256|max:32768',
        ]);
        $scope = $this->scope();
        return $this->success(['workspace' => $this->workspace->create($scope[0], $scope[1], $scope[2], $params)]);
    }

    public function state()
    {
        $params = $this->validate(['action' => 'required|string|in:start,stop']);
        $scope = $this->scope();
        return $this->success(['workspace' => $this->workspace->setState(
            $scope[0], $scope[1], $scope[2], (string) $params['action']
        )]);
    }

    public function delete()
    {
        $scope = $this->scope();
        $this->workspace->delete($scope[0], $scope[1], $scope[2]);
        return $this->success();
    }

    public function access()
    {
        return $this->success($this->workspace->access(...$this->scope()));
    }

    public function terminal()
    {
        return $this->success($this->workspace->terminal(...$this->scope()));
    }

    private function scope(): array
    {
        $uid = (int) Functions::getLoginUser()->getId();
        $requestedUid = (int) $this->request->input('user_id', 0);
        if ($requestedUid !== $uid) {
            throw new AppException(403, '只能访问自己的开发环境');
        }
        return [
            $uid,
            (int) Functions::getContextValue('org_id'),
            (int) Functions::getContextValue('group_id'),
        ];
    }
}
