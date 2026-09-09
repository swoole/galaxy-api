<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Model;

use App\Constants\UserLoginChannel;
use App\Support\MySQL;

/**
 * @property int $id
 * @property int $uid
 * @property string $city
 * @property string $ip
 * @property int $login_at
 * @property int $status
 * @property int $result
 * @property int $channel
 * @property string $ua
 * @property int $platform
 */
class LogUserLogin extends Model
{
    /**
     * 结果 0-失败; 1-成功;.
     */
    public const RESULT_FAILED = 0;

    public const RESULT_SUCCESS = 1;

    /**
     * 平台：1-web.
     */
    public const PLATFORM_WEB = 1;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected ?string $table = 'log_user_login';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
//    protected $fillable = [];
    protected array $guarded = [];
    protected array $hidden = ['id'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected array $casts = [];

    public function writeLog($data)
    {
        return self::create([
            'uid' => $data['uid'],
            'city' => $data['city'] ?? '',
            'ip' => $data['ip'] ?? '',
            'login_at' => $data['login_at'] ?? time(),
            'result' => $data['result'] ?? self::RESULT_SUCCESS,
            'channel' => $data['channel'] ?? UserLoginChannel::PWD,
            'ua' => empty($data['ua']) ? '' : mb_substr($data['ua'], 0, 200),
            'platform' => $data['platform'] ?? self::PLATFORM_WEB,
        ]);
    }

    /**
     * 日志列表.
     */
    public function list($uid, $page, $pageSize)
    {
        $builder = $this->select('id', 'city', 'ip', 'login_at', 'result', 'channel', 'ua')
            ->where('uid', $uid)
            ->orderBy('id', 'desc');

        $data = MySQL::jsonPaginate($builder, $page, $pageSize);

        // 只返回最多400条记录，超过之后隐藏
        $maxTotal = 400;
        if ($data['total'] > $maxTotal) {
            $data['total'] = $maxTotal;
        }

        return $data;
    }
}
