<?php

namespace App\Services\Project;

use App\Exception\AppException;
use App\Model\Build;
use App\Model\BuildSecret;
use App\Model\Pipeline;
use App\Model\PipelineSecret;
use App\Services\Encrypt\CredentialCipher;
use Hyperf\DbConnection\Db;

class PipelineSecretService
{
    private const MAX_VALUE_BYTES = 1024 * 1024;

    private const MAX_BUILD_SECRET_BYTES = 16 * 1024 * 1024;

    public function __construct(private CredentialCipher $cipher) {}

    public function list(int $orgId, int $groupId, int $projectId, int $pipelineId): array
    {
        $pipeline = $this->pipeline($orgId, $groupId, $projectId, $pipelineId);
        $declared = $this->declared($pipeline);
        $configured = PipelineSecret::where('pipeline_id', $pipelineId)
            ->where('org_id', $orgId)->get()->keyBy('name');
        return array_map(static function (string $name) use ($configured): array {
            /** @var PipelineSecret|null $secret */
            $secret = $configured->get($name);
            return [
                'name' => $name,
                'configured' => $secret !== null,
                'updated_at' => (int) ($secret?->updated_at ?? 0),
            ];
        }, $declared);
    }

    public function put(
        int $uid,
        int $orgId,
        int $groupId,
        int $projectId,
        int $pipelineId,
        string $name,
        string $value
    ): array {
        $name = trim($name);
        if ($value === '' || strlen($value) > self::MAX_VALUE_BYTES) {
            throw new AppException(422, '构建 Secret 不能为空且不能超过 1 MiB');
        }
        return Db::transaction(function () use ($uid, $orgId, $groupId, $projectId, $pipelineId, $name, $value): array {
            $pipeline = $this->pipeline($orgId, $groupId, $projectId, $pipelineId, true);
            if (! in_array($name, $this->declared($pipeline), true)) {
                throw new AppException(422, '该 Secret 未在 Pipeline build.secrets 中声明');
            }
            $now = time();
            $secret = PipelineSecret::firstOrNew(['pipeline_id' => $pipelineId, 'name' => $name]);
            if (! $secret->exists) {
                $secret->org_id = $orgId;
                $secret->group_id = $groupId;
                $secret->project_id = $projectId;
                $secret->creator = $uid;
                $secret->created_at = $now;
            }
            $secret->encrypted_value = $this->cipher->encrypt($value);
            $secret->value_hash = hash('sha256', $value);
            $secret->value_size = strlen($value);
            $secret->updated_at = $now;
            $secret->save();
            return $this->list($orgId, $groupId, $projectId, $pipelineId);
        });
    }

    public function delete(int $orgId, int $groupId, int $projectId, int $pipelineId, string $name): array
    {
        return Db::transaction(function () use ($orgId, $groupId, $projectId, $pipelineId, $name): array {
            $this->pipeline($orgId, $groupId, $projectId, $pipelineId, true);
            PipelineSecret::where('pipeline_id', $pipelineId)->where('org_id', $orgId)
                ->where('group_id', $groupId)->where('project_id', $projectId)->where('name', trim($name))->delete();
            return $this->list($orgId, $groupId, $projectId, $pipelineId);
        });
    }

    public function freeze(Build $build, Pipeline $pipeline): array
    {
        $declared = $this->declared($pipeline);
        if ($declared === []) {
            return [];
        }
        $configured = PipelineSecret::where('pipeline_id', (int) $pipeline->id)
            ->where('org_id', (int) $build->org_id)->whereIn('name', $declared)->get()->keyBy('name');
        $missing = array_values(array_filter($declared, static fn (string $name): bool => ! $configured->has($name)));
        if ($missing !== []) {
            throw new AppException(422, '请先配置 Pipeline 构建 Secret：' . implode(', ', $missing));
        }
        $totalBytes = (int) $configured->sum('value_size');
        if ($totalBytes > self::MAX_BUILD_SECRET_BYTES) {
            throw new AppException(422, '本次构建 Secret 总大小不能超过 16 MiB');
        }
        $hashes = [];
        foreach ($declared as $name) {
            /** @var PipelineSecret $secret */
            $secret = $configured->get($name);
            BuildSecret::create([
                'org_id' => (int) $build->org_id, 'project_id' => (int) $build->project_id,
                'build_id' => (int) $build->id, 'name' => $name,
                'encrypted_value' => (string) $secret->encrypted_value,
                'value_hash' => (string) $secret->value_hash, 'value_size' => (int) $secret->value_size,
                'created_at' => time(),
            ]);
            $hashes[$name] = (string) $secret->value_hash;
        }
        return $hashes;
    }

    public function runtimeValues(Build $build): array
    {
        $values = [];
        $totalBytes = 0;
        foreach (BuildSecret::where('build_id', (int) $build->id)->where('org_id', (int) $build->org_id)->get() as $secret) {
            $value = $this->cipher->decrypt((string) $secret->encrypted_value);
            $totalBytes += strlen($value);
            if ($totalBytes > self::MAX_BUILD_SECRET_BYTES) {
                throw new AppException(422, '本次构建 Secret 总大小不能超过 16 MiB');
            }
            $values[(string) $secret->name] = $value;
        }
        return $values;
    }

    public function purgeBuild(int $buildId): void
    {
        BuildSecret::where('build_id', $buildId)->delete();
    }

    public function prune(Pipeline $pipeline): void
    {
        $query = PipelineSecret::where('pipeline_id', (int) $pipeline->id);
        $declared = $this->declared($pipeline);
        if ($declared === []) {
            $query->delete();
            return;
        }
        $query->whereNotIn('name', $declared)->delete();
    }

    public function purgePipeline(int $pipelineId): void
    {
        PipelineSecret::where('pipeline_id', $pipelineId)->delete();
    }

    private function pipeline(
        int $orgId,
        int $groupId,
        int $projectId,
        int $pipelineId,
        bool $lockForUpdate = false
    ): Pipeline
    {
        /** @var Pipeline|null $pipeline */
        $query = Pipeline::where('id', $pipelineId)->where('org_id', $orgId)
            ->where('group_id', $groupId)->where('project_id', $projectId)->where('archived_at', 0);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $pipeline = $query->first();
        if ($pipeline === null) {
            throw new AppException(404, '流水线不存在');
        }
        return $pipeline;
    }

    private function declared(Pipeline $pipeline): array
    {
        return array_values(array_unique(array_map(
            'strval', (array) (((array) $pipeline->definition)['build']['secrets'] ?? [])
        )));
    }
}
