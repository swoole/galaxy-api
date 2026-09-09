<?php
namespace App\Model;

class GatewayVhostRewrite extends Model
{
    protected ?string $table = 'gateway_vhost_rewrite';
    protected array $guarded = ['id'];
    protected array $casts = ['id'=>'integer','vhost_id'=>'integer','sort_order'=>'integer','created_at'=>'integer','updated_at'=>'integer'];
}
