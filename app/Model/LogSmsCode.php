<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Model;

use App\Constants\Sms;
use App\Exception\AppException;
use Hyperf\DbConnection\Db;

class LogSmsCode extends Model
{
    public const STATUS_SUCCESS = 1;

    public const STATUS_FAIL = 2;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected ?string $table = 'log_sms_code';

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

    /**
     * 快速短信验验证.
     */
    public static function fastSmsValid($phone, $code, $type = Sms::SMS_TYPE_REGISTER)
    {
        $last = LogSmsCode::where('phone', $phone)
            ->where('type', $type)
            ->select('id', 'code', 'status', 'expire_at')
            ->orderBy('id', 'desc')
            ->first();
        if (empty($last)) {
            throw new AppException(422, '验证码错误');
        }
        if ($last['code'] != $code) {
            throw new AppException(422, '验证码错误');
        }
        if ($last['expire_at'] < time()) {
            throw new AppException(422, '验证码已过期');
        }
        if ($last['status'] == self::STATUS_FAIL) {
            throw new AppException(422, '验证码已失效');
        }
        $last->status = self::STATUS_FAIL;
        $last->save();
    }

    public function createRecord($uid, $data, $accountType)
    {
        return self::create([
            'uid' => $uid,
            $accountType => $data[$accountType] ?? Sms::ACCOUNT_PHONE,
            'type' => $data['type'] ?? Sms::SMS_TYPE_REGISTER,
            'code' => $data['code'] ?? '',
            'status' => LogSmsCode::STATUS_FAIL,
            'send_at' => $data['send_at'] ?? time(),
            'expire_at' => $data['expire_at'] ?? time(),
        ]);
    }

    public function updateRecord($id, $update)
    {
        Db::table(self::getTable())
            ->where('id', $id)
            ->update($update);
    }

    public function findLastRecord($account, $accountType, $codeType)
    {
        $model = Db::table(self::getTable());
        $model->where($accountType, $account);
        $model->where('type', $codeType);
        $model->where('status', self::STATUS_SUCCESS);
        $model->orderBy('send_at', 'desc');
        return $model->first();
    }

    public function getTodayRecordList($account, $accountType, $codeType)
    {
        $todayStart = strtotime(date('Y-m-d', time()));
        $todayEnd = $todayStart + 60 * 60 * 24;
        $model = Db::table(self::getTable());
        $model->where($accountType, $account);
//        $model->where('type', $codeType);
        $model->whereBetween('send_at', [$todayStart, $todayEnd]);
        $model->orderBy('send_at', 'desc');
        return $model->get();
    }
}
