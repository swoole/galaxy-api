<?php

namespace App\Services\Project;

use App\Exception\AppException;
use App\Model\Project;
use App\Model\ProjectRepository;
use App\Model\GitAuth;

class ProjectRepositoryService
{
    public function saveForProject(Project $project, int $type, int $provider, string $cloneUrl): ?ProjectRepository
    {
        $cloneUrl = trim($cloneUrl);
        if ($cloneUrl === '') {
            ProjectRepository::where('project_id', (int) $project->id)->delete();
            return null;
        }

        $now = time();
        /** @var ProjectRepository $repository */
        $repository = ProjectRepository::firstOrNew(['project_id' => (int) $project->id]);
        $identityChanged = $repository->exists && (
            (int) $repository->type !== $type
            || (int) $repository->provider !== $provider
            || trim((string) $repository->clone_url) !== $cloneUrl
        );
        if (! $repository->exists) {
            $repository->created_at = $now;
        }
        $repository->fill([
            'org_id' => (int) $project->org_id,
            'group_id' => (int) $project->group_id,
            'type' => $type,
            'provider' => $provider,
            'clone_url' => $cloneUrl,
            'web_url' => $this->toWebUrl($cloneUrl),
            'external_id' => $identityChanged ? '' : (string) $repository->external_id,
            'status' => ProjectRepository::STATUS_ACTIVE,
            'updated_at' => $now,
        ]);
        if ($identityChanged) {
            // Health and Webhook credentials describe the previous trust
            // boundary. Rotate the secret instead of allowing the old remote
            // repository to keep triggering builds after a repository switch.
            $repository->default_branch = 'main';
            $repository->connection_status = 'unknown';
            $repository->check_error = null;
            $repository->checked_at = 0;
            $repository->webhook_status = 'unknown';
            $repository->webhook_checked_at = 0;
            $repository->webhook_secret_encrypted = null;
            $repository->webhook_secret_hash = '';
        }
        $repository->save();

        return $repository;
    }

    public function profile(int $orgId, int $groupId, int $projectId): array
    {
        /** @var ProjectRepository|null $repository */
        $repository = ProjectRepository::where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('project_id', $projectId)
            ->first();
        if ($repository === null) {
            if (! Project::where('org_id', $orgId)->where('group_id', $groupId)->where('id', $projectId)->exists()) {
                throw new AppException(404, '项目不存在');
            }
            return [];
        }

        $data = $repository->toArray();
        $data['provider_name'] = GitAuth::$vendors[(int) $repository->provider] ?? '未知';
        $data['type_name'] = ProjectRepository::$types[(int) $repository->type] ?? '未知';
        return $data;
    }

    private function toWebUrl(string $cloneUrl): string
    {
        if (preg_match('#^ssh://[^@]+@([^/:]+)(?::\d+)?/(.+?)(?:\.git)?$#', $cloneUrl, $matches)) {
            return 'https://' . $matches[1] . '/' . preg_replace('/\.git$/', '', $matches[2]);
        }
        if (preg_match('#^[^@]+@([^:]+):(.+?)(?:\.git)?$#', $cloneUrl, $matches)) {
            return 'https://' . $matches[1] . '/' . preg_replace('/\.git$/', '', $matches[2]);
        }
        if (preg_match('#^https?://#', $cloneUrl)) {
            return preg_replace('/\.git$/', '', $cloneUrl);
        }
        return '';
    }
}
