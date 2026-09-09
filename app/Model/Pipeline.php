<?php

namespace App\Model;

use App\Exception\AppException;

/**
 * BuildKit 构建流水线。
 *
 * 流水线只保存执行器无关的构建定义，实际执行由
 * ProjectBuildService 和 BuildKit runner 负责。
 */
class Pipeline extends Model
{
    use TraitRelationCreatorInfo;

    protected ?string $table = 'pipeline';

    protected array $guarded = ['id'];

    protected array $casts = [
        'id' => 'integer',
        'org_id' => 'integer',
        'group_id' => 'integer',
        'project_id' => 'integer',
        'cluster_id' => 'integer',
        'creator' => 'integer',
        'created_at' => 'integer',
        'updated_at' => 'integer',
        'archived_at' => 'integer',
        'is_default' => 'integer',
        'version' => 'integer',
        'definition' => 'array',
    ];

    public function getPipelineInfo(int $id, array $columns = ['id', 'title']): ?self
    {
        /** @var self|null $pipeline */
        $pipeline = self::find($id, $columns);
        return $pipeline;
    }

    /**
     * Git Hook 只能引用当前项目自己的 BuildKit v1 流水线。
     */
    public function getOrThrow(
        int $pipelineId,
        int $orgId,
        int $groupId,
        int $projectId,
        array $fields = ['id', 'title'],
        bool $lockForUpdate = false
    ): self {
        $query = self::where('id', $pipelineId)
            ->where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('project_id', $projectId)
            ->where('archived_at', 0)
            ->where('schema_version', 'v1')
            ->where('runner_kind', 'buildkit');
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        if ($fields !== []) {
            $query->select(...$fields);
        }
        /** @var self|null $pipeline */
        $pipeline = $query->first();
        if ($pipeline === null) {
            throw new AppException(404, '流水线不存在');
        }
        return $pipeline;
    }
}
