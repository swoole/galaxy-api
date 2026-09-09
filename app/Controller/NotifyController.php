<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Controller;

use App\Model\Notify;
use App\Model\UserNotify;
use App\Services\Notify\Channel\Browser;
use App\Support\Functions;
use Hyperf\Di\Annotation\Inject;

class NotifyController extends AbstractController
{
    /**
     * @Inject
     */
    #[Inject]
    protected Notify $notify;

    /**
     * @Inject
     */
    #[Inject]
    protected UserNotify $userNotify;

    /**
     * @Inject
     */
    #[Inject]
    protected Browser $browser;

    /**
     * 用户通知渠道.
     */
    public function channels()
    {
        $uid = Functions::getLoginUser()->getId();

        $channels = $this->userNotify->channels($uid);

        return $this->success([
            'channels' => $channels,
        ]);
    }

    /**
     * 通知列表.
     */
    public function list()
    {
        $params = $this->validate([
            'timerange' => 'required|array',
            'timerange.begin' => 'required|integer',
            'timerange.end' => 'required|integer',
            'scene' => 'string',
            'page' => 'nullable|integer|min:1',
            'pagesize' => 'nullable|integer|min:1|max:100',
        ]);
        $params = Functions::arrNull2default($params, [
            'scene' => null,
            'page' => 1,
            'pagesize' => 20,
        ]);
        $uid = Functions::getLoginUser()->getId();

        $data = $this->notify->list(
            $uid,
            $params['timerange'],
            $params['scene'],
            $params['page'],
            $params['pagesize']
        );

        return $this->success($data);
    }

    /**
     * 简单通知列表.
     */
    public function simpleList()
    {
        $uid = Functions::getLoginUser()->getId();

        $notifys = $this->notify->simpleList($uid);

        return $this->success([
            'notifys' => $notifys,
        ]);
    }

    /**
     * 通知详情.
     */
    public function profile()
    {
        $params = $this->validate([
            'notify_id' => 'required|integer',
        ]);
        $uid = Functions::getLoginUser()->getId();

        $notify = $this->notify->profile(
            $uid,
            $params['notify_id']
        );

        return $this->success([
            'notify' => $notify,
        ]);
    }

    /**
     * 下一条消息.
     */
    public function next()
    {
        $params = $this->validate([
            'current_notify_id' => 'required|integer',
        ]);
        $uid = Functions::getLoginUser()->getId();

        $notify = $this->notify->nextProfile(
            $uid,
            $params['current_notify_id']
        );

        return $this->success([
            'notify' => $notify,
        ]);
    }

    /**
     * 上一条消息.
     */
    public function prev()
    {
        $params = $this->validate([
            'current_notify_id' => 'required|integer',
        ]);
        $uid = Functions::getLoginUser()->getId();

        $notify = $this->notify->prevProfile(
            $uid,
            $params['current_notify_id']
        );

        return $this->success([
            'notify' => $notify,
        ]);
    }

    /**
     * 标为已读.
     */
    public function setRead()
    {
        $params = $this->validate([
            'notify_ids' => 'required|array',
            'notify_ids.*' => 'required|integer|distinct',
        ]);
        $uid = Functions::getLoginUser()->getId();

        $this->notify->setRead(
            $uid,
            $params['notify_ids']
        );

        return $this->success();
    }

    /**
     * 全部标为已读.
     */
    public function setReadAll()
    {
        $uid = Functions::getLoginUser()->getId();

        $this->notify->setReadAll(
            $uid
        );

        return $this->success();
    }

    /**
     * 删除.
     */
    public function delete()
    {
        $params = $this->validate([
            'notify_ids' => 'required|array',
            'notify_ids.*' => 'required|integer|distinct',
        ]);
        $uid = Functions::getLoginUser()->getId();

        $this->notify->deleteNotifys(
            $uid,
            $params['notify_ids']
        );

        return $this->success();
    }

    /**
     * 监听浏览器通知.
     */
    public function listen()
    {
        $params = $this->validate([
            'begin' => 'nullable|numeric',
        ]);
        $params = Functions::arrNull2default($params, [
            'begin' => null,
        ]);

        $uid = Functions::getLoginUser()->getId();

        $messages = $this->browser->listen($uid, $params['begin']);

        return $this->success([
            'messages' => $messages,
        ]);
    }
}
