<?php

declare (strict_types=1);
namespace App\Model;

use App\Exception\AppException;
use App\Services\Encrypt\EncryptService;
use App\Support\Functions;
use Hyperf\Di\Annotation\Inject;

/**
 * @property int $id 
 * @property int $uid 
 * @property string $pubkey 
 * @property string $fingerprint 
 * @property string $remark 
 * @property int $created_at 
 */
class UserPersonalSshKey extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected ?string $table = 'user_personal_ssh_key';
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
    protected array $casts = ['id' => 'integer', 'uid' => 'integer', 'created_at' => 'integer'];

    /**
     * @Inject
     */
    #[Inject]
    protected EncryptService $encryptService;

    /**
     * 用户私人SSH公钥列表.
     */
    public function sshKeys($uid)
    {
        $keys = $this->where('uid', $uid)
            ->select('id', 'fingerprint', 'remark', 'created_at')
            ->get();

        return $keys;
    }

    /**
     * 创建私人公钥.
     */
    public function createSshKey($uid, $pubkey, $remark)
    {
        // 公钥格式校验
        if (!Functions::validSshPubKey($pubkey)) {
            throw new AppException(422, '公钥格式错误，请核对之后再创建');
        }

        // 每个用户不允许超过50个公钥
        $count = $this->where('uid', $uid)->count();
        if ($count >= 50) {
            throw new AppException(422, '每个用户最多允许创建50个私人公钥');
        }

        // 判断指纹是否有重复
        $fingerprint = Functions::calcuSshKeySha256Fingerprint($pubkey);
        if ($this->where('fingerprint', $fingerprint)->exists()) {
            throw new AppException(422, '该公钥已存在，请勿重复添加');
        }

        $encryptedPubkey = $this->encryptService->encryptFast($pubkey);

        $key = self::create([
            'uid' => $uid,
            'pubkey' => $encryptedPubkey,
            'fingerprint' => Functions::calcuSshKeySha256Fingerprint($pubkey),
            'remark' => $remark,
            'created_at' => time(),
        ]);

        return $key;
    }

    /**
     * 删除私人公钥.
     */
    public function deleteSshKey($uid, $keyId)
    {
        $key = $this->where('id', $keyId)
            ->where('uid', $uid)
            ->select('id', 'remark', 'created_at', 'fingerprint')
            ->first();
        if (empty($key)) {
            throw new AppException(404, '公钥不存在');
        }

        $key->delete();
    }

    /**
     * 私人公钥详情.
     */
    public function profile($uid, $keyId)
    {
        $key = $this->where('id', $keyId)
            ->where('uid', $uid)
            ->select('id', 'remark', 'created_at', 'fingerprint', 'pubkey')
            ->first();
        if (empty($key)) {
            throw new AppException(404, '公钥不存在');
        }

        $decryptedPubkey = $this->encryptService->decryptFast($key['pubkey']);
        unset($key['pubkey']);
        $key['pubkey'] = $decryptedPubkey;

        return $key;
    }
}
