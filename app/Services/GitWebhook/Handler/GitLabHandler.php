<?php

namespace App\Services\GitWebhook\Handler;

use Hyperf\HttpServer\Contract\RequestInterface;

/**
 * @see https://docs.gitlab.com/ee/user/project/integrations/webhook_events.html
 */
class GitLabHandler extends AbstractHandler
{
    /**
     * 是否匹配当前handler.
     */
    public static function matched(RequestInterface $request) : bool
    {
        return $request->hasHeader('x-gitlab-event');
    }

    public function isPing() : bool
    {
        // 不存在ping事件，固定返回false

        return false;
    }

    public function isPush() : bool
    {
        return $this->get('event_name') === 'push';
    }

    public function isTagPush() : bool
    {
        return $this->get('event_name') === 'tag_push' && $this->get('after') !== '0000000000000000000000000000000000000000';
    }

    public function isTagDelete() : bool
    {
        return $this->get('event_name') === 'tag_push' && $this->get('after') === '0000000000000000000000000000000000000000';
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
            return $this->get('event_name', null);
        }
    }

    /**
     * 仓库名称，例如 code-galaxy.
     */
    public function getProjectName() : ?string
    {
        return $this->get('project.name', null);
    }

    /**
     * 仓库完整名称，例如 cloud-platform/code-galaxy.
     */
    public function getProjectFullName() : ?string
    {
        return $this->get('project.path_with_namespace', null);
    }

    /**
     * 仓库HttpUrl.
     */
    public function getProjectHttpUrl() : ?string
    {
        return $this->get('project.http_url', null);
    }

    /**
     * 仓库SshUrl.
     */
    public function getProjectSshUrl() : ?string
    {
        return $this->get('project.ssh_url', null);
    }

    /**
     * 获取当前CommitID.
     */
    public function getCommitID() : ?string
    {
        return $this->get('after', null);
    }

    /**
     * 获取当前Commit Message.
     */
    public function getCommitMessage() : ?string
    {
        $commitsCount = count($this->get('commits', []));
        if ($commitsCount === 0) {
            return null;
        }
        return $this->get('commits.' . ($commitsCount - 1) . '.message', null);
    }

    /**
     * 获取当前Tag Message.
     */
    public function getTagMessage() : ?string
    {
        return $this->get('message', $this->getCommitMessage());
    }


    /**
     * 获取当前用户用户名.
     */
    public function getPusherUsername() : ?string
    {
        return $this->get('user_username');
    }

    /**
     * 获取当前用户邮箱.
     */
    public function getPusherEmail() : ?string
    {
        return $this->get('user_email');
    }
}
