<?php

namespace App\Services\GitWebhook\Handler;

use Hyperf\HttpServer\Contract\RequestInterface;

/**
 * @see https://gitee.com/help/articles/4186
 */
class GiteeHandler extends AbstractHandler
{
    /**
     * 是否匹配当前handler.
     */
    public static function matched(RequestInterface $request) : bool
    {
        return $request->hasHeader('x-gitee-event');
    }

    public function isPing() : bool
    {
        return $this->getRequest()->getHeaderLine('x-gitee-ping') === 'true';
    }

    public function isPush() : bool
    {
        return $this->getRequest()->getHeaderLine('x-gitee-event') === 'Push Hook';
    }

    public function isTagPush() : bool
    {
        return $this->getRequest()->getHeaderLine('x-gitee-event') === 'Tag Push Hook' && ! $this->get('deleted');
    }

    public function isTagDelete() : bool
    {
        return $this->getRequest()->getHeaderLine('x-gitee-event') === 'Tag Push Hook' && $this->get('deleted');
    }

    public function getEvent() : ?string
    {
        if ($this->isPing()) {
            return self::EVENT_PING;
        } elseif ($this->isPush()) {
            return self::EVENT_PUSH;
        } elseif ($this->isTagPush()) {
            return self::EVENT_TAG_PUSH;
        } elseif ($this->isTagDelete()) {
            return self::EVENT_TAG_DELETE;
        } else {
            return $this->get('hook_name', null);
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
        return $this->get('head_commit.id', null);
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
        return $this->getCommitMessage();
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
