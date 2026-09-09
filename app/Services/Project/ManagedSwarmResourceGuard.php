<?php

namespace App\Services\Project;

use App\Exception\AppException;

final class ManagedSwarmResourceGuard
{
    public function assertService(
        array $service,
        int $orgId,
        int $projectId,
        int $envId,
        ?string $expectedName = null
    ): void {
        $labels = (array) ($service['Spec']['Labels'] ?? $service['labels'] ?? []);
        $actualName = (string) ($service['Spec']['Name'] ?? $service['name'] ?? '');
        if (($expectedName !== null && $actualName !== $expectedName)
            || (int) ($labels['com.codegalaxy.org.id'] ?? 0) !== $orgId
            || (int) ($labels['com.codegalaxy.app.id'] ?? $labels['com.codegalaxy.project.id'] ?? 0) !== $projectId
            || (int) ($labels['com.codegalaxy.env.id'] ?? 0) !== $envId) {
            throw new AppException(409, '目标 Service 不属于当前项目运行实例，拒绝修改：' . ($actualName ?: 'unknown'));
        }
    }

    public function assertSecret(
        array $secret,
        int $releaseId,
        int $projectId,
        string $expectedName
    ): void {
        $labels = (array) ($secret['Spec']['Labels'] ?? []);
        if (($secret['Spec']['Name'] ?? '') !== $expectedName
            || (int) ($labels['com.codegalaxy.release.id'] ?? 0) !== $releaseId
            || (int) ($labels['com.codegalaxy.app.id'] ?? $labels['com.codegalaxy.project.id'] ?? 0) !== $projectId) {
            throw new AppException(409, 'Docker Secret 名称或 ID 已被其他资源占用，拒绝复用：' . $expectedName);
        }
    }

    public function assertConfig(array $config, int $releaseId, int $projectId, string $expectedName): void
    {
        $labels = (array) ($config['Spec']['Labels'] ?? []);
        if (($config['Spec']['Name'] ?? '') !== $expectedName
            || (int) ($labels['com.codegalaxy.release.id'] ?? 0) !== $releaseId
            || (int) ($labels['com.codegalaxy.app.id'] ?? $labels['com.codegalaxy.project.id'] ?? 0) !== $projectId) {
            throw new AppException(409, 'Docker Config 名称或 ID 已被其他资源占用，拒绝复用：' . $expectedName);
        }
    }
}
