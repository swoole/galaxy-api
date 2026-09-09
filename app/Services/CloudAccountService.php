<?php

namespace App\Services;

use App\Exception\AppException;
use App\Model\CloudAccount;
use App\Model\ObjectStorageBucket;
use App\Services\Encrypt\CredentialCipher;
use App\Services\PublicCloud\CloudAccountIdentityVerifier;

class CloudAccountService
{
    public function __construct(
        private CredentialCipher $cipher,
        private CloudAccountIdentityVerifier $identityVerifier
    ) {}

    public function list(int $orgId): array
    {
        return CloudAccount::where('org_id', $orgId)->orderBy('provider')->orderBy('title')
            ->get()->map(fn (CloudAccount $account): array => $this->present($account))->all();
    }

    public function create(int $uid, int $orgId, array $input): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        $provider = (string) ($input['provider'] ?? '');
        $accessKeyId = trim((string) ($input['access_key_id'] ?? ''));
        $secret = trim((string) ($input['access_key_secret'] ?? ''));
        if ($title === '' || mb_strlen($title) > 120) {
            throw new AppException(422, '云账号名称不能为空且不能超过 120 个字符');
        }
        if (! in_array($provider, [CloudAccount::PROVIDER_ALIYUN,
            CloudAccount::PROVIDER_TENCENT_CLOUD, CloudAccount::PROVIDER_AWS_S3], true)) {
            throw new AppException(422, '不支持的云厂商');
        }
        if ($accessKeyId === '' || strlen($accessKeyId) > 120 || $secret === '' || strlen($secret) > 4096) {
            throw new AppException(422, 'AccessKey ID 或 Secret 不能为空或长度不合法');
        }
        if (CloudAccount::where('org_id', $orgId)->where('title', $title)->exists()) {
            throw new AppException(409, '云账号名称已经存在');
        }
        $appId = trim((string) ($input['app_id'] ?? ''));
        if ($provider === CloudAccount::PROVIDER_TENCENT_CLOUD && $appId === '') {
            throw new AppException(422, '腾讯云账户必须填写 APPID');
        }
        // STS GetCallerIdentity verifies the key itself and does not couple the
        // reusable account to SSL, object storage, or any other resource API.
        $identity = $this->identityVerifier->verify($provider, $accessKeyId, $secret);
        $now = time();
        /** @var CloudAccount $account */
        $account = CloudAccount::create([
            'org_id' => $orgId, 'title' => $title, 'provider' => $provider,
            'access_key_id' => $this->cipher->encrypt($accessKeyId),
            'secret_ciphertext' => $this->cipher->encrypt($secret),
            'status' => 'ready',
            'last_error' => null, 'last_verified_at' => $now,
            'metadata' => ['app_id' => $appId, 'identity' => $identity],
            'creator' => $uid, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return $this->present($account->fresh());
    }

    public function delete(int $orgId, int $accountId): void
    {
        if (ObjectStorageBucket::where('org_id', $orgId)->where('cloud_account_id', $accountId)->exists()) {
            throw new AppException(409, '云账户正在被对象存储使用，不能删除');
        }
        $this->account($orgId, $accountId)->delete();
    }

    /** Return decrypted credentials only to trusted backend services. */
    public function storageCredentials(int $orgId, int $accountId, string $storageProvider): array
    {
        $account = $this->account($orgId, $accountId);
        $expected = ['cos' => CloudAccount::PROVIDER_TENCENT_CLOUD,
            'oss' => CloudAccount::PROVIDER_ALIYUN,
            's3' => CloudAccount::PROVIDER_AWS_S3][$storageProvider] ?? null;
        if ($expected === null || (string) $account->provider !== $expected) {
            throw new AppException(422, '云账户与对象存储厂商不匹配');
        }
        return $this->credentials($account);
    }

    public function account(int $orgId, int $accountId): CloudAccount
    {
        /** @var CloudAccount|null $account */
        $account = CloudAccount::where('org_id', $orgId)->where('id', $accountId)->first();
        if ($account === null) {
            throw new AppException(404, '云账户不存在');
        }
        return $account;
    }

    public function credentials(CloudAccount $account): array
    {
        $metadata = (array) $account->metadata;
        return ['access_key_id' => $this->accessKeyId($account),
            'access_key_secret' => $this->cipher->decrypt((string) $account->secret_ciphertext),
            'app_id' => (string) ($metadata['app_id'] ?? '')];
    }

    private function present(CloudAccount $account): array
    {
        $accessKey = $this->accessKeyId($account);
        return [
            'id' => (int) $account->id, 'title' => (string) $account->title,
            'provider' => (string) $account->provider,
            'app_id' => (string) (((array) $account->metadata)['app_id'] ?? ''),
            'access_key_hint' => strlen($accessKey) <= 8 ? str_repeat('*', strlen($accessKey))
                : substr($accessKey, 0, 4) . str_repeat('*', max(4, strlen($accessKey) - 8)) . substr($accessKey, -4),
            'status' => (string) $account->status, 'last_error' => $account->last_error,
            'last_verified_at' => (int) $account->last_verified_at,
            'created_at' => (int) $account->created_at, 'updated_at' => (int) $account->updated_at,
        ];
    }

    private function accessKeyId(CloudAccount $account): string
    {
        $stored = (string) $account->getRawOriginal('access_key_id');
        if (str_starts_with($stored, 'swc1:')) {
            return $this->cipher->decrypt($stored);
        }
        // Lazily encrypt an account saved by the brief plaintext implementation before this field was protected.
        if ($stored !== '') {
            $account->access_key_id = $this->cipher->encrypt($stored);
            $account->updated_at = time();
            $account->save();
        }
        return $stored;
    }
}
