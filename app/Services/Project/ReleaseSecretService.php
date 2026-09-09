<?php

namespace App\Services\Project;

use App\Model\ProjectReleaseSecret;
use App\Services\Encrypt\CredentialCipher;

class ReleaseSecretService
{
    public function __construct(private CredentialCipher $cipher) {}

    public function store(int $orgId, int $projectId, int $releaseId, array $secrets): void
    {
        foreach ($secrets as $secret) {
            ProjectReleaseSecret::updateOrCreate(
                ['release_id' => $releaseId, 'name' => $secret['name']],
                [
                    'org_id' => $orgId,
                    'project_id' => $projectId,
                    'source_configuration_id' => (int) ($secret['source_configuration_id'] ?? 0),
                    'source_version' => (int) ($secret['source_version'] ?? 0),
                    'target' => $secret['target'],
                    'encrypted_value' => $this->cipher->encrypt((string) $secret['value']),
                    'value_hash' => hash('sha256', (string) $secret['value']),
                    'value_size' => strlen((string) $secret['value']),
                    'file_mode' => (int) ($secret['file_mode'] ?? 0440),
                    'docker_secret_id' => '',
                    'docker_secret_name' => '',
                    'created_at' => time(),
                ]
            );
        }
    }

    public function copy(int $sourceReleaseId, int $targetReleaseId, int $orgId, int $projectId): void
    {
        foreach (ProjectReleaseSecret::where('release_id', $sourceReleaseId)->get() as $secret) {
            ProjectReleaseSecret::create([
                'org_id' => $orgId,
                'project_id' => $projectId,
                'release_id' => $targetReleaseId,
                'source_configuration_id' => $secret->source_configuration_id,
                'source_version' => $secret->source_version,
                'name' => $secret->name,
                'target' => $secret->target,
                'encrypted_value' => $secret->encrypted_value,
                'value_hash' => $secret->value_hash,
                'value_size' => $secret->value_size,
                'file_mode' => $secret->file_mode,
                'docker_secret_id' => '',
                'docker_secret_name' => '',
                'created_at' => time(),
            ]);
        }
    }

    public function plaintext(ProjectReleaseSecret $secret): string
    {
        return $this->cipher->decrypt((string) $secret->encrypted_value);
    }

    public function purge(int $releaseId): int
    {
        return ProjectReleaseSecret::where('release_id', $releaseId)->delete();
    }
}
