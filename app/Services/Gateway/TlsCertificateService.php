<?php

namespace App\Services\Gateway;

use App\Exception\AppException;
use App\Model\ProjectRoute;
use App\Model\ClusterWebGateway;
use App\Model\GatewayVhost;
use App\Model\TlsCertificate;
use App\Services\Encrypt\CredentialCipher;
use App\Support\MySQL;
use Throwable;

class TlsCertificateService
{
    public function __construct(private CredentialCipher $cipher) {}

    public function list(int $orgId, ?string $keyword, int $page, int $pageSize): array
    {
        $query = TlsCertificate::where('org_id', $orgId)->orderByDesc('id');
        if ($keyword !== null && trim($keyword) !== '') {
            $search = '%' . trim($keyword) . '%';
            $query->where(static function ($builder) use ($search): void {
                $builder->where('title', 'like', $search)
                    ->orWhere('common_name', 'like', $search)
                    ->orWhere('issuer', 'like', $search);
            });
        }
        return MySQL::jsonPaginate($query, $page, $pageSize);
    }

    public function profile(int $orgId, int $certificateId): TlsCertificate
    {
        return $this->certificate($orgId, $certificateId);
    }

    public function options(int $orgId, ?string $hostname = null): array
    {
        $items = TlsCertificate::where('org_id', $orgId)
            ->whereIn('status', [TlsCertificate::STATUS_ACTIVE, TlsCertificate::STATUS_PENDING])
            ->orderBy('title')->get();
        return $items->filter(function (TlsCertificate $certificate) use ($hostname): bool {
            return $hostname === null || $hostname === '' || $certificate->source === TlsCertificate::SOURCE_LETS_ENCRYPT
                || $this->coversHostname((array) $certificate->domains, $hostname);
        })->values()->map(static fn (TlsCertificate $certificate): array => [
            'id' => (int) $certificate->id,
            'title' => (string) $certificate->title,
            'source' => (string) $certificate->source,
            'provider' => (string) $certificate->provider,
            'status' => (string) $certificate->status,
            'domains' => (array) $certificate->domains,
            'valid_to' => (int) $certificate->valid_to,
            'deployable' => $certificate->status === TlsCertificate::STATUS_ACTIVE
                && $certificate->certificate_ciphertext !== null
                && $certificate->private_key_ciphertext !== null,
        ])->all();
    }

    public function import(int $uid, int $orgId, array $input): TlsCertificate
    {
        $source = (string) ($input['source'] ?? TlsCertificate::SOURCE_MANUAL);
        if (! in_array($source, [TlsCertificate::SOURCE_MANUAL, TlsCertificate::SOURCE_CLOUD_IMPORT], true)) {
            throw new AppException(422, '导入证书来源无效');
        }
        $provider = $source === TlsCertificate::SOURCE_CLOUD_IMPORT
            ? strtolower(trim((string) ($input['provider'] ?? ''))) : '';
        if ($source === TlsCertificate::SOURCE_CLOUD_IMPORT
            && ! in_array($provider, ['aliyun', 'tencent_cloud', 'other'], true)) {
            throw new AppException(422, '云证书来源仅支持阿里云、腾讯云或其他云厂商');
        }
        return $this->storeKeyPair(
            $uid,
            $orgId,
            trim((string) ($input['title'] ?? '')),
            $source,
            $provider,
            (string) ($input['certificate_pem'] ?? ''),
            (string) ($input['private_key_pem'] ?? ''),
            ['imported_at' => time(), 'provider_ref' => trim((string) ($input['provider_ref'] ?? ''))]
        );
    }

    public function importFromProvider(
        int $uid,
        int $orgId,
        string $title,
        string $provider,
        string $providerRef,
        string $certificatePem,
        string $privateKeyPem,
        array $metadata = []
    ): TlsCertificate {
        return $this->storeKeyPair(
            $uid, $orgId, $title, TlsCertificate::SOURCE_CLOUD_IMPORT, $provider,
            $certificatePem, $privateKeyPem,
            $metadata + ['provider_ref' => $providerRef, 'imported_at' => time()]
        );
    }

    public function createSelfSigned(int $uid, int $orgId, array $input): TlsCertificate
    {
        $title = trim((string) ($input['title'] ?? ''));
        $domains = $this->normalizeDomains((array) ($input['domains'] ?? []));
        $days = max(1, min(3650, (int) ($input['validity_days'] ?? 365)));
        $keyBits = (int) ($input['key_bits'] ?? 2048);
        if (! in_array($keyBits, [2048, 3072, 4096], true)) {
            throw new AppException(422, 'RSA 密钥长度仅支持 2048、3072 或 4096');
        }
        $config = $this->opensslConfig($domains);
        try {
            $key = openssl_pkey_new([
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
                'private_key_bits' => $keyBits,
                'digest_alg' => 'sha256',
                'config' => $config,
            ]);
            if ($key === false) {
                throw new AppException(500, 'OpenSSL 无法生成 RSA 私钥：' . $this->opensslErrors());
            }
            $csr = openssl_csr_new(['commonName' => $domains[0]], $key, [
                'digest_alg' => 'sha256', 'config' => $config, 'req_extensions' => 'v3_req',
            ]);
            if ($csr === false) {
                throw new AppException(500, 'OpenSSL 无法生成 CSR：' . $this->opensslErrors());
            }
            $x509 = openssl_csr_sign($csr, null, $key, $days, [
                'digest_alg' => 'sha256', 'config' => $config, 'x509_extensions' => 'v3_req',
            ]);
            if ($x509 === false || ! openssl_x509_export($x509, $certificatePem)
                || ! openssl_pkey_export($key, $privateKeyPem, null, ['config' => $config])) {
                throw new AppException(500, 'OpenSSL 无法签发自签名证书：' . $this->opensslErrors());
            }
        } finally {
            @unlink($config);
        }
        return $this->storeKeyPair(
            $uid, $orgId, $title, TlsCertificate::SOURCE_SELF_SIGNED, 'openssl',
            $certificatePem, $privateKeyPem,
            ['validity_days' => $days, 'requested_domains' => $domains]
        );
    }

    public function createLetsEncrypt(int $uid, int $orgId, array $input): TlsCertificate
    {
        $title = $this->validateTitle($orgId, trim((string) ($input['title'] ?? '')));
        $domains = $this->normalizeDomains((array) ($input['domains'] ?? []));
        $now = time();
        return TlsCertificate::create([
            'org_id' => $orgId, 'title' => $title, 'source' => TlsCertificate::SOURCE_LETS_ENCRYPT,
            'provider' => 'traefik_acme', 'provider_ref' => '', 'status' => TlsCertificate::STATUS_PENDING,
            'domains' => $domains, 'common_name' => $domains[0], 'issuer' => 'Let’s Encrypt',
            'serial_number' => '', 'fingerprint_sha256' => '', 'signature_algorithm' => '',
            'key_algorithm' => '', 'key_bits' => 0, 'certificate_ciphertext' => null,
            'private_key_ciphertext' => null, 'valid_from' => 0, 'valid_to' => 0,
            'auto_renew' => true, 'renew_before_days' => 30, 'last_renewed_at' => 0,
            'next_renew_at' => 0, 'last_error' => null,
            'metadata' => ['challenge' => 'http-01', 'resolver' => 'letsencrypt'],
            'creator' => $uid, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function delete(int $orgId, int $certificateId): void
    {
        $certificate = $this->certificate($orgId, $certificateId);
        $routeCount = ProjectRoute::where('org_id', $orgId)->where('certificate_id', $certificateId)->count();
        if ($routeCount > 0) {
            throw new AppException(422, sprintf('该证书仍被 %d 条域名路由使用，请先解除绑定', $routeCount));
        }
        $vhostCount = GatewayVhost::where('org_id', $orgId)->where('certificate_id', $certificateId)->count();
        if ($vhostCount > 0) {
            throw new AppException(422, sprintf('该证书仍被 %d 条网关路由规则使用，请先解除绑定', $vhostCount));
        }
        if (ClusterWebGateway::where('org_id', $orgId)
            ->where('workspace_certificate_id', $certificateId)->exists()) {
            throw new AppException(422, '该证书仍被 Workspace 泛域名规则使用，请先解除绑定');
        }
        $certificate->delete();
    }

    public function decryptedKeyPair(TlsCertificate $certificate): array
    {
        if ($certificate->certificate_ciphertext === null || $certificate->private_key_ciphertext === null) {
            throw new AppException(422, '该证书由 ACME 托管，不包含可导出的本地密钥对');
        }
        return [
            'certificate_pem' => $this->cipher->decrypt((string) $certificate->certificate_ciphertext),
            'private_key_pem' => $this->cipher->decrypt((string) $certificate->private_key_ciphertext),
        ];
    }

    public function assertUsableForHostname(int $orgId, int $certificateId, string $hostname): TlsCertificate
    {
        $certificate = $this->certificate($orgId, $certificateId);
        if (! in_array($certificate->status, [TlsCertificate::STATUS_ACTIVE, TlsCertificate::STATUS_PENDING], true)) {
            throw new AppException(422, '所选证书当前不可用：' . $certificate->status);
        }
        if (! $this->coversHostname((array) $certificate->domains, $hostname)) {
            throw new AppException(422, '所选证书不包含域名 ' . $hostname);
        }
        if ($certificate->source !== TlsCertificate::SOURCE_LETS_ENCRYPT
            && ((int) $certificate->valid_from > time() || (int) $certificate->valid_to <= time())) {
            throw new AppException(422, '所选证书尚未生效或已经过期');
        }
        return $certificate;
    }

    public function coversHostname(array $domains, string $hostname): bool
    {
        $hostname = strtolower(rtrim($hostname, '.'));
        foreach ($domains as $domain) {
            $domain = strtolower(rtrim((string) $domain, '.'));
            if ($domain === $hostname) {
                return true;
            }
            if (str_starts_with($domain, '*.') && substr_count($hostname, '.') === substr_count($domain, '.')
                && str_ends_with($hostname, substr($domain, 1))) {
                return true;
            }
        }
        return false;
    }

    private function storeKeyPair(
        int $uid,
        int $orgId,
        string $title,
        string $source,
        string $provider,
        string $certificatePem,
        string $privateKeyPem,
        array $metadata
    ): TlsCertificate {
        $title = $this->validateTitle($orgId, $title);
        $certificatePem = $this->normalizePem($certificatePem);
        $privateKeyPem = $this->normalizePem($privateKeyPem);
        $parsed = $this->parseAndValidate($certificatePem, $privateKeyPem);
        if (TlsCertificate::where('org_id', $orgId)
            ->where('fingerprint_sha256', $parsed['fingerprint_sha256'])->exists()) {
            throw new AppException(409, '该证书已经导入');
        }
        $now = time();
        return TlsCertificate::create([
            'org_id' => $orgId, 'title' => $title, 'source' => $source, 'provider' => $provider,
            'provider_ref' => (string) ($metadata['provider_ref'] ?? ''), 'status' => $parsed['status'],
            'domains' => $parsed['domains'], 'common_name' => $parsed['common_name'],
            'issuer' => $parsed['issuer'], 'serial_number' => $parsed['serial_number'],
            'fingerprint_sha256' => $parsed['fingerprint_sha256'],
            'signature_algorithm' => $parsed['signature_algorithm'],
            'key_algorithm' => $parsed['key_algorithm'], 'key_bits' => $parsed['key_bits'],
            'certificate_ciphertext' => $this->cipher->encrypt($certificatePem),
            'private_key_ciphertext' => $this->cipher->encrypt($privateKeyPem),
            'valid_from' => $parsed['valid_from'], 'valid_to' => $parsed['valid_to'],
            'auto_renew' => false, 'renew_before_days' => 30, 'last_renewed_at' => 0,
            'next_renew_at' => 0, 'last_error' => null, 'metadata' => $metadata,
            'creator' => $uid, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function parseAndValidate(string $certificatePem, string $privateKeyPem): array
    {
        $certificate = @openssl_x509_read($certificatePem);
        if ($certificate === false) {
            throw new AppException(422, 'Certificate 不是有效的 X.509 PEM 证书链');
        }
        $privateKey = @openssl_pkey_get_private($privateKeyPem);
        if ($privateKey === false) {
            throw new AppException(422, 'Private Key 不是有效的未加密 PEM 私钥');
        }
        if (! openssl_x509_check_private_key($certificate, $privateKey)) {
            throw new AppException(422, 'Certificate 与 Private Key 不匹配');
        }
        $info = openssl_x509_parse($certificate, false);
        if (! is_array($info)) {
            throw new AppException(422, '无法解析 X.509 证书');
        }
        $domains = [];
        $san = (string) ($info['extensions']['subjectAltName'] ?? '');
        foreach (explode(',', $san) as $entry) {
            $entry = trim($entry);
            if (str_starts_with($entry, 'DNS:')) {
                $domains[] = strtolower(substr($entry, 4));
            }
        }
        $commonName = strtolower((string) ($info['subject']['CN'] ?? ''));
        if ($commonName !== '' && ! in_array($commonName, $domains, true)) {
            $domains[] = $commonName;
        }
        if ($domains === []) {
            throw new AppException(422, '证书没有 DNS Subject Alternative Name 或 Common Name');
        }
        $validFrom = (int) ($info['validFrom_time_t'] ?? 0);
        $validTo = (int) ($info['validTo_time_t'] ?? 0);
        $status = $validFrom > time() ? TlsCertificate::STATUS_NOT_YET_VALID
            : ($validTo <= time() ? TlsCertificate::STATUS_EXPIRED : TlsCertificate::STATUS_ACTIVE);
        $keyInfo = openssl_pkey_get_details($privateKey) ?: [];
        return [
            'domains' => array_values(array_unique($domains)), 'common_name' => $commonName ?: $domains[0],
            'issuer' => $this->distinguishedName((array) ($info['issuer'] ?? [])),
            'serial_number' => (string) ($info['serialNumberHex'] ?? $info['serialNumber'] ?? ''),
            'fingerprint_sha256' => strtolower(str_replace(':', '', (string) openssl_x509_fingerprint($certificate, 'sha256'))),
            'signature_algorithm' => (string) ($info['signatureTypeSN'] ?? ''),
            'key_algorithm' => (($keyInfo['type'] ?? null) === OPENSSL_KEYTYPE_RSA) ? 'RSA' : 'other',
            'key_bits' => (int) ($keyInfo['bits'] ?? 0), 'valid_from' => $validFrom,
            'valid_to' => $validTo, 'status' => $status,
        ];
    }

    private function validateTitle(int $orgId, string $title): string
    {
        if ($title === '' || mb_strlen($title) > 120) {
            throw new AppException(422, '证书名称不能为空且不能超过 120 个字符');
        }
        if (TlsCertificate::where('org_id', $orgId)->where('title', $title)->exists()) {
            throw new AppException(409, '证书名称已经存在');
        }
        return $title;
    }

    private function normalizeDomains(array $domains): array
    {
        $result = [];
        foreach ($domains as $domain) {
            $domain = strtolower(rtrim(trim((string) $domain), '.'));
            $check = str_starts_with($domain, '*.') ? substr($domain, 2) : $domain;
            if (! preg_match('/^(?=.{3,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $check)) {
                throw new AppException(422, '证书域名格式不合法：' . $domain);
            }
            $result[] = (str_starts_with($domain, '*.') ? '*.' : '') . $check;
        }
        $result = array_values(array_unique($result));
        if ($result === [] || count($result) > 100) {
            throw new AppException(422, '证书必须包含 1-100 个域名');
        }
        return $result;
    }

    private function opensslConfig(array $domains): string
    {
        $path = tempnam(sys_get_temp_dir(), 'galaxy-openssl-');
        if ($path === false) {
            throw new AppException(500, '无法创建 OpenSSL 临时配置');
        }
        $altNames = [];
        foreach ($domains as $index => $domain) {
            $altNames[] = 'DNS.' . ($index + 1) . ' = ' . $domain;
        }
        $contents = "[ req ]\nprompt = no\ndistinguished_name = dn\nreq_extensions = v3_req\n"
            . "[ dn ]\nCN = " . $domains[0] . "\n[v3_req]\nbasicConstraints = critical,CA:FALSE\n"
            . "keyUsage = critical,digitalSignature,keyEncipherment\nextendedKeyUsage = serverAuth\n"
            . "subjectAltName = @alt_names\n[alt_names]\n" . implode("\n", $altNames) . "\n";
        if (file_put_contents($path, $contents) === false) {
            @unlink($path);
            throw new AppException(500, '无法写入 OpenSSL 临时配置');
        }
        chmod($path, 0600);
        return $path;
    }

    private function normalizePem(string $pem): string
    {
        $pem = trim(str_replace(["\r\n", "\r"], "\n", $pem));
        if ($pem === '' || strlen($pem) > 1024 * 1024) {
            throw new AppException(422, 'PEM 内容为空或超过 1 MiB');
        }
        return $pem . "\n";
    }

    private function distinguishedName(array $parts): string
    {
        $result = [];
        foreach (['CN', 'O', 'OU', 'C'] as $key) {
            if (isset($parts[$key])) {
                $result[] = $key . '=' . $parts[$key];
            }
        }
        return implode(', ', $result) ?: json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function certificate(int $orgId, int $certificateId): TlsCertificate
    {
        /** @var TlsCertificate|null $certificate */
        $certificate = TlsCertificate::where('org_id', $orgId)->where('id', $certificateId)->first();
        if ($certificate === null) {
            throw new AppException(404, 'SSL 证书不存在');
        }
        return $certificate;
    }

    private function opensslErrors(): string
    {
        $errors = [];
        while (($error = openssl_error_string()) !== false) {
            $errors[] = $error;
        }
        return implode('; ', $errors) ?: 'unknown error';
    }
}
