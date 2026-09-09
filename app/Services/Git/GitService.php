<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Services\Git;

use App\Exception\AppException;
use App\Model\GitAuth;
use App\Services\Encrypt\CredentialCipher;
use App\Services\Git\Driver\AbstractDriver;
use App\Services\Git\Driver\CodeUpDriver;
use App\Services\Git\Driver\GiteaDriver;
use App\Services\Git\Driver\GiteeDriver;
use App\Services\Git\Driver\GithubDriver;
use App\Services\Git\Driver\GitlabDriver;
use Psr\Container\ContainerInterface;

class GitService
{
    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * 驱动列表.
     */
    protected $drivers = [
        GitAuth::VENDOR_GITHUB => GithubDriver::class,
        GitAuth::VENDOR_GITEE => GiteeDriver::class,
        GitAuth::VENDOR_GITEA => GiteaDriver::class,
        GitAuth::VENDOR_GITLAB => GitlabDriver::class,
        GitAuth::VENDOR_CODEUP => CodeUpDriver::class,
    ];

    public function __construct(ContainerInterface $container, private CredentialCipher $credentialCipher)
    {
        $this->container = $container;
    }

    /**
     * 获取分支列表.
     * @param int $uid
     * @param string $repo
     * @param int $vendor
     */
    public function getBranches($uid, $repo, $vendor, $orgId)
    {
        [$domain, $group, $token] = $this->getToken($uid, $repo, $vendor);
        $driver = $this->getDriver($vendor);

        return $driver->getBranches($group, $domain, $token, $orgId);
    }

    /**
     * 获取标签列表.
     * @param int $uid
     * @param string $repo
     * @param int $vendor
     */
    public function getTags($uid, $repo, $vendor, $orgId)
    {
        [$domain, $group, $token] = $this->getToken($uid, $repo, $vendor);
        $driver = $this->getDriver($vendor);

        return $driver->getTags($group, $domain, $token, $orgId);
    }

    /**
     * 获取Commits.
     * @param int $uid
     * @param string $repo
     * @param int $vendor
     * @param string $branch
     */
    public function getCommits($uid, $repo, $vendor, $branch, $orgId)
    {
        [$domain, $group, $token] = $this->getToken($uid, $repo, $vendor);
        $driver = $this->getDriver($vendor);

        return $driver->getCommits($branch, $group, $domain, $token, $orgId);
    }

    public function getCommitCount($uid, $repo, $vendor, $orgId): int
    {
        [$domain, $group, $token] = $this->getToken($uid, $repo, $vendor);
        return $this->getDriver($vendor)->getCommitCount($group, $domain, $token, $orgId);
    }

    /**
     * 测试token是否有效.
     * 测试失败会抛出异常.
     * @param int $vendor
     * @param string $domain
     * @param string $token
     */
    public function test($vendor, $domain, $token)
    {
        $driver = $this->getDriver($vendor);

        return $driver->test($domain, $token);
    }

    public function hasAuthorization(int $uid, string $repo, int $vendor): bool
    {
        [$domain, , $origin] = $this->parseRepository($repo);
        if ($domain === '' || $origin === '') {
            return false;
        }
        return GitAuth::where('uid', $uid)->where('vendor', $vendor)
            ->whereIn('domain', array_values(array_unique([$origin, $domain])))->exists();
    }

    /**
     * 获取驱动.
     * @param int $vendor
     * @return AbstractDriver
     */
    protected function getDriver($vendor)
    {
        return $this->container->get($this->drivers[$vendor]);
    }

    /**
     * 获取token.
     * @param int $uid
     * @param string $repo
     * @param int $vendor
     */
    protected function getToken($uid, $repo, $vendor)
    {
        [$domain, $group, $origin] = $this->parseRepository((string) $repo);
        if ($domain === '' || $group === '') {
            throw new AppException(
                1,
                'Git仓库地址不合法'
            );
        }

        // 查询鉴权
        $gitAuth = GitAuth::where('uid', $uid)
            ->where('vendor', $vendor)
            ->whereIn('domain', array_values(array_unique([$origin, $domain])))
            ->select('token')
            ->first();
        // Public repositories can be queried anonymously. Private repository
        // APIs will return an authorization error and the UI already exposes
        // the Git credential configuration entry.
        $token = empty($gitAuth)
            ? ''
            : $this->credentialCipher->decrypt((string) $gitAuth['token']);

        return [$origin, $group, $token];
    }

    private function parseRepository(string $repository): array
    {
        try {
            return GitAuth::parseRepositoryUrl($repository);
        } catch (AppException) {
            return ['', '', ''];
        }
    }
}
