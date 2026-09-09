<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Services\Git\Driver;

use App\Support\NetworkEndpoint;
use Hyperf\Stringable\Str;
use Psr\Http\Message\ResponseInterface;

abstract class AbstractDriver
{
    /**
     * 获取分支.
     * @param string $repo
     * @param string $domain
     * @param string $token
     */
    abstract public function getBranches($repo, $domain, $token, $orgId);

    /**
     * 获取标签.
     * @param string $repo
     * @param string $domain
     * @param string $token
     */
    abstract public function getTags($repo, $domain, $token, $orgId);

    /**
     * 获取Commit记录.
     * @param string $branch
     * @param string $repo
     * @param string $domain
     * @param string $token
     */
    abstract public function getCommits($branch, $repo, $domain, $token, $orgId);

    /**
     * 获取默认分支的提交总数.
     */
    abstract public function getCommitCount($repo, $domain, $token, $orgId): int;

    /**
     * 测试token是否有效.
     * 测试失败会抛出异常.
     * @param string $domain
     * @param string $token
     */
    abstract public function test($domain, $token);

    /**
     * 获取仓库名.
     */
    protected function getRepoName(string $repo)
    {
        $repo = preg_replace('/(^.*@)/', '', $repo);
        $name = Str::after($repo, ':');
        if (Str::substrCount($name, '/') >= 2) {
            // git@git.swoole.com:2222/codinghuang/cloud-test-webhook-app.git
            // 出现两次
            $name = Str::after($name, '/');
        }
        return Str::before($name, '.git');
    }

    /**
     * 解析响应.
     */
    protected function resolveJsonResponse(ResponseInterface $resp)
    {
        $statusCode = $resp->getStatusCode();
        $respStr = (string) $resp->getBody()->getContents();
        if ($statusCode >= 400) {
            return [
                'code' => $statusCode,
                'msg' => $resp->getReasonPhrase(),
                'data' => [
                    'resp' => $respStr,
                ],
            ];
        }

        return json_decode($respStr, true);
    }

    protected function requestOptions(string $url, array $options = []): array
    {
        if (NetworkEndpoint::isLocalOrPrivate($url)) {
            $options['proxy'] = '';
        }
        return $options;
    }

    /**
     * Git 服务商通常通过总数响应头或 Link 分页头返回记录总数。
     */
    protected function resolvePaginationTotal(ResponseInterface $response, int $fallback): int
    {
        foreach (['X-Total', 'X-Total-Count'] as $header) {
            $value = trim($response->getHeaderLine($header));
            if ($value !== '' && ctype_digit($value)) {
                return (int) $value;
            }
        }

        $link = $response->getHeaderLine('Link');
        if ($link !== '' && preg_match('/<([^>]+)>;\s*rel="last"/i', $link, $matches)) {
            $query = parse_url($matches[1], PHP_URL_QUERY);
            if (is_string($query)) {
                parse_str($query, $params);
                if (isset($params['page']) && is_numeric($params['page'])) {
                    return max(0, (int) $params['page']);
                }
            }
        }

        return max(0, $fallback);
    }
}
