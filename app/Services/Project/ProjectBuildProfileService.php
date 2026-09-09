<?php

namespace App\Services\Project;

use App\Exception\AppException;
use App\Model\Project;
use App\Model\ProjectBuildProfile;
use App\Model\ProjectBuildProfileRevision;
use App\Model\DockerfileTemplate;

class ProjectBuildProfileService
{
    public function __construct(private DockerfileTemplateService $templates) {}

    public function save(Project $project, int $uid, array $input): ProjectBuildProfile
    {
        $source = (string) ($input['dockerfile_source'] ?? 'repository');
        $context = $this->path((string) ($input['build_context'] ?? '.'), true);
        $values = [
            'org_id' => (int) $project->org_id, 'group_id' => (int) $project->group_id, 'project_id' => (int) $project->id,
            'dockerfile_source' => $source, 'build_context' => $context,
            'repository_dockerfile_path' => 'Dockerfile', 'template_id' => 0,
            'template_key' => '', 'template_version' => '', 'options' => [], 'build_args' => [],
            'rendered_dockerfile' => '', 'rendered_checksum' => '', 'rendered_dockerignore' => '',
            'dockerignore_profile' => 'default-secure-v1', 'dockerignore_checksum' => '',
            'renderer_version' => '1', 'updated_by' => $uid, 'updated_at' => time(),
        ];
        if ($source === ProjectBuildProfile::SOURCE_REPOSITORY) {
            $values['repository_dockerfile_path'] = $this->path(
                (string) ($input['repository_dockerfile_path'] ?? 'Dockerfile'),
                false
            );
        } elseif ($source === ProjectBuildProfile::SOURCE_TEMPLATE) {
            $rendered = $this->templates->render(
                (string) ($input['template_key'] ?? ''),
                (array) ($input['options'] ?? [])
            );
            $values += [];
            $values['template_key'] = (string) $rendered['template']['key'];
            $values['template_version'] = (string) $rendered['template']['version'];
            $values['template_id'] = (int) DockerfileTemplate::where('source', 'builtin')->where('org_id', 0)
                ->where('template_key', $values['template_key'])
                ->where('template_version', $values['template_version'])->where('status', 'active')->value('id');
            if ($values['template_id'] <= 0) {
                throw new AppException(409, 'Dockerfile 模板索引尚未初始化');
            }
            $values['options'] = (array) $rendered['options'];
            $values['build_args'] = (array) $rendered['build_args'];
            $values['rendered_dockerfile'] = (string) $rendered['dockerfile'];
            $values['rendered_checksum'] = (string) $rendered['dockerfile_checksum'];
            $values['rendered_dockerignore'] = (string) $rendered['dockerignore'];
            $values['dockerignore_profile'] = (string) $rendered['dockerignore_profile'];
            $values['dockerignore_checksum'] = (string) $rendered['dockerignore_checksum'];
            $values['renderer_version'] = (string) $rendered['renderer_version'];
        } else {
            throw new AppException(422, 'Dockerfile 来源只支持代码仓库或模板库');
        }
        /** @var ProjectBuildProfile $profile */
        $profile = ProjectBuildProfile::firstOrNew([
            'org_id' => (int) $project->org_id, 'group_id' => (int) $project->group_id, 'project_id' => (int) $project->id,
        ]);
        if (! $profile->exists) {
            $values['created_by'] = $uid;
            $values['created_at'] = time();
        }
        $profile->fill($values);
        $profile->save();
        $this->createRevision($profile, $uid, 'save');
        return $profile;
    }

    public function profile(int $orgId, int $groupId, int $projectId): ?ProjectBuildProfile
    {
        return ProjectBuildProfile::where('org_id', $orgId)->where('group_id', $groupId)->where('project_id', $projectId)->first();
    }

    public function snapshot(int $orgId, int $groupId, int $projectId): array
    {
        $profile = $this->profile($orgId, $groupId, $projectId);
        if ($profile === null) {
            throw new AppException(422, '项目尚未配置 Dockerfile 来源');
        }
        $snapshot = $profile->toArray();
        $this->validateSnapshot($snapshot);
        return $snapshot;
    }

    public function revisions(int $orgId, int $groupId, int $projectId): array
    {
        return ProjectBuildProfileRevision::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('project_id', $projectId)->orderByDesc('revision')->limit(50)->get()->toArray();
    }

    public function upgradePreview(int $orgId, int $groupId, int $projectId): array
    {
        $profile = $this->profile($orgId, $groupId, $projectId);
        if ($profile === null || (string) $profile->dockerfile_source !== ProjectBuildProfile::SOURCE_TEMPLATE) {
            throw new AppException(409, '只有模板构建配置可以检查模板升级');
        }
        $rendered = $this->templates->render((string) $profile->template_key, (array) $profile->options);
        $old = (string) $profile->rendered_dockerfile;
        $new = (string) $rendered['dockerfile'];
        return [
            'changed' => ! hash_equals((string) $profile->rendered_checksum, (string) $rendered['dockerfile_checksum']),
            'current' => [
                'template_key' => (string) $profile->template_key,
                'template_version' => (string) $profile->template_version,
                'renderer_version' => (string) $profile->renderer_version,
                'checksum' => (string) $profile->rendered_checksum,
            ],
            'latest' => [
                'template_key' => (string) $rendered['template']['key'],
                'template_version' => (string) $rendered['template']['version'],
                'renderer_version' => (string) $rendered['renderer_version'],
                'checksum' => (string) $rendered['dockerfile_checksum'],
            ],
            'diff' => $this->lineDiff($old, $new),
            'rendered' => $rendered,
        ];
    }

    public function rollback(Project $project, int $uid, int $revisionId): ProjectBuildProfile
    {
        /** @var ProjectBuildProfileRevision|null $revision */
        $revision = ProjectBuildProfileRevision::where('id', $revisionId)
            ->where('org_id', (int) $project->org_id)->where('group_id', (int) $project->group_id)
            ->where('project_id', (int) $project->id)->first();
        if ($revision === null) {
            throw new AppException(404, '构建配置历史版本不存在');
        }
        $snapshot = (array) $revision->profile;
        $allowed = [
            'dockerfile_source', 'build_context', 'repository_dockerfile_path', 'template_id',
            'template_key', 'template_version', 'options', 'build_args', 'rendered_dockerfile',
            'rendered_checksum', 'rendered_dockerignore', 'dockerignore_profile',
            'dockerignore_checksum', 'renderer_version',
        ];
        /** @var ProjectBuildProfile|null $profile */
        $profile = $this->profile((int) $project->org_id, (int) $project->group_id, (int) $project->id);
        if ($profile === null) {
            throw new AppException(404, '项目构建配置不存在');
        }
        $profile->fill(array_intersect_key($snapshot, array_flip($allowed)) + [
            'updated_by' => $uid, 'updated_at' => time(),
        ]);
        $this->validateSnapshot($profile->toArray());
        $profile->save();
        $this->createRevision($profile, $uid, 'rollback:' . (int) $revision->revision);
        return $profile;
    }

    private function createRevision(ProjectBuildProfile $profile, int $uid, string $reason): void
    {
        $snapshot = $this->revisionSnapshot($profile);
        $checksum = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        /** @var ProjectBuildProfileRevision|null $latest */
        $latest = ProjectBuildProfileRevision::where('org_id', (int) $profile->org_id)
            ->where('group_id', (int) $profile->group_id)->where('project_id', (int) $profile->project_id)
            ->orderByDesc('revision')->lockForUpdate()->first();
        if ($latest !== null && hash_equals((string) $latest->profile_checksum, $checksum)) {
            return;
        }
        ProjectBuildProfileRevision::create([
            'org_id' => (int) $profile->org_id, 'group_id' => (int) $profile->group_id,
            'project_id' => (int) $profile->project_id, 'revision' => (int) ($latest?->revision ?? 0) + 1,
            'profile' => $snapshot, 'profile_checksum' => $checksum, 'reason' => $reason,
            'created_by' => $uid, 'created_at' => time(),
        ]);
    }

    private function revisionSnapshot(ProjectBuildProfile $profile): array
    {
        return array_intersect_key($profile->toArray(), array_flip([
            'dockerfile_source', 'build_context', 'repository_dockerfile_path', 'template_id',
            'template_key', 'template_version', 'options', 'build_args', 'rendered_dockerfile',
            'rendered_checksum', 'rendered_dockerignore', 'dockerignore_profile',
            'dockerignore_checksum', 'renderer_version',
        ]));
    }

    private function validateSnapshot(array $profile): void
    {
        $source = (string) ($profile['dockerfile_source'] ?? '');
        if ($source === ProjectBuildProfile::SOURCE_REPOSITORY) {
            $this->path((string) ($profile['build_context'] ?? '.'), true);
            $this->path((string) ($profile['repository_dockerfile_path'] ?? ''), false);
            return;
        }
        if ($source !== ProjectBuildProfile::SOURCE_TEMPLATE
            || ! hash_equals(hash('sha256', (string) ($profile['rendered_dockerfile'] ?? '')), (string) ($profile['rendered_checksum'] ?? ''))
            || ! hash_equals(hash('sha256', (string) ($profile['rendered_dockerignore'] ?? '')), (string) ($profile['dockerignore_checksum'] ?? ''))) {
            throw new AppException(409, '历史构建配置完整性校验失败');
        }
    }

    private function lineDiff(string $old, string $new): string
    {
        if ($old === $new) {
            return '';
        }
        $oldLines = preg_split('/\R/', $old) ?: [];
        $newLines = preg_split('/\R/', $new) ?: [];
        $prefix = 0;
        while (isset($oldLines[$prefix], $newLines[$prefix]) && $oldLines[$prefix] === $newLines[$prefix]) {
            ++$prefix;
        }
        $oldSuffix = count($oldLines) - 1;
        $newSuffix = count($newLines) - 1;
        while ($oldSuffix >= $prefix && $newSuffix >= $prefix
            && $oldLines[$oldSuffix] === $newLines[$newSuffix]) {
            --$oldSuffix;
            --$newSuffix;
        }
        $start = max(0, $prefix - 3);
        $lines = ['--- current', '+++ latest', '@@ line ' . ($prefix + 1) . ' @@'];
        for ($i = $start; $i < $prefix; ++$i) {
            $lines[] = ' ' . $oldLines[$i];
        }
        for ($i = $prefix; $i <= $oldSuffix; ++$i) {
            $lines[] = '-' . $oldLines[$i];
        }
        for ($i = $prefix; $i <= $newSuffix; ++$i) {
            $lines[] = '+' . $newLines[$i];
        }
        $contextEnd = min(count($oldLines) - 1, $oldSuffix + 3);
        for ($i = $oldSuffix + 1; $i <= $contextEnd; ++$i) {
            $lines[] = ' ' . $oldLines[$i];
        }
        return implode("\n", $lines);
    }

    private function path(string $path, bool $allowDot): string
    {
        if (str_starts_with(trim($path), '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', trim($path))) {
            throw new AppException(422, '构建路径必须是仓库内的相对路径');
        }
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($allowDot && ($path === '' || $path === '.')) {
            return '.';
        }
        if ($path === '' || str_contains('/' . $path . '/', '/../') || str_starts_with($path, '.git/')) {
            throw new AppException(422, '构建路径必须是仓库内的相对路径');
        }
        if (! preg_match('#^[A-Za-z0-9._/@+-]+(?:/[A-Za-z0-9._/@+-]+)*$#', $path)) {
            throw new AppException(422, '构建路径包含不支持的字符');
        }
        return $path;
    }
}
