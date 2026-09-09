<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Controller;

use App\Services\Project\ProjectBuildService;
use App\Services\Project\RepositoryReferenceService;
use App\Support\Functions;

class BuildController extends AbstractController
{
    public function __construct(
        private ProjectBuildService $projectBuild,
        private RepositoryReferenceService $repositoryReferences
    ) {}

    /**
     * 记录列表.
     */
    public function list()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $projectId = Functions::getContextValue('project_id');
        $params = $this->validate([
            'pipeline_id' => 'integer',
            'status' => 'integer',
            'hook_id' => 'integer',
            'page' => 'integer|min:1',
            'pagesize' => 'integer|min:1|max:100',
        ]);
        $params = Functions::arrNull2default($params, [
            'pipeline_id' => null,
            'status' => null,
            'hook_id' => null,
            'page' => 1,
            'pagesize' => 20,
        ]);

        $data = $this->projectBuild->list(
            $orgId,
            $groupId,
            $projectId,
            $params['pipeline_id'],
            $params['status'],
            $params['hook_id'],
            (int) $params['page'],
            (int) $params['pagesize']
        );

        return $this->success($data);
    }

    /**
     * 新建构建.
     */
    public function create()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $projectId = Functions::getContextValue('project_id');
        $params = $this->validate([
            'pipeline_id' => 'required|integer',
            'remark' => 'required|string|max:500',
            'branch' => 'required|string|max:255',
            'commit_id' => 'required|string|max:100',
            'revision_type' => 'nullable|string|in:commit,tag',
            'hook_id' => 'integer',
        ]);
        $params = Functions::arrNull2default($params, [
            'hook_id' => 0,
        ]);
        $uid = Functions::getLoginUser()->getId();

        $build = $this->projectBuild->create(
            $uid,
            $orgId,
            $groupId,
            $projectId,
            $params['pipeline_id'],
            $params['remark'],
            $params['branch'],
            $params['commit_id'],
            $params['hook_id'],
            0,
            (string) ($params['revision_type'] ?? 'commit')
        );

        return $this->success([
            'build' => $build,
        ]);
    }

    /**
     * 重新构建.
     */
    public function rebuild()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $projectId = Functions::getContextValue('project_id');
        $params = $this->validate([
            'build_id' => 'required|integer',
        ]);

        $uid = Functions::getLoginUser()->getId();

        $build = $this->projectBuild->rebuild(
            $uid,
            $orgId,
            $groupId,
            $projectId,
            $params['build_id']
        );

        return $this->success([
            'build' => $build,
        ]);
    }

    public function importArtifact()
    {
        $params = $this->validate([
            'registry_id' => 'required|string',
            'repository' => 'required|string|max:255',
            'tag' => 'required|string|max:128',
            'remark' => 'required|string|max:500',
        ]);
        $result = $this->projectBuild->importArtifact(
            (int) Functions::getLoginUser()->getId(),
            (int) Functions::getContextValue('org_id'),
            (int) Functions::getContextValue('group_id'),
            (int) Functions::getContextValue('project_id'),
            (int) Functions::decodeID($params['registry_id'], true, true),
            basename(trim((string) $params['repository'], '/')),
            (string) $params['tag'],
            (string) $params['remark'],
            true
        );
        return $this->success($result);
    }

    /**
     * 取消构建.
     */
    public function cancelBuild()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $projectId = Functions::getContextValue('project_id');
        $params = $this->validate([
            'build_id' => 'required|integer',
        ]);

        $this->projectBuild->cancel(
            $orgId,
            $groupId,
            $projectId,
            $params['build_id']
        );

        return $this->success();
    }

    /**
     * 构建日志.
     */
    public function log()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $projectId = Functions::getContextValue('project_id');
        $params = $this->validate([
            'build_id' => 'required|integer',
        ]);

        $buildLog = $this->projectBuild->log(
            $orgId,
            $groupId,
            $projectId,
            $params['build_id']
        );

        return $this->success([
            'buildlog' => $buildLog,
        ]);
    }

    public function artifactProfile()
    {
        $params = $this->validate([
            'artifact' => 'required|integer|min:1',
        ]);
        $profile = $this->projectBuild->artifactProfile(
            (int) Functions::getContextValue('org_id'),
            (int) Functions::getContextValue('group_id'),
            (int) Functions::getContextValue('project_id'),
            (int) $params['artifact']
        );

        return $this->success($profile);
    }

    public function artifacts()
    {
        $params = Functions::arrNull2default($this->validate([
            'page' => 'integer|min:1',
            'pagesize' => 'integer|min:1|max:100',
        ]), ['page' => 1, 'pagesize' => 20]);
        return $this->success($this->projectBuild->artifacts(
            (int) Functions::getContextValue('org_id'),
            (int) Functions::getContextValue('group_id'),
            (int) Functions::getContextValue('project_id'),
            (int) $params['page'],
            (int) $params['pagesize']
        ));
    }

    /**
     * 获取Git分支列表.
     */
    public function gitBranches()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $projectId = Functions::getContextValue('project_id');
        $params = $this->validate(['refresh' => 'nullable|boolean']);

        $uid = Functions::getLoginUser()->getId();

        $branches = $this->repositoryReferences->branches(
            $uid,
            $orgId,
            $groupId,
            $projectId,
            (bool) ($params['refresh'] ?? false)
        );

        return $this->success([
            'branches' => $branches,
            'mode' => $this->repositoryReferences->mode($uid, $orgId, $groupId, $projectId),
        ]);
    }

    public function gitTags()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $projectId = Functions::getContextValue('project_id');
        $params = $this->validate(['refresh' => 'nullable|boolean']);
        $uid = (int) Functions::getLoginUser()->getId();
        return $this->success([
            'tags' => $this->repositoryReferences->tags(
                $uid,
                $orgId,
                $groupId,
                $projectId,
                (bool) ($params['refresh'] ?? false)
            ),
            'mode' => $this->repositoryReferences->mode($uid, $orgId, $groupId, $projectId),
        ]);
    }

    /**
     * 获取Git标签/Commits列表.
     */
    public function gitCommits()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $projectId = Functions::getContextValue('project_id');
        $params = $this->validate([
            'branch' => 'string|nullable|max:255',
            'refresh' => 'nullable|boolean',
        ]);
        $params = Functions::arrNull2default($params, [
            'branch' => null,
        ]);

        $uid = Functions::getLoginUser()->getId();

        if ($params['branch']) {
            // 获取Commits列表
            $commits = $this->repositoryReferences->commits(
                $uid,
                $orgId,
                $groupId,
                $projectId,
                $params['branch'],
                (bool) ($params['refresh'] ?? false)
            );

            return $this->success([
                'commits' => $commits,
                'mode' => $this->repositoryReferences->mode($uid, $orgId, $groupId, $projectId),
            ]);
        }
        return $this->success(['commits' => []]);
    }
}
