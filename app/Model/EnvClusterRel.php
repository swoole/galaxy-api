<?php

declare (strict_types=1);
/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Model;

/**
 * @property int $id 
 * @property int $org_id 
 * @property int $cluster_id 
 * @property int $env_id 
 */
class EnvClusterRel extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected ?string $table = 'env_cluster_rel';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    //    protected $fillable = [];
    protected array $guarded = ['id'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected array $casts = ['id' => 'integer', 'org_id' => 'integer', 'cluster_id' => 'integer', 'env_id' => 'integer'];
}
