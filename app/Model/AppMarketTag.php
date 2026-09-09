<?php

declare (strict_types=1);
namespace App\Model;

/**
 * @property int $id 
 * @property int $type 
 * @property string $title 
 * @property string $style 
 * @property int $sort 
 * @property int $created_at 
 */
class AppMarketTag extends Model
{
    /**
     * 标签类型定义.
     */
    public const TYPE_TAG = 0; // 普通标签
    public const TYPE_SERVICE = 1; // 服务
    public const TYPE_LANG = 2; // 语言
    public const TYPE_FRAMEWORK = 3; // 框架
    public const TYPE_CATEGORY = 4; // 产品分类

    public static $types = [
        self::TYPE_TAG => 'tag',
        self::TYPE_SERVICE => 'service',
        self::TYPE_LANG => 'lang',
        self::TYPE_FRAMEWORK => 'framework',
        self::TYPE_CATEGORY => 'category',
    ];

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected ?string $table = 'app_market_tag';
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
    protected array $casts = [
        'id' => 'integer', 'type' => 'integer', 'sort' => 'integer', 'created_at' => 'integer', 'style' => 'array',
    ];

    /**
     * 简单标签列表.
     */
    public function simpleList($orgId, $type = null, $keyword = null)
    {
        $builder = $this->select('id', 'type', 'title', 'style')
            ->limit(20);

        if (!is_null($type)) {
            $builder->where('type', $type);
        }
        if (!empty($keyword)) {
            $builder->where('title', 'like', '%' . $keyword . '%');
        }

        return $builder->get();
    }
}