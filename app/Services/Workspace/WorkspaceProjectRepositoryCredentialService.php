<?php

namespace App\Services\Workspace;

use App\Exception\AppException;
use App\Model\ProjectRepository;
use App\Model\Workspace;
use App\Model\WorkspaceProjectRepository;
use App\Model\Cluster;
use App\Services\Docker\SwarmApiClient;
use App\Services\Docker\SwarmContainerExecService;
use App\Services\Project\ProjectRepositoryCredentialService;
use GuzzleHttp\Client;
use Throwable;

class WorkspaceProjectRepositoryCredentialService
{
    public function __construct(
        private SwarmApiClient $docker,
        private SwarmContainerExecService $containerExec,
        private ProjectRepositoryCredentialService $credentials
    ) {}

    public function reconcile(
        Workspace $workspace,
        WorkspaceProjectRepository $mapping,
        ProjectRepository $repository
    ): array {
        $runtime = $this->credentials->runtimeConfigForWorkspace(
            (int) $workspace->uid,
            (int) $repository->id
        );
        if ($runtime === []) {
            throw new AppException(409, '项目代码仓库已经不存在');
        }

        $transport = (string) ($runtime['transport'] ?? 'http');
        $revision = (string) ($runtime['credential_revision'] ?? 'none');
        $credential = $transport === 'ssh'
            ? (string) ($runtime['ssh_private_key'] ?? '')
            : (string) ($runtime['token'] ?? '');
        $workspaceSpec = (array) $workspace->spec;
        $state = (array) ($workspaceSpec['repository_credential'] ?? []);
        if ((int) ($state['repository_id'] ?? 0) === (int) $repository->id
            && (string) ($state['transport'] ?? '') === $transport
            && (string) ($state['revision'] ?? '') === $revision
            && (bool) ($state['mounted'] ?? false) === ($credential !== '')) {
            return $runtime;
        }

        /** @var Cluster|null $cluster */
        $cluster = Cluster::where('id', (int) $workspace->cluster_id)
            ->where('org_id', (int) $workspace->org_id)
            ->where('orchestrator_type', Cluster::ORCHESTRATOR_DOCKER_SWARM)
            ->first();
        if ($cluster === null) {
            throw new AppException(404, 'Docker Swarm 集群不存在');
        }

        $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api) use (
            $workspace,
            $mapping,
            $repository,
            $runtime,
            $transport,
            $revision,
            $credential,
            &$workspaceSpec
        ): void {
            $serviceId = (string) $workspace->runtime_ref;
            $service = $api->request($client, 'GET', '/services/' . rawurlencode($serviceId));
            $this->assertLabels($workspace, (array) ($service['Spec']['Labels'] ?? []));
            $serviceSpec = (array) ($service['Spec'] ?? []);
            $serviceSpec['Labels'] = $this->canonicalLabels(
                $workspace,
                (array) ($serviceSpec['Labels'] ?? [])
            );
            $containerSpec = (array) ($serviceSpec['TaskTemplate']['ContainerSpec'] ?? []);
            $containerSpec['Labels'] = $this->canonicalLabels(
                $workspace,
                (array) ($containerSpec['Labels'] ?? [])
            );
            $secretRefs = (array) ($workspaceSpec['secret_refs'] ?? []);
            $oldSecret = (array) ($secretRefs['git_credential'] ?? []);
            $filename = $transport === 'ssh' ? 'git-ssh-key' : 'git-token';

            $containerSpec['Secrets'] = array_values(array_filter(
                (array) ($containerSpec['Secrets'] ?? []),
                static fn (array $secret): bool => ! in_array(
                    (string) ($secret['File']['Name'] ?? ''),
                    ['git-token', 'git-ssh-key'],
                    true
                )
            ));
            $newSecret = [];
            if ($credential !== '') {
                $name = 'galaxy-workspace-' . $workspace->id . '-git-'
                    . $mapping->id . '-' . substr($revision, 0, 12);
                $newSecret = $this->findOrCreateSecret($client, $api, $workspace, $name, $credential);
                $containerSpec['Secrets'][] = [
                    'SecretID' => $newSecret['id'],
                    'SecretName' => $newSecret['name'],
                    'File' => ['Name' => $filename, 'UID' => '1000', 'GID' => '1000', 'Mode' => 256],
                ];
            }

            $serviceSpec['TaskTemplate']['ContainerSpec'] = $containerSpec;
            $forceUpdate = (int) ($serviceSpec['TaskTemplate']['ForceUpdate'] ?? 0) + 1;
            $serviceSpec['TaskTemplate']['ForceUpdate'] = $forceUpdate;
            try {
                $api->request($client, 'POST', '/services/' . rawurlencode($serviceId) . '/update', [
                    'query' => [
                        'version' => (int) ($service['Version']['Index'] ?? 0),
                        'registryAuthFrom' => 'spec',
                    ],
                    'json' => $serviceSpec,
                ]);
                $this->containerExec->waitForRunningContainerIds($client, $api, $serviceId, 120, $forceUpdate);
            } catch (Throwable $e) {
                if ($newSecret !== [] && ($newSecret['id'] ?? '') !== ($oldSecret['id'] ?? '')) {
                    $this->tryDeleteSecret($client, $api, (string) $newSecret['id']);
                }
                throw $e;
            }

            unset($secretRefs['git_credential']);
            if ($newSecret !== []) {
                $secretRefs['git_credential'] = $newSecret;
            }
            $workspaceSpec['secret_refs'] = $secretRefs;
            $workspaceSpec['repository_credential'] = [
                'repository_id' => (int) $repository->id,
                'transport' => $transport,
                'revision' => $revision,
                'mounted' => $credential !== '',
                'reconciled_at' => time(),
            ];
            $workspace->spec = $workspaceSpec;
            $workspace->updated_at = time();
            $workspace->save();

            $mapping->credential_transport = $transport;
            $mapping->credential_revision = $revision;
            $mapping->updated_at = time();
            $mapping->save();

            if (($oldSecret['id'] ?? '') !== '' && ($oldSecret['id'] ?? '') !== ($newSecret['id'] ?? '')) {
                $this->tryDeleteSecret($client, $api, (string) $oldSecret['id']);
            }
        }, 180);

        return $runtime;
    }

    private function findOrCreateSecret(
        Client $client,
        SwarmApiClient $api,
        Workspace $workspace,
        string $name,
        string $content
    ): array {
        $secrets = $api->request($client, 'GET', '/secrets', ['query' => ['filters' => json_encode([
            'name' => [$name],
        ], JSON_THROW_ON_ERROR)]]);
        foreach ($secrets as $secret) {
            if ((string) ($secret['Spec']['Name'] ?? '') !== $name) {
                continue;
            }
            $this->assertLabels($workspace, (array) ($secret['Spec']['Labels'] ?? []));
            return ['id' => (string) $secret['ID'], 'name' => $name];
        }
        $created = $api->request($client, 'POST', '/secrets/create', ['json' => [
            'Name' => $name,
            'Data' => base64_encode($content),
            'Labels' => $this->labels($workspace),
        ]]);
        $id = (string) ($created['ID'] ?? '');
        if ($id === '') {
            throw new AppException(502, 'Docker API 未返回 Workspace Git Secret ID');
        }
        return ['id' => $id, 'name' => $name];
    }

    private function tryDeleteSecret(Client $client, SwarmApiClient $api, string $id): void
    {
        try {
            $api->request($client, 'DELETE', '/secrets/' . rawurlencode($id));
        } catch (Throwable) {
            // A labeled stale Secret is reclaimed with the Workspace and is
            // safer than undoing an already successful Service update.
        }
    }

    private function labels(Workspace $workspace): array
    {
        return [
            'com.codegalaxy.kind' => 'workspace',
            'com.codegalaxy.workspace.id' => (string) $workspace->id,
            'com.codegalaxy.group.id' => (string) $workspace->group_id,
            'com.codegalaxy.user.id' => (string) $workspace->uid,
        ];
    }

    private function assertLabels(Workspace $workspace, array $labels): void
    {
        foreach ($this->labels($workspace) as $name => $value) {
            if ($name === 'com.codegalaxy.group.id'
                && (string) ($labels[$name] ?? $labels['com.codegalaxy.project.id'] ?? '') === $value) {
                continue;
            }
            if ((string) ($labels[$name] ?? '') !== $value) {
                throw new AppException(409, '拒绝更新归属标签不匹配的 Docker Workspace 资源');
            }
        }
    }

    private function canonicalLabels(Workspace $workspace, array $labels): array
    {
        unset($labels['com.codegalaxy.project.id']);
        return array_replace($labels, $this->labels($workspace));
    }
}
