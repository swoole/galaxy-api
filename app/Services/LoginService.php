<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Services;

use App\Constants\ErrorCode;
use App\Constants\UserLoginChannel;
use App\Exception\AppException;
use App\Model\LogUserLogin;
use App\Model\Org;
use App\Model\User;
use App\Model\UserCliInfo;
use App\Model\UserProfile;
use App\Model\UserSshKey;
use App\Support\Git;
use Hyperf\Contract\ContainerInterface;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Context\Context;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class LoginService
{
    /**
     * @Inject
     * @var ContainerInterface
     */
    #[Inject]
    protected $container;

    /**
     * @Inject
     * @var User
     */
    #[Inject]
    private $userModel;

    /**
     * @Inject
     * @var UserProfile
     */
    #[Inject]
    private $userProfileModel;

    /** @Inject */
    #[Inject]
    private UserSshKey $userSshKeyModel;

    /**
     * @Inject
     * @var Org
     */
    #[Inject]
    private $orgModel;

    /**
     * @Inject
     * @var UserCliInfo
     */
    #[Inject]
    private $userCliInfoModel;

    /**
     * @Inject
     * @var LogUserLogin
     */
    #[Inject]
    private $logUserLoginModel;

    /**
     * 注册.
     * @param $param
     * @throws \Exception
     * @return array
     */
    public function register($param)
    {
        // 处理注册逻辑
        $res = $this->handleRegister($param);
        // 返回数据
        return $this->handleReturn($res);
    }

    /**
     * 账号密码登录.
     * @param $param
     * @param $accountType
     * @throws \Exception
     * @return array
     */
    public function pwdLogin($param, $accountType)
    {
        /**
         * @var User $user
         */
        $user = $this->userModel->findUserByAccount($param['account'], $accountType);
        if (empty($user)) {
            // 用户未注册
            throw new AppException(
                ErrorCode::USER_PASSWORD_ERROR,
                ErrorCode::getMessage(ErrorCode::USER_PASSWORD_ERROR)
            );
        }
        // 校验密码
        if (! password_verify($param['password'], $user['password'])) {
            throw new AppException(
                ErrorCode::USER_PASSWORD_ERROR,
                ErrorCode::getMessage(ErrorCode::USER_PASSWORD_ERROR)
            );
        }
        // 记录登陆日志
        $insertLog = $this->handleInsertLogData($user, $param);
        $logLogin = $this->logUserLoginModel->writeLog($insertLog);
        // 处理登录逻辑
        Db::beginTransaction();
        try {
            // 处理注册逻辑
            $res = $this->handleLogin($user);
            // 事务提交
            Db::commit();
            // 返回数据
            return $this->handleReturn($res);
        } catch (\Throwable $ex) {
            // 事务回滚
            Db::rollBack();
            // 登录日志记为失败
            $logLogin->result = LogUserLogin::RESULT_FAILED;
            $logLogin->save();
            throw new \Exception($ex->getMessage(), $ex->getCode());
        }
    }

    /**
     * 短信验证码登录.
     * @param $param
     * @param $accountType
     * @throws \Exception
     * @return array
     */
    public function smsLogin($param, $accountType)
    {
        /**
         * @var User $user
         */
        $user = $this->userModel->findUserByAccount($param['phone'], $accountType);
        if (empty($user)) {
            // 用户未注册 执行注册
            return $this->register($param);
        }
        // 记录登陆日志
        $insertLog = $this->handleInsertLogData($user, $param);
        $logLogin = $this->logUserLoginModel->writeLog($insertLog);
        // 处理登录逻辑
        Db::beginTransaction();
        try {
            // 处理注册逻辑
            $res = $this->handleLogin($user);
            // 事务提交
            Db::commit();
            // 返回数据
            return $this->handleReturn($res);
        } catch (\Throwable $ex) {
            // 事务回滚
            Db::rollBack();
            // 登录日志记为失败
            $logLogin->result = LogUserLogin::RESULT_FAILED;
            $logLogin->save();
            throw new \Exception($ex->getMessage(), $ex->getCode());
        }
    }

    /**
     * 处理用户登录.
     * @param User $user
     * @return array
     */
    public function handleLogin($user)
    {
        // 1.获取用户信息
        $userProfile = $this->userProfileModel->findUserProfile($user['id']);
        // 2.获取最后空间信息
        $org = $user['last_org'] ? $this->orgModel->findOrg($user['last_org']) : null;
        // 3.生成token
        $token = $this->createUserToken($user);
        // 4.更新最后登录时间
        $user->last_login = time();
        $user->save();

        // 返回数据
        return compact('user', 'userProfile', 'org', 'token');
    }

    /**
     * 处理注册逻辑.
     * @param $param
     * @throws \Exception
     * @return array
     */
    public function handleRegister($param)
    {
        Db::beginTransaction();
        try {
            // 1.生成用户
            $user = $this->userModel->createUser($param);
            if (! $user) {
                throw new \Exception(ErrorCode::getMessage(ErrorCode::USER_CREATE_ERROR), ErrorCode::USER_CREATE_ERROR);
            }
            // 2.生成用户信息
            $userProfile = $this->userProfileModel->createUserProfile($user['id'], $param);
            if (! $userProfile) {
                throw new \Exception(ErrorCode::getMessage(ErrorCode::USER_PROFILE_CREATE_ERROR), ErrorCode::USER_PROFILE_CREATE_ERROR);
            }
            // 3.生成归用户个人所有的 SSH 密钥对。项目组 Git 平台密钥另行生成。
            $sshKey = $this->createUserSshKey($user);
            if (! $sshKey) {
                throw new \Exception(ErrorCode::getMessage(ErrorCode::SSH_RECORD_ERROR), ErrorCode::SSH_RECORD_ERROR);
            }
            // 4.更新用户表
            $user->last_login = time();
            $user->save();
            // 6.注册成功直接登录生成token
            $token = $this->createUserToken($user);
            // 7.新注册用户暂不加入组织
            $org = [];

            // 记录登陆日志
            $insertLog = $this->handleInsertLogData($user, $param);
            $this->logUserLoginModel->writeLog($insertLog);

            Db::commit();

            // 返回数据
            return compact('user', 'userProfile', 'org', 'token');
        } catch (Throwable $e) {
            Db::rollBack();
            throw $e;
        }
    }

    /**
     * 生成用户个人 SSH 密钥对，不作为项目组 Git 平台身份使用。
     */
    public function createUserSshKey($user, bool $insert = true, string $algo = 'ed25519')
    {
        $account = $user['email'] ?: ($user['username'] ?: ('user-' . $user['id']));
        $directory = BASE_PATH . '/runtime/tmp_ssh/user-' . $user['id'] . '-' . bin2hex(random_bytes(6));
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new AppException(ErrorCode::SSH_CREATE_DIR_ERROR, ErrorCode::getMessage(ErrorCode::SSH_CREATE_DIR_ERROR));
        }
        $path = $directory . '/id_key';
        try {
            if (! Git::createSshKey($account, $path, $algo)) {
                throw new AppException(ErrorCode::SSH_CREATE_ERROR, ErrorCode::getMessage(ErrorCode::SSH_CREATE_ERROR));
            }
            $privatekey = file_get_contents($path);
            $pubkey = file_get_contents($path . '.pub');
            if ($privatekey === false || $pubkey === false) {
                throw new AppException(ErrorCode::SSH_CREATE_ERROR, ErrorCode::getMessage(ErrorCode::SSH_CREATE_ERROR));
            }
            $data = [
                'uid' => (int) $user['id'],
                'pubkey' => $pubkey,
                'privatekey' => $privatekey,
                'generate_at' => time(),
            ];
            return $insert ? $this->userSshKeyModel->createUserSshKey($data) : $data;
        } finally {
            @unlink($path);
            @unlink($path . '.pub');
            @rmdir($directory);
        }
    }

    /**
     * 生成用户token.
     * @param $user  //指定单个用户user记录
     * @return mixed
     */
    public function createUserToken($user)
    {
        return auth()->login($user);
    }

    /**
     * 生成cli客户端主机信息.
     * @param $uid
     * @param $cliVersion
     * @param $hostinfo
     */
    public function createUserCliInfo($uid, $cliVersion, $hostinfo)
    {
        $data = [
            'uid' => $uid,
            'version' => $cliVersion,
        ];
        $data = array_merge($data, $hostinfo);
        $this->userCliInfoModel->createCliInfo($data);
    }

    /**
     * 处理登录日志数据.
     * @param $user
     * @param $param
     * @return array
     */
    public function handleInsertLogData($user, $param)
    {
        $ip = getUserIp();
        $city = queryIpAddress($ip);
        return [
            'uid' => $user['id'],
            'city' => $city,
            'ip' => $ip,
            'login_at' => $param['login_at'] ?? time(),
            'result' => $param['result'] ?? LogUserLogin::RESULT_SUCCESS,
            'channel' => $param['channel'] ?? UserLoginChannel::PWD,
            'ua' => getUserAgent(),
            'platform' => $param['platform'] ?? LogUserLogin::PLATFORM_WEB,
        ];
    }

    /**
     * 处理返回数据.
     * @param $res
     * @return array
     */
    public function handleReturn($res)
    {
        Context::override(ResponseInterface::class, function (ResponseInterface $response) use ($res) {
            return $response->withHeader('Authorization', 'Bearer ' . $res['token']);
        });

        return [
            'profile' => [
                'id' => $res['user']['id'],
                'nickname' => $res['userProfile']['nickname'] ?: '',
                'avatar' => $res['userProfile']['avatar'] ?: '',
                'email' => $res['user']['email'] ?: '',
            ],
            'last_org' => $res['org'] ? [
                'id' => $res['org']['id'],
                'title' => $res['org']['title'],
                'logo' => $res['org']['logo'],
            ] : null,
            'token' => $res['token'],
        ];
    }
}
