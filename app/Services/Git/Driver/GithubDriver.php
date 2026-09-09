<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Services\Git\Driver;

use App\Exception\AppException;
use App\Support\GuzzleCreator;
use GuzzleHttp\Client;

class GithubDriver extends AbstractDriver
{
    protected const BASE_URL_API = 'https://api.github.com';

    /**
     * @var Client
     */
    protected $guzzle;

    public function __construct()
    {
        $this->guzzle = GuzzleCreator::create(config('git-service.guzzle', []));
    }

    /**
     * 获取分支.
     * @see https://docs.github.com/en/rest/reference/repos#list-branches
     * @param string $group
     * @param string $domain
     * @param string $token
     * @return array
     */
    public function getBranches($group, $domain, $token, $orgId)
    {
        $url = sprintf('%s/repos/%s/branches', self::BASE_URL_API, $group);

        $resp = $this->guzzle->get($url, $this->authOptions((string) $token));

        $respJson = $this->resolveJsonResponse($resp);
        if (isset($respJson['code'])) {
            throw new AppException($respJson['code'], $respJson['msg'], $respJson);
        }

        $branches = [];
        foreach ($respJson as $item) {
            $branches[] = [
                'name' => $item['name'],
            ];
        }

        return $branches;
    }

    /**
     * 获取标签.
     * @see https://docs.github.com/en/rest/reference/repos#list-repository-tags
     * @param string $group
     * @param string $domain
     * @param string $token
     * @return array
     */
    public function getTags($group, $domain, $token, $orgId)
    {
        $url = sprintf('%s/repos/%s/tags', self::BASE_URL_API, $group);

        $resp = $this->guzzle->get($url, $this->authOptions((string) $token));

        $respJson = $this->resolveJsonResponse($resp);
        if (isset($respJson['code'])) {
            throw new AppException($respJson['code'], $respJson['msg'], $respJson);
        }

        $tags = [];
        foreach ($respJson as $item) {
            $tags[] = [
                'name' => $item['name'],
            ];
        }

        return $tags;
    }

    /**
     * 获取Commit记录.
     * @see https://docs.github.com/en/rest/reference/repos#list-commits
     * @param string $branch
     * @param string $group
     * @param string $domain
     * @param string $token
     * @return array
     */
    public function getCommits($branch, $group, $domain, $token, $orgId)
    {
        $url = sprintf('%s/repos/%s/commits', self::BASE_URL_API, $group);

        $resp = $this->guzzle->get($url, [
            'query' => [
                'page' => 1,
                'per_page' => 50,
                'sha' => $branch,
            ],
            'headers' => $token === '' ? [] : ['Authorization' => 'token ' . $token],
        ]);

        $respJson = $this->resolveJsonResponse($resp);
        if (isset($respJson['code'])) {
            throw new AppException($respJson['code'], $respJson['msg'], $respJson);
        }

        $commits = [];
        foreach ($respJson as $item) {
            $commits[] = [
                'hash' => $item['sha'],
                'message' => $item['commit']['message'],
                'commit_at' => strtotime($item['commit']['committer']['date']),
            ];
        }

        return $commits;
    }

    public function getCommitCount($group, $domain, $token, $orgId): int
    {
        $url = sprintf('%s/repos/%s/commits', self::BASE_URL_API, $group);
        $resp = $this->guzzle->get($url, [
            'query' => ['page' => 1, 'per_page' => 1],
            'headers' => $token === '' ? [] : ['Authorization' => 'token ' . $token],
        ]);
        $items = $this->resolveJsonResponse($resp);
        if (isset($items['code'])) {
            throw new AppException($items['code'], $items['msg'], $items);
        }
        return $this->resolvePaginationTotal($resp, count($items));
    }

    /**
     * 测试token是否有效.
     * 测试失败会抛出异常.
     * @param string $domain
     * @param string $token
     */
    public function test($domain, $token)
    {
        $url = sprintf('%s/user/repos', self::BASE_URL_API);

        $resp = $this->guzzle->get($url, [
            'query' => [
                'page' => 1,
                'per_page' => 1,
            ],
            'headers' => [
                'Authorization' => 'token ' . $token,
            ],
        ]);

        $respJson = $this->resolveJsonResponse($resp);
        if (isset($respJson['code'])) {
            throw new AppException($respJson['code'], $respJson['msg'], $respJson);
        }
    }

    private function authOptions(string $token): array
    {
        return $token === '' ? [] : ['headers' => ['Authorization' => 'token ' . $token]];
    }
}
