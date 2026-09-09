<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Controller;

use App\Constants\ErrorCode;
use App\Exception\AppException;
use App\Model\User;
use App\Model\UserProfile;
use App\Model\UserSshKey;
use App\Model\LogUserLogin;
use App\Model\UserPersonalSshKey;
use App\Services\LoginService;
use App\Support\Functions;
use App\Support\Git;
use Hyperf\Di\Annotation\Inject;

class UserController extends AbstractController
{
    /** @Inject */
    #[Inject]
    protected UserSshKey $sshKey;

    /**
     * @Inject
     */
    #[Inject]
    protected UserPersonalSshKey $userPersonalSshKey;

    /**
     * @Inject
     */
    #[Inject]
    protected User $user;

    /**
     * @Inject
     */
    #[Inject]
    protected LogUserLogin $loginLog;

    /** 查看用户个人 SSH 密钥对的公钥。 */
    public function sshkey()
    {
        $uid = Functions::getLoginUser()->getId();
        return $this->success(['sshkey' => $this->sshKey->show($uid)]);
    }

    /** 重置用户个人 SSH 密钥对。 */
    public function sshkeyReset(LoginService $loginService)
    {
        $params = $this->validate([
            'confirm_token' => 'required|string',
            'algo' => 'nullable|string|in:' . implode(',', array_keys(Git::$sshKeyAlgos)),
        ]);
        $uid = Functions::getLoginUser()->getId();
        $this->user->idConfirm($uid, $params['confirm_token']);
        $ssh = $loginService->createUserSshKey(User::find($uid), false, (string) ($params['algo'] ?? 'ed25519'));
        $key = UserSshKey::where('uid', $uid)->first();
        if ($key === null) {
            $key = new UserSshKey();
            $key->uid = $uid;
        }
        $key->pubkey = $ssh['pubkey'];
        $key->privatekey = $ssh['privatekey'];
        $key->generate_at = time();
        $key->save();
        return $this->success(['sshkey' => $this->sshKey->show($uid)]);
    }

    // 用户信息详细/用户信息更新
    public function profile()
    {
        $user_id = Functions::getLoginUser()->getId();

        $method = $this->request->getMethod();
        if ($method == 'PUT') {
            $param = $this->validate([
                'nickname' => 'string|max:20',
                'avatar' => 'string',
                'gender' => 'integer',
                'company' => 'string|max:100',
                'position' => 'string|max:100',
                'wechat' => 'string|max:100',
                'qq' => 'string|max:20',
                'city' => 'string|max:100',
                'introduce' => 'string|max:200',
            ]);

            $user = UserProfile::where('uid', $user_id)->first();
            $user->nickname = $param['nickname'] ?? $user->nickname;
            $user->avatar = $param['avatar'] ?? $user->avatar;
            $user->gender = $param['gender'] ?? $user->gender;
            $user->company = $param['company'] ?? $user->company;
            $user->position = $param['position'] ?? $user->position;
            $user->wechat = $param['wechat'] ?? $user->wechat;
            $user->qq = $param['qq'] ?? $user->qq;
            $user->city = $param['city'] ?? $user->city;
            $user->introduce = $param['introduce'] ?? $user->introduce;

            $res = $user->save();
            if (! $res) {
                // CODE
                throw new AppException(
                    ErrorCode::UPDATE_FAILED,
                    ErrorCode::getMessage(ErrorCode::UPDATE_FAILED)
                );
            }

            // 获取邮箱
            $email = User::find($user_id, ['email'])->toArray();

            return $this->success(['user' => array_merge($user->toArray(), $email)]);
        }

        $ret = User::with(['lastOrg' => function ($query) {
            $query->select(['id', 'title', 'logo']);
        }])->find($user_id)->toArray();
        $info = UserProfile::where('uid', $user_id)->get();
        if (! empty($info)) {
            $info = $info->toArray();
            $ret = array_merge($ret, $info[0]);
            unset($ret['password']);
        }
        return $this->success(['user' => $ret]);
    }

    /**
     * 用户简要信息.
     */
    public function simpleprofile()
    {
        $uid = Functions::getLoginUser()->getId();

        $user = $this->user->simpleProfile($uid);

        return $this->success([
            'user' => $user,
        ]);
    }

    // 头像上传 需要登录
    public function avatarUpload(\App\Services\ObjectStorageService $storageService)
    {
        $user_id = Functions::getLoginUser()->getId();
        $orgId = (int) Functions::getContextValue('org_id');

        $file = $this->request->file('avatar');

        $this->validateAll(['avatar' => 'file|image'], [], ['avatar' => $file]);

        $avatar_path = '/uploads/avatars/';
        // 生成用户uid相关的文件名
        $filename = md5((string) $user_id . time()) . '.' . $file->getExtension();
        $filepath = $avatar_path . $filename;

        $resolved = $storageService->resolveForOrg($orgId);
        $stream = fopen($file->getRealPath(), 'r+');
        $resolved['filesystem']->writeStream($filepath, $stream);
        fclose($stream);

        $avatar = $resolved['base_url'] . $filepath;
        return $this->success(['avatar' => $avatar]);
    }

    /**
     * 私人公钥列表.
     */
    public function personalSshKeys()
    {
        $uid = Functions::getLoginUser()->getId();

        $keys = $this->userPersonalSshKey->sshKeys($uid);

        return $this->success([
            'keys' => $keys,
        ]);
    }

    /**
     * 创建私人公钥.
     */
    public function createPersonalSshKey()
    {
        $params = $this->validate([
            'pubkey' => 'required|string',
            'remark' => 'nullable|string|max:200',
        ]);
        $params = Functions::arrNull2default($params, [
            'remark' => '',
        ]);
        $uid = Functions::getLoginUser()->getId();

        $key = $this->userPersonalSshKey->createSshKey(
            $uid,
            $params['pubkey'],
            $params['remark']
        );

        return $this->success([
            'key' => $key,
        ]);
    }

    /**
     * 删除私人公钥.
     */
    public function deletePersonalSshKey()
    {
        $params = $this->validate([
            'keyid' => 'required|integer',
        ]);
        $uid = Functions::getLoginUser()->getId();

        $this->userPersonalSshKey->deleteSshKey($uid, $params['keyid']);

        return $this->success();
    }

    /**
     * 私人公钥详情.
     */
    public function profilePersonalSshKey()
    {
        $params = $this->validate([
            'keyid' => 'required|integer',
        ]);
        $uid = Functions::getLoginUser()->getId();

        $key = $this->userPersonalSshKey->profile($uid, $params['keyid']);

        return $this->success([
            'key' => $key,
        ]);
    }

    /**
     * 登录日志.
     */
    public function loginHistory()
    {
        $params = $this->validate([
            'page' => 'nullable|integer|min:1|max:20',
            'pagesize' => 'nullable|integer|min:20|max:100',
        ]);
        $uid = Functions::getLoginUser()->getId();

        $data = $this->loginLog->list($uid, $params['page'], $params['pagesize']);

        return $this->success($data);
    }

}
