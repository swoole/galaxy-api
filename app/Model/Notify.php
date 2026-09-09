<?php

declare (strict_types=1);
namespace App\Model;

use App\Exception\AppException;
use App\Support\MySQL;

/**
 * @property int $id 
 * @property int $uid 
 * @property string $scene 
 * @property string $title 
 * @property string $content 
 * @property string $context 
 * @property int $read_at 
 * @property int $created_at 
 */
class Notify extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected ?string $table = 'notify';
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
        'id' => 'integer', 'uid' => 'integer', 'read_at' => 'integer', 'created_at' => 'integer', 'context' => 'array',
    ];

    /**
     * 通知列表.
     */
    public function list($uid, array $timerange, $scene = null, $page = 1, $pageSize  = 20)
    {
        $builder = $this->where('uid', $uid)
            ->select(
                'id', 'scene', 'title', 'content', 'read_at', 'created_at'
            )->orderBy('id', 'desc');
        MySQL::whereTime($builder, $timerange, 'created_at');

        if (!empty($scene)) {
            $builder->where('scene', $scene);
        }

        return MySQL::jsonPaginate($builder, $page, $pageSize);
    }

    /**
     * 简单列表.
     */
    public function simpleList($uid, $limit = 6)
    {
        $notifys = $this->where('uid', $uid)
            ->where('read_at', 0)
            ->select(
                'id', 'scene', 'title', 'read_at', 'created_at'
            )->orderBy('id', 'desc')
            ->limit($limit)
            ->get();

        return $notifys;
    }

    /**
     * 通知详情.
     */
    public function profile($uid, $notifyId)
    {
        $notify = $this->where('id', $notifyId)
            ->where('uid', $uid)
            ->select(
                'id', 'scene', 'title', 'content', 'context', 'read_at', 'created_at'
            )->first();
        if (empty($notify)) {
            throw new AppException(404, '该站内信不存在');
        }
        // 自动设置已读
        if ($notify['read_at'] == 0) {
            $notify->read_at = time();
            $notify->save();
        }

        return $notify;
    }

    /**
     * 下一条消息.
     */
    public function nextProfile($uid, $current)
    {
        $notify = $this->where('uid', $uid)
            ->where('id', '>', $current)
            ->orderBy('id', 'asc')
            ->select(
                'id', 'scene', 'title', 'content', 'context', 'read_at', 'created_at'
            )->first();
        if (empty($notify)) {
            throw new AppException(404, '已经到底了');
        }

        return $notify;
    }

    /**
     * 上一条消息.
     */
    public function prevProfile($uid, $current)
    {
        $notify = $this->where('uid', $uid)
            ->where('id', '<', $current)
            ->orderBy('id', 'desc')
            ->select(
                'id', 'scene', 'title', 'content', 'context', 'read_at', 'created_at'
            )->first();
        if (empty($notify)) {
            throw new AppException(404, '已经到顶了');
        }

        return $notify;
    }

    /**
     * 标为已读.
     */
    public function setRead($uid, $notifyIds)
    {
        $result = $this->where('uid', $uid)
            ->where('read_at', 0)
            ->whereIn('id', $notifyIds)
            ->update([
                'read_at' => time(),
            ]);
        
        return $result;
    }

    /**
     * 全部标为已读.
     */
    public function setReadAll($uid)
    {
        $result = $this->where('uid', $uid)
            ->where('read_at', 0)
            ->update([
                'read_at' => time(),
            ]);
        
        return $result;
    }

    /**
     * 删除.
     */
    public function deleteNotifys($uid, $notifyIds)
    {
        $result = $this->where('uid', $uid)
            ->whereIn('id', $notifyIds)
            ->delete();

        return $result;
    }
}
