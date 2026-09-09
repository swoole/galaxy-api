<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Controller;

use App\Constants\Sms;
use App\Services\CommonService;
use Hyperf\Di\Annotation\Inject;

/**
 * 公共功能
 * Class CommonController.
 */
class CommonController extends AbstractController
{
    /**
     * @Inject
     * @var CommonService
     */
    #[Inject]
    private $commonService;

    /**
     * 获取图形验证码
     * @throws \Exception
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function captcha()
    {
        //获取图形验证码
        $res = $this->commonService->getCaptcha();
        return $this->success($res);
    }

    /**
     * 获取本地滑动验证码挑战.
     */
    public function sliderCaptcha()
    {
        return $this->success($this->commonService->createSliderCaptcha());
    }

    /**
     * 校验滑动轨迹并签发一次性令牌.
     */
    public function verifySliderCaptcha()
    {
        $param = $this->validate([
            'captchaid' => 'required|string',
            'distance' => 'required|integer|min:0|max:1000',
            'duration' => 'required|integer|min:0|max:30000',
            'trail' => 'required|array|min:3|max:100',
        ]);

        return $this->success($this->commonService->verifySliderCaptcha($param));
    }

    /**
     * 发送短信验证吗.
     * @throws \Exception
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function smsCode()
    {
        $param = $this->validate([
            'phone' => 'required|string',
            'type' => 'required|integer',
            'captcha' => 'required|array',
            'captcha.token' => 'nullable|string',
            'captcha.captchaid' => 'nullable|string',
            'captcha.captcha' => 'nullable|string',
            'action' => 'required|string',
        ]);
        //验证手机号
        $this->commonService->mobileValid($param['phone']);
        //验证验证码类型
        $this->commonService->smsTypeValid($param['type']);
        // 联合验证码校验
        $this->commonService->captchaUnionValid($param['captcha'], ['action' => $param['action']]);
        //发送验证码
        $uid = 0;
        $codeType = $param['type'];
        $account = $param['phone'];
        $accountType = Sms::ACCOUNT_PHONE;
        $res = $this->commonService->smsCode($uid, $codeType, $account, $accountType);
        //返回数据
        return $this->success($res);
    }

    /**
     * 通过邮箱获取验证码
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function getAccountCode()
    {
        $param = $this->validate([
            'account' => 'required|string',
            'captcha' => 'required|array',
            'captcha.token' => 'nullable|string',
            'captcha.captchaid' => 'nullable|string',
            'captcha.captcha' => 'nullable|string',
            'action' => 'required|string',
        ]);
        $accountType = Sms::ACCOUNT_EMAIL;
        $this->commonService->emailValid($param['account']);
        // 联合验证码校验
        $this->commonService->captchaUnionValid($param['captcha'], ['action' => $param['action']]);
        //发送验证码
        $uid = 0;
        $codeType = Sms::SMS_TYPE_FORGET;
        $account = $param['account'];
        $res = $this->commonService->smsCode($uid, $codeType, $account, $accountType);
        //返回数据
        return $this->success($res);
    }

    /**
     * 重置密码操作.
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function forgetpassword()
    {
        $param = $this->validateAll(
            [
                'account' => 'required',
                'password' => 'required|min:6|max:20',
                'password_retry' => 'required',
                'smscode' => 'required|string',
            ],
            [
                'account.required' => '请输入账号',
                'password.required' => '请输入密码',
                'password.min' => '密码长度6-20个字符',
                'password.max' => '密码长度6-20个字符',
                'password_retry.required' => '请输入确认密码',
                'smscode.required' => '请输入验证码',
            ]
        );
        $accountType = Sms::ACCOUNT_EMAIL;
        $this->commonService->emailValid($param['account']);
        //校验两次密码一致
        $this->commonService->pwdConfirmValid($param['password'], $param['password_retry']);
        // 校验验证码
        $account = $param['account'];
        $this->commonService->smsValid($param['smscode'], Sms::SMS_TYPE_FORGET, $account, $accountType);
        //处理忘记密码
        $this->commonService->forgetpassword($account, $accountType, $param);
        //返回数据
        return $this->success();
    }

}
