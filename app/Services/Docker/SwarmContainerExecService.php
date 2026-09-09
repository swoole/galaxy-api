<?php

namespace App\Services\Docker;

use App\Exception\AppException;
use App\Model\Cluster;
use GuzzleHttp\Client;
use Swoole\Coroutine;

class SwarmContainerExecService
{
    public function __construct(private SwarmApiClient $docker) {}

    public function executeOnContainer(
        Cluster $cluster,
        string $containerId,
        array $command,
        array $environment = [],
        ?string $workingDir = null
    ): array {
        return $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api) use (
            $containerId, $command, $environment, $workingDir
        ): array {
            $config = [
                'AttachStdout' => true,
                'AttachStderr' => true,
                'Tty' => false,
                'Cmd' => array_values(array_map('strval', $command)),
                'Env' => array_values(array_map('strval', $environment)),
            ];
            if ($workingDir !== null && $workingDir !== '') {
                $config['WorkingDir'] = $workingDir;
            }
            $exec = $api->request($client, 'POST', '/containers/' . rawurlencode($containerId) . '/exec', [
                'json' => $config,
            ]);
            $execId = (string) ($exec['Id'] ?? '');
            if ($execId === '') {
                throw new AppException(502, 'Docker API 未返回 Exec ID');
            }
            $stream = $api->requestRaw($client, 'POST', '/exec/' . rawurlencode($execId) . '/start', [
                'json' => ['Detach' => false, 'Tty' => false],
            ]);
            [$stdout, $stderr] = $this->demultiplex($stream);
            $inspect = $api->request($client, 'GET', '/exec/' . rawurlencode($execId) . '/json');
            return [
                'exit_code' => (int) ($inspect['ExitCode'] ?? -1),
                'stdout' => $stdout,
                'stderr' => $stderr,
            ];
        });
    }

    public function executeService(
        Cluster $cluster,
        string $serviceId,
        array $command,
        array $environment = [],
        ?string $workingDir = null,
        int $timeout = 30
    ): array {
        return $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api) use (
            $serviceId, $command, $environment, $workingDir
        ): array {
            $containerId = $this->runningContainerId($client, $api, $serviceId);
            $config = [
                'AttachStdout' => true,
                'AttachStderr' => true,
                'Tty' => false,
                'Cmd' => array_values(array_map('strval', $command)),
                'Env' => array_values(array_map('strval', $environment)),
            ];
            if ($workingDir !== null && $workingDir !== '') {
                $config['WorkingDir'] = $workingDir;
            }
            $exec = $api->request($client, 'POST', '/containers/' . rawurlencode($containerId) . '/exec', [
                'json' => $config,
            ]);
            $execId = (string) ($exec['Id'] ?? '');
            if ($execId === '') {
                throw new AppException(502, 'Docker API 未返回 Exec ID');
            }
            $stream = $api->requestRaw($client, 'POST', '/exec/' . rawurlencode($execId) . '/start', [
                'json' => ['Detach' => false, 'Tty' => false],
            ]);
            [$stdout, $stderr] = $this->demultiplex($stream);
            $inspect = $api->request($client, 'GET', '/exec/' . rawurlencode($execId) . '/json');
            return [
                'exit_code' => (int) ($inspect['ExitCode'] ?? -1),
                'stdout' => $stdout,
                'stderr' => $stderr,
            ];
        }, $timeout);
    }

    private function runningContainerId(Client $client, SwarmApiClient $api, string $serviceId): string
    {
        $tasks = $api->request($client, 'GET', '/tasks', ['query' => ['filters' => json_encode([
            'service' => [$serviceId], 'desired-state' => ['running'],
        ], JSON_THROW_ON_ERROR)]]);
        foreach ($tasks as $task) {
            $containerId = (string) ($task['Status']['ContainerStatus']['ContainerID'] ?? '');
            if (($task['Status']['State'] ?? '') === 'running' && $containerId !== '') {
                return $containerId;
            }
        }
        throw new AppException(409, '目标 Service 尚无运行中的容器');
    }

    public function runningContainerIds(Client $client, SwarmApiClient $api, string $serviceId): array
    {
        return $this->findRunningContainerIds($client, $api, $serviceId);
    }

    public function waitForRunningContainerIds(
        Client $client,
        SwarmApiClient $api,
        string $serviceId,
        int $timeoutSeconds = 30,
        ?int $forceUpdate = null
    ): array {
        $deadline = microtime(true) + max(1, $timeoutSeconds);
        do {
            try {
                return $this->findRunningContainerIds($client, $api, $serviceId, $forceUpdate);
            } catch (AppException $e) {
                if (microtime(true) >= $deadline) {
                    throw $e;
                }
                if (Coroutine::getCid() >= 0) {
                    Coroutine::sleep(0.5);
                } else {
                    usleep(500000);
                }
            }
        } while (true);
    }

    private function findRunningContainerIds(
        Client $client,
        SwarmApiClient $api,
        string $serviceId,
        ?int $forceUpdate = null
    ): array {
        $tasks = $api->request($client, 'GET', '/tasks', ['query' => ['filters' => json_encode([
            'service' => [$serviceId], 'desired-state' => ['running'],
        ], JSON_THROW_ON_ERROR)]]);
        $ids = [];
        foreach ($tasks as $task) {
            $containerId = (string) ($task['Status']['ContainerStatus']['ContainerID'] ?? '');
            $matchesGeneration = $forceUpdate === null
                || (int) ($task['Spec']['ForceUpdate'] ?? -1) >= $forceUpdate;
            if (($task['Status']['State'] ?? '') === 'running' && $containerId !== '' && $matchesGeneration) {
                $ids[] = $containerId;
            }
        }
        if ($ids === []) {
            throw new AppException(409, '目标 Service 尚无运行中的容器');
        }
        return $ids;
    }

    public function listFiles(Cluster $cluster, string $containerId, string $path = '/'): array
    {
        $result = $this->executeOnContainer($cluster, $containerId, [
            'ls', '-la', $path,
        ]);
        $stdout = (string) ($result['stdout'] ?? '');
        if ($stdout === '') {
            throw new AppException(500, '无法列出目录内容：' . ($result['stderr'] ?? '无输出'));
        }
        $lines = explode("\n", trim($stdout));
        $files = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, 'total ')) {
                continue;
            }
            $parts = preg_split('/\s+/', $line, 9);
            if (count($parts) < 9) {
                continue;
            }
            $perm = $parts[0];
            $isDir = $perm[0] === 'd';
            $isLink = $perm[0] === 'l';
            $size = (int) $parts[4];
            $date = $parts[5];
            $time = $parts[6];
            $name = $parts[8];
            // Handle symlink: name -> target
            $linkTarget = '';
            if ($isLink && str_contains($name, ' -> ')) {
                [$name, $linkTarget] = explode(' -> ', $name, 2);
            }
            $files[] = [
                'name' => $name,
                'type' => $isDir ? 'dir' : ($isLink ? 'link' : 'file'),
                'size' => $size,
                'permissions' => $perm,
                'owner' => $parts[2],
                'group' => $parts[3],
                'date' => $date,
                'time' => $time,
                'link_target' => $linkTarget,
            ];
        }
        // Sort: directories first, then alphabetical
        usort($files, static function (array $a, array $b): int {
            $aIsDir = $a['type'] === 'dir' ? 0 : 1;
            $bIsDir = $b['type'] === 'dir' ? 0 : 1;
            if ($aIsDir !== $bIsDir) {
                return $aIsDir <=> $bIsDir;
            }
            return strnatcasecmp($a['name'], $b['name']);
        });
        $pwd = $this->resolveListedPath($cluster, $containerId, $path);
        return [
            'pwd' => $pwd,
            'files' => $files,
        ];
    }

    /**
     * 解析被列出目录的绝对路径（逻辑路径，保留符号链接名称），
     * 用于前端面包屑展示，避免前端自行拼接路径导致历史叠加/漂移。
     */
    private function resolveListedPath(Cluster $cluster, string $containerId, string $path): string
    {
        $escaped = addcslashes($path, '"');
        $result = $this->executeOnContainer($cluster, $containerId, [
            'sh', '-c', 'cd "' . $escaped . '" && pwd',
        ]);
        $stdout = trim((string) ($result['stdout'] ?? ''));
        if ($stdout !== '' && (int) ($result['exit_code'] ?? -1) === 0) {
            return $stdout;
        }
        // 兜底：规范化请求路径
        $normalized = '/' . ltrim($path, '/');
        $normalized = preg_replace('#/+#', '/', $normalized);
        return $normalized === '' ? '/' : (rtrim($normalized, '/') ?: '/');
    }

    public function readFile(Cluster $cluster, string $containerId, string $path): array
    {
        $result = $this->executeOnContainer($cluster, $containerId, [
            'sh', '-c', 'base64 "' . addcslashes($path, '"') . '" 2>/dev/null || echo "___EXEC_ERROR___"',
        ]);
        $stdout = (string) ($result['stdout'] ?? '');
        if (str_contains($stdout, '___EXEC_ERROR___') || $result['exit_code'] !== 0) {
            throw new AppException($result['exit_code'] === 126 ? 403 : 404,
                '无法读取文件：' . (($result['stderr'] ?? '') ?: '文件不存在或无权访问'));
        }
        $clean = trim(str_replace('___EXEC_ERROR___', '', $stdout));
        return [
            'content' => $clean,
            'encoding' => 'base64',
            'size' => strlen($clean),
        ];
    }

    /**
     * Read a file from a running Swarm task without requiring any utility inside
     * the workload image. This is important for scratch/distroless images such
     * as Traefik, where cat, sh and base64 may not exist.
     */
    public function readServiceFile(Cluster $cluster, string $serviceId, string $path): string
    {
        return $this->docker->withCluster($cluster, function (
            Client $client,
            SwarmApiClient $api
        ) use ($serviceId, $path): string {
            $containerId = $this->runningContainerId($client, $api, $serviceId);
            return $this->extractArchiveFile($api->getArchive($client, $containerId, $path), basename($path));
        });
    }

    public function writeFile(Cluster $cluster, string $containerId, string $path, string $content, string $encoding = 'base64'): void
    {
        if ($encoding !== 'base64') {
            throw new AppException(400, '仅支持 base64 编码的文件内容');
        }
        $clean = trim($content);
        if ($clean === '') {
            throw new AppException(400, '文件内容不能为空');
        }
        $decoded = base64_decode($clean, true);
        if ($decoded === false) {
            throw new AppException(400, 'base64 内容解码失败');
        }

        $filename = basename($path);
        $dir = dirname($path);
        $tar = $this->buildTar($filename, $decoded);

        $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api) use ($containerId, $dir, $tar): void {
            $api->putArchive($client, $containerId, $dir, $tar);
        });
    }

    public function buildTar(string $filename, string $content): string
    {
        $size = strlen($content);
        $header = str_repeat("\0", 512);

        // name (100)
        $this->tarField($header, 0, 100, $filename);
        // mode (8) — 0644
        $this->tarField($header, 100, 8, '0000644');
        // uid (8)
        $this->tarField($header, 108, 8, '0000000');
        // gid (8)
        $this->tarField($header, 116, 8, '0000000');
        // size (12)
        $this->tarField($header, 124, 12, sprintf('%011o', $size));
        // mtime (12)
        $this->tarField($header, 136, 12, sprintf('%011o', time()));
        // typeflag (1) — '0' for regular file
        $header[156] = '0';
        // magic (6) — ustar
        $this->tarField($header, 257, 6, 'ustar');
        // version (2)
        $this->tarField($header, 263, 2, '00');

        // checksum: sum of all header bytes with chksum field treated as spaces
        $chksum = 0;
        for ($i = 0; $i < 512; $i++) {
            $chksum += ($i >= 148 && $i < 156) ? 32 : ord($header[$i]);
        }
        $this->tarField($header, 148, 8, sprintf('%07o', $chksum));

        // pad content to 512-byte boundary, then two zero blocks (end-of-archive)
        $pad = (512 - ($size % 512)) % 512;
        return $header . $content . str_repeat("\0", $pad) . str_repeat("\0", 1024);
    }

    private function tarField(string &$buf, int $offset, int $len, string $value): void
    {
        for ($i = 0; $i < $len; $i++) {
            $buf[$offset + $i] = $i < strlen($value) ? $value[$i] : "\0";
        }
    }

    private function extractArchiveFile(string $archive, string $expectedName): string
    {
        $offset = 0;
        $length = strlen($archive);
        while ($offset + 512 <= $length) {
            $header = substr($archive, $offset, 512);
            if ($header === str_repeat("\0", 512)) {
                break;
            }
            $name = rtrim(substr($header, 0, 100), "\0");
            $prefix = rtrim(substr($header, 345, 155), "\0");
            if ($prefix !== '') {
                $name = $prefix . '/' . $name;
            }
            $sizeField = trim(substr($header, 124, 12), " \0");
            if ($sizeField === '' || preg_match('/^[0-7]+$/', $sizeField) !== 1) {
                throw new AppException(502, 'Docker Archive 包含不支持的文件大小格式');
            }
            $size = octdec($sizeField);
            $dataOffset = $offset + 512;
            if ($size < 0 || $dataOffset + $size > $length) {
                throw new AppException(502, 'Docker Archive 响应不完整');
            }
            $type = $header[156] ?? "\0";
            if (($type === '0' || $type === "\0") && basename($name) === $expectedName) {
                return substr($archive, $dataOffset, $size);
            }
            $offset = $dataOffset + (int) (ceil($size / 512) * 512);
        }
        throw new AppException(404, 'Docker Archive 中未找到目标文件');
    }

    private function demultiplex(string $stream): array
    {
        $stdout = '';
        $stderr = '';
        $offset = 0;
        $length = strlen($stream);
        while ($offset + 8 <= $length) {
            $type = ord($stream[$offset]);
            $size = unpack('Nlength', substr($stream, $offset + 4, 4))['length'];
            if ($size < 0 || $offset + 8 + $size > $length) {
                return [$stream, ''];
            }
            $payload = substr($stream, $offset + 8, $size);
            if ($type === 2) {
                $stderr .= $payload;
            } else {
                $stdout .= $payload;
            }
            $offset += 8 + $size;
        }
        if ($offset !== $length) {
            $stdout .= substr($stream, $offset);
        }
        return [$stdout, $stderr];
    }
}
