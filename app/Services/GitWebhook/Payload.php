<?php

namespace App\Services\GitWebhook;

use App\Services\GitWebhook\Exception\HandlerNotFoundException;
use App\Services\GitWebhook\Interfaces\HandlerInterface;
use Hyperf\HttpServer\Contract\RequestInterface;

class Payload
{
    /**
     * @var RequestInterface
     */
    protected $request;

    /**
     * @var HandlerInterface[]
     */
    protected static $handlers = [
        \App\Services\GitWebhook\Handler\GiteaHandler::class,
        \App\Services\GitWebhook\Handler\GitLabHandler::class,
        \App\Services\GitWebhook\Handler\GiteeHandler::class,
        \App\Services\GitWebhook\Handler\GitHubHandler::class,
        \App\Services\GitWebhook\Handler\CodeUpHandler::class,
    ];

    public function __construct(RequestInterface $request)
    {
        $this->request = $request;
    }

    public function getHandler() : HandlerInterface
    {
        foreach (Payload::$handlers as $handler) {
            if ($handler::matched($this->request)) {
                return new $handler($this->request);
            }
        }
        
        throw new HandlerNotFoundException();
    }
}
