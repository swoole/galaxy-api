<?php

namespace App\Services;

use App\Exception\AppException;
use App\Model\User;
use App\Model\UserNotify;
use App\Services\Notify\Channel\Browser;
use App\Services\Notify\Channel\Email;
use App\Services\Notify\Channel\Notify as ChannelNotify;
use App\Services\Notify\AbstractNotifyChannel;
use App\Support\Functions;
use Hyperf\Logger\LoggerFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class Notify
{
    protected ContainerInterface $container;

    protected LoggerInterface $logger;

    protected array $scenes;

    /**
     * @var AbstractNotifyChannel[]
     */
    protected array $channels = [];

    /**
     * @var array
     */
    protected array $channelsSender = [
        UserNotify::CHANNEL_EMAIL => Email::class,
        UserNotify::CHANNEL_BROWSER => Browser::class,
        UserNotify::CHANNEL_NOTIFY => ChannelNotify::class,
    ];

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->logger = $container->get(LoggerFactory::class)->get('notify', 'notify');
        $this->scenes = config('notify.tpls', []);

        foreach (config('notify.channels', []) as $channel) {
            $this->channels[$channel] = $container->get($this->channelsSender[$channel]);
        }
    }

    /**
     * 发送通知.
     */
    public function send(array $uids, string $scene, array $params = [], array $channels = [])
    {
        $this->sendTracked($uids, $scene, $params, $channels);
    }

    /** Preserve channel outcomes for monitored deliveries without changing existing callers. */
    public function sendTracked(array $uids, string $scene, array $params = [], array $channels = []): array
    {
        if (empty($this->scenes[$scene])) {
            throw new AppException(404, '通知场景不存在');
        }

        $users = User::whereIn('id', $uids)
            ->select('id', 'email')
            ->get();
        // TODO 根据用户偏好设置不发送某些渠道的通知
        $outcomes = [];
        foreach ($channels as $channel) $outcomes[$channel] = ['sent' => false, 'error' => '通知渠道未配置或未启用'];
        foreach ($this->scenes[$scene] as $channel => $config) {
            // 没有该通知渠道则跳过，说明未支持
            if (!isset($this->channels[$channel])) {
                continue;
            }
            // 如果设置了渠道，对应渠道没有消息则跳过
            if (!empty($channels) && !in_array($channel, $channels)) {
                continue;
            }
            try {
                $this->channels[$channel]->send($users, $config, $params, $scene);
                $outcomes[$channel] = ['sent' => true, 'error' => ''];
            } catch (Throwable $e) {
                $outcomes[$channel] = ['sent' => false, 'error' => mb_substr($e->getMessage(), 0, 1000)];
                $this->logger->warning('catch unknown exception on send', [
                    'params' => [
                        'uids' => $uids,
                        'channel' => $channel,
                        'config' => $config,
                        'params' => $params,
                    ],
                    'exception' => Functions::exceptionContext($e),
                ]);
            }
        }
        return $outcomes;
    }
}
