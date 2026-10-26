<?php

namespace App\Services\Gateway;

use App\Exception\AppException;
use App\Model\WafDataSource;
use MaxMind\Db\Reader;
use RuntimeException;

/** Immutable revisions; metadata is published only after complete validation and rename. */
final class WafDataStorage
{
    public function __construct(private WafIpIndex $index) {}

    public function root(): string
    {
        $root = rtrim((string) config('waf.data_directory', BASE_PATH . '/runtime/waf-data'), '/');
        if ($root === '' || $root === '/' || ! str_starts_with($root, '/')) throw new RuntimeException('WAF 数据目录必须为独立绝对路径');
        if (! is_dir($root) && ! mkdir($root, 0700, true) && ! is_dir($root)) throw new RuntimeException('WAF 数据目录无法创建');
        return $root;
    }

    public function temporary(): string
    {
        $directory = $this->root() . '/work-' . bin2hex(random_bytes(16));
        if (! mkdir($directory, 0700)) throw new RuntimeException('无法创建数据下载目录');
        return $directory;
    }

    public function removeWork(string $directory): void
    {
        if (! preg_match('#^' . preg_quote($this->root(), '#') . '/work-[a-f0-9]{32}$#D', $directory)) throw new RuntimeException('Refusing foreign work directory');
        foreach (glob($directory . '/*') ?: [] as $file) if (is_file($file) && ! is_link($file)) unlink($file);
        if (is_dir($directory)) rmdir($directory);
    }

    public function object(string $hash): string
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $hash)) throw new RuntimeException('数据版本标识无效');
        return $this->root() . '/object-' . $hash;
    }

    public function usableFile(WafDataSource $source): string
    {
        $path = $this->object($source->content_hash) . ($source->kind === 'geoip' ? '/data.mmdb' : '/data.ipbin');
        if (! is_file($path) || ! is_readable($path)) throw new RuntimeException('当前 API 的数据文件不可用，请检查共享数据目录');
        return $path;
    }

    public function publish(WafDataSource $source, string $work): array
    {
        $raw = $work . '/raw'; $hash = hash_file('sha256', $raw);
        if (filesize($raw) < 1 || filesize($raw) > (int) config('waf.data_max_download_bytes', 16777216)) throw new AppException(422, '数据文件为空或超出容量上限');
        if ($source->kind === 'geoip') {
            $path = $work . '/data.mmdb'; $this->unpack($raw, $path);
            $reader = new Reader($path);
            try {
                $meta = $reader->metadata();
                if ($meta->nodeCount < 1 || ! in_array($meta->ipVersion, [4,6], true) || ! preg_match('/^[a-z0-9_. -]{1,64}$/iD', $meta->databaseType) || ! preg_match('/country|city/i', $meta->databaseType)) throw new AppException(422, 'MMDB 不是支持的国家/地区数据库');
                $reader->get('1.1.1.1'); // Validate a complete lookup, not just a plausible header.
                $info = ['record_count' => $meta->nodeCount, 'published_at' => $meta->buildEpoch, 'copyright' => '', 'version' => $meta->databaseType . ' · ' . gmdate('Y-m-d', $meta->buildEpoch)];
            } finally { $reader->close(); }
        } else {
            $info = $this->index->build($raw, $work . '/data.ipbin');
            $info['version'] = ($info['published_at'] > 0 ? gmdate('Y-m-d H:i', $info['published_at']) : gmdate('Y-m-d H:i')) . ' · ' . substr($hash, 0, 12);
        }
        $published = $info['published_at'];
        if (($source->kind === 'geoip' && $published <= 0) || $published > time() + 86400 || ($published > 0 && $published < time() - $source->max_age)) throw new AppException(422, '数据源生成时间异常或已超过最长使用期');
        if (str_starts_with($source->provider, 'spamhaus-') && ($published <= 0 || $info['copyright'] === '')) throw new AppException(422, 'Spamhaus 元数据缺失；保留有效旧版本');
        $info += ['content_hash' => $hash, 'file_bytes' => filesize($raw), 'kind' => $source->kind, 'provider' => $source->provider,
            'license_url' => $source->license_url, 'attribution' => $source->attribution];
        file_put_contents($work . '/metadata.json', json_encode($info, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $object = $this->object($hash);
        if (! is_dir($object) && ! rename($work, $object) && ! is_dir($object)) throw new RuntimeException('发布数据版本失败');
        return $info;
    }

    public function metadata(string $hash): array
    {
        $raw = file_get_contents($this->object($hash) . '/metadata.json', length: 16385);
        if (strlen($raw) > 16384) throw new RuntimeException('数据元信息超出容量上限');
        return json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    }

    public function cached(string $url): array
    {
        $path = $this->root() . '/url-' . hash('sha256', $url) . '.json';
        return is_file($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
    }

    public function cache(string $url, array $state): void
    {
        $path = $this->root() . '/url-' . hash('sha256', $url) . '.json';
        $temporary = $path . '.' . bin2hex(random_bytes(8));
        if (file_put_contents($temporary, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new RuntimeException('保存下载状态失败');
        chmod($temporary, 0600);
        if (! rename($temporary, $path)) throw new RuntimeException('发布下载状态失败');
    }

    public function prune(): void
    {
        $keep = array_fill_keys(WafDataSource::where('content_hash', '!=', '')->distinct()->pluck('content_hash')->all(), true);
        $root = $this->root();
        foreach (new \DirectoryIterator($root) as $entry) {
            if ($entry->isFile() && preg_match('/^url-[a-f0-9]{64}\.json$/D', $entry->getFilename())) {
                $state = json_decode(file_get_contents($entry->getPathname()), true) ?: [];
                if (($state['not_before'] ?? 0) > time() && ! empty($state['info']['content_hash'])) $keep[$state['info']['content_hash']] = true;
                if (($state['checked_at'] ?? 0) < time() - 5356800) unlink($entry->getPathname());
            }
        }
        $removed = 0;
        foreach (new \DirectoryIterator($root) as $entry) {
            if ($removed >= 50) break;
            if ($entry->isLink() || ! $entry->isDir()) continue;
            if (preg_match('/^object-([a-f0-9]{64})$/D', $entry->getFilename(), $match) && ! isset($keep[$match[1]]) && $entry->getMTime() < time() - 604800) {
                foreach (['raw','data.mmdb','data.ipbin','metadata.json'] as $file) if (is_file($entry->getPathname() . '/' . $file) && ! is_link($entry->getPathname() . '/' . $file)) unlink($entry->getPathname() . '/' . $file);
                if (count(scandir($entry->getPathname())) === 2) rmdir($entry->getPathname());
                ++$removed;
            } elseif (preg_match('/^work-[a-f0-9]{32}$/D', $entry->getFilename()) && $entry->getMTime() < time() - 86400) { $this->removeWork($entry->getPathname()); ++$removed; }
        }
    }

    private function unpack(string $raw, string $output): void
    {
        $input = fopen($raw, 'rb'); $magic = fread($input, 2); fclose($input);
        $input = $magic === "\x1f\x8b" ? @gzopen($raw, 'rb') : fopen($raw, 'rb'); $file = fopen($output, 'wb'); $size = 0;
        if ($input === false) { fclose($file); throw new AppException(422, '无法打开地域数据'); }
        try {
            while (! ($magic === "\x1f\x8b" ? gzeof($input) : feof($input))) {
                $chunk = $magic === "\x1f\x8b" ? @gzread($input, 65536) : fread($input, 65536);
                if ($chunk === '') {
                    if ($magic === "\x1f\x8b" ? gzeof($input) : feof($input)) break;
                    throw new AppException(422, '地域数据读取不完整');
                }
                if ($chunk === false) throw new AppException(422, '地域数据读取不完整');
                $size += strlen($chunk);
                if ($size > (int) config('waf.data_max_database_bytes', 67108864)) throw new AppException(422, '地域数据库解压后超出容量上限');
                if (fwrite($file, $chunk) !== strlen($chunk)) throw new RuntimeException('写入地域数据失败');
            }
        } finally { $magic === "\x1f\x8b" ? gzclose($input) : fclose($input); fclose($file); }
    }
}
