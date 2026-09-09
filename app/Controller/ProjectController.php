<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Controller;

use App\Constants\Consts;
use App\Exception\AppException;
use App\Model\Project;
use App\Model\ProjectRepository;
use App\Model\ProjectMember;
use App\Model\GitAuth;
use App\Model\Registry;
use App\Model\User;
use App\Services\ProjectService;
use App\Services\Project\ProjectRepositoryService;
use App\Services\Project\ProjectBuildService;
use App\Services\Project\ProjectRuntimeImportService;
use App\Services\RegistryGroupGrantService;
use App\Services\RegistryService;
use App\Support\Functions;
use Hyperf\Di\Annotation\Inject;
use Hyperf\DbConnection\Db;

class ProjectController extends AbstractController
{
    #[Inject]
    protected ProjectRepositoryService $projectRepositoryService;

    /**
     * @Inject
     */
    #[Inject]
    protected Project $project;

    /**
     * @Inject
     */
    #[Inject]
    protected ProjectService $projectService;

    /**
     * @Inject
     */
    #[Inject]
    protected ProjectMember $projectMember;

    /**
     * @Inject
     */
    #[Inject]
    protected User $user;

    /**
     * @Inject
     */
    #[Inject]
    protected Registry $registry;

    #[Inject]
    protected RegistryService $registryService;

    #[Inject]
    protected RegistryGroupGrantService $registryGroupGrants;

    #[Inject]
    protected ProjectBuildService $projectBuildService;

    #[Inject]
    protected ProjectRuntimeImportService $projectRuntimeImportService;

    // 项目列表
    public function list()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id', false, null);
        $params = $this->validate([
            'keyword' => 'nullable|string|max:50',
            'page' => 'nullable|integer|min:1',
            'pagesize' => 'nullable|integer|min:1|max:100',
        ]);
        $params = Functions::arrNull2default($params, [
            'keyword' => null,
            'page' => 1,
            'pagesize' => 20,
        ]);
        $uid = Functions::getLoginUser()->getId();

        $data = $this->project->list(
            $uid,
            $orgId,
            $groupId,
            $params['keyword'],
            $params['page'],
            $params['pagesize']
        );

        return $this->success($data);
    }

    // 创建项目
    public function createProject()
    {
        $params = $this->validate([
            'alias' => 'nullable|string|max:50|regex:' . Consts::ALIAS_REGEX,
            'title' => 'required|string|max:50',
            'desc' => 'nullable|string|max:500',
            'develop' => 'required|boolean',
            'default_port' => 'required|integer|min:1|max:65535',
            'image_name' => 'nullable|string|regex:#^[a-z0-9]+(?:[._/-][a-z0-9]+)*$#',
            'image_tag' => 'nullable|string|max:128',
            'source_registry_id' => 'nullable|string',
            'runtime_import' => 'nullable|boolean',
            'import_env_id' => 'nullable|integer|min:1',
            'import_source' => 'nullable|array',
            'registries' => 'nullable|array|max:20',
            'registries.*' => 'required|string|distinct',
        ], [
            'org_id.required' => '组织id必传',
            'group_id.required' => '项目组 id 必传',
            'title.required' => '请输入项目名称',
            'title.max' => '项目名称长度1-50个字符',
            'desc.max' => '项目描述长度1-500个字符',
        ]);

        if ($params['develop']) {
            $developParams = $this->validate([
                'build_cluster_id' => 'required|integer|min:1',
                'repository' => 'required|array',
                'repository.type' => 'required|integer|in:' . ProjectRepository::TYPE_EXTERNAL,
                'repository.clone_url' => 'required|string|max:1024',
                'repository.provider' => 'required|in:' . implode(',', array_keys(GitAuth::$vendors)),
                'build_profile' => 'required|array',
            ]);
            $params = array_merge($params, $developParams);
        } else {
            if (empty($params['registries'])) {
                throw new AppException(422, '关闭构建模块，必须设置镜像仓库');
            }
            if (trim((string) ($params['image_name'] ?? '')) === '') {
                throw new AppException(422, '关闭构建模块，必须填写已有镜像的 Repository 名称');
            }
            if (trim((string) ($params['image_tag'] ?? '')) === '') {
                throw new AppException(422, '关闭构建模块，必须选择或填写已有镜像的 Tag');
            }
            if (trim((string) ($params['source_registry_id'] ?? '')) === '') {
                throw new AppException(422, '关闭构建模块，必须选择已有镜像所在的仓库');
            }
            if (! in_array($params['source_registry_id'], $params['registries'], true)) {
                throw new AppException(422, '已有镜像所在仓库必须关联到项目');
            }
            if ((bool) ($params['runtime_import'] ?? false)) {
                $importParams = $this->validate([
                    'import_env_id' => 'required|integer|min:1',
                    'import_source' => 'required|array',
                    'import_source.type' => 'required|string|in:swarm_service,kubernetes_deployment,docker_container',
                    'import_source.cluster_id' => 'required|integer|min:1',
                    'import_source.reference' => 'required|string|max:255',
                    'import_source.namespace' => 'nullable|string|max:253',
                    'import_source.node_id' => 'nullable|string|max:128',
                ]);
                $params = array_merge($params, $importParams);
            }
        }

        $params = Functions::arrNull2default($params, [
            'desc' => '',
            'repository' => null,
            'image_name' => '',
            'registries' => [],
            'alias' => '',
        ]);
        $params['org_id'] = Functions::getContextValue('org_id');
        $params['group_id'] = Functions::getContextValue('group_id');

        $uid = Functions::getLoginUser()->getId();

        $artifact = null;
        $runtimeImport = null;
        if ($params['develop']) {
            $project = $this->project->createProject($uid, $params);
        } else {
            $project = Db::transaction(function () use ($uid, $params, &$artifact, &$runtimeImport) {
                $sourceProfile = null;
                if ((bool) ($params['runtime_import'] ?? false)) {
                    $sourceProfile = $this->projectRuntimeImportService->profile(
                        (int) $params['org_id'],
                        (int) $params['group_id'],
                        (array) $params['import_source']
                    );
                    $this->assertImportedImageMatches((string) $sourceProfile['image'], (string) $params['image_name']);
                }
                $project = $this->project->createProject($uid, $params);
                $result = $this->projectBuildService->importArtifact(
                    $uid,
                    (int) $params['org_id'],
                    (int) $params['group_id'],
                    (int) $project->getRawOriginal('id'),
                    (int) Functions::decodeID($params['source_registry_id'], true, true),
                    (string) $params['image_name'],
                    (string) $params['image_tag'],
                    '创建项目时登记已有镜像'
                );
                $artifact = $result['artifact'];
                if ($sourceProfile !== null
                    && (string) $params['import_source']['type'] !== ProjectRuntimeImportService::DOCKER_CONTAINER) {
                    $runtimeImport = $this->projectRuntimeImportService->import(
                        $uid,
                        $project,
                        $artifact,
                        (int) $params['import_env_id'],
                        (array) $params['import_source']
                    );
                }
                return $project;
            });
            if ((bool) ($params['runtime_import'] ?? false)
                && (string) $params['import_source']['type'] === ProjectRuntimeImportService::DOCKER_CONTAINER) {
                $runtimeImport = $this->projectRuntimeImportService->import(
                    $uid,
                    $project,
                    $artifact,
                    (int) $params['import_env_id'],
                    (array) $params['import_source']
                );
            }
        }

        return $this->success([
            'project' => $project,
            'artifact' => $artifact,
            'runtime_import' => $runtimeImport,
        ]);
    }

    // 更新项目
    public function updateProject()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $projectId = Functions::getContextValue('project_id');
        /** @var Project|null $currentProject */
        $currentProject = Project::where('id', $projectId)->where('org_id', $orgId)->where('group_id', $groupId)->first();
        if ($currentProject === null) {
            throw new AppException(404, '项目不存在');
        }
        $rules = [
            'title' => 'required|string|max:50',
            'desc' => 'nullable|string|max:500',
            'default_port' => 'required|integer|min:1|max:65535',
            'image_name' => 'nullable|string|regex:#^[a-z0-9]+(?:[._/-][a-z0-9]+)*$#',
            'registries' => 'nullable|array|max:20',
            'registries.*' => 'required|string|distinct',
        ];
        if ((bool) $currentProject->develop) {
            $rules += [
                'build_cluster_id' => 'required|integer|min:1',
                'repository' => 'required|array',
                'repository.type' => 'required|integer|in:' . ProjectRepository::TYPE_EXTERNAL,
                'repository.clone_url' => 'required|string|max:1024',
                'repository.provider' => 'required|in:' . implode(',', array_keys(GitAuth::$vendors)),
                'build_profile' => 'required|array',
            ];
        }
        $params = $this->validate($rules, [
            'title.required' => '请输入项目名称',
            'title.max' => '项目名称长度1-50个字符',
            'desc.max' => '项目描述长度1-500个字符',
            'repository.type.required' => '请选择 Git 仓库类型',
            'repository.provider.required' => '请选择 Git 服务类型',
            'repository.provider.in' => '不支持此 Git 服务类型',
        ]);
        if (! (bool) $currentProject->develop) {
            if (empty($params['registries'])) {
                throw new AppException(422, '已有镜像项目必须设置镜像仓库');
            }
            if (trim((string) ($params['image_name'] ?? '')) === '') {
                throw new AppException(422, '已有镜像项目必须填写 Repository 名称');
            }
        }
        $params = Functions::arrNull2default($params, [
            'desc' => '',
            'image_name' => '',
            'registries' => [],
        ]);
        $uid = Functions::getLoginUser()->getId();
        $params['_uid'] = $uid;

        $project = $this->project->updateProject($orgId, $groupId, $projectId, $params);

        return $this->success([
            'project' => $project,
        ]);
    }

    // 项目详情
    public function profile()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $projectId = Functions::getContextValue('project_id');
        $param = $this->validate([
            'with_default_registry' => 'nullable|in:0,1',
        ]);
        $param = Functions::arrNull2default($param, [
            'with_default_registry' => 0,
        ]);

        $projectInfo = $this->project->profile(
            $orgId,
            $groupId,
            $projectId,
            (bool) $param['with_default_registry']
        );

        return $this->success(['project' => $projectInfo]);
    }

    /**
     * 项目简单信息.
     */
    public function basicInfo()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $projectId = Functions::getContextValue('project_id');

        $project = $this->project->profileBasic($orgId, $groupId, $projectId);

        return $this->success([
            'project' => $project,
        ]);
    }

    /**
     * 退出项目.
     */
    public function exitProject()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $projectId = Functions::getContextValue('project_id');

        $uid = Functions::getLoginUser()->getId();

        // 退出项目
        $this->projectMember->exitProject($uid, $orgId, $groupId, $projectId);

        return $this->success();
    }

    /**
     * 获取删除项目资源概览.
     */
    public function getDeleteOverview()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $projectId = Functions::getContextValue('project_id');

        $overview = $this->project->getDeleteOverview($orgId, $groupId, $projectId);

        return $this->success($overview);
    }

    /**
     * 删除项目.
     */
    public function delete()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $projectId = Functions::getContextValue('project_id');
        $params = $this->validate([
            'confirm_token' => 'required|string',
        ]);
        $uid = Functions::getLoginUser()->getId();

        $this->user->idConfirm($uid, $params['confirm_token']);

        // 删除项目
        $this->project->deleteProject($uid, $orgId, $groupId, $projectId);

        return $this->success();
    }

    public function repository()
    {
        return $this->success([
            'repository' => $this->projectRepositoryService->profile(
                (int) Functions::getContextValue('org_id'),
                (int) Functions::getContextValue('group_id'),
                (int) Functions::getContextValue('project_id')
            ),
        ]);
    }

    /**
     * 概览.
     */
    public function overview()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $projectId = Functions::getContextValue('project_id');

        $overview = $this->projectService->overview(
            (int) Functions::getLoginUser()->getId(),
            $orgId,
            $groupId,
            $projectId
        );

        return $this->success([
            'overview' => $overview,
        ]);
    }

    /**
     * 概览页源码统计，独立异步加载。
     */
    public function overviewSource()
    {
        return $this->success([
            'source' => $this->projectService->overviewSource(
                (int) Functions::getLoginUser()->getId(),
                (int) Functions::getContextValue('org_id'),
                (int) Functions::getContextValue('group_id'),
                (int) Functions::getContextValue('project_id')
            ),
        ]);
    }

    /**
     * 项目简单列表.
     */
    public function simpleList()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id', false, null);
        $params = $this->validate([
            'keyword' => 'string',
        ]);
        $params = Functions::arrNull2default($params, [
            'keyword' => null,
        ]);
        $uid = Functions::getLoginUser()->getId();

        $projects = $this->project->simpleList(
            $uid,
            $orgId,
            $groupId,
            $params['keyword']
        );

        return $this->success([
            'projects' => $projects,
        ]);
    }

    /**
     * 获取项目创建/编辑特殊属性.
     */
    public function getCreateProps()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate(['group' => 'nullable']);
        $groupId = Functions::getContextValue('group_id', false, null);
        $uid = Functions::getLoginUser()->getId();

        $props = $this->project->getCreateProps($uid, $orgId, $groupId === null ? null : (int) $groupId);

        return $this->success([
            'props' => $props,
        ]);
    }

    /**
     * 项目可用的Registry列表.
     */
    public function registries()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate([
            'linked' => 'nullable|in:0,1',
        ]);
        $linked = (int) ($params['linked'] ?? 0) === 1;
        $groupId = (int) Functions::getContextValue('group_id');
        if ($linked) {
            $projectId = (int) Functions::getContextValue('project_id');
            if (! Project::where('id', $projectId)->where('org_id', $orgId)->where('group_id', $groupId)->exists()) {
                throw new AppException(404, '项目不存在');
            }
            $registries = $this->registry->listLinkedToProject((int) $orgId, $groupId, $projectId);
        } else {
            $registries = $this->registry->listForProject((int) $orgId, $groupId);
        }

        return $this->success([
            'registries' => $registries,
        ]);
    }

    /**
     * 项目组获授权 Registry 的 Repository 列表.
     */
    public function registryCatalog()
    {
        $orgId = (int) Functions::getContextValue('org_id');
        $groupId = (int) Functions::getContextValue('group_id');
        $params = $this->validate([
            'registry_id' => 'required|string',
            'search' => 'nullable|string|max:200',
        ]);
        $registry = $this->projectRegistry($orgId, $groupId, (string) $params['registry_id']);
        $repositories = $this->registryService->catalog($registry, (string) ($params['search'] ?? ''));
        $namespace = trim((string) $registry->namespace, '/');
        $repositories = array_values(array_filter(
            $repositories,
            static fn (string $repository): bool => $repository === $namespace
                || str_starts_with($repository, $namespace . '/')
        ));

        return $this->success(['repos' => $repositories]);
    }

    /**
     * 项目组获授权 Registry 的镜像 Tag 列表.
     */
    public function registryTags()
    {
        $orgId = (int) Functions::getContextValue('org_id');
        $groupId = (int) Functions::getContextValue('group_id');
        $params = $this->validate([
            'registry_id' => 'required|string',
            'repository' => 'required|string|max:500',
        ]);
        $registry = $this->projectRegistry($orgId, $groupId, (string) $params['registry_id']);
        $repository = strtolower(trim((string) $params['repository'], " \t\n\r\0\x0B/"));
        $namespace = trim((string) $registry->namespace, '/');
        if ($repository !== $namespace && ! str_starts_with($repository, $namespace . '/')) {
            throw new AppException(403, sprintf('Repository 必须位于项目组 namespace「%s」之下', $namespace));
        }

        return $this->success([
            'tags' => $this->registryService->tagsList($registry, $repository),
        ]);
    }

    public function importSources()
    {
        $params = $this->validate([
            'cluster_id' => 'required|integer|min:1',
            'type' => 'required|string|in:swarm_service,kubernetes_deployment,docker_container',
            'node_id' => 'nullable|string|max:128',
        ]);
        return $this->success($this->projectRuntimeImportService->sources(
            (int) Functions::getContextValue('org_id'),
            (int) Functions::getContextValue('group_id'),
            (int) $params['cluster_id'],
            (string) $params['type'],
            (string) ($params['node_id'] ?? '')
        ));
    }

    public function importSourceProfile()
    {
        $params = $this->validate([
            'source' => 'required|array',
            'source.type' => 'required|string|in:swarm_service,kubernetes_deployment,docker_container',
            'source.cluster_id' => 'required|integer|min:1',
            'source.reference' => 'required|string|max:255',
            'source.namespace' => 'nullable|string|max:253',
            'source.node_id' => 'nullable|string|max:128',
        ]);
        return $this->success([
            'profile' => $this->projectRuntimeImportService->profile(
                (int) Functions::getContextValue('org_id'),
                (int) Functions::getContextValue('group_id'),
                (array) $params['source'],
                true
            ),
        ]);
    }

    private function projectRegistry(int $orgId, int $groupId, string $registryId): Registry
    {
        $registry = $this->registry->resolveRegistry(
            (int) Functions::getLoginUser()->getId(),
            $orgId,
            $registryId
        );
        return $this->registryGroupGrants->apply($registry, $groupId);
    }

    private function assertImportedImageMatches(string $sourceImage, string $repository): void
    {
        $source = preg_replace('/@sha256:[a-f0-9]{64}$/i', '', trim($sourceImage));
        $slash = strrpos((string) $source, '/');
        $colon = strrpos((string) $source, ':');
        if ($colon !== false && ($slash === false || $colon > $slash)) {
            $source = substr((string) $source, 0, $colon);
        }
        $repository = trim($repository, '/');
        if ($source !== $repository && ! str_ends_with((string) $source, '/' . $repository)) {
            throw new AppException(422, sprintf(
                '所选镜像 Repository 与运行资源镜像不一致：%s',
                $sourceImage
            ));
        }
    }
}
