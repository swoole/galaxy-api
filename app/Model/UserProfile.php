<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Model;

/**
 * @property int $id
 * @property int $uid
 * @property string $nickname
 * @property string $avatar
 * @property int $gender
 * @property string $company
 * @property string $position
 * @property string $wechat
 * @property string $qq
 * @property string $city
 * @property string $introduce
 * @property int $id_auth
 */
class UserProfile extends Model
{
    /**
     * 性别 0-未填写  1-男  2-女.
     */
    public const GENDER_NO = 0;

    public const GENDER_MAN = 1;

    public const GENDER_WOMAN = 2;

    /**
     * 是否已进行身份认证：0-未认证；1-已认证
     */
    public const AUTH_NO = 0;

    public const AUTH_IS = 1;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected ?string $table = 'user_profile';

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
    protected array $casts = [];

    public function createUserProfile($uid, $param)
    {
        return self::create([
            'uid' => $uid,
            'nickname' => $param['nickname'] ?? substr(md5(uniqid('', true)), 8, 16),
            'avatar' => $param['avatar'] ?? '',
            'gender' => $param['gender'] ?? self::GENDER_NO,
            'company' => $param['company'] ?? '',
            'position' => $param['position'] ?? '',
            'wechat' => $param['wechat'] ?? '',
            'qq' => $param['qq'] ?? '',
            'city' => $param['city'] ?? '',
            'introduce' => $param['introduce'] ?? '',
            'id_auth' => $param['id_auth'] ?? self::AUTH_NO,
        ]);
    }

    public function findUserProfile($uid)
    {
        return self::where(['uid' => $uid])->first();
    }
}
