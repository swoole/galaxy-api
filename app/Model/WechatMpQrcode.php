<?php

declare (strict_types=1);
namespace App\Model;

use App\Exception\AppException;
use App\Services\EasyWechat\OfficialAccount;
use Hyperf\Di\Annotation\Inject;

/**
 * @property int $id 
 * @property int $uid 
 * @property string $scene 
 * @property string $url 
 * @property int $created_at 
 * @property int $expired_at 
 */
class WechatMpQrcode extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected ?string $table = 'wechat_mp_qrcode';
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
    protected array $casts = ['id' => 'integer', 'uid' => 'integer', 'created_at' => 'integer', 'expired_at' => 'integer'];

    /**
     * @Inject
     */
    #[Inject]
    protected OfficialAccount $officialAccount;

    /**
     * 拼接场景值.
     */
    public static function joinSceneStr($scene, $sceneVal)
    {
        return sprintf('%s:%s', $scene, $sceneVal);
    }

    /**
     * 拆分场景值.
     */
    public static function splitSceneStr($sceneStr)
    {
        $parts = explode(':', $sceneStr, 2);

        return [$parts[0], $parts[1] ?? null];
    }

    /**
     * 获取二维码.
     */
    public function getQrcode($uid, $scene, $sceneVal, $expire = 2592000)
    {
        $qrcode = $this->where('uid', $uid)
            ->where('scene', $scene)
            ->first();
        
        if (empty($qrcode)) {
            $resp = $this->genQrcode($scene, $sceneVal, $expire);
            $qrcode = self::create([
                'uid' => $uid,
                'scene' => $scene,
                'url' => $resp['url'],
                'created_at' => time(),
                'expired_at' => time() + $expire,
            ]);
        }

        // 过期时间小于10分钟，重新生成
        if ($qrcode['expired_at'] - time() < 600) {
            $resp = $this->genQrcode($scene, $sceneVal, $expire);
            $qrcode->url = $resp['url'];
            $qrcode->created_at = time();
            $qrcode->expired_at = time() + $expire;
            $qrcode->save();
        }

        return $qrcode['url'];
    }

    /**
     * 生成二维码.
     */
    protected function genQrcode($scene, $sceneVal, $expire)
    {
        $resp = $this->officialAccount->getClient()->postJson('/cgi-bin/qrcode/create', [
            'expire_seconds' => (int) $expire,
            'action_name' => 'QR_STR_SCENE',
            'action_info' => [
                'scene' => [
                    'scene_str' => self::joinSceneStr($scene, $sceneVal),
                ],
            ],
        ]);
        if (isset($resp['errcode']) && $resp['errcode'] != 0) {
            throw new AppException(
                500,
                sprintf('生成服务号二维码失败：%s(%s)', $resp['errmsg'], $resp['errcode'])
            );
        }

        return $resp;
    }
}
