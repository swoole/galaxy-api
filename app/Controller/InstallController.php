<?php

declare(strict_types=1);
/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */

namespace App\Controller;

use App\Exception\AppException;
use App\Model\User;
use App\Services\LoginService;
use Hyperf\DbConnection\Db;
use Hyperf\Redis\Redis;
use Psr\Http\Message\ResponseInterface;

class InstallController extends AbstractController
{
    private const LOCK_KEY = 'cg:install:first-user';

    public function __construct(
        private readonly LoginService $loginService,
        private readonly Redis $redis,
    ) {}

    public function page(): ResponseInterface
    {
        if ($this->isInstalled()) {
            return $this->response->raw('Not Found')->withStatus(404);
        }

        $file = BASE_PATH . '/storage/static/install.html';
        if (! is_file($file)) {
            return $this->response->raw('Installer page is missing')->withStatus(500);
        }

        return $this->response->html((string) file_get_contents($file));
    }

    public function status(): ResponseInterface
    {
        $connected = false;
        $error = '';
        try {
            Db::select('SELECT 1');
            $connected = true;
        } catch (\Throwable) {
            $error = '数据库连接失败，请检查部署配置和数据库状态。';
        }

        return $this->success([
            'installed' => $connected && $this->isInstalled(),
            'default_email' => (string) config('app.install_default_email', 'admin@example.com'),
            'database' => [
                'connected' => $connected,
                'host' => (string) config('databases.default.host', ''),
                'port' => (int) config('databases.default.port', 3306),
                'name' => (string) config('databases.default.database', ''),
                'username' => (string) config('databases.default.username', ''),
                'error' => $error,
            ],
        ]);
    }

    public function initialize(): ResponseInterface
    {
        if ($this->isInstalled()) {
            return $this->response->raw('Not Found')->withStatus(404);
        }

        $params = $this->validateAll([
            'install_token' => 'required|string|min:32|max:256',
            'email' => 'required|email|max:1024',
            'nickname' => 'required|string|max:255',
            'password' => 'required|string|min:8|max:20',
            'password_retry' => 'required|string|min:8|max:20',
        ]);

        $expectedToken = (string) config('app.install_token', '');
        if ($expectedToken === '' || ! hash_equals($expectedToken, (string) $params['install_token'])) {
            throw new AppException(403, '安装令牌无效');
        }
        if ($params['password'] !== $params['password_retry']) {
            throw new AppException(422, '两次输入的密码不一致');
        }

        $lockToken = bin2hex(random_bytes(16));
        if (! $this->redis->set(self::LOCK_KEY, $lockToken, ['nx', 'ex' => 60])) {
            throw new AppException(409, '另一个初始化请求正在执行，请稍后重试');
        }

        try {
            if ($this->isInstalled()) {
                return $this->response->raw('Not Found')->withStatus(404);
            }
            $this->loginService->register([
                'email' => strtolower(trim((string) $params['email'])),
                'nickname' => trim((string) $params['nickname']),
                'password' => (string) $params['password'],
            ]);
        } finally {
            $this->releaseLock($lockToken);
        }

        return $this->success([], '初始化完成');
    }

    private function isInstalled(): bool
    {
        try {
            return User::query()->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    private function releaseLock(string $token): void
    {
        try {
            $this->redis->eval(
                "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) end return 0",
                [self::LOCK_KEY, $token],
                1,
            );
        } catch (\Throwable) {
        }
    }
}
