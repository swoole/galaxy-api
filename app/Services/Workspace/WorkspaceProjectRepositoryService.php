<?php

namespace App\Services\Workspace;

use App\Exception\AppException;
use App\Model\Cluster;
use App\Model\OrgMember;
use App\Model\Project;
use App\Model\ProjectRepository;
use App\Model\User;
use App\Model\UserProfile;
use App\Model\Workspace;
use App\Model\WorkspaceProjectRepository;
use App\Services\Docker\SwarmContainerExecService;
use Hyperf\DbConnection\Db;
use Hyperf\Database\Model\Collection;
use Throwable;

class WorkspaceProjectRepositoryService
{
    private const REPOSITORY_ROOT = '/workspace/repositories';

    public function __construct(
        private SwarmContainerExecService $exec,
        private WorkspaceProjectRepositoryCredentialService $credential,
        private WorkspaceService $workspaces
    ) {}

    public function profile(int $uid, int $orgId, int $groupId, int $projectId): array
    {
        [$project, $repository] = $this->projectRepository($orgId, $groupId, $projectId);
        $workspaces = Workspace::where('uid', $uid)->where('org_id', $orgId)
            ->where('group_id', $groupId)->orderByDesc('updated_at')->get();
        $workspaceIds = $workspaces->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $mappings = $workspaceIds === [] ? new Collection() : WorkspaceProjectRepository::whereIn('workspace_id', $workspaceIds)
            ->where('project_id', $projectId)->get()->keyBy('workspace_id');
        foreach ($workspaces as $workspace) {
            $mapping = $mappings->get((int) $workspace->id);
            $workspace->setAttribute('repository_mapping', $mapping === null ? null : $this->present($mapping));
        }
        return [
            'project' => $project->only(['id', 'title', 'alias']),
            'repository' => $repository->only([
                'id', 'clone_url', 'web_url', 'default_branch', 'provider', 'connection_status', 'checked_at',
            ]),
            'workspaces' => $workspaces->map(fn (Workspace $workspace): array => $this->presentWorkspace($workspace))->all(),
            'default_workdir' => $this->workdir($project),
        ];
    }

    public function open(
        int $uid,
        int $orgId,
        int $groupId,
        int $projectId,
        int $workspaceId,
        string $branch = ''
    ): array {
        [$project, $repository] = $this->projectRepository($orgId, $groupId, $projectId);
        /** @var Workspace|null $workspace */
        $workspace = Workspace::where('id', $workspaceId)->where('uid', $uid)
            ->where('org_id', $orgId)->where('group_id', $groupId)->first();
        if ($workspace === null) {
            throw new AppException(404, '开发环境不存在或不属于当前用户');
        }
        if ($workspace->status !== Workspace::STATUS_RUNNING || $workspace->runtime_ref === '') {
            throw new AppException(409, '开发环境尚未运行');
        }
        if ($workspace->mode !== Workspace::MODE_WEB_IDE) {
            throw new AppException(409, '只有 Web IDE 模式的开发环境可以打开项目目录');
        }
        $branch = trim($branch) !== '' ? trim($branch) : trim((string) $repository->default_branch);
        if ($branch === '') {
            $branch = 'main';
        }
        if (strlen($branch) > 255 || preg_match('#[\x00-\x20\x7f~^:?*\[\x5c]#', $branch)) {
            throw new AppException(422, 'Git 分支名称不合法');
        }
        $workdir = $this->workdir($project);
        /** @var WorkspaceProjectRepository $mapping */
        $mapping = Db::transaction(function () use (
            $orgId, $groupId, $workspace, $project, $repository, $branch, $workdir
        ): WorkspaceProjectRepository {
            $mapping = WorkspaceProjectRepository::where('workspace_id', (int) $workspace->id)
                ->where('project_id', (int) $project->id)->lockForUpdate()->first();
            if ($mapping === null) {
                $mapping = WorkspaceProjectRepository::create([
                    'org_id' => $orgId,
                    'group_id' => $groupId,
                    'workspace_id' => (int) $workspace->id,
                    'project_id' => (int) $project->id,
                    'repository_id' => (int) $repository->id,
                    'branch' => $branch,
                    'workdir' => $workdir,
                    'status' => WorkspaceProjectRepository::STATUS_PENDING,
                    'error' => null,
                    'last_commit' => '',
                    'last_sync_at' => 0,
                    'credential_transport' => '',
                    'credential_revision' => '',
                    'created_at' => time(),
                    'updated_at' => time(),
                ]);
            } elseif ((int) $mapping->repository_id !== (int) $repository->id) {
                throw new AppException(409, '当前开发环境中的项目仓库映射与项目配置不一致');
            }
            if ($mapping->status === WorkspaceProjectRepository::STATUS_READY
                && (string) $mapping->branch !== $branch) {
                throw new AppException(409, '已 Clone 的 Workspace 映射不能在打开时自动切换分支');
            }
            $mapping->branch = $branch;
            $mapping->status = WorkspaceProjectRepository::STATUS_CLONING;
            $mapping->error = null;
            $mapping->updated_at = time();
            $mapping->save();
            return $mapping;
        });

        try {
            $runtime = $this->credential->reconcile($workspace, $mapping, $repository);
            $cloned = $this->ensureClone($workspace, $mapping, $repository, $runtime);
            $this->configureGitCredential($workspace, $workdir, $runtime);
            $this->ensureGitIdentity($workspace, $workdir);
            $commit = $this->run($workspace, [
                'sudo', '-E', '-u', 'openvscode-server', '--',
                'git', '-C', $workdir, 'rev-parse', 'HEAD',
            ], [], 60);
            $this->assertSuccess($commit, '读取 Workspace 仓库版本失败');
            $mapping->status = WorkspaceProjectRepository::STATUS_READY;
            $mapping->error = null;
            $mapping->last_commit = trim((string) $commit['stdout']);
            $mapping->last_sync_at = time();
            $mapping->updated_at = time();
            $mapping->save();

            $access = $this->workspaces->access($uid, $orgId, $groupId);
            $accessUrl = (string) $access['access_url'];
            $accessUrl .= (str_contains($accessUrl, '?') ? '&' : '?')
                . 'folder=' . rawurlencode($workdir);
            return [
                'mapping' => $this->present($mapping),
                'cloned' => $cloned,
                'access_url' => $accessUrl,
                'workspace_url' => (string) $access['url'],
            ];
        } catch (Throwable $e) {
            $mapping->status = WorkspaceProjectRepository::STATUS_ERROR;
            $mapping->error = mb_substr($e->getMessage(), 0, 2000);
            $mapping->updated_at = time();
            $mapping->save();
            throw $e;
        }
    }

    private function ensureClone(
        Workspace $workspace,
        WorkspaceProjectRepository $mapping,
        ProjectRepository $repository,
        array $runtime
    ): bool {
        $workdir = (string) $mapping->workdir;
        $this->assertManagedPath($workdir);
        $environment = ['GIT_TERMINAL_PROMPT=0'];
        if (($runtime['transport'] ?? 'http') === 'ssh') {
            if (! empty($runtime['ssh_private_key'])) {
                $environment[] = 'GIT_SSH_COMMAND=ssh -i /run/secrets/git-ssh-key -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=/tmp/galaxy-git-known-hosts';
            }
        } elseif (! empty($runtime['token'])) {
            $environment[] = 'GIT_ASKPASS=/usr/local/bin/galaxy-git-askpass';
            $environment[] = 'GALAXY_GIT_USERNAME=' . (string) ($runtime['username'] ?? 'oauth2');
        }

        $existing = $this->run($workspace, [
            'sudo', '-E', '-u', 'openvscode-server', '--',
            'git', '-C', $workdir, 'rev-parse', '--is-inside-work-tree',
        ]);
        if ($existing['exit_code'] === 0) {
            $origin = $this->run($workspace, [
                'sudo', '-E', '-u', 'openvscode-server', '--',
                'git', '-C', $workdir, 'remote', 'get-url', 'origin',
            ]);
            $this->assertSuccess($origin, '读取 Workspace Git Origin 失败');
            if (! hash_equals(trim((string) $repository->clone_url), trim((string) $origin['stdout']))) {
                throw new AppException(409, '目标目录已经存在其他 Git 仓库');
            }
            return false;
        }

        $parent = dirname($workdir);
        $temporary = $parent . '/.clone-' . $mapping->id;
        $this->assertManagedPath($temporary);
        $this->assertSuccess($this->run($workspace, [
            'install', '-d', '-o', 'openvscode-server', '-g', 'openvscode-server', $parent,
        ]), '创建 Workspace 仓库目录失败');
        $this->assertSuccess($this->run($workspace, [
            'rm', '-rf', '--', $temporary,
        ]), '清理 Workspace Clone 临时目录失败');
        $available = $this->run($workspace, ['test', '!', '-e', $workdir]);
        if ($available['exit_code'] !== 0) {
            throw new AppException(409, '目标目录已存在但不是当前项目的 Git 仓库，请先在 Web IDE 中处理该目录');
        }
        $clone = $this->run($workspace, [
            'sudo', '-E', '-u', 'openvscode-server', '--',
            'git', 'clone', '--branch', (string) $mapping->branch, '--single-branch',
            (string) $repository->clone_url, $temporary,
        ], $environment, 300);
        if (($clone['exit_code'] ?? -1) !== 0) {
            $this->run($workspace, ['rm', '-rf', '--', $temporary]);
            $this->assertSuccess($clone, 'Clone 项目代码仓库失败');
        }
        $this->assertSuccess($this->run($workspace, [
            'mv', '--', $temporary, $workdir,
        ]), '安装 Workspace 项目目录失败');
        return true;
    }

    private function configureGitCredential(Workspace $workspace, string $workdir, array $runtime): void
    {
        $transport = (string) ($runtime['transport'] ?? 'http');
        if ($transport === 'ssh' && trim((string) ($runtime['ssh_private_key'] ?? '')) !== '') {
            $sshDirectory = '/workspace/.galaxy-home/.ssh';
            $this->assertSuccess($this->run($workspace, [
                'install', '-d', '-m', '700', '-o', 'openvscode-server', '-g', 'openvscode-server', $sshDirectory,
            ]), '创建 Workspace SSH 目录失败');
            $this->setLocalGitConfig(
                $workspace,
                $workdir,
                'core.sshCommand',
                'ssh -i /run/secrets/git-ssh-key -o IdentitiesOnly=yes -o BatchMode=yes '
                . '-o StrictHostKeyChecking=accept-new '
                . '-o UserKnownHostsFile=' . $sshDirectory . '/known_hosts'
            );
            return;
        }
        if ($transport === 'http' && trim((string) ($runtime['token'] ?? '')) !== '') {
            $username = trim((string) ($runtime['username'] ?? 'oauth2')) ?: 'oauth2';
            $helper = '!f() { if [ "$1" = get ]; then printf \'%s\\n\' '
                . escapeshellarg('username=' . $username)
                . '; printf \'password=\'; cat /run/secrets/git-token; printf \'\\n\'; fi; }; f';
            $this->setLocalGitConfig($workspace, $workdir, 'credential.helper', $helper);
            $this->setLocalGitConfig($workspace, $workdir, 'credential.username', $username);
        }
    }

    private function ensureGitIdentity(Workspace $workspace, string $workdir): void
    {
        /** @var User|null $user */
        $user = User::where('id', (int) $workspace->uid)->first(['id', 'username', 'email']);
        if ($user === null) {
            throw new AppException(404, 'Workspace 用户不存在');
        }
        $realname = trim((string) OrgMember::where('org_id', (int) $workspace->org_id)
            ->where('uid', (int) $workspace->uid)->value('realname'));
        $nickname = trim((string) UserProfile::where('uid', (int) $workspace->uid)->value('nickname'));
        $username = trim((string) $user->username);
        $email = trim((string) $user->email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $email = 'user-' . (int) $workspace->uid . '@users.noreply.codegalaxy.local';
        }
        $name = $realname !== '' ? $realname : ($nickname !== '' ? $nickname : $username);
        if ($name === '') {
            $name = strstr($email, '@', true) ?: 'Galaxy User ' . (int) $workspace->uid;
        }
        $name = trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $name));
        if ($name === '') {
            $name = 'Galaxy User ' . (int) $workspace->uid;
        }

        $this->ensureLocalGitConfig($workspace, $workdir, 'user.name', mb_substr($name, 0, 255));
        $this->ensureLocalGitConfig($workspace, $workdir, 'user.email', $email);
    }

    private function ensureLocalGitConfig(
        Workspace $workspace,
        string $workdir,
        string $key,
        string $value
    ): void {
        $existing = $this->run($workspace, [
            'sudo', '-E', '-u', 'openvscode-server', '--',
            'git', '-C', $workdir, 'config', '--local', '--get', $key,
        ]);
        if (($existing['exit_code'] ?? -1) === 0 && trim((string) $existing['stdout']) !== '') {
            return;
        }
        $configured = $this->run($workspace, [
            'sudo', '-E', '-u', 'openvscode-server', '--',
            'git', '-C', $workdir, 'config', '--local', $key, $value,
        ]);
        $this->assertSuccess($configured, '配置 Workspace Git ' . $key . ' 失败');
    }

    private function setLocalGitConfig(
        Workspace $workspace,
        string $workdir,
        string $key,
        string $value
    ): void {
        $configured = $this->run($workspace, [
            'sudo', '-E', '-u', 'openvscode-server', '--',
            'git', '-C', $workdir, 'config', '--local', $key, $value,
        ]);
        $this->assertSuccess($configured, '配置 Workspace Git ' . $key . ' 失败');
    }

    private function run(
        Workspace $workspace,
        array $command,
        array $environment = [],
        int $timeout = 30
    ): array {
        /** @var Cluster|null $cluster */
        $cluster = Cluster::where('id', (int) $workspace->cluster_id)
            ->where('org_id', (int) $workspace->org_id)
            ->where('orchestrator_type', Cluster::ORCHESTRATOR_DOCKER_SWARM)->first();
        if ($cluster === null) {
            throw new AppException(404, 'Docker Swarm 集群不存在');
        }
        return $this->exec->executeService(
            $cluster,
            (string) $workspace->runtime_ref,
            $command,
            $environment,
            null,
            $timeout
        );
    }

    private function projectRepository(int $orgId, int $groupId, int $projectId): array
    {
        /** @var Project|null $project */
        $project = Project::where('id', $projectId)->where('org_id', $orgId)->where('group_id', $groupId)->first();
        if ($project === null) {
            throw new AppException(404, '项目不存在');
        }
        if (! (bool) $project->develop) {
            throw new AppException(409, '当前项目不使用代码仓库开发模式');
        }
        /** @var ProjectRepository|null $repository */
        $repository = ProjectRepository::where('org_id', $orgId)->where('group_id', $groupId)
            ->where('project_id', $projectId)->where('status', ProjectRepository::STATUS_ACTIVE)->first();
        if ($repository === null || trim((string) $repository->clone_url) === '') {
            throw new AppException(409, '当前项目尚未配置代码仓库');
        }
        return [$project, $repository];
    }

    private function workdir(Project $project): string
    {
        $slug = strtolower(trim((string) $project->alias));
        $slug = preg_replace('/[^a-z0-9._-]+/', '-', $slug) ?: 'project';
        $slug = trim($slug, '.-_');
        return self::REPOSITORY_ROOT . '/' . ($slug === '' ? 'project' : $slug) . '-' . $project->id;
    }

    private function assertManagedPath(string $path): void
    {
        if (! str_starts_with($path, self::REPOSITORY_ROOT . '/') || str_contains($path, '..')) {
            throw new AppException(409, '拒绝操作非 Workspace 管理目录');
        }
    }

    private function assertSuccess(array $result, string $message): void
    {
        if (($result['exit_code'] ?? -1) !== 0) {
            $detail = trim((string) (($result['stderr'] ?? '') ?: ($result['stdout'] ?? '')));
            throw new AppException(502, $message . ($detail === '' ? '' : '：' . mb_substr($detail, 0, 1500)));
        }
    }

    private function present(WorkspaceProjectRepository $mapping): array
    {
        return $mapping->makeHidden(['credential_revision'])->toArray();
    }

    private function presentWorkspace(Workspace $workspace): array
    {
        return [
            'id' => (int) $workspace->id,
            'title' => (string) $workspace->title,
            'status' => (string) $workspace->status,
            'mode' => (string) $workspace->mode,
            'cluster_id' => (int) $workspace->cluster_id,
            'url' => (string) $workspace->url,
            'repository_mapping' => $workspace->getAttribute('repository_mapping'),
        ];
    }
}
