<?php

namespace App\Services\Gateway;

use App\Exception\AppException;
use App\Model\ProjectRoute;
use App\Model\Cluster;
use App\Model\ClusterWebGateway;
use App\Model\GatewayVhost;
use App\Model\TlsCertificate;
use App\Services\Docker\SwarmApiClient;
use App\Services\Docker\SwarmContainerExecService;
use GuzzleHttp\Client;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class SwarmGatewayCertificateDeployer
{
    private const SECRET_PREFIX = 'galaxy-web-tls-';
    private const CONFIG_PREFIX = 'galaxy-web-tls-config-';

    public function __construct(
        private SwarmApiClient $docker,
        private TlsCertificateService $certificates,
        private SwarmGatewayMutationLock $gatewayLock,
        private SwarmContainerExecService $containerExec
    ) {}

    public function applyToServiceSpec(
        Client $client,
        SwarmApiClient $api,
        ClusterWebGateway $gateway,
        array $spec
    ): array {
        $container = (array) ($spec['TaskTemplate']['ContainerSpec'] ?? []);
        $container['Secrets'] = array_values(array_filter(
            (array) ($container['Secrets'] ?? []),
            static fn (array $ref): bool => ! str_starts_with((string) ($ref['SecretName'] ?? ''), self::SECRET_PREFIX)
        ));
        $container['Configs'] = array_values(array_filter(
            (array) ($container['Configs'] ?? []),
            static fn (array $ref): bool =>
                (string) ($ref['File']['Name'] ?? '') !== '/dynamic/tls.yml'
                && ! str_starts_with((string) ($ref['ConfigName'] ?? ''), self::CONFIG_PREFIX)
        ));
        $ids = array_values(array_unique(array_merge(
            ProjectRoute::where('cluster_id', (int) $gateway->cluster_id)->where('enabled', 1)
                ->where('tls_enabled', 1)->where('certificate_id', '>', 0)
                ->distinct()->pluck('certificate_id')->map(static fn ($id): int => (int) $id)->all(),
            GatewayVhost::where('cluster_id', (int) $gateway->cluster_id)->where('enabled', 1)
                ->where('tls_enabled', 1)->where('certificate_id', '>', 0)
                ->distinct()->pluck('certificate_id')->map(static fn ($id): int => (int) $id)->all()
        )));
        if ($ids === []) {
            $spec['TaskTemplate']['ContainerSpec'] = $container;
            return ['spec' => $spec, 'tlsYaml' => null];
        }
        $assets = TlsCertificate::where('org_id', (int) $gateway->org_id)->whereIn('id', $ids)
            ->whereNotIn('source', [TlsCertificate::SOURCE_LETS_ENCRYPT])->get()->keyBy('id');
        if ($assets->isEmpty()) {
            $spec['TaskTemplate']['ContainerSpec'] = $container;
            return ['spec' => $spec, 'tlsYaml' => null];
        }

        $dynamic = ['tls' => ['certificates' => []]];
        foreach ($ids as $id) {
            /** @var TlsCertificate|null $certificate */
            $certificate = $assets->get($id);
            if ($certificate === null || $certificate->status !== TlsCertificate::STATUS_ACTIVE) {
                continue;
            }
            $pair = $this->certificates->decryptedKeyPair($certificate);
            $version = substr((string) $certificate->fingerprint_sha256, 0, 16);
            $base = self::SECRET_PREFIX . $certificate->id . '-' . $version;
            $certName = $base . '-crt';
            $keyName = $base . '-key';
            $certId = $this->ensureSecret($client, $api, $certName, $pair['certificate_pem'], $gateway);
            $keyId = $this->ensureSecret($client, $api, $keyName, $pair['private_key_pem'], $gateway);
            $certPath = '/run/secrets/tls-' . $certificate->id . '.crt';
            $keyPath = '/run/secrets/tls-' . $certificate->id . '.key';
            $container['Secrets'][] = $this->secretReference($certId, $certName, $certPath);
            $container['Secrets'][] = $this->secretReference($keyId, $keyName, $keyPath);
            $dynamic['tls']['certificates'][] = ['certFile' => $certPath, 'keyFile' => $keyPath];
        }
        if ($dynamic['tls']['certificates'] !== []) {
            $tlsYaml = Yaml::dump($dynamic, 5, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
            $version = substr(hash('sha256', $tlsYaml), 0, 16);
            $configName = self::CONFIG_PREFIX . $gateway->cluster_id . '-' . $version;
            $configId = $this->ensureConfig($client, $api, $configName, $tlsYaml, $gateway);
            $container['Configs'][] = [
                'ConfigID' => $configId,
                'ConfigName' => $configName,
                'File' => ['Name' => '/dynamic/tls.yml', 'UID' => '0', 'GID' => '0', 'Mode' => 292],
            ];
        }
        $spec['TaskTemplate']['ContainerSpec'] = $container;
        // TLS configuration is an immutable Docker Config. Returning null keeps
        // callers from copying a task-local file that would disappear on the
        // next Swarm rolling update.
        return ['spec' => $spec, 'tlsYaml' => null];
    }

    public function syncGateway(Cluster $cluster): void
    {
        $this->gatewayLock->synchronized(
            (int) $cluster->org_id,
            (int) $cluster->id,
            fn () => $this->syncGatewayUnlocked($cluster),
            30,
            300
        );
    }

    private function syncGatewayUnlocked(Cluster $cluster): void
    {
        /** @var ClusterWebGateway|null $gateway */
        $gateway = ClusterWebGateway::where('org_id', (int) $cluster->org_id)
            ->where('cluster_id', (int) $cluster->id)->where('service_id', '<>', '')->first();
        if ($gateway === null) {
            return;
        }
        $this->docker->withCluster($cluster, function (Client $client, SwarmApiClient $api) use ($gateway): void {
            $service = $api->request($client, 'GET', '/services/' . rawurlencode((string) $gateway->service_id));
            $result = $this->applyToServiceSpec($client, $api, $gateway, (array) ($service['Spec'] ?? []));
            $spec = $result['spec'];
            $spec['TaskTemplate']['ForceUpdate'] = (int) ($service['Spec']['TaskTemplate']['ForceUpdate'] ?? 0) + 1;
            $api->request($client, 'POST', '/services/' . rawurlencode((string) $gateway->service_id) . '/update', [
                'query' => ['version' => (int) ($service['Version']['Index'] ?? 0), 'registryAuthFrom' => 'spec'],
                'json' => $spec,
            ]);
            if ($result['tlsYaml'] !== null) {
                $containerIds = $this->containerExec->waitForRunningContainerIds(
                    $client,
                    $api,
                    (string) $gateway->service_id,
                    60,
                    (int) $spec['TaskTemplate']['ForceUpdate']
                );
                $tar = $this->containerExec->buildTar('tls.yml', $result['tlsYaml']);
                foreach ($containerIds as $containerId) {
                    $api->putArchive($client, $containerId, '/dynamic/', $tar);
                }
            }
            $gc = $this->cleanupObsoleteResources($client, $api, $gateway, $spec);
            $configuration = (array) $gateway->configuration;
            $configuration['tls_gc'] = $gc;
            $gateway->configuration = $configuration;
            $gateway->synced_at = time();
            $gateway->updated_at = time();
            $gateway->save();
        }, 60);
    }

    /**
     * Delete only obsolete TLS secrets that are owned by this gateway and are
     * no longer referenced by any Swarm Service in the cluster.
     */
    public function cleanupObsoleteResources(
        Client $client,
        SwarmApiClient $api,
        ClusterWebGateway $gateway,
        array $activeGatewaySpec = []
    ): array {
        $references = ['secret' => [], 'config' => []];
        $services = $api->request($client, 'GET', '/services');
        foreach ($services as $service) {
            $this->collectReferences((array) ($service['Spec'] ?? []), $references);
        }
        if ($activeGatewaySpec !== []) {
            // Protect the just-submitted spec even if a remote Agent returns a
            // briefly stale service list immediately after the update.
            $this->collectReferences($activeGatewaySpec, $references);
        }

        $result = [
            'checked_at' => time(),
            'deleted_secrets' => 0,
            'deleted_configs' => 0,
            'skipped_referenced' => 0,
            'errors' => [],
        ];

        $items = $api->request($client, 'GET', '/secrets');
        foreach ($items as $item) {
            $id = (string) ($item['ID'] ?? '');
            $name = (string) ($item['Spec']['Name'] ?? '');
            $labels = (array) ($item['Spec']['Labels'] ?? []);
            if ($id === '' || ! str_starts_with($name, self::SECRET_PREFIX)
                || ! $this->ownedByGateway($labels, $gateway)) {
                continue;
            }
            if (isset($references['secret'][$id])) {
                ++$result['skipped_referenced'];
                continue;
            }
            try {
                $api->request($client, 'DELETE', '/secrets/' . rawurlencode($id));
                ++$result['deleted_secrets'];
            } catch (Throwable $e) {
                $result['errors'][] = [
                    'kind' => 'secret',
                    'id' => $id,
                    'name' => $name,
                    'error' => mb_substr($e->getMessage(), 0, 500),
                ];
            }
        }

        // Docker Configs are also used by the file provider and may still be
        // referenced by PreviousSpec or rollback tasks. This class only owns
        // TLS Secrets, so Config lifecycle is deliberately left to its creator.

        $result['errors'] = array_slice($result['errors'], 0, 20);
        return $result;
    }

    private function ensureSecret(
        Client $client,
        SwarmApiClient $api,
        string $name,
        string $data,
        ClusterWebGateway $gateway
    ): string {
        $items = $api->request($client, 'GET', '/secrets', ['query' => [
            'filters' => json_encode(['name' => [$name]], JSON_THROW_ON_ERROR),
        ]]);
        foreach ($items as $item) {
            if (($item['Spec']['Name'] ?? '') === $name) {
                return (string) ($item['ID'] ?? '');
            }
        }
        $created = $api->request($client, 'POST', '/secrets/create', ['json' => [
            'Name' => $name, 'Data' => base64_encode($data), 'Labels' => $this->labels($gateway),
        ]]);
        $id = (string) ($created['ID'] ?? '');
        if ($id === '') {
            throw new AppException(502, 'Docker API 未返回 TLS Secret ID');
        }
        return $id;
    }

    private function ensureConfig(
        Client $client,
        SwarmApiClient $api,
        string $name,
        string $data,
        ClusterWebGateway $gateway
    ): string {
        $items = $api->request($client, 'GET', '/configs', ['query' => [
            'filters' => json_encode(['name' => [$name]], JSON_THROW_ON_ERROR),
        ]]);
        foreach ($items as $item) {
            if (($item['Spec']['Name'] ?? '') === $name) {
                return (string) ($item['ID'] ?? '');
            }
        }
        $created = $api->request($client, 'POST', '/configs/create', ['json' => [
            'Name' => $name,
            'Data' => base64_encode($data),
            'Labels' => $this->labels($gateway),
        ]]);
        $id = (string) ($created['ID'] ?? '');
        if ($id === '') {
            throw new AppException(502, 'Docker API 未返回 TLS Config ID');
        }
        return $id;
    }

    private function secretReference(string $id, string $name, string $path): array
    {
        return [
            'SecretID' => $id,
            'SecretName' => $name,
            'File' => ['Name' => $path, 'UID' => '0', 'GID' => '0', 'Mode' => 256],
        ];
    }

    private function labels(ClusterWebGateway $gateway): array
    {
        return [
            'com.codegalaxy.component' => 'web-gateway-tls',
            'com.codegalaxy.cluster-id' => (string) $gateway->cluster_id,
        ];
    }

    private function collectReferences(array $spec, array &$references): void
    {
        $container = (array) ($spec['TaskTemplate']['ContainerSpec'] ?? []);
        foreach ((array) ($container['Secrets'] ?? []) as $reference) {
            $id = (string) ($reference['SecretID'] ?? '');
            if ($id !== '') {
                $references['secret'][$id] = true;
            }
        }
        foreach ((array) ($container['Configs'] ?? []) as $reference) {
            $id = (string) ($reference['ConfigID'] ?? '');
            if ($id !== '') {
                $references['config'][$id] = true;
            }
        }
    }

    private function ownedByGateway(array $labels, ClusterWebGateway $gateway): bool
    {
        return ($labels['com.codegalaxy.component'] ?? '') === 'web-gateway-tls'
            && ($labels['com.codegalaxy.cluster-id'] ?? '') === (string) $gateway->cluster_id;
    }
}
