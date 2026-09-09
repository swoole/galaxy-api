<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Services;

use App\Constants\ErrorCode;
use App\Constants\RedisKey;
use App\Constants\Sms;
use App\Exception\AppException;
use App\Model\LogSmsCode;
use App\Model\User;
use App\Support\Functions;
use Hyperf\Di\Annotation\Inject;
use PHPMailer\PHPMailer\PHPMailer;

class CommonService
{
    /**
     * @Inject
     * @var LogSmsCode
     */
    #[Inject]
    private $logSmsCodeModel;

    /**
     * @Inject
     * @var User
     */
    #[Inject]
    private $userModel;

    protected $emailCodeDebug = false;

    public function __construct()
    {
        $this->emailCodeDebug = config('debug.emailcode', false);
    }

    /**
     * 手机号 / 邮箱 发送验证码
     * @param $uid
     * @param $codeType
     * @param $account
     * @param $accountType
     * @param $isNew
     * @return mixed
     */
    public function smsCode($uid, $codeType, $account, $accountType, $isNew = 0)
    {
        if ($accountType == Sms::ACCOUNT_PHONE) {
            throw new AppException(ErrorCode::SMS_CODE_SEND_ERROR, '短信功能已停用，请使用邮箱验证码');
        }

        //校验账号是否存在 && 新邮箱或新手机号标识
        if (!in_array((int) $codeType, Sms::SMS_ACCOUNT_BLACKLIST) && ! $isNew) {
            $this->accountValid($account, $accountType);
        }
        //频次校验
        $record = $this->logSmsCodeModel->findLastRecord($account, $accountType, $codeType);
        $this->frequentValid($record, $account, $accountType, $codeType);
        //记录日志
        $code = Functions::createSmsCode(); //生成短信验证码
        $time = time();
        $expireTime = $time + Sms::SMS_EXPIRE_TIME;
        $insert = [
            $accountType => $account,
            'type' => $codeType,
            'code' => $code,
            'send_at' => $time,
            'expire_at' => $expireTime,
        ];
        $newRecord = $this->logSmsCodeModel->createRecord($uid, $insert, $accountType);
        //发送验证码
        //发送邮件
        $subject = '验证码';
        $data = ['code' => (string) $code, 'expire' => Sms::SMS_TEMPLATE_EXPIRE_NUM];
        $this->sendEmailCode($subject, $account, $data);
        //更改发送成功状态
        $update = ['status' => LogSmsCode::STATUS_SUCCESS];
        $this->logSmsCodeModel->updateRecord($newRecord['id'], $update);
        //返回过期时间
        if (config('app_env') != 'prod') {
            //todo 测试环境返回验证码
            $res['code'] = $code;
        }
        $res['expire_at'] = $expireTime;
        return $res;
    }

    /**
     * 获取图形验证码
     * @return mixed
     */
    public function getCaptcha()
    {
        // 使用 GD 内置位图字体，不依赖 FreeType 或外部验证码服务。
        $config = config('captcha');
        $charset = (string) $config['charset'];
        $length = max(4, min(8, (int) $config['length']));
        if ($charset === '') {
            throw new AppException(ErrorCode::INVALID_PARAMS, '验证码字符集不能为空');
        }
        $imgCode = '';
        $lastIndex = strlen($charset) - 1;
        for ($i = 0; $i < $length; ++$i) {
            $imgCode .= $charset[random_int(0, $lastIndex)];
        }
        $width = max(120, min(500, (int) $config['imageWidth']));
        $height = max(40, min(200, (int) $config['imageHeight']));
        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new AppException(ErrorCode::INVALID_PARAMS, '图形验证码初始化失败');
        }
        $bytes = false;
        try {
            $background = imagecolorallocate($image, 248, 250, 252);
            imagefilledrectangle($image, 0, 0, $width, $height, $background);
            if ((bool) $config['useNoise']) {
                for ($i = 0; $i < 10; ++$i) {
                    $noise = imagecolorallocate(
                        $image,
                        random_int(170, 225),
                        random_int(170, 225),
                        random_int(170, 225)
                    );
                    imageline(
                        $image,
                        random_int(0, $width - 1),
                        random_int(0, $height - 1),
                        random_int(0, $width - 1),
                        random_int(0, $height - 1),
                        $noise
                    );
                }
            }
            $font = 5;
            $charWidth = imagefontwidth($font);
            $charHeight = imagefontheight($font);
            $spacing = max(3, (int) floor(($width - $charWidth * $length) / ($length + 1)));
            $x = max(4, $spacing);
            foreach (str_split($imgCode) as $char) {
                $color = imagecolorallocate($image, random_int(20, 100), random_int(20, 100), random_int(20, 100));
                $y = max(2, (int) (($height - $charHeight) / 2) + random_int(-6, 6));
                imagestring($image, $font, $x, $y, $char, $color);
                $x += $charWidth + $spacing;
            }
            ob_start();
            imagepng($image);
            $bytes = ob_get_clean();
        } finally {
            imagedestroy($image);
        }
        if (! is_string($bytes) || $bytes === '') {
            throw new AppException(ErrorCode::INVALID_PARAMS, '图形验证码生成失败');
        }
        $base64 = 'data:image/png;base64,' . base64_encode($bytes);
        // 生成唯一标识
        $snowflakeId = Functions::createSnowflakeId();
        $captchaId = md5((string) $snowflakeId);
        // 缓存验证码
        $redisKey = RedisKey::CAPTCHA_REDIS_KEY . $captchaId;
        $ttl = Sms::CAPTCHA_EXPIRE_TIME; //过期时间
        redis()->setex($redisKey, $ttl, $imgCode);
        // 返回数据
        if (config('app_env') != 'prod') {
            //todo 测试环境返回验证码
            $res['code'] = $imgCode;
        }
        $res['captchaid'] = $captchaId;
        $res['captchasrc'] = $base64;
        return $res;
    }

    /**
     * 创建一个短时有效、只能使用一次的滑动验证码挑战.
     */
    public function createSliderCaptcha(): array
    {
        $captchaId = bin2hex(random_bytes(16));
        $ttl = Sms::CAPTCHA_EXPIRE_TIME;
        $challenge = json_encode([
            'created_at' => (int) floor(microtime(true) * 1000),
        ], JSON_THROW_ON_ERROR);

        redis()->setex(RedisKey::SLIDER_CAPTCHA_CHALLENGE_KEY . $captchaId, $ttl, $challenge);

        return [
            'captchaid' => $captchaId,
            'expire_at' => time() + $ttl,
        ];
    }

    /**
     * 校验滑动距离、耗时和轨迹，成功后签发一次性令牌.
     */
    public function verifySliderCaptcha(array $params): array
    {
        $challengeKey = RedisKey::SLIDER_CAPTCHA_CHALLENGE_KEY . $params['captchaid'];
        $challenge = redis()->get($challengeKey);
        redis()->del($challengeKey);

        if (! $challenge) {
            throw new AppException(ErrorCode::CAPTCHA_LOSS_EFFICACY_ERROR, '滑动验证已过期，请重试');
        }

        $challengeData = json_decode($challenge, true, 512, JSON_THROW_ON_ERROR);
        $serverDuration = (int) floor(microtime(true) * 1000) - (int) $challengeData['created_at'];

        $trail = $params['trail'];
        $first = reset($trail);
        $last = end($trail);
        $positions = [];
        $previousTime = -1;
        $validTrail = is_array($first) && is_array($last);

        foreach ($trail as $point) {
            if (! is_array($point) || ! isset($point['x'], $point['t'])) {
                $validTrail = false;
                break;
            }
            $x = (int) $point['x'];
            $time = (int) $point['t'];
            if ($x < 0 || $x > 1000 || $time < $previousTime || $time > 30000) {
                $validTrail = false;
                break;
            }
            $positions[$x] = true;
            $previousTime = $time;
        }

        $duration = (int) $params['duration'];
        $distance = (int) $params['distance'];
        $validTrail = $validTrail
            && $duration >= 350
            && $duration <= 12000
            && $serverDuration >= 350
            && $serverDuration <= Sms::CAPTCHA_EXPIRE_TIME * 1000
            && $distance >= 980
            && count($positions) >= 3
            && (int) $first['x'] <= 30
            && (int) $last['x'] >= 980
            && abs((int) $last['t'] - $duration) <= 750;

        if (! $validTrail) {
            throw new AppException(ErrorCode::CAPTCHA_NOT_EQ_ERROR, '滑动轨迹无效，请重试');
        }

        $token = bin2hex(random_bytes(32));
        redis()->setex(RedisKey::SLIDER_CAPTCHA_TOKEN_KEY . $token, Sms::CAPTCHA_EXPIRE_TIME, '1');

        return ['token' => $token];
    }

    /**
     * 重置密码
     * @param $account
     * @param $accountType
     * @param $param
     */
    public function forgetpassword($account, $accountType, $param)
    {
        //校验账号是否存在
        $user = $this->accountValid($account, $accountType);
        //重置密码
        $user->password = password_hash($param['password'], PASSWORD_DEFAULT);
        $res = $user->save();
        if (! $res) {
            throw new AppException(
                ErrorCode::RESET_PASSWORD_ERROR,
                ErrorCode::getMessage(ErrorCode::RESET_PASSWORD_ERROR)
            );
        }
    }

    /**
     * 检测账号是否存在.
     * @param $account
     * @param $accountType
     * @return null|\Hyperf\Database\Model\Model|\Hyperf\Database\Query\Builder|object
     */
    public function accountValid($account, $accountType)
    {
        $user = $this->userModel->findUserByAccount($account, $accountType);
        if (! $user) {
            throw new AppException(
                ErrorCode::ACCOUNT_NOT_EXIST_ERROR,
                ErrorCode::getMessage(ErrorCode::ACCOUNT_NOT_EXIST_ERROR)
            );
        }
        return $user;
    }

    /**
     * 验证码联合验证.
     */
    public function captchaUnionValid($params, $props = [])
    {
        if (!empty($params['token'])) {
            $this->sliderCaptchaValid($params['token']);
        } elseif (!empty($params['captcha']) && !empty($params['captchaid'])) {
            $this->captchaValid($params['captchaid'], $params['captcha']);
        } else {
            throw new AppException(ErrorCode::INVALID_PARAMS, '验证码参数不合法');
        }
    }

    /**
     * 消费本地滑动验证码签发的一次性令牌.
     */
    public function sliderCaptchaValid(string $token): void
    {
        $tokenKey = RedisKey::SLIDER_CAPTCHA_TOKEN_KEY . $token;
        $valid = redis()->get($tokenKey);
        redis()->del($tokenKey);

        if (! $valid) {
            throw new AppException(ErrorCode::CAPTCHA_LOSS_EFFICACY_ERROR, '滑动验证已失效，请重新验证');
        }
    }

    /**
     * 保存注册邮箱已完成滑动验证的状态.
     */
    public function markRegisterSliderVerified(string $email): void
    {
        redis()->setex(
            $this->registerSliderVerifiedKey($email),
            Sms::REGISTER_SLIDER_EXPIRE_TIME,
            '1'
        );
    }

    /**
     * 校验注册邮箱是否已在30分钟内完成滑动验证.
     */
    public function registerSliderValid(string $email): void
    {
        if (! $this->isRegisterSliderVerified($email)) {
            throw new AppException(
                ErrorCode::CAPTCHA_LOSS_EFFICACY_ERROR,
                '滑动验证已失效，请重新获取邮件验证码'
            );
        }
    }

    public function isRegisterSliderVerified(string $email): bool
    {
        return (bool) redis()->get($this->registerSliderVerifiedKey($email));
    }

    /**
     * 注册完成后清除滑动验证状态.
     */
    public function clearRegisterSliderVerified(string $email): void
    {
        redis()->del($this->registerSliderVerifiedKey($email));
    }

    private function registerSliderVerifiedKey(string $email): string
    {
        return RedisKey::REGISTER_SLIDER_VERIFIED_KEY . hash('sha256', strtolower(trim($email)));
    }

    /**
     * 校验图形验证码 (校验通过 过期时间内一直有效).
     * @param $captchaId
     * @param $code
     */
    public function captchaValid($captchaId, $code)
    {
        $errorCode = '';
        $errorMessage = '';
        $ttl = Sms::CAPTCHA_EXPIRE_TIME; //过期时间
        $redisKey = RedisKey::CAPTCHA_REDIS_KEY . $captchaId;
        $errorNumRedisLey = RedisKey::CAPTCHA_ERROR_NUM_REDIS_KEY . $captchaId;
        //获取验证码缓存
        $redisCode = redis()->get($redisKey);
        if (! $redisCode) {
            $errorCode = ErrorCode::CAPTCHA_LOSS_EFFICACY_ERROR;
            $errorMessage = ErrorCode::getMessage(ErrorCode::CAPTCHA_LOSS_EFFICACY_ERROR);
            //删除图形验证码信息
            redis()->del($errorNumRedisLey);
            goto ERROR;
        }
        //获取错误次数
        $errorNum = redis()->get($errorNumRedisLey);
        if (! empty($errorNum) && $errorNum >= 3) {
            $errorCode = ErrorCode::CAPTCHA_LOSS_EFFICACY_ERROR;
            $errorMessage = ErrorCode::getMessage(ErrorCode::CAPTCHA_LOSS_EFFICACY_ERROR);
            //删除图形验证码信息
            redis()->del($redisKey);
            //删除图形验证码错误信息
            redis()->del($errorNumRedisLey);
            goto ERROR;
        }
        //校验验证码
        if (strtolower($redisCode) != strtolower($code)) {
            //记录redis输入错误次数
            if (empty($errorNum)) {
                //创建错误次数记录
                redis()->setex($errorNumRedisLey, $ttl, 1);
            } else {
                //错误次数 +1
                redis()->incr($errorNumRedisLey);
            }
            $errorCode = ErrorCode::CAPTCHA_NOT_EQ_ERROR;
            $errorMessage = ErrorCode::getMessage(ErrorCode::CAPTCHA_NOT_EQ_ERROR);
            goto ERROR;
        }
        ERROR:
        if ($errorCode && $errorMessage) {
            throw new AppException($errorCode, $errorMessage);
        }
        //验证通过
        //删除图形验证码信息
        redis()->del($redisKey);
        //删除图形验证码错误信息
        redis()->del($errorNumRedisLey);
    }

    /**
     * 限制发送频次
     * @param $record
     * @param $account
     * @param $accountType
     * @param $codeType
     */
    public function frequentValid($record, $account, $accountType, $codeType)
    {
        if ($record) {
            //手机号每日发送上限校验
            if ($accountType == Sms::ACCOUNT_PHONE) {
                $recordList = $this->logSmsCodeModel->getTodayRecordList($account, $accountType, $codeType);
                if (count($recordList) >= Sms::SMS_SEND_NUM_MAX) {
                    throw new AppException(
                        ErrorCode::SMS_TODAY_SEND_MAX_ERROR,
                        ErrorCode::getMessage(ErrorCode::SMS_TODAY_SEND_MAX_ERROR)
                    );
                }
            }
            //1分钟中之内禁止发送
            $offsetTime = time() - $record['send_at'];
            $residueTime = 60 - $offsetTime;
            if ($offsetTime <= 60) {
                throw new AppException(
                    ErrorCode::SMS_CODE_FREQUENT_ERROR,
                    sprintf(ErrorCode::getMessage(ErrorCode::SMS_CODE_FREQUENT_ERROR), $residueTime)
                );
            }
        }
    }

    /**
     * 验证手机号.
     * @param $mobile
     */
    public function mobileValid($mobile)
    {
        if (! preg_match('/^1[3456789]\\d{9}$/', $mobile)) {
            throw new AppException(
                ErrorCode::PARAMS_ERROR,
                ErrorCode::getMessage(ErrorCode::ILLEGAL_PHONE_ERROR)
            );
        }
    }

    /**
     * 验证邮箱.
     * @param $email
     */
    public function emailValid($email)
    {
        if (! preg_match('/^[\w\-\.]+@[\w\-\.]+(\.\w+)+$/', $email)) {
            throw new AppException(
                ErrorCode::PARAMS_ERROR,
                ErrorCode::getMessage(ErrorCode::ILLEGAL_EMAIL_ERROR)
            );
        }
    }

    /**
     * 验证验证码类型.
     * @param $type
     */
    public function smsTypeValid($type)
    {
        if (! in_array($type, Sms::SMS_TYPE_ARRAY)) {
            throw new AppException(
                ErrorCode::PARAMS_ERROR,
                ErrorCode::getMessage(ErrorCode::SMS_CODE_TYPE_ERROR)
            );
        }
    }

    /**
     * 校验两次密码一致.
     * @param $password
     * @param $passwordConfirm
     */
    public function pwdConfirmValid($password, $passwordConfirm)
    {
        if ($password != $passwordConfirm) {
            throw new AppException(
                ErrorCode::PARAMS_ERROR,
                ErrorCode::getMessage(ErrorCode::PASSWORD_CONFIRM_ERROR)
            );
        }
    }

    /**
     * 校验数字验证码
     * @param $code          //验证码
     * @param $codeType      //验证码类型
     * @param $account       //手机号 or 邮箱
     * @param $accountType   //phone or email
     */
    public function smsValid($code, $codeType, $account, $accountType, bool $consume = false)
    {
        $errorCode = '';
        $errorMessage = '';
        $ttl = Sms::CAPTCHA_EXPIRE_TIME; //过期时间
        $errorNumRedisLey = RedisKey::SMS_ERROR_NUM_REDIS_KEY . 'codeType-' . $codeType . ':' . $account;
        //获取验证码记录
        $record = $this->logSmsCodeModel->findLastRecord($account, $accountType, $codeType);
        if (empty($record)) {
            $errorCode = ErrorCode::SMS_CODE_NO_EXIST_ERROR;
            $errorMessage = ErrorCode::getMessage(ErrorCode::SMS_CODE_NO_EXIST_ERROR);
            goto ERROR;
        }
        //校验过期时间
        if ($record['expire_at'] < time()) {
            $errorCode = ErrorCode::SMS_CODE_LOSS_EFFICACY_ERROR;
            $errorMessage = ErrorCode::getMessage(ErrorCode::SMS_CODE_LOSS_EFFICACY_ERROR);
            goto ERROR;
        }
        //获取错误次数
        $errorNum = redis()->get($errorNumRedisLey);
        if (! empty($errorNum) && $errorNum >= 3) {
            $errorCode = ErrorCode::SMS_CODE_LOSS_EFFICACY_ERROR;
            $errorMessage = ErrorCode::getMessage(ErrorCode::SMS_CODE_LOSS_EFFICACY_ERROR);
            //验证码置为失效 过期时间置为当前时间
            $this->logSmsCodeModel->updateRecord($record['id'], ['expire_at' => time()]);
            //删除验证码错误信息
            redis()->del($errorNumRedisLey);
            goto ERROR;
        }
        //校验验证码正确性
        if ($code != $record['code']) {
            //记录redis输入错误次数
            if (empty($errorNum)) {
                //创建错误次数记录
                redis()->setex($errorNumRedisLey, $ttl, 1);
            } else {
                //错误次数 +1
                redis()->incr($errorNumRedisLey);
            }
            $errorCode = ErrorCode::SMS_CODE_VALID_ERROR;
            $errorMessage = ErrorCode::getMessage(ErrorCode::SMS_CODE_VALID_ERROR);
            goto ERROR;
        }

        if ($consume) {
            $this->logSmsCodeModel->updateRecord($record['id'], [
                'status' => LogSmsCode::STATUS_FAIL,
                'expire_at' => time(),
            ]);
            redis()->del($errorNumRedisLey);
        }

        ERROR:
        if ($errorCode && $errorMessage) {
            throw new AppException($errorCode, $errorMessage);
        }
    }

    /**
     * API-发送邮件验证码
     * @param string $subject
     * @param $email
     * @param $data
     */
    protected function sendEmailCode($subject, $email, $data)
    {
        // todo 获取邮件HTML模板
        $htmlTempPath = BASE_PATH . '/storage/static/emailTemplate/code.html';
        if (! is_file($htmlTempPath)) {
            throw new AppException(
                ErrorCode::EMAIL_CODE_TEMP_ERROR,
                ErrorCode::getMessage(ErrorCode::EMAIL_CODE_TEMP_ERROR)
            );
        }
        $html = file_get_contents($htmlTempPath);
        if (! $html) {
            throw new AppException(
                ErrorCode::EMAIL_CODE_TEMP_ERROR,
                ErrorCode::getMessage(ErrorCode::EMAIL_CODE_TEMP_ERROR)
            );
        }
        //替换模板数据
        $search = $replace = [];
        foreach ($data as $k => $v) {
            $search[] = '{' . $k . '}';
            $replace[] = $v;
        }
        $html = str_replace($search, $replace, $html);

        if ($this->emailCodeDebug) {
            echo sprintf("EmailDebug: [receiver=%s, subject=%s, data=%s]\n", $email, $subject, $html);
            return;
        }

        //发送邮件
        $errorCode = ErrorCode::EMAIL_CODE_SEND_ERROR;
        $message = ErrorCode::getMessage($errorCode);
        try {
            $config = config('email');
            $mail = make(PHPMailer::class);
            $mail->IsSMTP();
            $mail->CharSet = $config['CharSet'];
            $mail->SMTPDebug = $config['SMTPDebug'];
            $mail->SMTPAuth = $config['SMTPAuth'];
            $mail->SMTPSecure = $config['SMTPSecure'];
            $mail->Host = $config['Host'];
            $mail->Port = $config['Port'];
            $mail->Username = $config['Username'];
            $mail->Password = $config['Password'];
            $mail->SetFrom($config['FromEmail'], $config['FromNickName']);
            $mail->Subject = $subject; //主题信息
            $mail->MsgHTML($html); //邮件HTML内容
            $mail->AddAddress($email); //收件人
            $result = $mail->Send();
            if (! $result) {
                $errorInfo = $mail->ErrorInfo;
                throw new AppException($errorCode, $message . '(' . $errorInfo . ')');
            }
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            $errorInfo = $e->getMessage();
            throw new AppException($errorCode, $message . '(' . $errorInfo . ')');
        }
    }
}
