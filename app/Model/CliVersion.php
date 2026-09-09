<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Model;

use Throwable;

/**
 * @property int $id
 * @property string $version
 * @property string $commit_id
 * @property string $notes
 * @property string $bin_name
 * @property string $md5
 * @property string $os
 * @property string $arch
 * @property int $size
 * @property int $status
 * @property int $created_at
 * @property int $updated_at
 */
class CliVersion extends Model
{
    /**
     * 设备状态  0-禁用  1-正常  2-注销
     */
    public const STATUS_DELETE = -1;

    public const STATUS_NORMAL = 0; //代码发布

    public const STATUS_DEPLOY = 1; //已发布

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected ?string $table = 'cli_versions';

    protected array $guarded = [];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected array $casts = ['id' => 'integer', 'size' => 'integer', 'status' => 'integer', 'created_at' => 'integer', 'updated_at' => 'integer'];

    /**
     * @param $params
     * @throws Throwable
     * @return CliVersion|\Hyperf\Database\Model\Model
     */
    public function createCliVersion($params)
    {
        try {
            return CliVersion::create([
                'version' => $params['version'],
                'commit_id' => $params['commit_id'],
                'notes' => $params['notes'],
                'bin_name' => $params['bin_name'] ?? '',
                'md5' => $params['md5'],
                'os' => $params['os'],
                'arch' => $params['arch'],
                'size' => $params['size'],
                'status' => CliVersion::STATUS_DEPLOY,
                'created_at' => time(),
                'updated_at' => time(),
            ]);
        } catch (Throwable $e) {
            throw $e;
        }
    }

    /**
     * 获取该平台最新版本.
     * @param $os
     * @param $arch
     * @return null|\Hyperf\Database\Model\Builder|\Hyperf\Database\Model\Model|object
     */
    public function getLastVersion($os, $arch)
    {
        return self::query()->where('os', $os)
            ->where('arch', $arch)
            ->where('status', self::STATUS_DEPLOY)
            ->where('version', '!=', 'test')
            ->orderByDesc('created_at')->first();
    }

    /**
     * 获取制定版本信息.
     * @param $os
     * @param $arch
     * @param $version
     * @return null|\Hyperf\Database\Model\Builder|\Hyperf\Database\Model\Model|object
     */
    public function getVersion($os, $arch, $version)
    {
        return self::query()->where('os', $os)->where('arch', $arch)->where('version', $version)->where('status', self::STATUS_DEPLOY)->orderByDesc('created_at')->first();
    }

    /**
     * 判断版本是否存在.
     * @param $os
     * @param $arch
     * @param $version
     * @return bool
     */
    public function existVersion($os, $arch, $version)
    {
        $info = self::query()->where('os', $os)->where('arch', $arch)->where('version', $version)->where('status', '!=', self::STATUS_DELETE)->first();
        if (empty($info)) {
            return false;
        }
        return true;
    }
}
