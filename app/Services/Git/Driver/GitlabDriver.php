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

class GitlabDriver extends AbstractDriver
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
     * @see https://docs.gitlab.com/ee/api/branches.html
     * @param string $group
     * @param string $domain
     * @param string $token
     * @return array
     */
    public function getBranches($group, $domain, $token, $orgId)
    {
        $url = sprintf('%s/api/v4/projects/%s/repository/branches', $this->baseUrl((string) $domain), urlencode($group));

        $resp = $this->guzzle->get($url, $this->requestOptions($url, [
            'headers' => $this->authHeaders((string) $token),
        ]));

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
     * @see https://docs.gitlab.com/ee/api/tags.html
     * @param string $group
     * @param string $domain
     * @param string $token
     * @return array
     */
    public function getTags($group, $domain, $token, $orgId)
    {
        $url = sprintf('%s/api/v4/projects/%s/repository/tags', $this->baseUrl((string) $domain), urlencode($group));

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
                'commit_at' => strtotime($item['commit']['created_at']),
            ];
        }

        return $tags;
    }

    /**
     * 获取Commit记录.
     * @see https://docs.gitlab.com/ee/api/commits.html
     * @param string $branch
     * @param string $group
     * @param string $domain
     * @param string $token
     * @return array
     */
    public function getCommits($branch, $group, $domain, $token, $orgId)
    {
        $url = sprintf('%s/api/v4/projects/%s/repository/commits', $this->baseUrl((string) $domain), urlencode($group));

        $resp = $this->guzzle->get($url, $this->requestOptions($url, [
            'headers' => $this->authHeaders((string) $token),
            'query' => array_filter([
                'ref_name' => $branch,
            ], static fn ($value): bool => $value !== ''),
        ]));

        $respJson = $this->resolveJsonResponse($resp);
        if (isset($respJson['code'])) {
            throw new AppException($respJson['code'], $respJson['msg'], $respJson);
        }

        $commits = [];
        foreach ($respJson as $item) {
            $commits[] = [
                'hash' => $item['id'],
                'message' => $item['message'],
                'commit_at' => strtotime($item['created_at']),
            ];
        }

        return $commits;
    }

    public function getCommitCount($group, $domain, $token, $orgId): int
    {
        $url = sprintf('%s/api/v4/projects/%s/repository/commits', $this->baseUrl((string) $domain), urlencode($group));
        $resp = $this->guzzle->get($url, $this->requestOptions($url, [
            'headers' => $this->authHeaders((string) $token),
            'query' => ['page' => 1, 'per_page' => 1],
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
        $url = sprintf('%s/api/v4/projects', $this->baseUrl((string) $domain));

        $resp = $this->guzzle->get($url, $this->requestOptions($url, [
            'headers' => $this->authHeaders((string) $token),
            'query' => [
                'owned' => 'true',
                'simple' => 'true',
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
        return $token === '' ? [] : ['PRIVATE-TOKEN' => $token];
    }
}
