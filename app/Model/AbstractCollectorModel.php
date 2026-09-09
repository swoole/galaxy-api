<?php

namespace App\Model;

use Closure;
use Hyperf\DbConnection\Db;
use Throwable;

abstract class AbstractCollectorModel extends Model
{
    /**
     * 生成采集时间.
     */
    protected function genCollectTime() : int
    {
        $now = time();

        return $now - ($now % 3600);
    }

    /**
     * 创建或更新采集记录.
     */
    protected function createOrUpdateCollector(array $data, array $where, Closure $compare)
    {
        Db::beginTransaction();
        try {
            $collector = $this->where($where)->lockForUpdate()->first();
            if (empty($collector)) {
                $collector = self::create($data);
                Db::commit();
                return $collector;
            }

            // 指标数据比较，返回false则不继续更新数据，否则更新
            if (! call_user_func($compare, $collector, $data)) {
                Db::commit();
                return ;
            }
            
            // 更新数据
            foreach ($data as $field => $value) {
                $collector->{$field} = $value;
            }
            $collector->save();

            Db::commit();

            return $collector;
        } catch (Throwable $e) {
            Db::rollBack();
            throw $e;
        }
    }
}
