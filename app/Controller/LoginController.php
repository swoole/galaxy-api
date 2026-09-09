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
use App\Services\CommonService;
use App\Services\LoginService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Redis\Redis;

/**
 * 登录/注册
 * Class LoginController.
 */
class LoginController extends AbstractController
{
    /**
     * @Inject
     * @var LoginService
     */
    #[Inject]
    private $loginService;

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
    protected Redis $redis;

    /**
     * 注册.
     * @throws \Exception
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function register()
    {
        $param = $this->validateAll(
            [
                'email' => 'required|email|unique:user,email',
                'emailcode' => 'required|string',
                'password' => 'required|min:6|max:20',
                'password_retry' => 'required|string',
                'nickname' => 'required|string',
            ],
            [
                'email.required' => '请输入邮箱',
                'email.email' => '邮箱格式不正确',
                'email.unique' => '该邮箱已被注册',
                'emailcode.required' => '请输入邮件验证码',
                'password.required' => '请输入密码',
                'password.min' => '密码长度6-20个字符',
                'password.max' => '密码长度6-20个字符',
                'password_retry.required' => '请输入确认密码',
                'nickname.required' => '请输入昵称',
            ]
        );
        //校验邮箱
        $this->commonService->emailValid($param['email']);
        //校验两次密码一致
        $this->commonService->pwdConfirmValid($param['password'], $param['password_retry']);
        //发送邮件验证码时已完成滑动验证，该状态30分钟内有效
        $this->commonService->registerSliderValid($param['email']);
        //校验并消费邮件验证码
        $this->commonService->smsValid(
            $param['emailcode'],
            Sms::SMS_TYPE_REGISTER,
            $param['email'],
            Sms::ACCOUNT_EMAIL,
            true
        );
        //注册用户
        $res = $this->loginService->register($param);
        $this->commonService->clearRegisterSliderVerified($param['email']);
        //返回数据
        return $this->success($res);
    }

    /**
     * 发送注册邮件验证码.
     */
    public function sendRegisterEmailCode()
    {
        $param = $this->validateAll(
            [
                'email' => 'required|email|unique:user,email',
                'captcha' => 'nullable|array',
                'captcha.token' => 'nullable|string',
            ],
            [
                'email.required' => '请输入邮箱',
                'email.email' => '邮箱格式不正确',
                'email.unique' => '该邮箱已被注册',
            ]
        );

        $this->commonService->emailValid($param['email']);
        $sliderVerified = $this->commonService->isRegisterSliderVerified($param['email']);
        if (! $sliderVerified) {
            if (empty($param['captcha']['token'])) {
                throw new AppException(ErrorCode::INVALID_PARAMS, '请完成滑动验证');
            }
            $this->commonService->captchaUnionValid($param['captcha']);
        }
        $res = $this->commonService->smsCode(
            0,
            Sms::SMS_TYPE_REGISTER,
            $param['email'],
            Sms::ACCOUNT_EMAIL
        );
        if (! $sliderVerified) {
            $this->commonService->markRegisterSliderVerified($param['email']);
        }

        return $this->success($res);
    }

    /**
     * 账号密码登录.
     * @throws \Exception
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function pwdLogin()
    {
        $param = $this->validate(
            [
                'account' => 'required|string',
                'password' => 'required|string',
                'captcha' => 'required|array',
                'captcha.token' => 'nullable|string',
                'captcha.captchaid' => 'nullable|string',
                'captcha.captcha' => 'nullable|string',
            ],
            [
                'account.required' => '请输入账号',
                'password.required' => '请输入密码',
            ]
        );
        
        $accountType = Sms::ACCOUNT_EMAIL;
        $this->commonService->emailValid($param['account']);
        // 联合验证码校验
        $this->commonService->captchaUnionValid($param['captcha'], ['action' => 'login']);
        //账号密码登录
        $res = $this->loginService->pwdLogin($param, $accountType);
        //返回数据
        return $this->success($res);
    }

    /**
     * 短信验证码登录.
     * @throws \Exception
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function smsLogin()
    {
        $param = $this->validateAll(
            [
                'phone' => 'required',
                'smscode' => 'required',
            ],
            [
                'phone.required' => '请输入手机号',
                'smscode.required' => '请输入验证码',
            ]
        );
        // 手机号验证
        $this->commonService->mobileValid($param['phone']);
        // 校验验证码
        $accountType = Sms::ACCOUNT_PHONE;
        $this->commonService->smsValid($param['smscode'], Sms::SMS_TYPE_LOGIN, $param['phone'], $accountType);
        //短信验证码登录
        $res = $this->loginService->smsLogin($param, $accountType);
        //返回数据
        return $this->success($res);
    }

    /**
     * cli 客户端登录
     * @return \Psr\Http\Message\ResponseInterface
     * @throws \Exception
     */
    public function cliLogin()
    {
        $param = $this->validate(
            [
                'account' => 'required|string',
                'password' => 'required|string',
                'hostinfo' => 'required|array',
            ],
            [
                'account.required' => '账号不能为空',
                'password.required' => '密码不能为空',
                'hostinfo.required' => '基础信息不能为空',
            ]
        );
        $accountType = Sms::ACCOUNT_EMAIL;
        $this->commonService->emailValid($param['account']);
        // 增加密码错误次数限制
        $accountKey = sprintf("cli_login_inc_%s",$param['account']);
        $accountInc = $this->redis->get($accountKey);
        if ($accountInc > 3){
            throw new AppException(
                ErrorCode::CLI_LOGIN_INC_FAIL,
                ErrorCode::getMessage(ErrorCode::CLI_LOGIN_INC_FAIL)
            );
        }
        try{
            //账号密码登录
            $res = $this->loginService->pwdLogin($param, $accountType);
        }catch (AppException $ex){
            if ($ex->getCode() === ErrorCode::USER_PASSWORD_ERROR){
                $this->redis->incr($accountKey);
                $this->redis->expire($accountKey,300);
            }
            throw $ex;
        }

        // 保存信息
        // message HostInfo{
        //  string hostname = 1;
        //  string os = 2;  // ex: freebsd, linux
        //  string platform = 3; // ex: ubuntu, linuxmint
        //  string platformFamily = 4; // ex: debian, rhel
        //  string platformVersion = 5; // version of the complete OS
        //  string kernelVersion = 6; // version of the OS kernel (if available)
        //  string kernelArch = 7;  // native cpu architecture queried at runtime, as returned by `uname -m` or empty string in case of error
        //  string hostid = 8; // ex: uuid
        //  int32 cpuCount = 9; // cpu 数目
        //  string cpuModel = 10; // cpu 类型
        //}

        $cliVersion = $this->request->getHeaderLine("X-CG-Version");
        $this->loginService->createUserCliInfo($res["profile"]["id"],$cliVersion,$param["hostinfo"]);
        //返回数据
        return $this->success($res);
    }

    /**
     * 退出登录.
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function logout()
    {
        auth()->logout();
        return $this->success();
    }
}
