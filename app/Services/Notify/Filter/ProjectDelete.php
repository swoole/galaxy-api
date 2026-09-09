<?php

namespace App\Services\Notify\Filter;

class ProjectDelete
{
    public function beforeOfficialAccount(array $params = [])
    {
        $data = [
            'first' => sprintf('项目删除通知，包含%s个实例。', $params['instance_count']),
            'keyword1' => sprintf('%s/%s', $params['group']['title'], $params['project']['title']),
            'keyword2' => BuildComplete::fmtUser($params['operator_info']),
            'keyword3' => date('Y-m-d H:i:s', $params['operated_at']),
            'remark' => sprintf(
                '@%s，关联Git仓库%s',
                $params['org']['title'],
                $params['project']['repository_url'] ?: '无'
            ),
        ];
       
        return $data;
    }

    public function beforeNotify(array $params = [])
    {
        $params['created_at'] = date('Y-m-d H:i:s', $params['operated_at']);
        $params['operator'] = BuildComplete::fmtUser($params['operator_info']);
        $params['gitrepo'] = $params['project']['repository_url'] ?: '无';

        return $params;
    }

    public function beforeBrowser(array $params = [])
    {
        $params['operator'] = BuildComplete::fmtUser($params['operator_info']);

        return $params;
    }
}
