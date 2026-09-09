<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Model;

use App\Constants\ErrorCode;
use App\Exception\AppException;
use App\Services\Encrypt\CredentialCipher;
use App\Support\GitReference;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;

/**
 * @property int $id
 * @property int $org_id
 * @property int $group_id
 * @property int $project_id
 * @property int $pipeline_id
 * @property string $branch
 * @property int $status
 * @property int $auto_deploy
 * @property string $auto_deploy_target
 * @property int $creator
 * @property int $created_at
 */
class PipelineGithook extends Model
{
    use TraitRelationCreatorInfo;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected ?string $table = 'pipeline_githook';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    // protected $fillable = [];
    protected array $guarded = ['id'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected array $casts = [
        'id' => 'integer', 'org_id' => 'integer', 'group_id' => 'integer', 'project_id' => 'integer',
        'pipeline_id' => 'integer', 'status' => 'integer', 'auto_deploy' => 'integer',
        'creator' => 'integer', 'created_at' => 'integer',
    ];

    /**
     * 列表.
     * @param int $orgId
     * @param int $groupId
     * @param int $projectId
     */
    public function list($orgId, $groupId, $projectId)
    {
        return $this->where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('project_id', $projectId)
            ->with('pipeline')
            ->with([
                'creatorInfo' => function ($query) use ($orgId) {
                    $query->where('org_id', $orgId);
                }
            ])
            ->select(
                'id',
                'org_id',
                'group_id',
                'project_id',
                'pipeline_id',
                'branch',
                'status',
                'auto_deploy',
                'auto_deploy_target',
                'creator',
                'created_at'
            )->get();
    }

    /**
     * 创建.
     * @param int $uid
     * @param int $orgId
     * @param int $groupId
     * @param int $projectId
     * @param int $pipelineId
     * @param string $branch
     * @param int $status
     */
    public function createHook(
        $uid,
        $orgId,
        $groupId,
        $projectId,
        $pipelineId,
        $branch,
        $status,
        int $autoDeploy = 0,
        array $autoDeployTargets = []
    ) {
        $branch = $this->normalizeBranch((string) $branch);
        $project = Project::where('id', $projectId)
            ->where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->select('id', 'title')
            ->first();
        if (empty($project)) {
            throw new AppException(
                ErrorCode::PROJECT_NO_EXIST,
                '项目不存在'
            );
        }

        $targets = $this->validateAutoDeployTargets(
            (int) $orgId,
            (int) $groupId,
            (int) $projectId,
            $autoDeploy === 1,
            $autoDeployTargets
        );
        return Db::transaction(function () use (
            $uid, $orgId, $groupId, $projectId, $pipelineId, $branch, $status, $autoDeploy, $targets
        ) {
            // Serialize against archival so a hook can never be inserted after
            // the target pipeline has been retired.
            $this->getInstance(Pipeline::class)
                ->getOrThrow($pipelineId, $orgId, $groupId, $projectId, ['id', 'title'], true);

            $exists = $this->where('org_id', $orgId)
                ->where('group_id', $groupId)
                ->where('project_id', $projectId)
                ->where('pipeline_id', $pipelineId)
                ->where('branch', $branch)
                ->exists();
            if ($exists) {
                throw new AppException(1, '已存在相同配置，请勿重复创建');
            }

            $hook = PipelineGithook::create([
                'org_id' => $orgId,
                'group_id' => $groupId,
                'project_id' => $projectId,
                'pipeline_id' => $pipelineId,
                'branch' => $branch,
                'status' => $status,
                'auto_deploy' => $autoDeploy === 1 ? 1 : 0,
                'auto_deploy_target' => implode(',', $targets),
                'creator' => $uid,
                'created_at' => time(),
            ]);
            $hook->load('pipeline')->load([
                'creatorInfo' => function ($query) use ($orgId) {
                    $query->where('org_id', $orgId);
                }
            ]);
            return $hook;
        });
    }

    /**
     * 更新.
     * @param int $uid
     * @param int $hookId
     * @param int $orgId
     * @param int $groupId
     * @param int $projectId
     * @param int $pipelineId
     * @param string $branch
     * @param int $status
     */
    public function updateHook(
        $uid,
        $hookId,
        $orgId,
        $groupId,
        $projectId,
        $pipelineId,
        $branch,
        $status,
        int $autoDeploy = 0,
        array $autoDeployTargets = []
    ) {
        $branch = $this->normalizeBranch((string) $branch);
        $targets = $this->validateAutoDeployTargets(
            (int) $orgId,
            (int) $groupId,
            (int) $projectId,
            $autoDeploy === 1,
            $autoDeployTargets
        );
        return Db::transaction(function () use (
            $hookId, $orgId, $groupId, $projectId, $pipelineId, $branch, $status, $autoDeploy, $targets
        ) {
            $this->getInstance(Pipeline::class)
                ->getOrThrow($pipelineId, $orgId, $groupId, $projectId, ['id', 'title'], true);

            $hook = $this->where('id', $hookId)
                ->where('org_id', $orgId)->where('group_id', $groupId)->where('project_id', $projectId)
                ->lockForUpdate()->first();
            if (empty($hook)) {
                throw new AppException(1, 'Git钩子配置不存在');
            }
            $exists = $this->where('org_id', $orgId)
                ->where('group_id', $groupId)->where('project_id', $projectId)
                ->where('pipeline_id', $pipelineId)->where('branch', $branch)
                ->where('id', '<>', $hookId)->exists();
            if ($exists) {
                throw new AppException(1, '已存在相同配置，请勿重复创建');
            }

            $hook->pipeline_id = $pipelineId;
            $hook->branch = $branch;
            $hook->status = $status;
            $hook->auto_deploy = $autoDeploy === 1 ? 1 : 0;
            $hook->auto_deploy_target = implode(',', $targets);
            $hook->save();
            $hook->load('pipeline')->load([
                'creatorInfo' => function ($query) use ($orgId) {
                    $query->where('org_id', $orgId);
                }
            ]);
            return $hook;
        });
    }

    /**
     * 删除.
     * @param int $uid
     * @param int $hookId
     * @param int $orgId
     * @param int $groupId
     * @param int $projectId
     */
    public function deleteHook(
        $uid,
        $hookId,
        $orgId,
        $groupId,
        $projectId
    ) {
        $hook = $this->where('id', $hookId)
            ->where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('project_id', $projectId)
            ->first();
        if (empty($hook)) {
            throw new AppException(
                1,
                'Git钩子配置不存在'
            );
        }

        $hook->delete();
    }

    /**
     * 修改状态.
     * @param int $uid
     * @param int $hookId
     * @param int $orgId
     * @param int $groupId
     * @param int $projectId
     * @param int $status
     */
    public function setStatus(
        $uid,
        $hookId,
        $orgId,
        $groupId,
        $projectId,
        $status
    ) {
        $hook = $this->where('id', $hookId)
            ->where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('project_id', $projectId)
            ->first();
        if (empty($hook)) {
            throw new AppException(
                1,
                'Git钩子配置不存在'
            );
        }

        $hook->status = $status;
        $hook->save();
    }

    /**
     * 获取Githook地址.
     */
    public function getHookUrl($orgId, $groupId, $projectId)
    {
        return sprintf(
            '%s/webhooks/githook/%s/%s',
            rtrim((string) config('webhook_base_url'), '/'),
            $orgId,
            $projectId
        );
    }

    public function webhookSecretConfigured(int $orgId, int $groupId, int $projectId): bool
    {
        return ProjectRepository::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('project_id', $projectId)->where('webhook_secret_hash', '<>', '')->exists();
    }

    public function rotateWebhookSecret(int $orgId, int $groupId, int $projectId): string
    {
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->saveWebhookSecret($orgId, $groupId, $projectId, $secret);
        return $secret;
    }

    private function ensureWebhookSecret(int $orgId, int $groupId, int $projectId): string
    {
        /** @var ProjectRepository|null $repository */
        $repository = ProjectRepository::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('project_id', $projectId)->first();
        if ($repository === null) {
            throw new AppException(422, '项目代码仓库尚未完成初始化');
        }
        if ((string) $repository->webhook_secret_encrypted === '') {
            return $this->rotateWebhookSecret($orgId, $groupId, $projectId);
        }
        /** @var CredentialCipher $cipher */
        $cipher = $this->getInstance(CredentialCipher::class);
        return $cipher->decrypt((string) $repository->webhook_secret_encrypted);
    }

    private function saveWebhookSecret(int $orgId, int $groupId, int $projectId, string $secret): void
    {
        /** @var ProjectRepository|null $repository */
        $repository = ProjectRepository::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('project_id', $projectId)->first();
        if ($repository === null) {
            throw new AppException(422, '项目代码仓库尚未完成初始化');
        }
        /** @var CredentialCipher $cipher */
        $cipher = $this->getInstance(CredentialCipher::class);
        $repository->webhook_secret_encrypted = $cipher->encrypt($secret);
        $repository->webhook_secret_hash = hash('sha256', $secret);
        $repository->webhook_status = 'secret-configured';
        $repository->webhook_checked_at = time();
        $repository->updated_at = time();
        $repository->save();
    }

    /**
     * 模型关联: pipeline.
     */
    public function pipeline()
    {
        return $this->hasOne(Pipeline::class, 'id', 'pipeline_id')->select('id', 'title');
    }

    private function normalizeBranch(string $branch): string
    {
        $branch = trim($branch);
        if ($branch === '' || $branch === ':tag') {
            return $branch;
        }
        if (! GitReference::validBranch($branch)) {
            throw new AppException(422, 'Git 分支名称不合法');
        }
        return $branch;
    }

    /** @return array<int, int> */
    private function validateAutoDeployTargets(
        int $orgId,
        int $groupId,
        int $projectId,
        bool $enabled,
        array $targets
    ): array {
        if (! $enabled) {
            return [];
        }
        $targets = array_values(array_unique(array_map('intval', $targets)));
        $targets = array_values(array_filter($targets, static fn (int $id): bool => $id > 0));
        if ($targets === []) {
            throw new AppException(422, '开启自动部署时必须选择至少一个运行实例');
        }
        $existing = ProjectRuntime::where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('project_id', $projectId)
            ->whereIn('id', $targets)
            ->where('runtime_ref', '<>', '')
            ->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        sort($targets);
        sort($existing);
        if ($targets !== $existing) {
            throw new AppException(422, '自动部署目标不存在、已销毁或不属于当前项目');
        }
        if (strlen(implode(',', $targets)) > 255) {
            throw new AppException(422, '自动部署目标数量过多');
        }
        return $targets;
    }

}
