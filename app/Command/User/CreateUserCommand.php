<?php

declare(strict_types=1);
/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */

namespace App\Command\User;

use App\Command\AbstractCommand;
use App\Model\User;
use App\Services\LoginService;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * Create the first local account without requiring an SMTP service.
 *
 * The password is read from stdin so installers do not expose it in the
 * process list or shell history.
 */
class CreateUserCommand extends AbstractCommand
{
    public function __construct(private readonly LoginService $loginService)
    {
        parent::__construct('user:create');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('Create a local Galaxy user for a self-hosted installation.');
        $this->addOption('email', null, InputOption::VALUE_REQUIRED, 'Login email address');
        $this->addOption('nickname', null, InputOption::VALUE_REQUIRED, 'Display name', 'Administrator');
        $this->addOption('password-stdin', null, InputOption::VALUE_NONE, 'Read the password from stdin');
        $this->addOption('if-not-exists', null, InputOption::VALUE_NONE, 'Succeed when the email already exists');
    }

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->input->getOption('email')));
        $nickname = trim((string) $this->input->getOption('nickname'));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->output->error('请输入有效的邮箱地址。');
            return SymfonyCommand::INVALID;
        }
        if ($nickname === '') {
            $this->output->error('昵称不能为空。');
            return SymfonyCommand::INVALID;
        }

        if (User::query()->where('email', $email)->exists()) {
            if ((bool) $this->input->getOption('if-not-exists')) {
                $this->output->writeln(sprintf('用户已存在：%s', $email));
                return SymfonyCommand::SUCCESS;
            }
            $this->output->error(sprintf('用户已存在：%s', $email));
            return SymfonyCommand::FAILURE;
        }

        if (! (bool) $this->input->getOption('password-stdin')) {
            $this->output->error('必须使用 --password-stdin 从标准输入读取密码。');
            return SymfonyCommand::INVALID;
        }

        $password = rtrim((string) fgets(STDIN), "\r\n");
        $length = strlen($password);
        if ($length < 6 || $length > 20) {
            $this->output->error('密码长度必须为 6 至 20 个字符。');
            return SymfonyCommand::INVALID;
        }

        $this->loginService->register([
            'email' => $email,
            'password' => $password,
            'nickname' => $nickname,
        ]);
        $this->output->success(sprintf('用户创建成功：%s', $email));
        return SymfonyCommand::SUCCESS;
    }
}
