<?php

namespace App\Services\Gateway;

use App\Model\ProjectRoute;
use App\Model\GatewayVhost;
use App\Model\Cluster;
use App\Model\ClusterWebGateway;
use App\Model\TlsCertificate;
use App\Services\Docker\SwarmContainerExecService;
use App\Services\Encrypt\CredentialCipher;

class TraefikAcmeCertificateReconciler
{
    public function __construct(
        private SwarmContainerExecService $exec,
        private CredentialCipher $cipher
    ) {}

    public function reconcile(Cluster $cluster, ClusterWebGateway $gateway): void
    {
        if (! $gateway->acme_enabled || $gateway->service_id === '') {
            return;
        }
        $ids = ProjectRoute::where('cluster_id', (int) $cluster->id)->where('enabled', 1)
            ->where('tls_enabled', 1)->where('certificate_id', '>', 0)
            ->distinct()->pluck('certificate_id')->all();
        $ids = array_values(array_unique(array_merge($ids, GatewayVhost::where(
            'cluster_id',
            (int) $cluster->id
        )->where('enabled', 1)->where('tls_enabled', 1)->where('certificate_id', '>', 0)
            ->distinct()->pluck('certificate_id')->all())));
        if ($ids === []) {
            return;
        }
        $assets = TlsCertificate::where('org_id', (int) $cluster->org_id)->whereIn('id', $ids)
            ->where('source', TlsCertificate::SOURCE_LETS_ENCRYPT)->get();
        if ($assets->isEmpty()) {
            return;
        }
        $configuration = (array) $gateway->configuration;
        // Pending assets need fast feedback immediately after the first ACME
        // issuance. Once all assets are active, keep the slower interval because
        // reading a task-local file through a remote Agent is comparatively
        // expensive.
        $interval = $assets->contains(
            static fn (TlsCertificate $asset): bool =>
                $asset->status === TlsCertificate::STATUS_PENDING
                || trim((string) $asset->issuer) === ''
                || trim((string) $asset->key_algorithm) === ''
                || $asset->certificate_ciphertext === null
                || $asset->private_key_ciphertext === null
        ) ? 10 : 300;
        if ((int) ($configuration['acme_checked_at'] ?? 0) > time() - $interval) {
            return;
        }
        // Record the attempt first so repeated overview refreshes cannot open a
        // new remote file-read session for every HTTP request.
        $configuration['acme_checked_at'] = time();
        $gateway->configuration = $configuration;
        $gateway->updated_at = time();
        $gateway->save();
        try {
            $contents = $this->exec->readServiceFile(
                $cluster,
                (string) $gateway->service_id,
                '/letsencrypt/acme.json'
            );
        } catch (\Throwable) {
            return;
        }
        if (trim($contents) === '') {
            return;
        }
        $document = json_decode($contents, true);
        if (! is_array($document)) {
            return;
        }
        $issued = [];
        foreach ($document as $resolver) {
            foreach ((array) ($resolver['Certificates'] ?? $resolver['certificates'] ?? []) as $item) {
                $domain = (array) ($item['domain'] ?? $item['Domain'] ?? []);
                $domains = array_values(array_unique(array_filter(array_merge(
                    [(string) ($domain['main'] ?? $domain['Main'] ?? '')],
                    (array) ($domain['sans'] ?? $domain['SANs'] ?? [])
                ))));
                $pem = base64_decode((string) ($item['certificate'] ?? $item['Certificate'] ?? ''), true);
                $privateKey = base64_decode((string) ($item['key'] ?? $item['Key'] ?? ''), true);
                if ($domains !== [] && is_string($pem) && $pem !== ''
                    && is_string($privateKey) && $privateKey !== '') {
                    $issued[] = [
                        'domains' => array_map('strtolower', $domains),
                        'certificate' => $pem,
                        'private_key' => $privateKey,
                    ];
                }
            }
        }
        foreach ($assets as $asset) {
            $expected = array_map('strtolower', (array) $asset->domains);
            sort($expected, SORT_STRING);
            foreach ($issued as $item) {
                $actual = $item['domains'];
                sort($actual, SORT_STRING);
                if ($expected !== $actual) {
                    continue;
                }
                $parsed = openssl_x509_parse($item['certificate'], false);
                if (! is_array($parsed)) {
                    continue;
                }
                $asset->issuer = $this->distinguishedName((array) ($parsed['issuer'] ?? []));
                $asset->serial_number = (string) ($parsed['serialNumberHex'] ?? $parsed['serialNumber'] ?? '');
                $asset->fingerprint_sha256 = strtolower(str_replace(':', '', (string) openssl_x509_fingerprint(
                    $item['certificate'], 'sha256'
                )));
                $asset->signature_algorithm = (string) ($parsed['signatureTypeSN'] ?? '');
                $publicKey = openssl_pkey_get_public($item['certificate']);
                $keyDetails = $publicKey !== false ? openssl_pkey_get_details($publicKey) : false;
                if (is_array($keyDetails)) {
                    $asset->key_algorithm = match ((int) ($keyDetails['type'] ?? -1)) {
                        OPENSSL_KEYTYPE_RSA => 'RSA',
                        OPENSSL_KEYTYPE_DSA => 'DSA',
                        OPENSSL_KEYTYPE_DH => 'DH',
                        OPENSSL_KEYTYPE_EC => 'EC',
                        default => 'other',
                    };
                    $asset->key_bits = (int) ($keyDetails['bits'] ?? 0);
                }
                $asset->valid_from = (int) ($parsed['validFrom_time_t'] ?? 0);
                $asset->valid_to = (int) ($parsed['validTo_time_t'] ?? 0);
                $asset->status = $asset->valid_to > time()
                    ? TlsCertificate::STATUS_ACTIVE : TlsCertificate::STATUS_EXPIRED;
                $asset->provider_ref = $asset->fingerprint_sha256;
                $asset->certificate_ciphertext = $this->cipher->encrypt($item['certificate']);
                $asset->private_key_ciphertext = $this->cipher->encrypt($item['private_key']);
                $asset->last_renewed_at = time();
                $asset->next_renew_at = max(0, $asset->valid_to - (int) $asset->renew_before_days * 86400);
                $asset->last_error = null;
                $asset->updated_at = time();
                $asset->save();
                break;
            }
        }
    }

    private function distinguishedName(array $parts): string
    {
        $result = [];
        foreach ([
            'CN' => ['CN', 'commonName'],
            'O' => ['O', 'organizationName'],
            'OU' => ['OU', 'organizationalUnitName'],
            'C' => ['C', 'countryName'],
        ] as $label => $keys) {
            foreach ($keys as $key) {
                if (! isset($parts[$key])) {
                    continue;
                }
                $result[] = $label . '=' . (is_array($parts[$key])
                    ? implode(', ', $parts[$key])
                    : (string) $parts[$key]);
                break;
            }
        }
        return implode(', ', $result);
    }
}
