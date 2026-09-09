<?php

namespace App\Casts;

use Hyperf\Contract\CastsAttributes;
use stdClass;

class ArrayObject implements CastsAttributes
{
    /**
     * Transform the attribute from the underlying model values.
     *
     * @param object $model
     * @param mixed $value
     * @return mixed
     */
    public function get($model, string $key, $value, array $attributes)
    {
        if ($value == '{}') {
            return new stdClass();
        }

        return json_decode($value, true);
    }

    /**
     * Transform the attribute to its underlying model values.
     *
     * @param object $model
     * @param mixed $value
     * @return array|string
     */
    public function set($model, string $key, $value, array $attributes)
    {
        $converted = json_encode($value);
        if ($converted == '[]') {
            $converted = '{}';
        }

        return $converted;
    }
}
