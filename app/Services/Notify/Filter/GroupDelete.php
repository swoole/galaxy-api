<?php

namespace App\Services\Notify\Filter;

class GroupDelete
{
    public function beforeOfficialAccount(array $params = [])
    {
        $data = [
            'first' => '项目组删除通知。',
            'keyword1' => $params['group']['title'],
            'keyword2' => BuildComplete::fmtUser($params['operator_info']),
            'keyword3' => date('Y-m-d H:i:s', $params['operated_at']),
            'remark' => sprintf(
                '@%s',
                $params['org']['title']
            ),
        ];
       
        return $data;
    }

    public function beforeNotify(array $params = [])
    {
        $params['created_at'] = date('Y-m-d H:i:s', $params['operated_at']);
        $params['operator'] = BuildComplete::fmtUser($params['operator_info']);

        return $params;
    }

    public function beforeBrowser(array $params = [])
    {
        $params['operator'] = BuildComplete::fmtUser($params['operator_info']);

        return $params;
    }
}
