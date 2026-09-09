<?php

namespace App\Services\Project;

use App\Exception\AppException;
use App\Model\ProjectRepository;
use App\Services\Git\GitService;
use Hyperf\Redis\Redis;

class RepositoryReferenceService
{
    private const BRANCH_TTL = 120;
    private const COMMIT_TTL = 60;
    private const COMMIT_COUNT_TTL = 300;

    public function __construct(
        private GitService $git,
        private Redis $redis
    ) {}

    public function branches(int $uid, int $orgId, int $groupId, int $projectId, bool $refresh = false): array
    {
        $repository = $this->repository($orgId, $groupId, $projectId);
        return $this->remember(
            $this->key($repository, ''),
            self::BRANCH_TTL,
            $refresh,
            fn (): array => $this->git->getBranches(
                $uid, (string) $repository->clone_url, (int) $repository->provider, $orgId
            )
        );
    }

    public function tags(int $uid, int $orgId, int $groupId, int $projectId, bool $refresh = false): array
    {
        $repository = $this->repository($orgId, $groupId, $projectId);
        return $this->remember(
            $this->key($repository, '__tags__'),
            self::BRANCH_TTL,
            $refresh,
            fn (): array => $this->git->getTags(
                $uid, (string) $repository->clone_url, (int) $repository->provider, $orgId
            )
        );
    }

    public function commits(
        int $uid,
        int $orgId,
        int $groupId,
        int $projectId,
        string $branch,
        bool $refresh = false
    ): array
    {
        $repository = $this->repository($orgId, $groupId, $projectId);
        return $this->remember(
            $this->key($repository, $branch),
            self::COMMIT_TTL,
            $refresh,
            fn (): array => $this->git->getCommits(
                $uid,
                (string) $repository->clone_url,
                (int) $repository->provider,
                $branch,
                $orgId
            )
        );
    }

    public function commitCount(
        int $uid,
        int $orgId,
        int $groupId,
        int $projectId,
        bool $refresh = false
    ): int {
        $repository = $this->repository($orgId, $groupId, $projectId);
        $key = $this->key($repository, '__commit_count__');
        if (! $refresh) {
            $cached = $this->redis->get($key);
            if (is_string($cached) && ctype_digit($cached)) {
                return (int) $cached;
            }
        }
        $count = $this->git->getCommitCount(
            $uid,
            (string) $repository->clone_url,
            (int) $repository->provider,
            $orgId
        );
        $this->redis->setex($key, self::COMMIT_COUNT_TTL, (string) $count);
        return $count;
    }

    private function repository(int $orgId, int $groupId, int $projectId): ProjectRepository
    {
        /** @var ProjectRepository|null $repository */
        $repository = ProjectRepository::where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('project_id', $projectId)
            ->where('status', ProjectRepository::STATUS_ACTIVE)
            ->first();
        if ($repository === null || trim((string) $repository->clone_url) === '') {
            throw new AppException(422, '项目尚未配置可用的代码仓库');
        }
        return $repository;
    }

    private function key(ProjectRepository $repository, string $branch): string
    {
        $identity = implode('|', [
            (int) $repository->org_id,
            (int) $repository->group_id,
            (int) $repository->project_id,
            (int) $repository->id,
            (int) $repository->provider,
            trim((string) $repository->clone_url),
            $branch,
        ]);
        return 'galaxy:git-references:v1:' . hash('sha256', $identity);
    }

    private function remember(string $key, int $ttl, bool $refresh, callable $resolver): array
    {
        if (! $refresh) {
            $cached = $this->redis->get($key);
            if (is_string($cached) && $cached !== '') {
                $decoded = json_decode($cached, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }
        $result = $resolver();
        $this->redis->setex($key, $ttl, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $result;
    }

    public function mode(int $uid, int $orgId, int $groupId, int $projectId): string
    {
        $this->repository($orgId, $groupId, $projectId);
        return 'provider';
    }

}
