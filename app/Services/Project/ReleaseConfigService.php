<?php

namespace App\Services\Project;

use App\Model\ProjectReleaseConfig;

class ReleaseConfigService
{
    public function store(int $orgId, int $projectId, int $releaseId, array $configs): void
    {
        foreach ($configs as $config) {
            $value = (string) $config['value'];
            ProjectReleaseConfig::updateOrCreate(
                ['release_id' => $releaseId, 'name' => $config['name']],
                [
                    'org_id' => $orgId, 'project_id' => $projectId,
                    'source_configuration_id' => (int) ($config['source_configuration_id'] ?? 0),
                    'source_version' => (int) ($config['source_version'] ?? 0),
                    'target' => $config['target'], 'content' => $value,
                    'content_hash' => hash('sha256', $value), 'content_size' => strlen($value),
                    'file_mode' => (int) ($config['file_mode'] ?? 0444),
                    'docker_config_id' => '', 'docker_config_name' => '', 'created_at' => time(),
                ]
            );
        }
    }

    public function copy(int $sourceReleaseId, int $targetReleaseId, int $orgId, int $projectId): void
    {
        foreach (ProjectReleaseConfig::where('release_id', $sourceReleaseId)->get() as $config) {
            ProjectReleaseConfig::create([
                'org_id' => $orgId, 'project_id' => $projectId, 'release_id' => $targetReleaseId,
                'source_configuration_id' => $config->source_configuration_id,
                'source_version' => $config->source_version, 'name' => $config->name,
                'target' => $config->target, 'content' => $config->content,
                'content_hash' => $config->content_hash, 'content_size' => $config->content_size,
                'file_mode' => $config->file_mode, 'docker_config_id' => '',
                'docker_config_name' => '', 'created_at' => time(),
            ]);
        }
    }
}
