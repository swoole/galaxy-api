<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Support;

use InvalidArgumentException;

class MySQL
{
    /**
     * Json 分页.
     *
     * @param object $builder
     * @param int $page
     * @param int $perPage
     * @return array
     */
    public static function jsonPaginate($builder, $page = 1, $perPage = 20)
    {
        $page = intval($page);
        $perPage = intval($perPage);
        $count = $builder->toBase()->getCountForPagination();

        $data = $count ? $builder->forPage($page, $perPage)->get() : [];

        return [
            'page' => $page,
            'pagesize' => $perPage,
            'total' => $count,
            'data' => $data,
        ];
    }

    /**
     * Where 时间范围.
     *
     * @param object $builder
     * @param string $field
     */
    public static function whereTime($builder, array $timeRange, $field)
    {
        if (! empty($timeRange)) {
            if (! empty($timeRange['end'])) {
                $builder->where($field, '<=', $timeRange['end']);
            }
            if (! empty($timeRange['begin'])) {
                $builder->where($field, '>=', $timeRange['begin']);
            }
        }
    }

    /**
     * 设置排序.
     *
     * @param object $builder
     * @param array|string $sort sort可以为JSON格式数据
     * @param array $map sort字段映射关系
     * @param array $allows 允许排序的字段
     */
    public static function builderSort($builder, $sort = [], $map = [], $allows = [])
    {
        if (empty($sort)) {
            return;
        }

        if (! is_array($sort)) {
            // 判断sort是否为JSON格式数据，如果是就进行JSON解析
            $jsonSort = json_decode($sort, true);
            if (json_last_error() == 0 && is_array($jsonSort)) {
                $sort = $jsonSort;
            } else {
                return;
            }
        }
        foreach ($sort as $column => $direction) {
            if (! empty($allows) && ! in_array($column, $allows)) {
                throw new InvalidArgumentException(sprintf('invalid sort failed: %s', $column));
            }
            if (! in_array(strtolower($direction), ['asc', 'desc'])) {
                continue;
            }
            // sort字段映射
            if (isset($map[$column])) {
                $column = $map[$column];
            }
            // 如果是数组，使用orderByRaw
            if (is_array($column)) {
                // $column = ['field']
                $builder->orderByRaw($column[0] . ' ' . strtoupper($direction));
            } else {
                $builder->orderBy($column, $direction);
            }
        }
    }

    /**
     * 格式化json.
     *
     * @param array|string $data
     * @return string
     */
    public static function jsonPrettyPrint($data)
    {
        if (! is_array($data)) {
            $data = json_decode($data, true);
        }

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
