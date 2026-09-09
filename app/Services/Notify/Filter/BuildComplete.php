<?php

namespace App\Services\Notify\Filter;

use App\Model\Build;
use App\Support\Functions;

class BuildComplete
{
    /**
     * 格式化用户.
     */
    public static function fmtUser($creatorInfo)
    {
        if (empty($creatorInfo)) {
            return '未知用户';
        }
        return sprintf('%s(%s)', $creatorInfo['realname'], $creatorInfo['nickname']);
    }

    /**
     * 格式化版本.
     */
    public static function fmtVersion($build)
    {
        if (empty($build['commit_id'])) {
            return $build['branch'];
        } else {
            return sprintf('%s#%s', $build['branch'], substr($build['commit_id'], 0, 7));
        }
    }

    // /**
    //  * 格式化项目.
    //  */
    // public static function fmtProject()

    /**
     * 格式化服务号通知.
     * @param array $params 模板参数
     */
    public function beforeOfficialAccount(array $params = [])
    {
        $data = [
            'first' => sprintf(
                '镜像%s，耗时%s',
                $params['build']['status'] == Build::STATUS_SUCCESS ? '构建成功' : '构建失败',
                Functions::formatTimeDiff($params['build']['end_at'] - $params['build']['start_at'])
            ),
            'keyword1' => $params['build']['pipeline']['title'],
            'keyword2' => date('Y-m-d H:i:s', $params['build']['start_at']),
            'keyword3' => sprintf('%s/%s', $params['group']['title'], $params['project']['title']),
            'keyword4' => BuildComplete::fmtVersion($params['build']),
            'keyword5' => $params['build']['remark'],
            'remark' => sprintf(
                '@%s',
                $params['org']['title']
            ),
        ];
       
        return $data;
    }

    /**
     * @param array $params 模板参数
     * @param array $data 发送请求的参数
     */
    public function afterOfficialAccount(array $params = [], array $data = [])
    {
        if ($params['build']['status'] == Build::STATUS_SUCCESS) {
            $data['data']['first']['color'] = '#67C23A';
        } else {
            $data['data']['first']['color'] = '#F56C6C';
        }
        return $data;
    }

    /**
     * @param array $params 模板参数
     */
    public function beforeEmail(array $params = [])
    {
        $params['duration'] = Functions::formatTimeDiff($params['build']['end_at'] - $params['build']['start_at']);
        $params['success'] = $params['build']['status'] == Build::STATUS_SUCCESS;
        $params['result'] = $params['build']['status'] == Build::STATUS_SUCCESS ? '成功' : '失败';

        return $params;
    }

    /**
     * @param array $params 模板参数
     * @param array $data 发送请求的参数
     */
    public function afterEmail(array $params = [], array $data = [])
    {
        $result = $params['build']['status'] == Build::STATUS_SUCCESS ? '成功' : '失败';
        $data['subject'] = sprintf($data['subject'], $result);

        return $data;
    }

    /**
     * @param array $params 模板参数
     */
    public function beforeNotify(array $params = [])
    {
        $params['version'] = BuildComplete::fmtVersion($params['build']);
        $params['start_at'] = date('Y-m-d H:i:s', $params['build']['start_at']);
        $params['end_at'] = date('Y-m-d H:i:s', $params['build']['end_at']);
        $params['duration'] = Functions::formatTimeDiff($params['build']['end_at'] - $params['build']['start_at']);
        $params['result'] = $params['build']['status'] == Build::STATUS_SUCCESS ? '构建成功' : '构建失败';

        return $params;
    }

    /**
     * @param array $params 模板参数
     * @param array $data 发送请求的参数
     */
    public function afterNotify(array $params = [], array $data = [])
    {
        $result = $params['build']['status'] == Build::STATUS_SUCCESS ? '成功' : '失败';
        $data['title'] = sprintf($data['title'], $result);

        return $data;
    }

    /**
     * @param array $params 模板参数
     */
    public function beforeBrowser(array $params = [])
    {
        $params['version'] = BuildComplete::fmtVersion($params['build']);
        $params['result'] = $params['build']['status'] == Build::STATUS_SUCCESS ? '成功' : '失败';
        $params['remark'] = $params['build']['remark'];
        $params['id'] = $params['build']['id'];

        return $params;
    }

    /**
     * @param array $params 模板参数
     * @param array $data 发送请求的参数
     */
    public function afterBrowser(array $params = [], array $data = [])
    {
        $result = $params['build']['status'] == Build::STATUS_SUCCESS ? '成功' : '失败';
        $data['title'] = sprintf($data['title'], $result);

        return $data;
    }
}
