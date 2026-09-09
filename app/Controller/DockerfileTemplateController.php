<?php

namespace App\Controller;

use App\Exception\AppException;
use App\Model\Project;
use App\Services\Project\ProjectBuildProfileService;
use App\Services\Project\DockerfileTemplateService;
use App\Support\Functions;
use Hyperf\DbConnection\Db;

class DockerfileTemplateController extends AbstractController
{
    public function __construct(
        private DockerfileTemplateService $templates,
        private ProjectBuildProfileService $profiles
    ) {}

    public function catalog()
    {
        $params = $this->validate([
            'keyword' => 'nullable|string|max:100',
            'language' => 'nullable|string|max:32',
            'framework' => 'nullable|string|max:64',
            'workload' => 'nullable|string|max:32',
            'page' => 'nullable|integer|min:1',
            'pagesize' => 'nullable|integer|min:1|max:100',
        ]);
        return $this->success($this->templates->catalog($params));
    }

    public function render()
    {
        $params = $this->validate([
            'template_key' => 'required|string|max:100',
            'options' => 'nullable|array',
        ]);
        return $this->success($this->templates->render(
            (string) $params['template_key'],
            (array) ($params['options'] ?? [])
        ));
    }

    public function template()
    {
        $params = $this->validate(['template_key' => 'required|string|max:100']);
        return $this->success($this->templates->detail((string) $params['template_key']));
    }

    public function validateTemplate()
    {
        $params = $this->validate([
            'template_key' => 'required|string|max:100',
            'options' => 'nullable|array',
        ]);
        return $this->success($this->templates->validate(
            (string) $params['template_key'],
            (array) ($params['options'] ?? [])
        ));
    }

    public function profile()
    {
        $profile = $this->profiles->profile(
            (int) Functions::getContextValue('org_id'),
            (int) Functions::getContextValue('group_id'),
            (int) Functions::getContextValue('project_id')
        );
        return $this->success(['profile' => $profile]);
    }

    public function save()
    {
        $orgId = (int) Functions::getContextValue('org_id');
        $groupId = (int) Functions::getContextValue('group_id');
        $projectId = (int) Functions::getContextValue('project_id');
        $params = $this->validate(['build_profile' => 'required|array']);
        /** @var Project|null $project */
        $project = Project::where('id', $projectId)->where('org_id', $orgId)->where('group_id', $groupId)->first();
        if ($project === null || ! (bool) $project->develop) {
            throw new AppException(404, '代码型项目不存在');
        }
        $profile = Db::transaction(fn () => $this->profiles->save(
            $project, (int) Functions::getLoginUser()->getId(), (array) $params['build_profile']
        ));
        return $this->success(['profile' => $profile]);
    }

    public function revisions()
    {
        return $this->success(['revisions' => $this->profiles->revisions(
            (int) Functions::getContextValue('org_id'),
            (int) Functions::getContextValue('group_id'),
            (int) Functions::getContextValue('project_id')
        )]);
    }

    public function upgradePreview()
    {
        return $this->success($this->profiles->upgradePreview(
            (int) Functions::getContextValue('org_id'),
            (int) Functions::getContextValue('group_id'),
            (int) Functions::getContextValue('project_id')
        ));
    }

    public function rollback()
    {
        $params = $this->validate(['revision_id' => 'required|integer|min:1']);
        $orgId = (int) Functions::getContextValue('org_id');
        $groupId = (int) Functions::getContextValue('group_id');
        $projectId = (int) Functions::getContextValue('project_id');
        /** @var Project|null $project */
        $project = Project::where('id', $projectId)->where('org_id', $orgId)->where('group_id', $groupId)->first();
        if ($project === null || ! (bool) $project->develop) {
            throw new AppException(404, '代码型项目不存在');
        }
        return $this->success(['profile' => Db::transaction(fn () => $this->profiles->rollback(
            $project, (int) Functions::getLoginUser()->getId(), (int) $params['revision_id']
        ))]);
    }
}
