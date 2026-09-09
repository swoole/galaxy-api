<?php

namespace App\Services\GitWebhook\Handler;

use App\Services\GitWebhook\Interfaces\HandlerInterface;
use Hyperf\HttpServer\Contract\RequestInterface;

abstract class AbstractHandler implements HandlerInterface
{
    /**
     * 事件.
     */
    public const EVENT_PING = 'ping'; // ping

    public const EVENT_PUSH = 'push'; // push

    public const EVENT_TAG_PUSH = 'tag_push'; // tag push

    public const EVENT_TAG_DELETE = 'tag_delete'; // tag delete

    /**
     * @var RequestInterface
     */
    protected $request;

    public function __construct(RequestInterface $request)
    {
        $this->request = $request;
    }

    public function getRequest() : RequestInterface
    {
        return $this->request;
    }

    public function get(string $key, $default = null)
    {
        return $this->request->input($key, $default);
    }

    public function getBranch(): ?string
    {
        return explode('refs/heads/', $this->get('ref'), 2)[1] ?? null;
    }

    public function getTag() : ?string
    {
        return explode('refs/tags/', $this->get('ref'), 2)[1] ?? null;
    }
}
