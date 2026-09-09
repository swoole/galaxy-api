<?php

namespace App\Services\Project;

use App\Exception\AppException;

class ReleaseDefinitionService
{
    public function normalize(array $input): array
    {
        // `instance_name` is a project-facing title, not an orchestrator
        // resource identifier. Keep accepting the old service_name field so
        // older clients can migrate without turning the title into an ID.
        $instanceName = trim((string) ($input['instance_name'] ?? $input['service_name'] ?? ''));
        if ($instanceName === '' || mb_strlen($instanceName) > 128
            || preg_match('/[\x00-\x1f\x7f]/u', $instanceName)) {
            throw new AppException(422, '实例名称不能为空、不能包含控制字符，最长 128 个字符');
        }
        $replicas = $this->integer($input['replicas'] ?? 1, 0, 100, '副本数');
        $resources = (array) ($input['resources'] ?? []);
        $limits = $this->resources((array) ($resources['limits'] ?? []), '资源上限');
        $reservations = $this->resources((array) ($resources['reservations'] ?? []), '资源预留');
        if ($reservations['cpus'] > $limits['cpus'] && $limits['cpus'] > 0) {
            throw new AppException(422, 'CPU 预留不能大于 CPU 上限');
        }
        if ($reservations['memory_mb'] > $limits['memory_mb'] && $limits['memory_mb'] > 0) {
            throw new AppException(422, '内存预留不能大于内存上限');
        }

        $command = $this->stringList((array) ($input['command'] ?? []), '启动命令', 100);
        $args = $this->stringList((array) ($input['args'] ?? []), '启动参数', 200);
        $envInput = (array) ($input['env'] ?? []);
        if (count($envInput) > 256) {
            throw new AppException(422, '每次发布最多配置 256 个环境变量');
        }
        $env = [];
        $envBytes = 0;
        foreach ($envInput as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,127}$/', $name)) {
                throw new AppException(422, '环境变量名称不合法：' . $name);
            }
            if (array_key_exists($name, $env)) {
                throw new AppException(422, '环境变量名称重复：' . $name);
            }
            $value = (string) ($item['value'] ?? '');
            if (str_contains($value, "\0") || strlen($value) > 32768) {
                throw new AppException(422, '环境变量值过长或包含空字节：' . $name);
            }
            $envBytes += strlen($name) + strlen($value) + 1;
            if ($envBytes > 256 * 1024) {
                throw new AppException(422, '环境变量总容量不能超过 256 KiB');
            }
            $env[$name] = $value;
        }

        $secrets = [];
        $secretInput = (array) ($input['secrets'] ?? []);
        if (count($secretInput) > 64) {
            throw new AppException(422, '每次发布最多配置 64 个 Secret');
        }
        $secretTargets = [];
        foreach ($secretInput as $item) {
            $name = strtolower(trim((string) ($item['name'] ?? '')));
            $target = trim((string) ($item['target'] ?? $name));
            if (! preg_match('/^[a-z0-9][a-z0-9_.-]{0,127}$/', $name)) {
                throw new AppException(422, 'Secret 名称不合法：' . $name);
            }
            if (isset($secrets[$name])) {
                throw new AppException(422, 'Secret 名称重复：' . $name);
            }
            if (! preg_match('#^(?:/[A-Za-z0-9_.-]+)+$|^[A-Za-z0-9][A-Za-z0-9_.-]{0,254}$#', $target)) {
                throw new AppException(422, 'Secret 挂载目标不合法：' . $target);
            }
            if (isset($secretTargets[$target])) {
                throw new AppException(422, 'Secret 挂载文件名重复：' . $target);
            }
            if (! array_key_exists('value', $item) || (string) $item['value'] === '') {
                throw new AppException(422, 'Secret 值不能为空：' . $name);
            }
            $value = (string) $item['value'];
            if (strlen($value) > 500 * 1024) {
                throw new AppException(422, 'Docker Secret 不能超过 500 KiB：' . $name);
            }
            $secretTargets[$target] = true;
            $mode = $this->integer($item['file_mode'] ?? 0440, 0, 0777, 'Secret 文件权限');
            $secrets[$name] = ['name' => $name, 'target' => $target, 'value' => $value, 'file_mode' => $mode];
        }

        $configs = [];
        $configInput = (array) ($input['configs'] ?? []);
        if (count($configInput) > 64) {
            throw new AppException(422, '每次发布最多配置 64 个配置文件');
        }
        $configTargets = [];
        foreach ($configInput as $item) {
            $name = strtolower(trim((string) ($item['name'] ?? '')));
            $target = trim((string) ($item['target'] ?? ''));
            if (! preg_match('/^[a-z0-9][a-z0-9_.-]{0,127}$/', $name)) {
                throw new AppException(422, '配置文件名称不合法：' . $name);
            }
            if (isset($configs[$name])) {
                throw new AppException(422, '配置文件名称重复：' . $name);
            }
            if (! str_starts_with($target, '/') || $target === '/' || str_contains($target, "\0")
                || preg_match('#(?:^|/)\.\.(?:/|$)#', $target)) {
                throw new AppException(422, '配置文件必须声明安全的容器绝对挂载路径：' . $name);
            }
            if (isset($configTargets[$target])) {
                throw new AppException(422, '配置文件挂载路径重复：' . $target);
            }
            $value = (string) ($item['value'] ?? '');
            if (str_contains($value, "\0") || strlen($value) > 500 * 1024) {
                throw new AppException(422, 'Docker Config 不能超过 500 KiB 或包含空字节：' . $name);
            }
            $configTargets[$target] = true;
            $configs[$name] = [
                'name' => $name, 'target' => $target, 'value' => $value,
                'file_mode' => $this->integer($item['file_mode'] ?? 0444, 0, 0777, '配置文件权限'),
            ];
        }

        $networkInput = (array) ($input['networks'] ?? []);
        if (count($networkInput) > 32) {
            throw new AppException(422, '每个 Service 最多连接 32 个网络');
        }
        $networks = [];
        foreach ($networkInput as $network) {
            $network = trim((string) $network);
            if ($network === '' || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,254}$/', $network)) {
                throw new AppException(422, 'Docker 网络名称或 ID 不合法');
            }
            $networks[] = $network;
        }

        $portInput = (array) ($input['ports'] ?? []);
        if (count($portInput) > 128) {
            throw new AppException(422, '每个 Service 最多配置 128 个端口');
        }
        $ports = [];
        $published = [];
        foreach ($portInput as $item) {
            $target = $this->integer($item['target'] ?? 0, 1, 65535, '容器端口');
            $public = $this->integer($item['published'] ?? 0, 0, 65535, '发布端口');
            $protocol = strtolower((string) ($item['protocol'] ?? 'tcp'));
            $mode = strtolower((string) ($item['mode'] ?? 'ingress'));
            if (! in_array($protocol, ['tcp', 'udp', 'sctp'], true) || ! in_array($mode, ['ingress', 'host'], true)) {
                throw new AppException(422, '端口协议或发布模式不合法');
            }
            $key = $public . '/' . $protocol;
            if ($public > 0 && isset($published[$key])) {
                throw new AppException(422, '发布端口重复：' . $key);
            }
            $published[$key] = true;
            $ports[] = ['target' => $target, 'published' => $public, 'protocol' => $protocol, 'mode' => $mode];
        }

        $mountInput = (array) ($input['mounts'] ?? []);
        if (count($mountInput) > 128) {
            throw new AppException(422, '每个 Service 最多配置 128 个目录映射');
        }
        $mounts = [];
        $mountTargets = [];
        foreach ($mountInput as $item) {
            $type = strtolower((string) ($item['type'] ?? 'volume'));
            $source = trim((string) ($item['source'] ?? ''));
            $target = trim((string) ($item['target'] ?? ''));
            if ($target !== '/') {
                $target = rtrim($target, '/');
            }
            if (! in_array($type, ['volume', 'bind', 'tmpfs'], true)
                || ! str_starts_with($target, '/')
                || str_contains($target, "\0")
                || preg_match('#(?:^|/)\.\.(?:/|$)#', $target)) {
                throw new AppException(422, '目录映射类型或容器路径不合法');
            }
            if (isset($mountTargets[$target])) {
                throw new AppException(422, '容器挂载路径重复：' . $target);
            }
            if ($type !== 'tmpfs' && $source === '') {
                throw new AppException(422, 'Volume/Bind 映射必须填写来源');
            }
            if (str_contains($source, "\0") || preg_match('#(?:^|/)\.\.(?:/|$)#', $source)) {
                throw new AppException(422, '目录映射来源不合法');
            }
            if ($type === 'bind' && ! str_starts_with($source, '/')) {
                throw new AppException(422, 'Bind 来源必须是主机绝对路径');
            }
            if ($type === 'volume' && ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,254}$/', $source)) {
                throw new AppException(422, 'Volume 名称不合法：' . $source);
            }
            $mountTargets[$target] = true;
            $mounts[] = [
                'type' => $type, 'source' => $source, 'target' => $target,
                'readonly' => (bool) ($item['readonly'] ?? false),
            ];
        }
        if (array_filter($mounts, static fn (array $mount): bool => $mount['type'] === 'bind')
            && ($input['bind_risk_acknowledged'] ?? false) !== true) {
            throw new AppException(422, 'Bind Mount 可直接访问 Swarm 节点宿主机数据，必须明确确认风险后才能发布');
        }
        $placementNodeId = trim((string) ($input['placement_node_id'] ?? ''));
        if ($placementNodeId !== '' && ! preg_match('/^[a-z0-9]{20,64}$/', $placementNodeId)) {
            throw new AppException(422, '节点放置约束不是有效的 Swarm Node ID');
        }
        $runtimeImportSource = [];
        if (isset($input['runtime_import_source'])) {
            $source = (array) $input['runtime_import_source'];
            $sourceType = trim((string) ($source['type'] ?? ''));
            $sourceNodeId = trim((string) ($source['node_id'] ?? ''));
            $sourceReference = trim((string) ($source['reference'] ?? ''));
            if ($sourceType !== ProjectRuntimeImportService::DOCKER_CONTAINER
                || ! preg_match('/^[a-z0-9]{20,64}$/', $sourceNodeId)
                || ! preg_match('/^[a-f0-9]{12,64}$/', $sourceReference)) {
                throw new AppException(422, '运行资源导入来源无效');
            }
            $runtimeImportSource = [
                'type' => $sourceType,
                'cluster_id' => $this->integer(
                    $source['cluster_id'] ?? 0,
                    1,
                    PHP_INT_MAX,
                    '导入来源集群'
                ),
                'node_id' => $sourceNodeId,
                'reference' => $sourceReference,
            ];
        }

        $update = (array) ($input['update'] ?? []);
        $updateOrder = (string) ($update['order'] ?? 'stop-first');
        $failureAction = (string) ($update['failure_action'] ?? 'pause');
        if (! in_array($updateOrder, ['stop-first', 'start-first'], true)) {
            throw new AppException(422, '更新顺序只支持 stop-first 或 start-first');
        }
        if (! in_array($failureAction, ['pause', 'continue', 'rollback'], true)) {
            throw new AppException(422, '更新失败动作只支持 pause、continue 或 rollback');
        }
        $definition = [
            'schema_version' => '1',
            'instance_name' => $instanceName,
            'replicas' => $replicas,
            'command' => $command,
            'args' => $args,
            'resources' => ['limits' => $limits, 'reservations' => $reservations],
            'networks' => array_values(array_unique($networks)),
            'ports' => $ports,
            'mounts' => $mounts,
            'placement_node_id' => $placementNodeId,
            'runtime_import_source' => $runtimeImportSource,
            'env' => array_map(static fn (string $name, string $value): array => ['name' => $name, 'value' => $value], array_keys($env), $env),
            'configs' => array_map(static fn (array $item): array => [
                'name' => $item['name'], 'target' => $item['target'], 'file_mode' => $item['file_mode'],
            ], array_values($configs)),
            'secrets' => array_map(static fn (array $item): array => [
                'name' => $item['name'], 'target' => $item['target'], 'file_mode' => $item['file_mode'],
            ], array_values($secrets)),
            'update' => [
                'parallelism' => $this->integer($update['parallelism'] ?? 1, 1, 100, '更新并发数'),
                'delay_seconds' => $this->integer($update['delay_seconds'] ?? 0, 0, 3600, '更新间隔'),
                'order' => $updateOrder,
                'failure_action' => $failureAction,
            ],
        ];
        return [$definition, array_values($secrets), array_values($configs)];
    }

    private function resources(array $resources, string $label): array
    {
        $cpuInput = $resources['cpus'] ?? 0;
        if (! is_numeric($cpuInput) || ! is_finite((float) $cpuInput)) {
            throw new AppException(422, $label . ' CPU 必须是有效数字');
        }
        $cpus = round((float) $cpuInput, 3);
        $memory = $this->integer($resources['memory_mb'] ?? 0, 0, 1048576, $label . '内存 MiB');
        if ($cpus < 0 || $cpus > 256 || $memory < 0 || $memory > 1048576) {
            throw new AppException(422, $label . '超出允许范围');
        }
        return ['cpus' => $cpus, 'memory_mb' => $memory];
    }

    private function integer(mixed $value, int $min, int $max, string $label): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < $min || (int) $value > $max) {
            throw new AppException(422, $label . '必须在 ' . $min . ' 到 ' . $max . ' 之间');
        }
        return (int) $value;
    }

    private function stringList(array $values, string $label, int $max): array
    {
        if (count($values) > $max) {
            throw new AppException(422, $label . '数量过多');
        }
        return array_map(static function (mixed $value) use ($label): string {
            if (! is_scalar($value)
                || str_contains((string) $value, "\0")
                || strlen((string) $value) > 32768) {
                throw new AppException(422, $label . '包含非法值');
            }
            return (string) $value;
        }, array_values($values));
    }
}
