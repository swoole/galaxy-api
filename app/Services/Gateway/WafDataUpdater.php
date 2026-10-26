<?php

namespace App\Services\Gateway;

use App\Exception\AppException;
use App\Model\WafDataSource;
use RuntimeException;
use Throwable;

final class WafDataUpdater
{
    public function __construct(private WafDataCatalog $catalog, private WafDataConfig $config,
        private WafDataStorage $storage, private WafDataTransport $transport, private WafDataLease $lease) {}

    public function due(): bool
    {
        if (! $this->catalog->initialized()) return false;
        $source = WafDataSource::where('enabled', true)->where('next_check_at', '<=', time())
            ->where('updating_until', '<=', time())->orderBy('next_check_at')->orderBy('id')->first();
        if ($source === null) return false;
        $this->update($source); return true;
    }

    public function prune(): void { if ($this->catalog->initialized()) $this->storage->prune(); }

    public function update(WafDataSource $source): void
    {
        $this->lease->run('source:' . $source->id, 120, function () use ($source): void {
            $source = $source->fresh();
            if (! $source || ! $source->enabled) return;
            $query = WafDataSource::where('org_id', $source->org_id)->where('id', $source->id)->where('config_version', $source->config_version);
            (clone $query)->update(['updating_until' => time() + 120]);
            $this->catalog->invalidate($source->org_id, $source->source_key);
            try {
                $url = $this->config->sourceUrl($source);
                $result = $this->lease->run('url:' . hash('sha256', $url), 120, fn () => $this->download($source, $url));
                $this->validate($source, $result['info']);
                $info = array_intersect_key($result['info'], array_flip(['content_hash','version','published_at','record_count','file_bytes']));
                (clone $query)->update($info + ['validated_at' => $result['validated_at'], 'last_checked_at' => $result['checked_at'],
                    'next_check_at' => max(time() + 60, $result['checked_at'] + $source->refresh_interval), 'last_error' => $result['error'] ?? '']);
            } catch (Throwable $e) {
                $busy = $e instanceof AppException && $e->getCode() === 409;
                (clone $query)->update(['last_checked_at' => time(), 'next_check_at' => time() + ($busy ? 60 : $source->refresh_interval),
                    'last_error' => mb_substr($e->getMessage(), 0, 1000)]);
            } finally {
                (clone $query)->update(['updating_until' => 0]);
                $this->catalog->invalidate($source->org_id, $source->source_key);
            }
        });
    }

    private function download(WafDataSource $source, string $url): array
    {
        $cache = $this->storage->cached($url);
        // Public providers publish daily/monthly. Repeated manual requests and
        // different organizations share one URL cooldown and immutable object.
        $interval = isset(WafDataConfig::PRESETS[$source->provider]) || str_ends_with(strtolower((string) parse_url($url, PHP_URL_HOST)), 'spamhaus.org')
            ? 86400 : 3600;
        if (($cache['not_before'] ?? 0) > time()) {
            if (empty($cache['info'])) throw new RuntimeException($cache['error'] ?? '数据源处于更新间隔内，请稍后检查');
            $this->validate($source, $cache['info']);
            $this->storage->usableFile(new WafDataSource(['kind' => $source->kind, 'content_hash' => $cache['info']['content_hash']]));
            return $cache;
        }
        $work = $this->storage->temporary();
        try {
            // Reserve the cooldown before network I/O: a killed worker must not
            // make the next replica immediately download the same provider again.
            $reservation = $cache;
            $reservation['checked_at'] = time(); $reservation['not_before'] = time() + $interval;
            $reservation['error'] = '上次数据检查未完成；在更新间隔结束前保留已验证旧版本';
            $this->storage->cache($url, $reservation);
            $headers = [];
            if (! empty($cache['info']['content_hash']) && is_dir($this->storage->object($cache['info']['content_hash']))) $headers = $cache['headers'] ?? [];
            $response = $this->transport->fetch($url, $work . '/raw', $headers);
            if ($response['status'] === 304) {
                if (empty($cache['info']) || $headers === []) throw new RuntimeException('数据源返回 304，但不存在可验证的本地版本');
                $info = $cache['info']; $this->validate($source, $info);
            } else $info = $this->storage->publish($source, $work);
            $state = ['info' => $info, 'headers' => $response['headers'] + $headers, 'checked_at' => time(),
                'validated_at' => time(), 'not_before' => time() + $interval, 'error' => ''];
            $this->storage->cache($url, $state); return $state;
        } catch (Throwable $e) {
            $cache['checked_at'] = time(); $cache['not_before'] = time() + $interval; $cache['error'] = mb_substr($e->getMessage(), 0, 1000);
            $this->storage->cache($url, $cache); throw $e;
        } finally { $this->storage->removeWork($work); }
    }

    private function validate(WafDataSource $source, array $info): void
    {
        if (($info['kind'] ?? '') !== $source->kind || empty($info['record_count']) || ! preg_match('/^[a-f0-9]{64}$/D', $info['content_hash'] ?? '')) throw new RuntimeException('缓存的数据类型或版本无效');
        $at = (int) ($info['published_at'] ?? 0);
        if (($at <= 0 && ($source->kind === 'geoip' || str_starts_with($source->provider, 'spamhaus-')))
            || $at > time() + 86400 || ($at > 0 && $at < time() - $source->max_age)) throw new RuntimeException('数据源生成时间异常或已过期');
    }
}
