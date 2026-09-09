<?php

namespace App\Services\Notify\Filter;

class ClusterInit
{
    public function beforeOfficialAccount(array $params = [])
    {
        $data = [
            'first' => sprintf(
                '集群被%s初始化完成通知',
                BuildComplete::fmtUser($params['cluster']['creator_info'])
            ),
            'keyword1' => sprintf('%s（%s）', $params['cluster']['title'], $params['vendor']),
            'keyword2' => date('Y-m-d H:i:s', $params['cluster']['created_at']),
            'remark' => sprintf(
                '@%s',
                $params['org']['title']
            ),
        ];
       
        return $data;
    }

    public function beforeNotify(array $params = [])
    {
        $params['created_at'] = date('Y-m-d H:i:s', $params['cluster']['created_at']);
        $params['operator'] = BuildComplete::fmtUser($params['cluster']['creator_info']);

        return $params;
    }
}
