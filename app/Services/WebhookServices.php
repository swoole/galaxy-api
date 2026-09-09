<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Services;

use App\Model\Project;
use App\Model\ProjectRepository;
use App\Model\PipelineGithook;
use App\Services\GitWebhook\Payload;
use App\Services\Project\ProjectBuildService;
use App\Services\Project\ProjectMutationLock;
use App\Services\Project\RepositoryIdentityService;
use App\Services\Encrypt\CredentialCipher;
use App\Exception\AppException;
use App\Model\OrgMember;
use App\Model\User;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\Logger\LoggerFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class WebhookServices
{
    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    public function __construct(
        ContainerInterface $container,
        private ProjectBuildService $builds,
        private CredentialCipher $cipher,
        private ProjectMutationLock $projectMutationLock,
        private RepositoryIdentityService $repositoryIdentity
    )
    {
        $this->container = $container;
        $this->logger = $container->get(LoggerFactory::class)->get('webhook.deployment');
    }

    /**
     * 处理githooks.
     */
    public function handleGithook(RequestInterface $request, $orgId, $projectId)
    {
        return $this->projectMutationLock->synchronized(
            (int) $orgId,
            (int) $projectId,
            function () use ($request, $orgId, $projectId): ?string {
                // Verify after obtaining the project lock so a concurrent
                // rotation cannot let a request authenticated with the old
                // secret continue after the rotation has completed.
                $this->verifySignature($request, (int) $orgId, (int) $projectId);
                return $this->dispatchGithook($request, (int) $orgId, (int) $projectId);
            }
        );
    }

    private function dispatchGithook(RequestInterface $request, int $orgId, int $projectId): ?string
    {
        $payload = new Payload($request);
        $handler = $payload->getHandler();
        if ($handler->isPing()) {
            return 'pong';
        } elseif ($handler->isPush()) {
            $branch = $handler->getBranch();
            $commitId = $handler->getCommitID();
            $commitMsg = mb_substr($handler->getCommitMessage() ?: '', 0, 255);
            if (empty($branch) || empty($commitId)) {
                throw new AppException(1, '缺少分支和Commit信息');
            }

            $project = $this->validGithookUrl($orgId, $projectId, [
                $handler->getProjectSshUrl(),
                $handler->getProjectHttpUrl(),
            ]);
            $uid = $this->tryCalcuGithookUid($orgId, $handler->getPusherUsername(), $handler->getPusherEmail());
            $hooks = PipelineGithook::where('org_id', $project['org_id'])
                ->where('group_id', $project['group_id'])
                ->where('project_id', $project['id'])
                ->where('status', 1)
                ->where(function ($query) use ($branch) {
                    $query->where('branch', '')->orWhere('branch', $branch);
                })
                ->select('id', 'pipeline_id', 'creator')
                ->get();
            foreach ($hooks as $hook) {
                $this->builds->create(
                    $uid ?: $hook['creator'],
                    $project['org_id'],
                    $project['group_id'],
                    $project['id'],
                    $hook['pipeline_id'],
                    $commitMsg,
                    $branch,
                    $commitId,
                    $hook['id'],
                    $hook['creator']
                );
            }
        } elseif ($handler->isTagPush()) {
            $branch = $handler->getTag();
            $commitId = $handler->getCommitID();
            $commitMsg = mb_substr($handler->getTagMessage() ?: ('Git Tag ' . $branch), 0, 255);
            if (empty($branch) || empty($commitId)) {
                throw new AppException(1, 'Tag Push 缺少Tag或Commit信息');
            }

            $project = $this->validGithookUrl($orgId, $projectId, [
                $handler->getProjectSshUrl(),
                $handler->getProjectHttpUrl(),
            ]);
            $uid = $this->tryCalcuGithookUid($orgId, $handler->getPusherUsername(), $handler->getPusherEmail());
            $hooks = PipelineGithook::where('org_id', $project['org_id'])
                ->where('group_id', $project['group_id'])
                ->where('project_id', $project['id'])
                ->where('status', 1)
                ->where('branch', ':tag')
                ->select('id', 'pipeline_id', 'creator')
                ->get();
            foreach ($hooks as $hook) {
                $this->builds->create(
                    $uid ?: $hook['creator'],
                    $project['org_id'],
                    $project['group_id'],
                    $project['id'],
                    $hook['pipeline_id'],
                    $commitMsg,
                    $branch,
                    $commitId,
                    $hook['id'],
                    $hook['creator']
                );
            }
        }
        return null;
    }

    private function verifySignature(RequestInterface $request, int $orgId, int $projectId): void
    {
        /** @var ProjectRepository|null $repository */
        $repository = ProjectRepository::where('org_id', $orgId)->where('project_id', $projectId)->first();
        if ($repository === null || (string) $repository->webhook_secret_encrypted === '') {
            throw new AppException(401, 'Git Webhook 尚未配置签名 Secret');
        }
        $secret = $this->cipher->decrypt((string) $repository->webhook_secret_encrypted);
        $body = (string) $request->getBody();
        $expected = hash_hmac('sha256', $body, $secret);
        $signatures = array_filter([
            strtolower(trim($request->getHeaderLine('X-Gitea-Signature'))),
            strtolower(trim($request->getHeaderLine('X-Gogs-Signature'))),
            preg_replace('/^sha256=/i', '', trim($request->getHeaderLine('X-Hub-Signature-256'))),
        ], static fn ($value): bool => is_string($value) && $value !== '');
        foreach ($signatures as $signature) {
            if (preg_match('/^[a-f0-9]{64}$/', $signature) && hash_equals($expected, $signature)) {
                return;
            }
        }
        $gitlabToken = $request->getHeaderLine('X-Gitlab-Token');
        if ($gitlabToken !== '' && hash_equals($secret, $gitlabToken)) {
            return;
        }
        throw new AppException(401, 'Git Webhook 签名验证失败');
    }

    /**
     * 验证githook是否合法.
     */
    /** @param array<int, string|null> $repositoryUrls */
    protected function validGithookUrl($orgId, $projectId, array $repositoryUrls) : Project
    {
        $project = Project::where('id', $projectId)
            ->where('org_id', $orgId)
            ->select('id', 'org_id', 'group_id', 'title')
            ->first();
        $cloneUrl = $project === null ? '' : (string) ProjectRepository::where('org_id', $orgId)
            ->where('project_id', $projectId)->value('clone_url');
        if (empty($project) || $cloneUrl === '' || ! $this->repositoryIdentity->matches($cloneUrl, $repositoryUrls)) {
            throw new AppException(1, '仓库地址与Githook配置不符');
        }

        return $project;
    }

    /**
     * 尝试计算githook触发用户ID.
     */
    protected function tryCalcuGithookUid(int $orgId, ?string $username, ?string $email) : ?int
    {
        $username = trim((string) $username);
        $email = trim((string) $email);
        if ($username === '' && $email === '') {
            return null;
        }
        if ($username === '') {
            $uid = User::where('email', $email)->value('id');
        } elseif ($email === '') {
            $uid = User::where('username', $username)->value('id');
        } else {
            $uid = User::where('email', $email)->orWhere('username', $username)->value('id');
        }

        if (empty($uid)) {
            return null;
        }

        // 判断是否在组织内
        $exists = OrgMember::where('org_id', $orgId)
            ->where('uid', $uid)
            ->exists();
        if ($exists) {
            return (int) $uid;
        }

        return null;
    }
}
