<?php

namespace App\Services\Project;

use App\Model\ProjectRuntime;

/** Canonical Docker Service identity for a project runtime. */
final class ProjectServiceIdentity
{
    public static function newResourceName(int $orgId, int $projectId): string
    {
        return sprintf('cg-%d-%d-%s', $orgId, $projectId, bin2hex(random_bytes(6)));
    }

    public static function instanceName(array $definition): string
    {
        return trim((string) ($definition['instance_name'] ?? $definition['service_name'] ?? ''));
    }

    public static function releaseResourceName(
        array $definition,
        int $orgId,
        int $projectId,
        int $envId,
        bool $kubernetes = false
    ): string {
        $resourceName = trim((string) ($definition['service_name'] ?? ''));
        if (trim((string) ($definition['resource_identity_version'] ?? '')) !== '' && $resourceName !== '') {
            return $resourceName;
        }
        return $kubernetes
            ? self::kubernetesName($orgId, $projectId, $envId, $resourceName ?: 'app')
            : self::dockerName($orgId, $projectId, $envId, $resourceName ?: 'app');
    }

    public static function dockerName(int $orgId, int $projectId, int $envId, string $logicalName): string
    {
        $prefix = sprintf('cg-%d-%d-%d-', $orgId, $projectId, $envId);
        return self::boundedName($prefix, $logicalName);
    }

    public static function legacyDockerName(int $orgId, int $projectId, string $logicalName): string
    {
        return self::boundedName(sprintf('cg-%d-%d-', $orgId, $projectId), $logicalName);
    }

    public static function kubernetesName(int $orgId, int $projectId, int $envId, string $logicalName): string
    {
        $logicalName = strtolower((string) preg_replace('/[^a-z0-9-]+/', '-', $logicalName));
        $logicalName = trim((string) preg_replace('/-+/', '-', $logicalName), '-');
        return self::boundedName(sprintf('cg-%d-%d-%d-', $orgId, $projectId, $envId), $logicalName ?: 'app');
    }

    private static function boundedName(string $prefix, string $logicalName): string
    {
        if (strlen($prefix . $logicalName) <= 63) {
            return $prefix . $logicalName;
        }
        $suffix = '-' . substr(hash('sha256', $logicalName), 0, 10);
        return $prefix . substr($logicalName, 0, 63 - strlen($prefix) - strlen($suffix)) . $suffix;
    }

    public static function runtimeDockerName(ProjectRuntime $runtime): string
    {
        $persisted = trim((string) ($runtime->service_name ?? ''));
        if ($persisted !== '') {
            return $persisted;
        }
        return self::legacyDockerName(
            (int) $runtime->org_id,
            (int) $runtime->project_id,
            (string) $runtime->name
        );
    }
}
