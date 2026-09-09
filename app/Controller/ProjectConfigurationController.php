<?php

namespace App\Controller;

use App\Services\Project\ProjectConfigurationService;
use App\Model\Env;
use App\Support\Functions;

class ProjectConfigurationController extends AbstractController
{
    public function __construct(private ProjectConfigurationService $configurations) {}

    public function list()
    {
        $orgId = (int) Functions::getContextValue('org_id');
        return $this->success([
            'configurations' => $this->configurations->list(
            $orgId,
            (int) Functions::getContextValue('group_id'),
            (int) Functions::getContextValue('project_id')
            ),
            'environments' => Env::where('org_id', $orgId)
                ->select('id', 'title', 'archived_at')->orderBy('id')->get(),
        ]);
    }

    public function create()
    {
        $params = $this->validate([
            'env_id' => 'nullable|integer|min:0',
            'kind' => 'required|string|in:env,config,secret', 'name' => 'required|string|max:128',
            'value' => 'present|string|max:512000', 'target' => 'nullable|string|max:1024',
            'file_mode' => 'nullable|integer|min:0|max:511', 'description' => 'nullable|string|max:500',
        ]);
        return $this->success(['configuration' => $this->configurations->create(
            Functions::getLoginUser()->getId(), (int) Functions::getContextValue('org_id'),
            (int) Functions::getContextValue('group_id'), (int) Functions::getContextValue('project_id'), $params
        )]);
    }

    public function update()
    {
        $params = $this->validate([
            'configuration_id' => 'required|integer|min:1', 'env_id' => 'nullable|integer|min:0',
            'kind' => 'required|string|in:env,config,secret',
            'name' => 'required|string|max:128', 'value' => 'nullable|string|max:512000',
            'target' => 'nullable|string|max:1024', 'file_mode' => 'nullable|integer|min:0|max:511',
            'description' => 'nullable|string|max:500',
        ]);
        if (! array_key_exists('value', (array) $this->request->getParsedBody())) {
            unset($params['value']);
        }
        return $this->success(['configuration' => $this->configurations->update(
            Functions::getLoginUser()->getId(), (int) Functions::getContextValue('org_id'),
            (int) Functions::getContextValue('group_id'), (int) Functions::getContextValue('project_id'),
            (int) $params['configuration_id'], $params
        )]);
    }

    public function delete()
    {
        $params = $this->validate(['configuration_id' => 'required|integer|min:1']);
        $this->configurations->delete(
            (int) Functions::getContextValue('org_id'), (int) Functions::getContextValue('group_id'),
            (int) Functions::getContextValue('project_id'), (int) $params['configuration_id']
        );
        return $this->success();
    }
}
