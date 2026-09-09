<?php

namespace App\Services\Notify\Channel;

use App\Constants\ErrorCode;
use App\Exception\AppException;
use App\Services\Notify\AbstractNotifyChannel;
use eftec\bladeone\BladeOne;
use PHPMailer\PHPMailer\PHPMailer;
use Psr\Container\ContainerInterface;

class Email extends AbstractNotifyChannel
{
    protected $emailCodeDebug = false;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->emailCodeDebug = config('debug.emailcode', false);
    }

    /**
     * 发送通知.
     */
    public function send($users, array $config, array $params = [], ?string $scene = null)
    {
        $receivers = [];
        foreach ($users as $user) {
            if (!empty($user['email'])) {
                $receivers[$user['email']] = '';
            }
        }

        $paramsFiltered = $this->beforeFilter($config, $params);
        $data = [
            'subject' => $config['subject'],
            'template' => $config['template'],
            'params' => $paramsFiltered,
        ];
        $data = $this->afterFilter($config, $params, $data);

        $this->sendEmail($receivers, $data['subject'], $data['template'], $data['params']);
    }

    /**
     * 发送邮件.
     * @param array $receivers ['xx@example.com' => 'realname']
     */
    public function sendEmail(array $receivers, $subject, $tplName, $params)
    {
        $tpl = $this->renderHtml($tplName, $params);

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
     * 渲染html.
     */
    protected function renderHtml($tplName, $params)
    {
        $templatePath = BASE_PATH . '/storage/static/notify/email/';
        $cachePath = BASE_PATH . '/runtime/views/email/';
        if (! is_dir($cachePath) && ! mkdir($cachePath, 0775, true) && ! is_dir($cachePath)) {
            throw new AppException(ErrorCode::EMAIL_CODE_SEND_ERROR, '无法创建邮件模板编译目录');
        }
        if (! is_writable($cachePath)) {
            throw new AppException(ErrorCode::EMAIL_CODE_SEND_ERROR, '邮件模板编译目录不可写');
        }
        $mode = BladeOne::MODE_DEBUG;
        $blade = new BladeOne($templatePath, $cachePath, $mode);

        $html = $blade->run($tplName, $params);

        return $html;
    }
}
