<?php

namespace App\Services;

use App\Constants\ErrorCode;
use App\Exception\AppException;
use PHPMailer\PHPMailer\PHPMailer;

class Utils
{
    protected $emailCodeDebug = false;

    public function __construct()
    {
        $this->emailCodeDebug = config('debug.emailcode', false);
    }

    /**
     * 简易发送邮件.
     * 
     * @param array $receivers ['xx@example.com' => 'realname']
     */
    public function sendMail(array $receivers, $subject, $tplName, $params)
    {
        $tplPath = sprintf('%s/storage/static/emailTemplate/%s.html', BASE_PATH, $tplName);
        if (!is_file($tplPath)) {
            throw new AppException(
                ErrorCode::EMAIL_CODE_TEMP_ERROR,
                ErrorCode::getMessage(ErrorCode::EMAIL_CODE_TEMP_ERROR)
            );
        }
        $tpl = file_get_contents($tplPath);

        // 变量占位符替换
        if (!empty($params)) {
            $search = [];
            $replace = [];
            foreach ($params as $varName => $varVal) {
                $search[] = '{{' . $varName . '}}';
                $replace[] = $varVal;
            }
            $tpl = str_replace($search, $replace, $tpl);
        }

        if ($this->emailCodeDebug) {
            echo sprintf("EmailDebug: [receivers=%s, subject=%s, data=%s]\n", json_encode($receivers), $subject, $tpl);
            return;
        }

        $config = config('email');
        $mail = new PHPMailer();
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
        $mail->Subject = $subject;
        $mail->MsgHTML($tpl);

        // 设置收件人
        foreach ($receivers as $receiver => $name) {
            $mail->AddAddress($receiver, $name);
        }

        $result = $mail->Send();
        if (!$result) {
            throw new AppException(
                ErrorCode::EMAIL_CODE_SEND_ERROR,
                sprintf('邮件发送失败：%s', $mail->ErrorInfo)
            );
        }
    }

    /**
     * 解析emails配置.
     * 
     * @param string $emails 'foo@example.com:姓名,bar@example.com:姓名2'
     * @return array
     */
    public static function parseEmailsConfig($emails)
    {
        $receivers = [];
        if (empty($emails)) {
            return $receivers;
        }

        foreach (explode(',', $emails) as $receiver) {
            $parts = explode(':', $receiver, 2);
            $receivers[$parts[0]] = $parts[1] ?? '';
        }

        return $receivers;
    }
}
