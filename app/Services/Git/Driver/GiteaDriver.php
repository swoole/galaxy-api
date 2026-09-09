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

class GiteaDriver extends AbstractDriver
{
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
     * @see https://docs.gitea.io/en-us/api-usage/
     * @param string $group
     * @param string $domain
     * @param string $token
     * @return array
     */
    public function getBranches($group, $domain, $token, $orgId)
    {
        $url = sprintf('%s/api/v1/repos/%s/branches', $this->baseUrl((string) $domain), $group);

        $resp = $this->guzzle->get($url, $this->requestOptions($url, [
            'headers' => $this->authHeaders((string) $token),
        ]));
        if ($resp->getStatusCode() == 500) {
            throw new AppException(500, '服务异常或Git仓库为空');
        }

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
     * @see https://docs.gitea.io/en-us/api-usage/
     * @param string $group
     * @param string $domain
     * @param string $token
     * @return array
     */
    public function getTags($group, $domain, $token, $orgId)
    {
        $url = sprintf('%s/api/v1/repos/%s/tags', $this->baseUrl((string) $domain), $group);

        $resp = $this->guzzle->get($url, $this->requestOptions($url, [
            'headers' => $this->authHeaders((string) $token),
        ]));

        $respJson = $this->resolveJsonResponse($resp);
        if (isset($respJson['code'])) {
            throw new AppException($respJson['code'], $respJson['msg'], $respJson);
        }

        $tags = [];
        foreach ($respJson as $item) {
            $tags[] = [
                'name' => $item['name'],
                'message' => $item['message'],
                'commit_at' => strtotime($item['commit']['created']),
            ];
        }

        return $tags;
    }

    /**
     * 获取Commit记录.
     * @see https://docs.gitea.io/en-us/api-usage/
     * @param string $branch
     * @param string $group
     * @param string $domain
     * @param string $token
     * @return array
     */
    public function getCommits($branch, $group, $domain, $token, $orgId)
    {
        $url = sprintf('%s/api/v1/repos/%s/commits', $this->baseUrl((string) $domain), $group);

        $resp = $this->guzzle->get($url, $this->requestOptions($url, [
            'headers' => $this->authHeaders((string) $token),
            'query' => array_filter([
                'page' => 1,
                'limit' => 50,
                'sha' => $branch,
            ], static fn ($value): bool => $value !== ''),
        ]));

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
        $url = sprintf('%s/api/v1/repos/%s/commits', $this->baseUrl((string) $domain), $group);
        $resp = $this->guzzle->get($url, $this->requestOptions($url, [
            'headers' => $this->authHeaders((string) $token),
            'query' => ['page' => 1, 'limit' => 1],
        ]));
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
        $url = sprintf('%s/api/v1/user/repos', $this->baseUrl((string) $domain));

        $resp = $this->guzzle->get($url, $this->requestOptions($url, [
            'headers' => $this->authHeaders((string) $token),
            'query' => [
                'page' => 1,
                'limit' => 1,
            ],
        ]));

        $respJson = $this->resolveJsonResponse($resp);
        if (isset($respJson['code'])) {
            throw new AppException($respJson['code'], $respJson['msg'], $respJson);
        }
    }

    private function baseUrl(string $endpoint): string
    {
        return rtrim(preg_match('#^https?://#i', $endpoint) ? $endpoint : 'https://' . $endpoint, '/');
    }

    private function authHeaders(string $token): array
    {
        return $token === '' ? [] : ['Authorization' => 'token ' . $token];
    }
}
