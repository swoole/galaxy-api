<?php

namespace App\Services\Alias;

use Overtrue\Pinyin\Pinyin;

class Alias
{
    /**
     * 计算默认alias.
     */
    public static function calcuDefault($query, $title, $id = null)
    {
        if (empty($title)) {
            return '';
        }

        // 计算title的拼音
        $alias = Pinyin::permalink($title, '');
        // 如果为空，直接返回空字符串
        if (empty($alias)) {
            return '';
        }

        $alias = strtolower($alias);

        if (is_numeric($alias)) {
            $alias = "p{$alias}";
        }

        // 加上最大自增数字4位总共不可超过50位长度，超过则截取
        if (strlen($alias) > 46) {
            $alias = substr($alias, 0, 46);
        }

        // 查询所有以此为prefix的记录
        $exists = $query->where('alias', 'like', "{$alias}%")
            ->pluck('id', 'alias')
            ->toArray();

        // 自动尝试所有结果
        $index = -1;
        while (++$index <= 9999) {
            // 加上自增数字区分重名别名
            $tryAlias = $alias . ($index ?: '');
            // 如果不重复则直接使用该值
            if (!isset($exists[$tryAlias])) {
                return $tryAlias;
            }
            // 如果重复但是与原生ID相同则仍然使用该值
            if ($exists[$tryAlias] == $id) {
                return $tryAlias;
            }
        }

        return '';
    }
}
