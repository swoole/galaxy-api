<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Model;

use App\Constants\ErrorCode;
use App\Exception\AppException;
use App\Services\Alias\Alias;
use App\Services\Project\ProjectBuildService;
use App\Services\Project\ProjectBuildProfileService;
use App\Services\Project\ProjectReleaseService;
use App\Services\Project\ProjectRepositoryService;
use App\Services\Project\ProjectPipelineService;
use App\Services\Project\ProjectServiceIdentity;
use App\Services\Notify as ServicesNotify;
use App\Support\Functions;
use App\Support\MySQL;
use Hyperf\DbConnection\Db;
use Hyperf\HttpServer\Request;
use Hyperf\Context\ApplicationContext;
use Hyperf\Context\Context;
use Hyperf\Database\Exception\QueryException;
use Throwable;

/**
 * @property int $id
 * @property int $org_id
 * @property int $group_id
 * @property string $alias
 * @property string $title
 * @property string $desc
 * @property int $default_port
 * @property string $image_name
 * @property int $creator
 * @property int $created_at
 */
class Project extends Model
{
    use TraitRelationCreatorInfo;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected ?string $table = 'project';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
//    protected $fillable = [];
    protected array $guarded = [];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected array $casts = ['id' => 'integer', 'org_id' => 'integer', 'group_id' => 'integer',
        'creator' => 'integer', 'created_at' => 'integer', 'default_port' => 'integer',
        'build_cluster_id' => 'integer'];

    /**
     * 获取别名ID.
     */
    public static function getAliasId()
    {
        $orgId = Context::get('org_id');

        $request = ApplicationContext::getContainer()->get(Request::class);
        if (array_key_exists('project_id', $request->getQueryParams())) {
            throw new AppException(ErrorCode::INVALID_PARAMS, 'parameter project_id has been renamed to project');
        }
        $input = $request->input('project', null);
        if (empty($input)) {
            $projectId = null;
        } elseif (is_numeric($input)) {
            $projectId = (int) $input;
        } elseif (! is_string($input)) {
            throw new AppException(ErrorCode::INVALID_PARAMS, 'invalid parameter project');
        } else {
            $projectId = (int) Project::where('org_id', $orgId)->where('alias', $input)->value('id');
            if (!$projectId) {
                throw new AppException(ErrorCode::INVALID_PARAMS, 'invalid parameter project');
            }
        }
        Context::set('project_id', $projectId);

        return $projectId;
    }

    public function user()
    {
        return $this->hasOne(User::class, 'id', 'creator');
    }

    public function userProfile()
    {
        return $this->hasOne(UserProfile::class, 'uid', 'creator');
    }

    public function orgMember()
    {
        return $this->hasOne(OrgMember::class, 'uid', 'creator');
    }

    public function group()
    {
        return $this->hasOne(Group::class, 'id', 'group_id')
            ->select('id', 'org_id', 'title', 'alias');
    }

    public function org()
    {
        return $this->hasOne(Org::class, 'id', 'org_id')
            ->select('id', 'title', 'alias');
    }

    public function repository()
    {
        return $this->hasOne(ProjectRepository::class, 'project_id', 'id');
    }

    public function releases()
    {
        return $this->hasMany(ProjectRelease::class, 'project_id', 'id');
    }

    public function runtimes()
    {
        return $this->hasMany(ProjectRuntime::class, 'project_id', 'id');
    }

    public function routes()
    {
        return $this->hasMany(ProjectRoute::class, 'project_id', 'id');
    }

    /**
     * 项目列表.
     */
    public function list($uid, $orgId, $groupId = null, $keyword = null, $page = 1, $pageSize = 20)
    {
        $builder = $this->where('org_id', (int) $orgId)
            ->select(
              'id', 'org_id', 'group_id', 'title', 'desc', 'develop', 'alias'
            )
            ->with('group');

        if (!empty($groupId)) {
            $builder->where('group_id', $groupId);
        }

        // ------------------- 权限过滤 begin -----------------------
        $roles = Context::get('roles', [0]);
        if (!in_array((int) $roles[0], [OrgMember::ROLE_MANAGER])) {
            // 非组织管理员进行项目过滤
            $builder->where(function ($query) use ($uid, $orgId) {
                // Every member of a project group can see the group's
                // projects.  Restricting this to managers/directors made
                // newly added ordinary members appear to have no projects.
                $groupIds = GroupMember::where('org_id', $orgId)
                    ->where('uid', $uid)
                    ->pluck('group_id')
                    ->toArray();
                $query->orWhereIn('group_id', $groupIds);

                $projectIds = ProjectMember::where('org_id', $orgId)
                    ->where('uid', $uid)
                    ->pluck('project_id')
                    ->toArray();
                $query->orWhereIn('id', $projectIds);
            });
        }
        // ------------------- 权限过滤 end -----------------------

        if (!empty($keyword)) {
            $builder->where(function ($query) use ($keyword) {
                $query->where('title', 'like', '%' . $keyword . '%');
                if (is_numeric($keyword)) {
                    $query->orWhere('id', (int) $keyword);
                } else {
                    $query->orWhere('alias', $keyword);
                }
            });
        }

        $data = MySQL::jsonPaginate($builder, $page, $pageSize);

        $membersCount = $this->getMembersCount($data['data']);
        $lastActives = $this->getLastActives($data['data']);
        $instances = $this->getInstancesCount($data['data']);

        foreach ($data['data'] as &$item) {
            $item['member_count'] = $membersCount[$item['id']] ?? 0;
            $item['last_active'] = $lastActives[$item['id']] ?? 0;
            $item['instances'] = $instances[$item['id']];
        }
        unset($item);

        return $data;
    }

    /**
     * 创建项目.
     */
    public function createProject($uid, $params)
    {
        // 校验 项目名称 项目标识 组织/项目内唯一
        $dupliTitle =$this->where('org_id', $params['org_id'])
            ->where('group_id', $params['group_id'])
            ->where('title', $params['title'])
            ->exists();
        if ($dupliTitle) {
            throw new AppException(
                ErrorCode::PROJECT_TITLE_DUPLICATE,
                ErrorCode::getMessage(ErrorCode::PROJECT_TITLE_DUPLICATE)
            );
        }

        // 验证alias唯一性
        if (!empty($params['alias'])) {
            $dupliAlias = $this->where('org_id', $params['org_id'])
                ->where('alias', $params['alias'])
                ->exists();
            if ($dupliAlias) {
                throw new AppException(
                    ErrorCode::INVALID_PARAMS,
                    '别名已被使用，请更改'
                );
            }
        } else {
            $params['alias'] = $this->calcuDefaultAlias($params['org_id'], $params['title']);
        }

        $params['image_name'] = trim((string) ($params['image_name'] ?? ''));
        if ($params['image_name'] === '') {
            $params['image_name'] = $params['alias'];
        }

        // 镜像名称校验
        if (!empty($params['image_name'])) {
            // 不能与其他项目一样
            $dupliImage =$this->where('org_id', $params['org_id'])
                ->where('image_name', $params['image_name'])
                ->exists();
            if ($dupliImage) {
                throw new AppException(400, '镜像名称已被其他项目使用，请更改');
            }
        }

        // 镜像仓库校验
        $registryIds = $this->validRegistries(
            (int) $params['org_id'],
            (int) $params['group_id'],
            $params['registries'] ?? []
        );

        // 启用构建，必须设置开发环境信息
        if (!empty($params['develop'])) {
            $params['build_cluster_id'] = $this->validBuildCluster(
                (int) $params['org_id'], (int) $params['group_id'],
                (int) ($params['build_cluster_id'] ?? 0)
            );
            if ((int) ($params['repository']['type'] ?? 0) !== ProjectRepository::TYPE_EXTERNAL) {
                throw new AppException(422, '代码型项目必须绑定外部 Git 仓库');
            }
            [$repositoryType, $repositoryProvider, $repositoryUrl] = $this->checkRepositoryParams($params);
        } else {
            $params['build_cluster_id'] = 0;
            $repositoryType = 0;
            $repositoryProvider = 0;
            $repositoryUrl = '';
        }

        /** @var Project $project */
        try {
            $project = Db::transaction(function () use (
                $params,
                $repositoryType,
                $repositoryProvider,
                $repositoryUrl,
                $registryIds,
                $uid
            ): Project {
                if (! empty($params['develop'])) {
                    $this->lockBuildClusterGrant(
                        (int) $params['org_id'], (int) $params['group_id'], (int) $params['build_cluster_id']
                    );
                }
                $project = Project::create([
                    'org_id' => $params['org_id'],
                    'group_id' => $params['group_id'],
                    'title' => $params['title'],
                    'alias' => $params['alias'],
                    'desc' => $params['desc'] ?? '',
                    'develop' => empty($params['develop']) ? 0 : 1,
                    'build_cluster_id' => (int) $params['build_cluster_id'],
                    'default_port' => $params['default_port'],
                    'image_name' => $params['image_name'],
                    'creator' => $uid,
                    'created_at' => time(),
                ]);
                ProjectMember::create([
                    'org_id' => $project['org_id'],
                    'group_id' => $project['group_id'],
                    'uid' => $uid,
                    'project_id' => $project['id'],
                    'role' => ProjectMember::ROLE_DIRECTOR,
                    'join_at' => time(),
                ]);
                $this->syncRegistries((int) $project->org_id, (int) $project->id, $registryIds);
                /** @var ProjectRepositoryService $repositoryService */
                $repositoryService = $this->getInstance(ProjectRepositoryService::class);
                $repositoryService->saveForProject($project, $repositoryType, $repositoryProvider, $repositoryUrl);
                if ((bool) $project->develop) {
                    $this->getInstance(ProjectBuildProfileService::class)->save(
                        $project,
                        $uid,
                        (array) ($params['build_profile'] ?? [])
                    );
                    $this->getInstance(ProjectPipelineService::class)->ensureDefault(
                        $uid,
                        (int) $project->org_id,
                        (int) $project->group_id,
                        (int) $project->id
                    );
                }
                return $project;
            });
        } catch (QueryException $e) {
            $this->throwProjectIdentityConflict($e);
        }

        $project->setVisible([
            'id', 'org_id', 'group_id', 'title', 'alias', 'desc', 'default_port', 'image_name',
            'build_cluster_id', 'group',
        ]);
        $project->load('group');
        return $project;
    }

    /**
     * 检查Git参数.
     */
    protected function checkRepositoryParams($params)
    {
        $repository = (array) ($params['repository'] ?? []);
        if ((int) ($repository['type'] ?? 0) !== ProjectRepository::TYPE_EXTERNAL) {
            throw new AppException(422, '代码型项目必须绑定用户自有的外部 Git 仓库');
        }
        if (empty($repository['clone_url'])) {
            throw new AppException(422, '请填写 Git 仓库地址');
        }
        if (empty($repository['provider']) || ! isset(GitAuth::$vendors[$repository['provider']])) {
            throw new AppException(422, '请选择 Git 服务类型');
        }
        [$domain] = GitAuth::parseRepositoryUrl((string) $repository['clone_url']);
        $matchedVendor = $this->getInstance(GitAuth::class)->getMatchedVendor($domain);
        if ($matchedVendor && (int) $matchedVendor !== (int) $repository['provider']) {
            throw new AppException(422, sprintf('Git 服务类型选择错误，应该选择%s', GitAuth::$vendors[$matchedVendor]));
        }
        return [
            ProjectRepository::TYPE_EXTERNAL,
            (int) $repository['provider'],
            trim((string) $repository['clone_url']),
        ];
    }

    protected function validBuildCluster(int $orgId, int $groupId, int $clusterId): int
    {
        if (! GroupResourceGrant::canUseCluster($orgId, $groupId, $clusterId)) {
            throw new AppException(403, '当前项目组未获授权使用该构建集群');
        }
        if ($clusterId <= 0 || ! Cluster::where('id', $clusterId)->where('org_id', $orgId)
            ->whereIn('orchestrator_type', Cluster::buildableOrchestrators())
            ->where('status', Cluster::STATUS_READY)->exists()) {
            throw new AppException(422, '请选择在线且支持 BuildKit 的构建集群');
        }
        return $clusterId;
    }

    private function lockBuildClusterGrant(int $orgId, int $groupId, int $clusterId): void
    {
        if (GroupResourceGrant::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('resource_type', GroupResourceGrant::TYPE_CLUSTER)->where('resource_id', $clusterId)
            ->lockForUpdate()->first(['id']) === null) {
            throw new AppException(403, '当前项目组已无权使用该构建集群');
        }
    }

    /**
     * @param $orgId
     * @param $groupId
     * @param $projectId
     * @return string
     */
    public function getImageRepository($orgId, $groupId, $projectId)
    {
        $project = $this->where('id', $projectId)
            ->where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->select('id', 'image_name')
            ->first();
        if (empty($project)) {
            throw new AppException(1404, '项目不存在');
        }
        $registryIds = ProjectRegistryRel::where('org_id', $orgId)->where('project_id', $projectId)
            ->orderBy('id')->pluck('registry_id')->toArray();
        if ($registryIds === []) {
            throw new AppException(1406, '未设置镜像仓库');
        }
        $repository = Registry::where('org_id', $orgId)
            ->whereIn('id', $registryIds)
            ->select('address', 'namespace')
            ->first();
        if (empty($repository)) {
            throw new AppException(1407, '镜像仓库不存在');
        }
        $repository['namespace'] = RegistryGroupGrant::where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('registry_id', (int) $registryIds[0])
            ->value('namespace');
        if (! is_string($repository['namespace']) || $repository['namespace'] === '') {
            throw new AppException(403, '当前项目组已无权使用该镜像仓库');
        }
        $repository['image'] = $project['image_name'];
        return $repository;
    }

    // 获取项目详细信息
    public function profile($org_id, $group_id, $project_id, $withDefaultRegistry = false)
    {
        $where = [
            'id' => $project_id,
            'org_id' => $org_id,
            'group_id' => $group_id,
        ];
        $project = $this->with(['group', 'repository'])
            ->with([
                'creatorInfo' => function ($query) use ($org_id) {
                    $query->where('org_id', $org_id);
                }
            ])
            ->where($where)->first();
        if ($project === null) {
            throw new AppException(404, '项目不存在');
        }

        $membersCount = $this->getMembersCount([$project]);
        $project['member_count'] = $membersCount[$project['id']] ?? 0;

        $lastActives = $this->getLastActives([$project]);
        $project['last_active'] = $lastActives[$project['id']] ?? 0;

        $instances = $this->getInstancesCount([$project]);
        $project['instances'] = $instances[$project['id']];

        $registryIds = ProjectRegistryRel::where('org_id', $org_id)->where('project_id', $project_id)
            ->orderBy('id')->pluck('registry_id')->toArray();
        $registries = [];
        if ($registryIds !== []) {
            $grantNamespaces = RegistryGroupGrant::where('org_id', $org_id)->where('group_id', $group_id)
                ->whereIn('registry_id', $registryIds)->pluck('namespace', 'registry_id');
            $registries = Registry::where('org_id', $org_id)
                ->whereIn('id', $registryIds)
                ->select('id', 'proto', 'address', 'namespace', 'remark')
                ->get()->map(static function (Registry $registry) use ($grantNamespaces): Registry {
                    $registry->credential_namespace = (string) $registry->namespace;
                    $registry->namespace = (string) ($grantNamespaces[(int) $registry->getRawOriginal('id')] ?? '');
                    return $registry;
                })->filter(static fn (Registry $registry): bool => (string) $registry->namespace !== '')->values();
        }
        $project['registries'] = $registries;

        if ($withDefaultRegistry && empty($registries)) {
            $project['default_registry'] = $this->getPushRegistry((int) $org_id, (int) $group_id);
        }

        $buildProfile = $this->getInstance(ProjectBuildProfileService::class)->profile(
            (int) $org_id,
            (int) $group_id,
            (int) $project_id
        );
        if ($buildProfile !== null && $buildProfile->dockerfile_source === ProjectBuildProfile::SOURCE_TEMPLATE) {
            /** @var DockerfileTemplate|null $template */
            $template = DockerfileTemplate::where('id', (int) $buildProfile->template_id)
                ->first(['template_key', 'title', 'language', 'framework']);
            if ($template !== null) {
                $options = (array) $buildProfile->options;
                $buildProfile->technology = [
                    'key' => (string) $template->template_key,
                    'title' => (string) $template->title,
                    'language' => (string) $template->language,
                    'framework' => (string) $template->framework,
                    'runtime_version' => (string) ($options['runtime_version'] ?? ''),
                    'framework_version' => (string) ($options['framework_version'] ?? ''),
                ];
            }
        }
        $project['build_profile'] = $buildProfile;

        return $project;
    }

    /**
     * 获取项目成员数量.
     */
    protected function getMembersCount($projects)
    {
        [$orgId, $projectIds] = $this->aggregateScope($projects);
        if ($projectIds === []) {
            return [];
        }
        return ProjectMember::where('org_id', $orgId)->whereIn('project_id', $projectIds)
            ->select('project_id', Db::raw('COUNT(1) AS cnt'))
            ->groupBy('project_id')->pluck('cnt', 'project_id')->toArray();
    }

    /**
     * 最后活跃时间.
     */
    public function getLastActives($projects)
    {
        [$orgId, $projectIds] = $this->aggregateScope($projects);
        if ($projectIds === []) {
            return [];
        }

        $lastActives = ProjectRelease::where('org_id', $orgId)->whereIn('project_id', $projectIds)
            ->select('project_id', Db::raw('MAX(created_at) AS last_active'))
            ->groupBy('project_id')->pluck('last_active', 'project_id')->toArray();
        $lastBuilds = Build::where('org_id', $orgId)->whereIn('project_id', $projectIds)
            ->select('project_id', Db::raw('MAX(created_at) AS last_active'))
            ->groupBy('project_id')->pluck('last_active', 'project_id')->toArray();
        foreach ($lastBuilds as $projectId => $lastActive) {
            $projectId = (int) $projectId;
            $lastActives[$projectId] = max((int) ($lastActives[$projectId] ?? 0), (int) $lastActive);
        }
        return $lastActives;
    }

    /**
     * 获取项目实例总数.
     */
    public function getInstancesCount($projects)
    {
        [$orgId, $projectIds] = $this->aggregateScope($projects);
        $instances = array_fill_keys($projectIds, 0);
        if ($projectIds === []) {
            return $instances;
        }
        $counts = ProjectRuntime::where('org_id', $orgId)->whereIn('project_id', $projectIds)
            ->select('project_id', Db::raw('COUNT(1) AS cnt'))
            ->groupBy('project_id')->pluck('cnt', 'project_id')->toArray();
        foreach ($counts as $projectId => $count) {
            $instances[(int) $projectId] = (int) $count;
        }
        return $instances;
    }

    /** @return array{0: int, 1: array<int, int>} */
    private function aggregateScope(iterable $projects): array
    {
        $orgId = 0;
        $projectIds = [];
        foreach ($projects as $project) {
            $orgId = $orgId ?: (int) $project['org_id'];
            if ((int) $project['org_id'] !== $orgId) {
                throw new AppException(500, '项目聚合查询不能跨组织执行');
            }
            $projectIds[] = (int) $project['id'];
        }
        return [$orgId, array_values(array_unique(array_filter($projectIds)))];
    }

    /**
     * 获取项目简单信息.
     */
    public function profileBasic($orgId, $groupId, $projectId)
    {
        $project = $this->where('id', $projectId)
            ->where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->select(
                'id', 'title', 'desc', 'develop', 'image_name', 'alias'
            )
            ->first();
        if (empty($project)) {
            throw new AppException(404, '项目不存在');
        }

        $roles = Context::get('roles');
        $project->org_role = $roles[0];
        $project->group_role = $roles[1];
        $project->role = $roles[2];
        return $project;
    }

    // 修改项目信息
    public function updateProject($orgId, $groupId, $projectId, $params)
    {
        // 校验 项目名称 项目标识 组织/项目内唯一
        $dupliTitle =$this->where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('title', $params['title'])
            ->where('id', '<>', $projectId)
            ->exists();
        if ($dupliTitle) {
            throw new AppException(
                ErrorCode::PROJECT_TITLE_DUPLICATE,
                ErrorCode::getMessage(ErrorCode::PROJECT_TITLE_DUPLICATE)
            );
        }

        // 项目存在性校验
        $project = $this->where('id', $projectId)
            ->where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->first();
        if (empty($project)) {
            throw new AppException(
                1,
                '项目不存在'
            );
        }

        // // 验证alias唯一性
        // if (!empty($params['alias'])) {
        //     $dupliAlias = $this->where('org_id', $orgId)
        //         ->where('alias', $params['alias'])
        //         ->where('id', '<>', $projectId)
        //         ->exists();
        //     if ($dupliAlias) {
        //         throw new AppException(
        //             ErrorCode::INVALID_PARAMS,
        //             '别名已被使用，请更改'
        //         );
        //     }
        // } elseif ($project->alias) {
        //     $params['alias'] = $project->alias;
        // } else {
        //     $params['alias'] = $this->calcuDefaultAlias($orgId, $params['title'], $projectId);
        // }

        $previousBuildClusterId = (int) $project->build_cluster_id;
        if ((bool) $project->develop) {
            $project->build_cluster_id = $this->validBuildCluster(
                $orgId, $groupId, (int) ($params['build_cluster_id'] ?? 0)
            );
            [$repositoryType, $repositoryProvider, $repositoryUrl] = $this->checkRepositoryParams($params);
        } else {
            $project->build_cluster_id = 0;
            $repositoryType = 0;
            $repositoryProvider = 0;
            $repositoryUrl = '';
        }

        $params['image_name'] = trim((string) ($params['image_name'] ?? '')) ?: (string) $project->alias;

        // 镜像名称校验
        if (!empty($params['image_name'])) {
            if (strpos($params['image_name'], 'o-') === 0) {
                throw new AppException(400, '镜像名称不能以o-开头');
            }
            // 不能与其他项目一样
            $dupliImage =$this->where('org_id', $orgId)
                ->where('image_name', $params['image_name'])
                ->where('id', '<>', $projectId)
                ->exists();
            if ($dupliImage) {
                throw new AppException(400, '镜像名称已被其他项目使用，请更改');
            }
        }

        // 镜像仓库校验
        $registryIds = $this->validRegistries($orgId, $groupId, $params['registries']);
        $activePipelineRegistries = [];
        $pipelines = Pipeline::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('project_id', $projectId)->where('archived_at', 0)
            ->get(['id', 'title', 'definition']);
        foreach ($pipelines as $pipeline) {
            $registryId = (int) (((array) $pipeline->definition)['output']['registry_id'] ?? 0);
            if ($registryId > 0 && ! in_array($registryId, $registryIds, true)) {
                $activePipelineRegistries[] = sprintf('%s (#%d)', (string) $pipeline->title, $registryId);
            }
        }
        if ($activePipelineRegistries !== []) {
            throw new AppException(409, '以下活动 Pipeline 仍使用将被移除的 Registry，请先修改或归档：'
                . implode('、', $activePipelineRegistries));
        }

        // 更新项目
        $project->title = $params['title'];
        // $project->alias = $params['alias']; // 不允许更新alias
        $project->desc = $params['desc'];
        $project->default_port = $params['default_port'];
        $project->image_name = $params['image_name'];
        try {
            Db::transaction(function () use (
                $project, $repositoryType, $repositoryProvider, $repositoryUrl, $registryIds, $previousBuildClusterId,
                $params
            ): void {
                if ((bool) $project->develop) {
                    $this->lockBuildClusterGrant(
                        (int) $project->org_id, (int) $project->group_id, (int) $project->build_cluster_id
                    );
                }
                $project->save();
                if ($previousBuildClusterId !== (int) $project->build_cluster_id) {
                    Pipeline::where('org_id', (int) $project->org_id)->where('group_id', (int) $project->group_id)
                        ->where('project_id', (int) $project->id)->where('archived_at', 0)->update([
                            'cluster_id' => (int) $project->build_cluster_id,
                            'version' => Db::raw('version + 1'),
                            'updated_at' => time(),
                        ]);
                }
                $this->syncRegistries((int) $project->org_id, (int) $project->id, $registryIds);
                /** @var ProjectRepositoryService $repositoryService */
                $repositoryService = $this->getInstance(ProjectRepositoryService::class);
                $repositoryService->saveForProject($project, $repositoryType, $repositoryProvider, $repositoryUrl);
                if ((bool) $project->develop) {
                    $this->getInstance(ProjectBuildProfileService::class)->save(
                        $project,
                        (int) ($params['_uid'] ?? 0),
                        (array) ($params['build_profile'] ?? [])
                    );
                }
            });
        } catch (QueryException $e) {
            $this->throwProjectIdentityConflict($e);
        }

        $project->setVisible([
            'id', 'org_id', 'group_id', 'title', 'alias', 'desc', 'default_port', 'image_name',
            'build_cluster_id',
        ]);

        return $project;
    }

    /**
     * 获取删除项目资源概览.
     */
    public function getDeleteOverview($orgId, $groupId, $projectId)
    {
        $project = $this->where('id', $projectId)
            ->where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->select('id', 'org_id', 'group_id')
            ->first();
        if (empty($project)) {
            throw new AppException(404, '项目不存在');
        }

        $instances = ProjectRuntime::where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('project_id', $projectId)
            ->count();
        $routes = ProjectRoute::where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('project_id', $projectId)
            ->count();
        $runtimes = ProjectRuntime::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('project_id', $projectId)->get();
        $gatewayRoutes = $this->gatewayVhostCount($runtimes, $orgId);
        $artifacts = BuildArtifact::where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('project_id', $projectId)
            ->count();
        $pipelines = Pipeline::where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('project_id', $projectId)
            ->count();
        $pipelineHooks = PipelineGithook::where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('project_id', $projectId)
            ->count();
        $members = ProjectMember::where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('project_id', $projectId)
            ->count();
        $builds = Build::where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('project_id', $projectId)
            ->count();
        $deploys = ProjectRelease::where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('project_id', $projectId)
            ->count();
        $monitoringRecords = ProjectRuntimeMetric::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('project_id', $projectId)->count()
            + ProjectRuntimeEvent::where('org_id', $orgId)->where('group_id', $groupId)
                ->where('project_id', $projectId)->count()
            + ProjectAlert::where('org_id', $orgId)->where('group_id', $groupId)
                ->where('project_id', $projectId)->count()
            + ProjectAuditLog::where('org_id', $orgId)->where('group_id', $groupId)
                ->where('project_id', $projectId)->count();
        $secrets = PipelineSecret::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('project_id', $projectId)->count()
            + BuildSecret::where('org_id', $orgId)->where('project_id', $projectId)->count()
            + ProjectReleaseSecret::where('org_id', $orgId)->where('project_id', $projectId)->count()
            + ProjectConfiguration::where('org_id', $orgId)->where('group_id', $groupId)
                ->where('project_id', $projectId)->where('kind', ProjectConfiguration::KIND_SECRET)->count();
        $configurations = ProjectConfiguration::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('project_id', $projectId)->count();

        return [
            'instances' => $instances,
            'routes' => $routes,
            'gateway_routes' => $gatewayRoutes,
            'artifacts' => $artifacts,
            'pipelines' => $pipelines,
            'githooks' => $pipelineHooks,
            'members' => $members,
            'builds' => $builds,
            'deploys' => $deploys,
            'monitoring_records' => $monitoringRecords,
            'secrets' => $secrets,
            'configurations' => $configurations,
        ];
    }

    /**
     * 删除项目.
     */
    public function deleteProject($uid, $orgId, $groupId, $projectId)
    {
        $project = $this->where('id', $projectId)
            ->where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->select('id', 'title', 'org_id', 'group_id', 'creator')
            ->first();
        if (empty($project)) {
            throw new AppException(404, '项目不存在');
        }
        $projectProfile = $project->toArray();
        $projectProfile['repository_url'] = (string) ProjectRepository::where('org_id', $orgId)
            ->where('group_id', $groupId)->where('project_id', $projectId)->value('clone_url');

        if (ProjectRelease::where('org_id', $orgId)->where('group_id', $groupId)->where('project_id', $projectId)
            ->whereIn('status', [ProjectRelease::STATUS_PENDING, ProjectRelease::STATUS_DEPLOYING])->exists()) {
            throw new AppException(409, '项目存在等待或正在执行的发布，请等待发布结束后再删除');
        }
        if (ProjectRelease::where('org_id', $orgId)->where('group_id', $groupId)->where('project_id', $projectId)
            ->where('status', ProjectRelease::STATUS_FAILED)
            ->where(function ($query): void {
                $query->whereNull('result')
                    ->orWhereRaw("JSON_EXTRACT(`result`, '$.failure_reconciled_at') IS NULL");
            })->exists()) {
            throw new AppException(409, '项目存在尚未完成远端资源对账的失败发布，请等待集群恢复并完成治理后再删除');
        }

        $runtimes = ProjectRuntime::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('project_id', $projectId)->get();
        $gatewayRouteCount = $this->gatewayVhostCount($runtimes, $orgId);
        if ($gatewayRouteCount > 0) {
            throw new AppException(
                409,
                sprintf(
                    '项目仍被 %d 条 Web 网关路由引用，请先在“资源 → 集群 → Web 网关”中删除或改绑路由',
                    $gatewayRouteCount
                )
            );
        }

        // 在删除数据库记录前停止 BuildKit Runner；队列中的任务会因 Build 已进入取消状态而安全退出。
        /** @var ProjectBuildService $buildService */
        $buildService = $this->getInstance(ProjectBuildService::class);
        $activeBuilds = Build::where('org_id', $orgId)->where('group_id', $groupId)->where('project_id', $projectId)
            ->whereIn('status', [Build::STATUS_PENDING_RUN, Build::STATUS_RUNNING])->get(['id']);
        foreach ($activeBuilds as $activeBuild) {
            $buildService->cancel($orgId, $groupId, $projectId, (int) $activeBuild->id);
        }
        if (Build::where('org_id', $orgId)->where('group_id', $groupId)->where('project_id', $projectId)
            ->where('executor', 'buildkit')
            ->whereIn('status', [Build::STATUS_SUCCESS, Build::STATUS_FAILED, Build::STATUS_CANCELED])
            ->where(function ($query): void {
                $query->whereNull('result')
                    ->orWhereRaw("JSON_EXTRACT(`result`, '$.runner_reconciled_at') IS NULL");
            })->exists()) {
            throw new AppException(409, '项目存在尚未完成远端资源对账的构建任务，请等待集群恢复并完成治理后再删除');
        }

        // 成员会在事务中删除，通知对象必须提前冻结。
        $notifyRecipients = ProjectMember::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('project_id', $projectId)
            ->whereIn('role', [ProjectMember::ROLE_DIRECTOR])
            ->pluck('uid')->map(static fn ($memberUid): int => (int) $memberUid)->all();

        /** @var ProjectReleaseService $releaseService */
        $releaseService = $this->getInstance(ProjectReleaseService::class);
        $instanceCount = $runtimes->count();
        foreach ($runtimes as $runtime) {
            // Reuse the same lock, ownership-label checks and Secret cleanup as
            // an explicit Runtime deletion. Never bypass the managed-resource guard.
            $releaseService->removeRuntime($orgId, $groupId, $projectId, (int) $runtime->id);
        }

        try {
            Db::beginTransaction();
            ProjectRoute::where('org_id', $orgId)->where('group_id', $groupId)->where('project_id', $projectId)->delete();
            ProjectRuntimeMetric::where('org_id', $orgId)->where('group_id', $groupId)->where('project_id', $projectId)->delete();
            ProjectRuntimeEvent::where('org_id', $orgId)->where('group_id', $groupId)->where('project_id', $projectId)->delete();
            ProjectAlert::where('org_id', $orgId)->where('group_id', $groupId)->where('project_id', $projectId)->delete();
            ProjectAlertRule::where('org_id', $orgId)->where('group_id', $groupId)->where('project_id', $projectId)->delete();
            ProjectAuditLog::where('org_id', $orgId)->where('group_id', $groupId)->where('project_id', $projectId)->delete();
            ProjectRetentionPolicy::where('org_id', $orgId)->where('group_id', $groupId)->where('project_id', $projectId)->delete();
            ProjectRuntime::where('org_id', $orgId)->where('group_id', $groupId)->where('project_id', $projectId)->delete();
            $releaseIds = ProjectRelease::where('org_id', $orgId)->where('group_id', $groupId)
                ->where('project_id', $projectId)->pluck('id')->toArray();
            if ($releaseIds !== []) {
                ProjectReleaseSecret::whereIn('release_id', $releaseIds)->delete();
                ProjectReleaseConfig::whereIn('release_id', $releaseIds)->delete();
            }
            ProjectRelease::where('org_id', $orgId)->where('group_id', $groupId)->where('project_id', $projectId)->delete();
            ProjectConfiguration::where('org_id', $orgId)->where('group_id', $groupId)->where('project_id', $projectId)->delete();
            BuildArtifact::where('org_id', $orgId)->where('group_id', $groupId)->where('project_id', $projectId)->delete();

            PipelineSecret::where('org_id', $orgId)->where('group_id', $groupId)->where('project_id', $projectId)->delete();
            BuildSecret::where('org_id', $orgId)->where('project_id', $projectId)->delete();

            Pipeline::where('org_id', $orgId)
                ->where('group_id', $groupId)
                ->where('project_id', $projectId)
                ->delete();

            // 删除Githook
            PipelineGithook::where('org_id', $orgId)
                ->where('group_id', $groupId)
                ->where('project_id', $projectId)
                ->delete();

            // 删除项目成员
            ProjectMember::where('org_id', $orgId)
                ->where('group_id', $groupId)
                ->where('project_id', $projectId)
                ->delete();

            // 删除构建记录
            Build::where('org_id', $orgId)
                ->where('group_id', $groupId)
                ->where('project_id', $projectId)
                ->delete();

            ProjectRegistryRel::where('org_id', $orgId)->where('project_id', $projectId)->delete();
            WorkspaceProjectRepository::where('org_id', $orgId)->where('group_id', $groupId)
                ->where('project_id', $projectId)->delete();
            ProjectRepository::where('org_id', $orgId)->where('group_id', $groupId)->where('project_id', $projectId)->delete();
            ProjectBuildProfileRevision::where('org_id', $orgId)->where('group_id', $groupId)
                ->where('project_id', $projectId)->delete();
            ProjectBuildProfile::where('org_id', $orgId)->where('group_id', $groupId)
                ->where('project_id', $projectId)->delete();

            // 删除项目
            $project->delete();

            Db::commit();
        } catch (Throwable $e) {
            try {
                Db::rollBack();
            } catch (Throwable) {
                // beginTransaction itself may have failed; preserve the original exception.
            }

            $this->logger->error('catch unknown exception on deleteProject', [
                'params' => [
                    'uid' => $uid,
                    'orgId' => $orgId,
                    'groupId' => $groupId,
                    'projectId' => $projectId,
                ],
                'exception' => Functions::exceptionContext($e),
            ]);

            throw $e;
        }

        // 发送删除通知.
        $this->sendDeleteNotify($uid, $projectProfile, $instanceCount, $notifyRecipients);
    }

    private function gatewayVhostCount(iterable $runtimes, int $orgId): int
    {
        $targetsByCluster = [];
        foreach ($runtimes as $runtime) {
            $targetsByCluster[(int) $runtime->cluster_id][] = ProjectServiceIdentity::runtimeDockerName($runtime);
        }
        $count = 0;
        foreach ($targetsByCluster as $clusterId => $targets) {
            $count += GatewayVhost::where('org_id', $orgId)->where('cluster_id', $clusterId)
                ->whereIn('target_service', array_values(array_unique($targets)))->count();
        }
        return $count;
    }

    /**
     * 发送删除通知.
     */
    protected function sendDeleteNotify($operator, $project, $instanceCount, array $memberRecipients = [])
    {
        try {
            // 消息接收人
            $tos = [(int) $project['creator'], (int) $operator, ...$memberRecipients];
            $tos = array_unique($tos);

            $org = Org::where('id', $project['org_id'])
                ->select('id', 'title')
                ->first();
            $group = Group::where('id', $project['group_id'])
                ->select('id', 'title')
                ->first();
            $operatorInfo = $this->userInfo($operator, $project['org_id']);

            $params = [
                'org' => $org->toArray(),
                'project' => $project,
                'group' => $group->toArray(),
                'operator_info' => $operatorInfo->toArray(),
                'operated_at' => time(),
                'instance_count' => $instanceCount,
            ];

            /** @var ServicesNotify */
            $notify = $this->getInstance(ServicesNotify::class);
            $notify->send($tos, 'project_delete', $params);
        } catch (Throwable $e) {
            $this->logger->warning('send project delete notify failed', [
                'params' => [
                    'operator' => $operator,
                    'orgId' => $project['org_id'],
                    'groupId' => $project['group_id'],
                    'projectId' => $project['id'],
                    'instanceCount' => $instanceCount,
                ],
                'exception' => Functions::exceptionContext($e),
            ]);
        }
    }

    /**
     * 简单列表.
     * @param int $orgId
     * @param int $groupId
     * @param null|string $keyword
     */
    public function simpleList($uid, $orgId, $groupId = null, $keyword = null)
    {
        $builder = $this->where('org_id', $orgId)
            ->select('id', 'group_id', 'title', 'alias')
            ->with('group:id,title,alias')
            ->limit(20);
        if ($groupId) {
            $builder->where('group_id', $groupId);
        }

        // ------------------- 权限过滤 begin -----------------------
        $roles = Context::get('roles', [0, 0]);
        if (!in_array((int) $roles[0], [OrgMember::ROLE_MANAGER])) {
            // 非组织管理员进行项目过滤
            if (!in_array((int) $roles[1], [GroupMember::ROLE_DIRECTOR])) {
                // 非项目管理员进行项目过滤
                $builder->where(function ($query) use ($uid, $orgId, $groupId) {
                    $projectIds = ProjectMember::where('org_id', $orgId)
                        ->where('group_id', $groupId)
                        ->where('uid', $uid)
                        ->pluck('project_id')
                        ->toArray();
                    $query->orWhereIn('id', $projectIds);
                });
            }
        }
        // ------------------- 权限过滤 end -----------------------

        if ($keyword) {
            $builder->where(function ($query) use ($keyword) {
                $query->where('title', 'like', "%{$keyword}%");
                if (is_numeric($keyword)) {
                    $query->orWhere('id', (int) $keyword);
                } else {
                    $query->orWhere('alias', $keyword);
                }
            });
        }
        return $builder->get();
    }

    /**
     * 获取创建/编辑项目属性.
     */
    public function getCreateProps($uid, $orgId, ?int $groupId = null)
    {
        $clusterIds = $groupId !== null && $groupId > 0
            ? GroupResourceGrant::clusterIds((int) $orgId, $groupId)
            : [];
        $props = [
            'registry_push' => $groupId === null ? null : $this->getPushRegistry((int) $orgId, $groupId),
            'build_clusters' => Cluster::where('org_id', $orgId)
                ->whereIn('id', $clusterIds)
                ->whereIn('orchestrator_type', Cluster::buildableOrchestrators())
                ->where('status', Cluster::STATUS_READY)
                ->select('id', 'title', 'status', 'orchestrator_type')->orderBy('id')->get(),
        ];

        return $props;
    }

    /**
     * 获取组织默认镜像推送registry.
     */
    protected function getPushRegistry(int $orgId, int $groupId)
    {
        $registry = Registry::where('org_id', $orgId)
            ->where('is_push', 1)
            ->select('id', 'proto', 'address', 'namespace', 'remark')
            ->first();
        if ($registry === null) {
            return null;
        }
        $namespace = RegistryGroupGrant::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('registry_id', (int) $registry->getRawOriginal('id'))->value('namespace');
        if (! is_string($namespace) || $namespace === '') {
            return null;
        }
        $registry->credential_namespace = (string) $registry->namespace;
        $registry->namespace = $namespace;
        return $registry;
    }

    /**
     * 验证registry.
     */
    protected function validRegistries(int $orgId, int $groupId, $ids)
    {
        if (empty($ids)) {
            return [];
        }

        $registryIds = [];
        foreach ($ids as $id) {
            $registryIds[] = Functions::decodeID($id, true, true);
        }

        $grants = RegistryGroupGrant::where('org_id', $orgId)->where('group_id', $groupId)
            ->whereIn('registry_id', $registryIds)->get(['registry_id', 'namespace'])->keyBy('registry_id');
        $registries = Registry::where('org_id', $orgId)
            ->whereIn('id', $grants->keys()->all() ?: [0])
            ->select('id', 'address', 'namespace', 'username', 'remark')
            ->get();

        $exists = [];
        foreach ($registries as $registry) {
            $grant = $grants->get((int) $registry->getRawOriginal('id'));
            if ($grant === null || empty($grant->namespace)) {
                throw new AppException(
                    ErrorCode::REGISTRY_EMPTY_NAMESPACE_AS_PUSH,
                    sprintf('当前项目组在镜像仓库 %s 上未设置 namespace', $registry['address'])
                );
            }
            $exists[$registry->getOri('id')] = true;
        }

        $notExists = [];
        foreach ($registryIds as $registryId) {
            if (!isset($exists[$registryId])) {
                $notExists[] = $registryId;
            }
        }
        if (!empty($notExists)) {
            throw new AppException(
                ErrorCode::REGISTRY_NOT_FOUND_FOR_PROJECT,
                sprintf('镜像仓库 %s 不存在', implode(',', $notExists))
            );
        }

        return $registryIds;
    }

    private function syncRegistries(int $orgId, int $projectId, array $registryIds): void
    {
        ProjectRegistryRel::where('org_id', $orgId)->where('project_id', $projectId)->delete();
        $now = time();
        foreach (array_values(array_unique(array_map('intval', $registryIds))) as $registryId) {
            ProjectRegistryRel::create([
                'org_id' => $orgId,
                'project_id' => $projectId,
                'registry_id' => $registryId,
                'created_at' => $now,
            ]);
        }
    }

    private function throwProjectIdentityConflict(QueryException $exception): void
    {
        if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
            throw $exception;
        }

        $message = $exception->getMessage();
        if (str_contains($message, 'uk_project_group_title')) {
            throw new AppException(ErrorCode::PROJECT_TITLE_DUPLICATE, ErrorCode::getMessage(ErrorCode::PROJECT_TITLE_DUPLICATE));
        }
        if (str_contains($message, 'uk_project_org_alias')) {
            throw new AppException(ErrorCode::INVALID_PARAMS, '别名已被使用，请更改');
        }
        if (str_contains($message, 'uk_project_org_image_name')) {
            throw new AppException(400, '镜像名称已被其他项目使用，请更改');
        }

        throw new AppException(409, '项目标识发生并发冲突，请刷新后重试');
    }

    /**
     * 计算默认alias.
     */
    protected function calcuDefaultAlias($orgId, $title, $projectId = null)
    {
        return Alias::calcuDefault($this->where('org_id', $orgId), $title, $projectId);
    }
}
