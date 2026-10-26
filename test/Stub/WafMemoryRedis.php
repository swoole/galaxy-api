<?php

namespace HyperfTest\Stub;

use Hyperf\Redis\Redis;
use RuntimeException;

/** A deterministic cache fixture; no test connects to the application's Redis. */
final class WafMemoryRedis extends Redis
{
    public array $values = [];
    public ?string $failKey = null;
    public array $expires = [];
    public function __construct() {}
    public function __call(string $name, array $arguments): mixed
    {
        $key = $arguments[0] ?? '';
        if (isset($this->expires[$key]) && $this->expires[$key] <= time()) unset($this->values[$key], $this->expires[$key]);
        if ($key === $this->failKey) throw new RuntimeException('cache write failed');
        return match (strtolower($name)) {
            'get' => $this->values[$key] ?? false,
            'setex' => $this->expiring($key, $arguments[2], (int) $arguments[1]),
            'set' => isset($this->values[$key]) && in_array('nx', $arguments[2] ?? [], true) ? false : $this->assign($key, $arguments[1]),
            'hsetnx' => isset($this->values[$key][$arguments[1]]) ? false : $this->hashAssign($key, $arguments[1], $arguments[2]),
            'hgetall' => $this->values[$key] ?? [],
            'hdel' => $this->hashDelete($key, $arguments[1]),
            'del' => $this->delete($key),
            'eval' => $this->script($arguments[0], $arguments[1]),
            default => throw new RuntimeException('Unexpected fixture command: ' . $name),
        };
    }
    private function assign(string $key, mixed $value): bool { $this->values[$key] = $value; return true; }
    private function expiring(string $key, mixed $value, int $ttl): bool { $this->expires[$key] = time() + $ttl; return $this->assign($key, $value); }
    private function script(string $script, array $args): mixed
    {
        if ($script === \App\Services\Gateway\WafChallengeStore::RATE) {
            $n = (int) $this->__call('get', [$args[0]]) + 1;
            if ($n === 1) $this->expires[$args[0]] = time() + 60;
            $this->values[$args[0]] = $n; return $n;
        }
        if ($script === \App\Services\Gateway\WafChallengeStore::CLAIM) {
            $value = $this->__call('get', [$args[0]]); $this->delete($args[0]); return $value;
        }
        return $this->unlock($args);
    }
    private function hashAssign(string $key, string $field, mixed $value): bool { $this->values[$key][$field] = $value; return true; }
    private function hashDelete(string $key, string $field): int { unset($this->values[$key][$field]); return 1; }
    private function delete(string $key): int { unset($this->values[$key]); return 1; }
    private function unlock(array $args): int { if (($this->values[$args[0]] ?? null) === $args[1]) unset($this->values[$args[0]]); return 1; }
}
