<?php

namespace App\Services\EasyWechat\OfficialAccount;

use App\Model\UserNotify;
use App\Model\WechatMpQrcode;
use App\Services\EasyWechat\OfficialAccount;
use App\Support\Functions;
use Closure;
use EasyWeChat\OfficialAccount\Message;
use Hyperf\Logger\LoggerFactory;
use Psr\Http\Message\RequestInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class Webhook
{
    protected UserNotify $userNotify;

    protected LoggerInterface $logger;

    public function __construct(ContainerInterface $container)
    {
        $this->userNotify = $container->get(UserNotify::class);
        $this->logger = $container->get(LoggerFactory::class)->get('OfficialAccountWebhook');
    }

    public function handle(RequestInterface $request)
    {
        $project = $this->getApp($request);
        $server = $project->getServer();
        $server->addEventListener('SCAN', function (Message $message, Closure $next) {
            $this->handleEventScan($message, $next);
        });
        $server->addEventListener('subscribe', function (Message $message, Closure $next) {
            $this->handleEventSubscribe($message, $next);
        });

        return $server->serve();
    }

    /**
     * @return OfficialAccount
     */
    protected function getApp(RequestInterface $request)
    {
        $project = make(OfficialAccount::class);
        $project->setRequest($request);

        return $project;
    }

    /**
     * 监听扫码事件.
     */
    protected function handleEventScan(Message $message, Closure $next)
    {
        $this->doQrcode($message->EventKey, $message);
        $next($message);
    }

    /**
     * 监听订阅事件.
     */
    protected function handleEventSubscribe(Message $message, Closure $next)
    {
        if (!empty($message->EventKey)) {
            $this->doQrcode(substr($message->EventKey, strlen('qrscene_')), $message);
        }
        $next($message);
    }

    /**
     * 处理参数二维码事件.
     */
    protected function doQrcode($eventKey, Message $message)
    {
        [$scene, $sceneVal] = WechatMpQrcode::splitSceneStr($eventKey);
        try {
            switch ($scene) {
                case 'account-bind':
                    $this->userNotify->handleOfficialAccountBind($sceneVal, $message->FromUserName);
                    break;
                default:
                    # code...
                    break;
            }
        } catch (Throwable $e) {
            $this->logger->error('catch unknown exception on doQrcode', [
                'params' => [
                    'eventKey' => $eventKey,
                    'message' => $message,
                ],
                'exception' => Functions::exceptionContext($e),
            ]);
        }
    }
}
