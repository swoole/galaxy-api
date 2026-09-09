<?php

namespace App\Controller;

use App\Services\Project\ProjectRouteService;
use App\Support\Functions;

class ProjectRouteController extends AbstractController
{
    public function __construct(private ProjectRouteService $routes) {}

    public function list()
    {
        $params = Functions::arrNull2default($this->validate([
            'page' => 'nullable|integer|min:1', 'pagesize' => 'nullable|integer|min:1|max:100',
            'runtime_id' => 'nullable|integer|min:1',
        ]), ['page' => 1, 'pagesize' => 20, 'runtime_id' => null]);
        return $this->success($this->routes->list(...array_merge($this->scope(), [
            (int) $params['page'], (int) $params['pagesize'],
            $params['runtime_id'] === null ? null : (int) $params['runtime_id'],
        ])));
    }

    public function create()
    {
        $params = $this->validate([
            'runtime_id' => 'required|integer|min:1',
            'hostname' => 'required|string|max:253',
            'path_prefix' => 'nullable|string|max:1024',
            'path_match' => 'nullable|string|in:prefix,exact',
            'target_port' => 'required|integer|min:1|max:65535',
            'entrypoint' => 'nullable|string|max:64',
            'priority' => 'nullable|integer|min:0|max:100000',
            'tls_enabled' => 'nullable|boolean',
            'certificate_id' => 'nullable|integer|min:1',
            'https_redirect' => 'nullable|boolean',
            'https_redirect_port' => 'nullable|integer|min:1|max:65535',
            'enabled' => 'nullable|boolean',
        ]);
        $runtimeId = (int) $params['runtime_id'];
        unset($params['runtime_id']);
        return $this->success(['route' => $this->routes->create(
            (int) Functions::getLoginUser()->getId(),
            ...array_merge($this->scope(), [$runtimeId, $params])
        )]);
    }

    public function profile()
    {
        $params = $this->validate(['route_id' => 'required|integer|min:1']);
        return $this->success(['route' => $this->routes->profile(
            ...array_merge($this->scope(), [(int) $params['route_id']])
        )]);
    }

    public function update()
    {
        $params = $this->validate([
            'route_id' => 'required|integer|min:1',
            'hostname' => 'nullable|string|max:253',
            'path_prefix' => 'nullable|string|max:1024',
            'path_match' => 'nullable|string|in:prefix,exact',
            'target_port' => 'nullable|integer|min:1|max:65535',
            'entrypoint' => 'nullable|string|max:64',
            'priority' => 'nullable|integer|min:0|max:100000',
            'tls_enabled' => 'nullable|boolean',
            'certificate_id' => 'nullable|integer|min:1',
            'https_redirect' => 'nullable|boolean',
            'https_redirect_port' => 'nullable|integer|min:1|max:65535',
            'enabled' => 'nullable|boolean',
        ]);
        $routeId = (int) $params['route_id'];
        unset($params['route_id']);
        return $this->success(['route' => $this->routes->update(
            ...array_merge($this->scope(), [$routeId, $params])
        )]);
    }

    public function delete()
    {
        $params = $this->validate(['route_id' => 'required|integer|min:1']);
        $this->routes->delete(...array_merge($this->scope(), [(int) $params['route_id']]));
        return $this->success();
    }

    public function importCandidates()
    {
        $params = $this->validate(['runtime_id' => 'required|integer|min:1']);
        return $this->success(['candidates' => $this->routes->importCandidates(
            ...array_merge($this->scope(), [(int) $params['runtime_id']])
        )]);
    }

    public function import()
    {
        $params = $this->validate([
            'runtime_id' => 'required|integer|min:1',
            'vhost_ids' => 'required|array|min:1|max:50',
            'vhost_ids.*' => 'required|integer|min:1|distinct',
        ]);
        return $this->success(['routes' => $this->routes->importGatewayVhosts(
            (int) Functions::getLoginUser()->getId(),
            ...array_merge($this->scope(), [
                (int) $params['runtime_id'],
                (array) $params['vhost_ids'],
            ])
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
