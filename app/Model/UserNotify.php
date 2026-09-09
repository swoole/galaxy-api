<?php

declare (strict_types=1);
namespace App\Model;

use App\Support\Functions;

/**
 * @property int $id 
 * @property int $uid 
 * @property string $official_account 
 */
class UserNotify extends Model
{
    public const CHANNEL_EMAIL = 'email'; // 邮件
    public const CHANNEL_NOTIFY = 'notify'; // 站内信
    public const CHANNEL_BROWSER = 'browser'; // 浏览器通知

    public static $channels = [
        self::CHANNEL_EMAIL => '邮件',
        self::CHANNEL_NOTIFY => '站内信',
        self::CHANNEL_BROWSER => '浏览器通知',
    ];

    /**
     * 需要绑定的渠道.
     */
    public static $bindChannels = [];

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected ?string $table = 'user_notify';
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    // protected $fillable = [];
    protected array $guarded = ['id'];
    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected array $casts = ['id' => 'integer', 'uid' => 'integer'];

    /**
     * 用户通知渠道.
     */
    public function channels($uid)
    {
        $user = User::where('id', $uid)
            ->select('id', 'email')
            ->first();

        $email = empty($user['email']) ? false : Functions::strHidden($user['email'], 5, 6);
        $data = [
            self::CHANNEL_EMAIL => $email,
            self::CHANNEL_NOTIFY => true,
            self::CHANNEL_BROWSER => true,
        ];

        return $data;
    }

}
