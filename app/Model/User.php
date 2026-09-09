<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Model;

use App\Constants\ErrorCode;
use App\Exception\AppException;
use App\Support\Functions;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Redis\Redis;
use Qbhy\HyperfAuth\AuthAbility;
use Qbhy\HyperfAuth\Authenticatable;
use Ramsey\Uuid\Uuid;

/**
 * @property int $id
 * @property string $username
 * @property string $email
 * @property string $phone
 * @property string $password
 * @property int $status
 * @property int $last_login
 * @property int $last_org
 * @property int $register_at
 */
class User extends Model implements Authenticatable
{
    use AuthAbility;

    public const DEFAULT_PASSWORD = '';

    /**
     * 用户状态  0-禁用  1-正常  2-注销
     */
    public const STATUS_DISABLED = 0;

    public const STATUS_NORMAL = 1; //正常

    public const STATUS_CANCELLATION = 2; //注销

    /**
     * 用户认证状态.
     */
    public const AUTH_STATUS_NO = 0; // 未认证
    public const AUTH_STATUS_ACCEPTED = 1; // 已认证
    public const AUTH_STATUS_EXPIRED = 2; // 认证过期

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected ?string $table = 'user';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
//    protected $fillable = [];
    protected array $guarded = [];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected array $casts = ['id' => 'integer', 'status' => 'integer', 'last_login' => 'integer', 'last_org' => 'integer', 'register_at' => 'integer'];

    /**
     * @Inject
     */
    #[Inject]
    protected Redis $redis;

    /**
     * 创建用户表数据.
     * @param $data
     * @return \Hyperf\Database\Model\Model|User
     */
    public function createUser($data)
    {
        $time = time();
        $user = self::create([
            'username' => $this->genUsername(),
            'email' => $data['email'] ?? '',
            'phone' => $data['phone'] ?? '',
            'password' => ! empty($data['password']) ? password_hash($data['password'], PASSWORD_DEFAULT) : self::DEFAULT_PASSWORD,
            //            'password' => password_hash($data['password'] ?? self::DEFAULT_PASSWORD, PASSWORD_DEFAULT),
            'status' => self::STATUS_NORMAL,
            'last_login' => $time,
            'last_org' => 0,
            'register_at' => $time,
        ]);

        return $user;
    }

    /**
     * 获取用户数据 - 根据账号(手机号/邮箱).
     * @param $account
     * @param $accountType
     * @return null|\Hyperf\Database\Model\Model|\Hyperf\Database\Query\Builder|object
     */
    public function findUserByAccount($account, $accountType)
    {
        $model = self::query();
        $model->where($accountType, $account);
        $model->orderBy('id', 'desc');
        return $model->first();
    }

    /**
     * 申请身份验证.
     */
    public function applyIdConfirm(User $user, $password)
    {
        // 校验密码
        if (!password_verify($password, $user['password'])) {
            throw new AppException(
                ErrorCode::USER_PASSWORD_ERROR,
                ErrorCode::getMessage(ErrorCode::USER_PASSWORD_ERROR)
            );
        }

        $token = sha1(Uuid::uuid4()->toString());
        $key = sprintf('cg.idc.%s.%s', $user['id'], $token);
        $this->redis->set($key, '1', ['ex' => 300]);

        return $token;
    }

    /**
     * 身份验证.
     */
    public function idConfirm($uid, $token)
    {
        $key = sprintf('cg.idc.%s.%s', $uid, $token);
        $deleted = $this->redis->del($key);

        if ($deleted) {
            return true;
        }

        throw new AppException(403, '身份验证失败');
    }

    /**
     * 用户简要信息.
     */
    public function simpleProfile($uid)
    {
        $user = $this->where('id', $uid)
            ->select(
                'id', 'username', 'email', 'phone', 'last_login', 'register_at', 'password'
            )->first();
        if (empty($user)) {
            throw new AppException(404, '用户不存在');
        }
        $user['nickname'] = '';
        $user['avatar'] = '';
        $profile = UserProfile::where('uid', $uid)
            ->select('nickname', 'avatar')
            ->first();
        if (!empty($profile)) {
            $user['nickname'] = $profile['nickname'];
            $user['avatar'] = $profile['avatar'];
        }
        // 是否设置密码 0-未设置 1-已设置
        $user['has_password'] = empty($user['password']) ? 0 : 1;
        unset($user['password']);

        return $user;
    }

    public function org()
    {
        return $this->hasOne(Org::class, 'id', 'last_org')->select('id', 'title', 'alias', 'logo');
    }

    public static function getOneByWhere($where)
    {
        return self::where($where)->first(['id', 'username', 'email', 'phone', 'status', 'last_login', 'last_org', 'register_at']);
    }

    public function userInfo()
    {
        return $this->hasOne(UserProfile::class, 'uid', 'id');
    }

    /**
     * 模型关联：notify;
     */
    public function notify()
    {
        return $this->hasOne(UserNotify::class, 'uid', 'id');
    }

    public function lastOrg()
    {
        return $this->hasOne(Org::class, 'id', 'last_org');
    }

    /**
     * 生成用户名.
     */
    public function genUsername() : string
    {
        return 'cg' . base_convert((string) Functions::createSnowflakeId(), 10, 36);
    }

}
