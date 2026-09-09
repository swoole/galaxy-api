<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Controller;

use App\Constants\ErrorCode;
use App\Constants\Sms;
use App\Exception\AppException;
use App\Model\GitAuth;
use App\Model\User;
use App\Services\AccountService;
use App\Services\CommonService;
use App\Support\Functions;
use Hyperf\Di\Annotation\Inject;

class AccountController extends AbstractController
{
    /**
     * @Inject
     * @var GitAuth
     */
    #[Inject]
    protected $gitAuth;

    /**
     * @Inject
     * @var AccountService
     */
    #[Inject]
    private $accountService;

    /**
     * @Inject
     * @var CommonService
     */
    #[Inject]
    private $commonService;

    /**
     * @Inject
     */
    #[Inject]
    protected User $user;

    /**
     * 获取身份校验状态
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function getStatus()
    {
        //获取用户
        $user = Functions::getLoginUser();
        //身份校验
        $res = $this->accountService->getStatus($user);
        return $this->success($res);
    }

    /**
     * 密码身份校验.
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function pwdAuth()
    {
        $param = $this->validateAll(
            [
                'password' => 'required',
                'captchaid' => 'required',
                'captcha' => 'required',
            ],
            [
                'password.required' => '请输入密码',
                'captchaid.required' => '缺少图形验证码id',
                'captcha.required' => '请输入图形验证码',
            ]
        );
        //校验图形验证码
        $this->commonService->captchaValid($param['captchaid'], $param['captcha']);
        //获取用户
        $user = Functions::getLoginUser();
        //身份校验
        $res = $this->accountService->pwdAuth($user, $param);
        return $this->success($res);
    }

    /**
     * 发送短信验证码
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function sendSmsCode()
    {
        $param = $this->validateAll(
            [
                'captchaid' => 'required',
                'captcha' => 'required',
            ],
            [
                'captchaid.required' => '缺少图形验证码id',
                'captcha.required' => '请输入图形验证码',
            ]
        );
        //校验图形验证码
        $this->commonService->captchaValid($param['captchaid'], $param['captcha']);
        //发送短信验证码
        $user = Functions::getLoginUser();
        $uid = $user->getId();
        $codeType = Sms::SMS_TYPE_ACTION;
        $account = $user[Sms::ACCOUNT_PHONE];
        $accountType = Sms::ACCOUNT_PHONE;
        if (empty($account)) {
            throw new AppException(
                ErrorCode::PARAMS_ERROR,
                ErrorCode::getMessage(ErrorCode::PHONE_NOT_BIND_ERROR)
            );
        }
        $res = $this->accountService->sendSmsCode($uid, $codeType, $account, $accountType);
        return $this->success($res);
    }

    /**
     * 发送邮件验证码
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function sendEmailCode()
    {
        $param = $this->validateAll(
            [
                'captchaid' => 'required',
                'captcha' => 'required',
            ],
            [
                'captchaid.required' => '缺少图形验证码id',
                'captcha.required' => '请输入图形验证码',
            ]
        );
        //校验图形验证码
        $this->commonService->captchaValid($param['captchaid'], $param['captcha']);
        //发送短信验证码
        $user = Functions::getLoginUser();
        $uid = $user->getId();
        $codeType = Sms::SMS_TYPE_ACTION;
        $account = $user[Sms::ACCOUNT_EMAIL];
        $accountType = Sms::ACCOUNT_EMAIL;
        if (empty($account)) {
            throw new AppException(
                ErrorCode::PARAMS_ERROR,
                ErrorCode::getMessage(ErrorCode::EMAIL_NOT_BIND_ERROR)
            );
        }
        $res = $this->accountService->sendSmsCode($uid, $codeType, $account, $accountType);
        return $this->success($res);
    }

    /**
     * 短信验证码身份校验.
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function smsCodeAuth()
    {
        $param = $this->validateAll(
            [
                'smscode' => 'required',
            ],
            [
                'smscode.required' => '请输入验证码',
            ]
        );
        //校验验证码
        $user = Functions::getLoginUser();
        $codeType = Sms::SMS_TYPE_ACTION;
        $account = $user[Sms::ACCOUNT_PHONE];
        $accountType = Sms::ACCOUNT_PHONE;
        if (empty($account)) {
            throw new AppException(
                ErrorCode::PARAMS_ERROR,
                ErrorCode::getMessage(ErrorCode::PHONE_NOT_BIND_ERROR)
            );
        }
        $this->commonService->smsValid($param['smscode'], $codeType, $account, $accountType);
        //短信验证码校验
        $res = $this->accountService->smsCodeAuth($user);
        return $this->success($res);
    }

    /**
     * 邮箱验证码身份校验.
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function emailCodeAuth()
    {
        $param = $this->validateAll(
            [
                'emailcode' => 'required',
            ],
            [
                'emailcode.required' => '请输入验证码',
            ]
        );
        //校验验证码
        $user = Functions::getLoginUser();
        $codeType = Sms::SMS_TYPE_ACTION;
        $account = $user[Sms::ACCOUNT_EMAIL];
        $accountType = Sms::ACCOUNT_EMAIL;
        if (empty($account)) {
            throw new AppException(
                ErrorCode::PARAMS_ERROR,
                ErrorCode::getMessage(ErrorCode::EMAIL_NOT_BIND_ERROR)
            );
        }
        $this->commonService->smsValid($param['emailcode'], $codeType, $account, $accountType);
        //邮箱验证码校验
        $res = $this->accountService->smsCodeAuth($user);
        return $this->success($res);
    }

    /**
     * 发送新邮箱验证码
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function newEmailCode()
    {
        $param = $this->validateAll(
            [
                'email' => 'required',
            ],
            [
                'email.required' => '请输入邮箱',
            ]
        );
        //校验邮箱
        $this->commonService->emailValid($param[Sms::ACCOUNT_EMAIL]);
        //发送邮箱验证码
        $user = Functions::getLoginUser();
        $uid = $user->getId();
        $codeType = Sms::SMS_TYPE_ACTION;
        $account = $param[Sms::ACCOUNT_EMAIL];
        $accountType = Sms::ACCOUNT_EMAIL;
        if ($user[Sms::ACCOUNT_EMAIL] == $account) {
            throw new AppException(
                ErrorCode::PARAMS_ERROR,
                ErrorCode::getMessage(ErrorCode::EMAIL_SAME_ERROR)
            );
        }
        $res = $this->accountService->sendSmsCode($uid, $codeType, $account, $accountType);
        return $this->success($res);
    }

    /**
     * 新邮箱验证码校验-修改邮箱.
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function newEmailCodeAuth()
    {
        $param = $this->validateAll(
            [
                'email' => 'required',
                'emailcode' => 'required',
            ],
            [
                'email.required' => '请输入新邮箱',
                'emailcode.required' => '请输入新邮箱验证码',
            ]
        );
        //校验邮箱
        $this->commonService->emailValid($param[Sms::ACCOUNT_EMAIL]);
        //校验验证码
        $user = Functions::getLoginUser();
        $codeType = Sms::SMS_TYPE_ACTION;
        $newAccount = $param[Sms::ACCOUNT_EMAIL];
        $accountType = Sms::ACCOUNT_EMAIL;
        if ($user[Sms::ACCOUNT_EMAIL] == $newAccount) {
            throw new AppException(
                ErrorCode::PARAMS_ERROR,
                ErrorCode::getMessage(ErrorCode::EMAIL_SAME_ERROR)
            );
        }
        $this->commonService->smsValid($param['emailcode'], $codeType, $newAccount, $accountType);
        //设置新邮箱
        $this->accountService->setNewEmail($user, $newAccount);
        return $this->success();
    }

    /**
     * 发送新手机验证码
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function newSmsCode()
    {
        $param = $this->validateAll(
            [
                'phone' => 'required',
                'captchaid' => 'required',
                'captcha' => 'required',
            ],
            [
                'phone.required' => '请输入邮箱',
                'captchaid.required' => '缺少图形验证码id',
                'captcha.required' => '请输入图形验证码',
            ]
        );
        //校验手机号
        $this->commonService->mobileValid($param[Sms::ACCOUNT_PHONE]);
        //校验图形验证码
        $this->commonService->captchaValid($param['captchaid'], $param['captcha']);
        //发送验证码
        $user = Functions::getLoginUser();
        $uid = $user->getId();
        $codeType = Sms::SMS_TYPE_ACTION;
        $account = $param[Sms::ACCOUNT_PHONE];
        $accountType = Sms::ACCOUNT_PHONE;
        if ($user[Sms::ACCOUNT_PHONE] == $account) {
            throw new AppException(
                ErrorCode::PARAMS_ERROR,
                ErrorCode::getMessage(ErrorCode::PHONE_SAME_ERROR)
            );
        }
        $res = $this->accountService->sendSmsCode($uid, $codeType, $account, $accountType);
        return $this->success($res);
    }

    /**
     * 新手机验证码校验-修改手机.
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function newSmsCodeAuth()
    {
        $param = $this->validateAll(
            [
                'phone' => 'required',
                'smscode' => 'required',
            ],
            [
                'phone.required' => '请输入新手机',
                'smscode.required' => '请输入新手机验证码',
            ]
        );
        //校验手机号
        $this->commonService->mobileValid($param[Sms::ACCOUNT_PHONE]);
        //校验验证码
        $user = Functions::getLoginUser();
        $codeType = Sms::SMS_TYPE_ACTION;
        $newAccount = $param[Sms::ACCOUNT_PHONE];
        $accountType = Sms::ACCOUNT_PHONE;
        if ($user[Sms::ACCOUNT_PHONE] == $newAccount) {
            throw new AppException(
                ErrorCode::PARAMS_ERROR,
                ErrorCode::getMessage(ErrorCode::PHONE_SAME_ERROR)
            );
        }
        $this->commonService->smsValid($param['smscode'], $codeType, $newAccount, $accountType);
        //设置新邮箱
        $this->accountService->setNewPhone($user, $newAccount);
        return $this->success();
    }

    public function resetPwd()
    {
        $param = $this->validateAll(
            [
                'password' => 'required|min:6|max:20',
                'password_retry' => 'required',
            ],
            [
                'password.required' => '请输入新密码',
                'password.min' => '密码长度6-20个字符',
                'password.max' => '密码长度6-20个字符',
                'password_retry.required' => '请输入确认密码',
            ]
        );
        //校验两次密码一致
        $this->commonService->pwdConfirmValid($param['password'], $param['password_retry']);
        //重置密码
        $user = Functions::getLoginUser();
        $this->accountService->resetPwd($user, $param);
        return $this->success();
    }

    /**
     * Git仓库授权列表.
     */
    public function gitAuths()
    {
        $uid = Functions::getLoginUser()->getId();

        $auths = $this->gitAuth->list($uid);

        return $this->success([
            'auths' => $auths,
        ]);
    }

    /**
     * 创建Git仓库授权.
     */
    public function createGitAuth()
    {
        $params = $this->validate([
            'vendor' => 'required|in:' . implode(',', array_keys(GitAuth::$vendors)),
            'domain' => 'required|string|max:255',
            'token' => 'required|string|max:2000',
        ], [
            'vendor.in' => '不支持该厂商',
        ]);
        $uid = Functions::getLoginUser()->getId();

        $auth = $this->gitAuth->createAuth($uid, $params['vendor'], $params['domain'], $params['token']);
        unset($auth['uid']);

        return $this->success([
            'auth' => $auth,
        ]);
    }

    /**
     * 更新Git仓库授权.
     */
    public function updateGitAuth()
    {
        $params = $this->validate([
            'vendor' => 'required|in:' . implode(',', array_keys(GitAuth::$vendors)),
            'domain' => 'required|string|max:255',
            'token' => 'required|string|max:2000',
        ], [
            'vendor.in' => '不支持该厂商',
        ]);
        $uid = Functions::getLoginUser()->getId();

        $auth = $this->gitAuth->updateAuth($uid, $params['vendor'], $params['domain'], $params['token']);
        unset($auth['uid']);

        return $this->success([
            'auth' => $auth,
        ]);
    }

    /**
     * 删除Git仓库授权.
     */
    public function removeGitAuth()
    {
        $params = $this->validate([
            'vendor' => 'required|in:' . implode(',', array_keys(GitAuth::$vendors)),
            'domain' => 'required|string|max:255',
        ], [
            'vendor.in' => '不支持该厂商',
        ]);
        $uid = Functions::getLoginUser()->getId();

        $this->gitAuth->removeAuth($uid, $params['vendor'], $params['domain']);

        return $this->success();
    }

    /**
     * 检查Git授权.
     */
    public function checkGitRepoAuth()
    {
        $params = $this->validate([
            'repo' => 'required|string',
            'vendor' => 'required|in:' . implode(',', array_keys(GitAuth::$vendors)),
        ], [
            'vendor.in' => '不支持此 Git 服务类型',
        ]);

        $uid = Functions::getLoginUser()->getId();

        $data = $this->gitAuth->checkGitRepoAuth($uid, $params['vendor'], $params['repo']);

        return $this->success($data);
    }

    /**
     * 身份验证.
     */
    public function idConfirm()
    {
        $params = $this->validate([
            'password' => 'required|string',
        ]);
        $user = Functions::getLoginUser();

        $token = $this->user->applyIdConfirm($user, $params['password']);

        return $this->success([
            'confirm_token' => $token,
        ]);
    }
}
