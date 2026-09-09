<?php

declare (strict_types=1);
namespace App\Model;

/**
 * @property int $id 
 * @property int $uid 
 * @property string $hostid 
 * @property string $cli_version 
 * @property string $cli_ip 
 * @property string $os 
 * @property string $platform 
 * @property string $platform_family 
 * @property string $platform_version 
 * @property string $kernel_version 
 * @property string $kernel_arch 
 * @property string $cpu_model 
 * @property int $cpu_count 
 * @property int $status 
 * @property int $created_at 
 * @property int $updated_at 
 */
class UserCliInfo extends Model
{
    /**
     * 设备状态  0-禁用  1-正常  2-注销
     */
    public const STATUS_DELETE = -1;

    public const STATUS_NORMAL = 0; //正常
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected ?string $table = 'user_cli_info';

    protected array $guarded = [];
    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected array $casts = ['id' => 'integer', 'uid' => 'integer', 'cpu_count' => 'integer', 'status' => 'integer', 'created_at' => 'integer', 'updated_at' => 'integer'];

    /**
     * 保存用户cli项目主机数据.
     * @param $data
     * @return \Hyperf\Database\Model\Model|UserCliInfo
     */
    public function createCliInfo($data)
    {
        $time = time();
        $model = self::query();
        $cliInfo = $model->where("uid",$data["uid"])->where("hostid",$data['hostid'])->first();
        if (!$cliInfo){
            $cliInfo = self::create([
                'uid' => $data["uid"],
                'hostid' => $data['hostid'] ?? '',
                'cli_version' => $data['version'] ?? '',
                'cli_ip' => $data['cli_ip'] ?? '',
                'os' => $data['os'] ?? '',
                'platform' => $data['platform'] ?? '',
                'platform_family' => $data['platformFamily'] ?? '',
                'platform_version' => $data['platformVersion'] ?? '',
                'kernel_version' => $data['kernelVersion'] ?? '',
                'kernel_arch' => $data['kernelArch'] ?? '',
                'cpu_model' => $data['cpuModel'] ?? '',
                'cpu_count' => $data['cpuCount'] ?? 0,
                'status' => self::STATUS_NORMAL,
                'created_at' => $time,
                'updated_at' => $time,
            ]);
            $cliInfo->save();
            return $cliInfo;
        }
        $cliInfo->update([
            'cli_version' => $data['version'] ?? '',
            'cli_ip' => $data['cli_ip'] ?? '',
            'os' => $data['os'] ?? '',
            'platform' => $data['platform'] ?? '',
            'platform_family' => $data['platformFamily'] ?? '',
            'platform_version' => $data['platformVersion'] ?? '',
            'kernel_version' => $data['kernelVersion'] ?? '',
            'kernel_arch' => $data['kernelArch'] ?? '',
            'cpu_model' => $data['cpuModel'] ?? '',
            'cpu_count' => $data['cpuCount'] ?? 0,
            'status' => self::STATUS_NORMAL,
            'updated_at' => $time,
        ]);
        return $cliInfo;
    }

}