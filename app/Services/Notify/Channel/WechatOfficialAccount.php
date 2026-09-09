<?php

namespace App\Services\Notify\Channel;

use App\Exception\AppException;
use App\Services\EasyWechat\OfficialAccount;
use App\Services\Notify\AbstractNotifyChannel;
use App\Support\Functions;
use Psr\Container\ContainerInterface;

class WechatOfficialAccount extends AbstractNotifyChannel
{
    protected OfficialAccount $officialAccount;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->officialAccount = $container->get(OfficialAccount::class);
    }

    /**
     * 替换字段的.符号.
     */
    public static function replaceFieldDot($field)
    {
        return str_replace('.', '-', $field);
    }

    /**
     * 发送通知.
     */
    public function send($users, array $config, array $params = [], ?string $scene = null)
    {
        // 解析出receiver
        $receivers = [];
        foreach ($users as $user) {
            if (!empty($user['notify']) && !empty($user['notify']['official_account'])) {
                $receivers[$user['notify']['official_account']] = true;
            }
        }

        $paramsFiltered = $this->beforeFilter($config, $params);
        $dataParams = [];
        foreach ($config['fields'] ?? [] as $field) {
            $keys = Functions::fieldSplit($field);
            [$exists, $value] = Functions::getValue($paramsFiltered, $keys, '');
            $dataParams[self::replaceFieldDot($field)] = [
                'value' => $value,
            ];
        }

        $errors = [];
        foreach ($receivers as $receiver => $bool) {
            $data = [
                'touser' => $receiver,
                'template_id' => $config['template_id'],
                // 'url' => '',
                // 'miniprogram' => [
                //     'appid' => '',
                //     'pagepath' => 'index?foo=bar',
                // ],
                'data' => $dataParams,
            ];
            $data = $this->afterFilter($config, $params, $data);
            $resp = $this->officialAccount->getClient()->postJson('/cgi-bin/message/template/send', $data);
            if (isset($resp['errcode']) && $resp['errcode'] == 0) {
                continue;
            }
            $errors[$receiver] = $resp;
        }

        if (!empty($errors)) {
            $msg = '';
            foreach ($errors as $receiver => $resp) {
                $msg .= sprintf(
                    'send template msg failed to %s: %s(%s); ',
                    $receiver, $resp['errmsg'] ?? '', $resp['errcode'] ?? 0
                );
            }
            throw new AppException(500, $msg);
        }
    }
}
