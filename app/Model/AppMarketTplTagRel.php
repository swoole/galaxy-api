<?php

declare (strict_types=1);
namespace App\Model;

/**
 * @property int $id 
 * @property int $tpl_id 
 * @property int $tag_id 
 */
class AppMarketTplTagRel extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected ?string $table = 'app_market_tpl_tag_rel';
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
    protected array $casts = ['id' => 'integer', 'tpl_id' => 'integer', 'tag_id' => 'integer'];
}