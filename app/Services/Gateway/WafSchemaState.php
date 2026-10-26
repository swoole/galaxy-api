<?php

namespace App\Services\Gateway;

use Throwable;

/** Share additive-module probes; connection failures must never look like missing tables. */
final class WafSchemaState
{
    private array $cache = [];

    public function ready(array $models): bool
    {
        $key = implode(':', $models);
        if (isset($this->cache[$key]) && time() - $this->cache[$key]['at'] < 30) return $this->cache[$key]['ready'];
        try {
            foreach ($models as $model) $model::query()->select('id')->limit(0)->get();
            $ready = true;
        } catch (Throwable $e) {
            if (! str_contains($e->getMessage(), 'no such table') && ! str_contains($e->getMessage(), '42S02')) throw $e;
            $ready = false;
        }
        $this->cache[$key] = ['at' => time(), 'ready' => $ready];
        return $ready;
    }
}
