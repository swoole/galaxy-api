<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Services;

use App\Constants\ErrorCode;
use App\Constants\RedisKey;
use App\Exception\AppException;
use App\Model\User;
use Hyperf\Di\Annotation\Inject;

class AccountService
{
    public const AUTH_TTL = 10 * 60;

    /**
     * @Inject
     * @var CommonService
     */
    #[Inject]
    private $commonService;

    /**
     * 获取身份校验状态
     * @param $user
     * @return mixed
     */
    public function getStatus($user)
    {
        $status = 1;
        $redisKey = RedisKey::ACTION_AUTH_REDIS_KEY . md5((string) $user['id']);
        $expire = $this->checkAuthMark($redisKey);
        if (! $expire) {
            $status = $expire = 0;
        }
        
        $res['status'] = $status;
        $res['expired_at'] = $expire;
        return $res;
    }

    /**
     * 密码身份校验.
     * @param User $user
     * @param $param
     * @return mixed
     */
    public function pwdAuth($user, $param)
    {
        if (! $user) {
            throw new AppException(
                ErrorCode::ACCOUNT_NOT_EXIST_ERROR,
                ErrorCode::getMessage(ErrorCode::ACCOUNT_NOT_EXIST_ERROR)
            );
        }
        //校验密码
        if (! password_verify($param['password'], $user['password'])) {
            throw new AppException(
                ErrorCode::USER_PASSWORD_ERROR,
                ErrorCode::getMessage(ErrorCode::USER_PASSWORD_ERROR)
            );
        }
        //获取标识
        $redisKey = RedisKey::ACTION_AUTH_REDIS_KEY . md5((string) $user['id']);
        $expire = $this->checkAuthMark($redisKey);
        if (! $expire) {
            //生成校验通过标识
            $expire = $this->createAuthMark($redisKey);
        }
        //返回数据
        $res['expired_at'] = $expire;
        return $res;
    }

    /**
     * 发送 短信/ 邮箱 验证码
     * @param $uid
     * @param $codeType
     * @param $account
     * @param $accountType
     * @return mixed
     */
    public function sendSmsCode($uid, $codeType, $account, $accountType)
    {
        //校验身份验证
//        $redisKey = RedisKey::ACTION_AUTH_REDIS_KEY . md5((string) $uid);
//        $expire = $this->checkAuthMark($redisKey);
//        if (! $expire) {
//            throw new AppException(
//                ErrorCode::AUTH_LOSS_EFFICACY_ERROR,
//                ErrorCode::getMessage(ErrorCode::AUTH_LOSS_EFFICACY_ERROR)
//            );
//        }
        return $this->commonService->smsCode($uid, $codeType, $account, $accountType, 1);
    }

    /**
     * 验证码身份校验.
     * @param $user
     * @return mixed
     */
    public function smsCodeAuth($user)
    {
        //获取标识
        $redisKey = RedisKey::ACTION_AUTH_REDIS_KEY . md5((string) $user['id']);
        $expire = $this->checkAuthMark($redisKey);
        if (! $expire) {
            //生成校验通过标识
            $expire = $this->createAuthMark($redisKey);
        }
        //返回数据
        $res['expired_at'] = $expire;
        return $res;
    }

    /**
     * 修改邮箱.
     * @param User $user
     * @param $newAccount
     */
    public function setNewEmail($user, $newAccount)
    {
        //校验身份验证
        $redisKey = RedisKey::ACTION_AUTH_REDIS_KEY . md5((string) $user['id']);
        $expire = $this->checkAuthMark($redisKey);
        if (! $expire) {
            throw new AppException(
                ErrorCode::AUTH_LOSS_EFFICACY_ERROR,
                ErrorCode::getMessage(ErrorCode::AUTH_LOSS_EFFICACY_ERROR)
            );
        }
        //校验新邮箱是否被使用
        $email = User::where(['email' => $newAccount])->first();
        if ($email) {
            throw new AppException(
                ErrorCode::NEW_EMAIL_IS_USED_ERROR,
                ErrorCode::getMessage(ErrorCode::NEW_EMAIL_IS_USED_ERROR)
            );
        }
        //重置邮箱
        $user->email = $newAccount;
        $res = $user->save();
        if (! $res) {
            throw new AppException(
                ErrorCode::SET_EMAIL_ERROR,
                ErrorCode::getMessage(ErrorCode::SET_EMAIL_ERROR)
            );
        }
        //清除验证状态
        $this->clearAuthMark($redisKey);
    }

    /**
     * 设置手机号.
     * @param User $user
     * @param $newAccount
     */
    public function setNewPhone($user, $newAccount)
    {
        //校验身份验证
        $redisKey = RedisKey::ACTION_AUTH_REDIS_KEY . md5((string) $user['id']);
        $expire = $this->checkAuthMark($redisKey);
        if (! $expire) {
            throw new AppException(
                ErrorCode::AUTH_LOSS_EFFICACY_ERROR,
                ErrorCode::getMessage(ErrorCode::AUTH_LOSS_EFFICACY_ERROR)
            );
        }
        //校验新手机号是否被使用
        $phone = User::where(['phone' => $newAccount])->first();
        if ($phone) {
            throw new AppException(
                ErrorCode::NEW_PHONE_IS_USED_ERROR,
                ErrorCode::getMessage(ErrorCode::NEW_PHONE_IS_USED_ERROR)
            );
        }
        //重置手机号
        $user->phone = $newAccount;
        $res = $user->save();
        if (! $res) {
            throw new AppException(
                ErrorCode::SET_PHONE_ERROR,
                ErrorCode::getMessage(ErrorCode::SET_PHONE_ERROR)
            );
        }
        //清除验证状态
        $this->clearAuthMark($redisKey);
    }

    /**
     * 重置密码
     * @param User $user
     * @param $param
     */
    public function resetPwd($user, $param)
    {
        //校验身份验证
        $redisKey = RedisKey::ACTION_AUTH_REDIS_KEY . md5((string) $user['id']);
        $expire = $this->checkAuthMark($redisKey);
        if (! $expire) {
            throw new AppException(
                ErrorCode::AUTH_LOSS_EFFICACY_ERROR,
                ErrorCode::getMessage(ErrorCode::AUTH_LOSS_EFFICACY_ERROR)
            );
        }
        //重置密码
        $user->password = password_hash($param['password'], PASSWORD_DEFAULT);
        $res = $user->save();
        if (! $res) {
            throw new AppException(
                ErrorCode::RESET_PASSWORD_ERROR,
                ErrorCode::getMessage(ErrorCode::RESET_PASSWORD_ERROR)
            );
        }
        //清除验证状态
        $this->clearAuthMark($redisKey);
    }

    /**
     * 生成校验通过标识.
     * @param $redisKey
     * @return float|int
     */
    public function createAuthMark($redisKey)
    {
        $ttl = self::AUTH_TTL;
        $expire = time() + $ttl;
        redis()->setex($redisKey, $ttl, $expire);
        return (int) $expire;
    }

    /**
     * 校验身份验证标识.
     * @param $redisKey
     * @return bool|int|string
     */
    public function checkAuthMark($redisKey)
    {
        $expire = redis()->get($redisKey);
        if (! $expire || $expire < time()) {
            $expire = 0;
        }
        return (int) $expire;
    }

    /**
     * 清除验证状态
     * @param $redisKey
     */
    public function clearAuthMark($redisKey)
    {
        redis()->delete($redisKey);
    }
}
