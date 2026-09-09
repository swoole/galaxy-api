<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Controller;

use App\Constants\ErrorCode;
use App\Model\CliVersion;
use Hyperf\Di\Annotation\Inject;

class CliAppController extends AbstractController
{
    /**
     * @Inject
     */
    #[Inject]
    protected CliVersion $cliVersion;

    /**
     * @throws \Throwable
     */
    public function createVersion()
    {
        $param = $this->validate(
            [
                'version' => 'required|string',
                'commit_id' => 'required|string',
                'notes' => 'required|string',
                'bin_name' => 'required|string',
                'md5' => 'required|string',
                'os' => 'required|string',
                'arch' => 'required|string',
                'size' => 'required|integer',
                'token' => 'required|string',
            ],
            [
                'version.required' => '版本必须',
                'commit_id.required' => 'commitid必须',
                'notes.required' => '发布说明必须',
                'bin_name.required' => '文件名必须',
                'md5.required' => '文件hash必须',
                'os.required' => '操作系统必须',
                'arch.required' => '运行平台必须',
                'size.required' => '文件大小必须',
                'token.required' => '请传入正确的参数',
            ]
        );
        $param['os'] = strtolower($param['os']);
        $param['arch'] = strtolower($param['arch']);
        $param['version'] = strtolower($param['version']);
        if ($param['token'] !== '50mOp4ENTQEqNly32ufGfqnW5SdmjcM0') {
            return $this->failed('error');
        }
        if ($param['version'] !== "test" && $this->cliVersion->existVersion($param['os'], $param['arch'], $param['version'])) {
            return $this->failed('版本已存在');
        }
        $cli = $this->cliVersion->createCliVersion($param);
        if (!empty($cli)) {
            return $this->success();
        }
        return $this->failed('保存版本失败');
    }

    /**
     * 下载
     * @return \Psr\Http\Message\ResponseInterface|string
     */
    public function download()
    {
        $param = $this->validate(
            [
                'os' => 'required|string',
                'arch' => 'required|string',
                'version' => 'string',
            ],
            [
                'os.required' => '运行平台必须',
                'arch.required' => '运行平台必须',
            ]
        );
        if (! empty($param['version'])) {
            $info = $this->cliVersion->getVersion($param['os'], $param['arch'], $param['version']);
        }else{
            $info = $this->cliVersion->getLastVersion($param['os'], $param['arch']);
        }
        if (empty($info)) {
            return $this->failed('没有找到要升级的版本信息', [], ErrorCode::CLI_FIND_VERSION_FAIL);
        }
        return $this->response->redirect($this->getCdnUrl($info),302,"https");
    }
    /**
     * 获取最新版本.
     */
    public function upgrade()
    {
        $param = $this->validate(
            [
                'os' => 'required|string',
                'arch' => 'required|string',
                'version' => 'string',
            ],
            [
                'os.required' => '运行平台必须',
                'arch.required' => '运行平台必须',
            ]
        );
        $version = $this->request->getHeaderLine('X-CG-Version');
        if (! empty($param['version'])) {
            if ($version == $param['version']) {
                return $this->failed('当前版本就是指定升级的版本', [], ErrorCode::CLI_FIND_VERSION_FAIL);
            }
            $info = $this->cliVersion->getVersion($param['os'], $param['arch'], $param['version']);
            if (empty($info)) {
                return $this->failed('没有找到要升级的版本信息', [], ErrorCode::CLI_FIND_VERSION_FAIL);
            }
            $ret = [
                'version' => $info['version'],
                'notes' => $info['notes'],
                'md5' => $info['md5'],
                'os' => $info['os'],
                'arch' => $info['arch'],
                'size' => $info['size'],
                'download' => $this->getCdnUrl($info),
                'created_at' => $info['created_at'],
            ];
            return $this->success($ret);
        }
        $info = $this->cliVersion->getLastVersion($param['os'], $param['arch']);
        if (empty($info)) {
            return $this->failed('没有找到要升级的版本信息', [], ErrorCode::CLI_FIND_VERSION_FAIL);
        }
        $ret = [
            'version' => $info['version'],
            'notes' => $info['notes'],
            'md5' => $info['md5'],
            'arch' => $info['arch'],
            'os' => $info['os'],
            'size' => $info['size'],
            'download' => $this->getCdnUrl($info),
            'created_at' => $info['created_at'],
        ];
        return $this->success($ret);
    }

    public function getLatest()
    {
        $param = $this->validate(
            [
                'os' => 'required|string',
                'arch' => 'required|string',
            ],
            [
                'os.required' => '运行平台必须',
                'arch.required' => '运行平台必须',
            ]
        );
        $info = $this->cliVersion->getLastVersion($param['os'], $param['arch']);
        if (empty($info)) {
            return $this->failed('没有该平台使用的版本');
        }
        $ret = [
            'version' => $info['version'],
            'notes' => $info['notes'],
            'md5' => $info['md5'],
            'arch' => $info['arch'],
            'os' => $info['os'],
            'size' => $info['size'],
            'download' => $this->getCdnUrl($info),
            'created_at' => $info['created_at'],
        ];
        return $this->success($ret);
    }

    private function getCdnUrl($info)
    {
        return sprintf('%s/%s/%s', 'https://s.code-galaxy.net/downloads', $info['version'], $info['bin_name']);
    }
}
