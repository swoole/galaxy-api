<?php

namespace App\Services\Git\Driver;

use App\Exception\AppException;

/**
 * CodeUp SDK 的最后版本依赖旧版 Darabonba OpenAPI，与当前阿里云 SDK 冲突。
 * 保留驱动入口以便对历史数据返回明确错误，后续可改为直接调用 CodeUp HTTP API。
 */
class CodeUpDriver extends AbstractDriver
{
    private function unsupported(): never
    {
        throw new AppException(1, 'CodeUp 集成已停用：官方 PHP SDK 与当前阿里云 SDK 不兼容');
    }

    public function getBranches($group, $domain, $token, $orgId)
    {
        $this->unsupported();
    }

    public function getTags($group, $domain, $token, $orgId)
    {
        $this->unsupported();
    }

    public function getCommits($branch, $group, $domain, $token, $orgId)
    {
        $this->unsupported();
    }

    public function getCommitCount($group, $domain, $token, $orgId): int
    {
        $this->unsupported();
    }

    public function test($domain, $token)
    {
        $this->unsupported();
    }
}
