<?php

namespace App\Services\GitWebhook\Interfaces;

use Hyperf\HttpServer\Contract\RequestInterface;

interface HandlerInterface
{
    /**
     * 是否匹配当前handler.
     */
    public static function matched(RequestInterface $request) : bool;

    public function isPing() : bool;

    public function isPush() : bool;

    public function isTagPush() : bool;

    public function isTagDelete() : bool;

    public function getEvent() : ?string;

    public function getRequest() : RequestInterface;

    public function get(string $key, $default = null);

    /**
     * 仓库名称，例如 code-galaxy.
     */
    public function getProjectName() : ?string;

    /**
     * 仓库完整名称，例如 cloud-platform/code-galaxy.
     */
    public function getProjectFullName() : ?string;

    /**
     * 仓库HttpUrl.
     */
    public function getProjectHttpUrl() : ?string;

    /**
     * 仓库SshUrl.
     */
    public function getProjectSshUrl() : ?string;

    /**
     * 获取当前CommitID.
     */
    public function getCommitID() : ?string;

    /**
     * 获取当前Commit Message.
     */
    public function getCommitMessage() : ?string;

    /**
     * 获取当前Tag Message.
     */
    public function getTagMessage() : ?string;

    /**
     * 获取当前用户用户名.
     */
    public function getPusherUsername() : ?string;

    /**
     * 获取当前用户邮箱.
     */
    public function getPusherEmail() : ?string;

    public function getBranch(): ?string;

    public function getTag() : ?string;
}
