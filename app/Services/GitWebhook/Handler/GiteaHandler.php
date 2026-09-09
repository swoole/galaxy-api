<?php

namespace App\Services\GitWebhook\Handler;

use Hyperf\HttpServer\Contract\RequestInterface;

/**
 * @see https://docs.gitea.io/en-us/webhooks/
 */
class GiteaHandler extends AbstractHandler
{
    /**
     * 是否匹配当前handler.
     */
    public static function matched(RequestInterface $request) : bool
    {
        return $request->hasHeader('x-gitea-event');
    }

    public function isPing() : bool
    {
        // 不存在ping事件，固定返回false

        return false;
    }

    public function isPush() : bool
    {
        return $this->getRequest()->getHeaderLine('x-gitea-event') === 'push'
            && str_starts_with((string) $this->get('ref', ''), 'refs/heads/')
            && $this->get('after') !== '0000000000000000000000000000000000000000';
    }

    public function isTagPush() : bool
    {
        return $this->getRequest()->getHeaderLine('x-gitea-event') === 'push'
            && substr($this->get('ref', ''), 0, 10) === 'refs/tags/'
            && $this->get('after') !== '0000000000000000000000000000000000000000';
    }

    public function isTagDelete() : bool
    {
        return $this->getRequest()->getHeaderLine('x-gitea-event') === 'push'
            && substr($this->get('ref', ''), 0, 10) === 'refs/tags/'
            && $this->get('after') === '0000000000000000000000000000000000000000';
    }

    public function getEvent() : ?string
    {
        if ($this->isPush()) {
            return self::EVENT_PUSH;
        } elseif ($this->isTagPush()) {
            return self::EVENT_TAG_PUSH;
        } elseif ($this->isTagDelete()) {
            return self::EVENT_TAG_DELETE;
        } else {
            return $this->getRequest()->getHeaderLine('x-gitea-event');
        }
    }

    /**
     * 仓库名称，例如 code-galaxy.
     */
    public function getProjectName() : ?string
    {
        return $this->get('repository.name', null);
    }

    /**
     * 仓库完整名称，例如 cloud-platform/code-galaxy.
     */
    public function getProjectFullName() : ?string
    {
        return $this->get('repository.full_name', null);
    }

    /**
     * 仓库HttpUrl.
     */
    public function getProjectHttpUrl() : ?string
    {
        return $this->get('repository.html_url', null);
    }

    /**
     * 仓库SshUrl.
     */
    public function getProjectSshUrl() : ?string
    {
        return $this->get('repository.ssh_url', null);
    }

    /**
     * 获取当前CommitID.
     */
    public function getCommitID() : ?string
    {
        return $this->get('head_commit.id', null) ?: $this->get('after', null);
    }

    /**
     * 获取当前Commit Message.
     */
    public function getCommitMessage() : ?string
    {
        return $this->get('head_commit.message', null);
    }

    /**
     * 获取当前Tag Message.
     */
    public function getTagMessage() : ?string
    {
        return $this->get('head_commit.message', null);
    }

    /**
     * 获取当前用户用户名.
     */
    public function getPusherUsername() : ?string
    {
        return $this->get('pusher.username');
    }

    /**
     * 获取当前用户邮箱.
     */
    public function getPusherEmail() : ?string
    {
        return $this->get('pusher.email');
    }
}
