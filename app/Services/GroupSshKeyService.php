<?php

namespace App\Services;

use App\Exception\AppException;
use App\Model\Group;
use App\Model\GroupSshKey;
use App\Services\Encrypt\CredentialCipher;
use App\Support\Functions;
use App\Support\Git;
use Throwable;

class GroupSshKeyService
{
    public function __construct(private CredentialCipher $cipher) {}

    public function show(int $orgId, int $groupId): array
    {
        return $this->publicData($this->getOrCreate($orgId, $groupId));
    }

    public function privateKey(int $orgId, int $groupId): array
    {
        $key = $this->getOrCreate($orgId, $groupId);
        $privateKey = trim($this->cipher->decrypt((string) $key->privatekey_encrypted));
        if ($privateKey !== '') {
            $privateKey .= "\n";
        }
        return [
            'id' => (int) $key->id,
            'privatekey' => $privateKey,
            'generate_at' => (int) $key->generate_at,
        ];
    }

    public function reset(int $uid, int $orgId, int $groupId, string $algo): array
    {
        $this->assertGroup($orgId, $groupId);
        $material = $this->generate($orgId, $groupId, $algo);
        $key = GroupSshKey::where('org_id', $orgId)->where('group_id', $groupId)->first();
        if ($key === null) {
            $key = new GroupSshKey();
            $key->org_id = $orgId;
            $key->group_id = $groupId;
        }
        $key->pubkey = $material['pubkey'];
        $key->privatekey_encrypted = $this->cipher->encrypt($material['privatekey']);
        $key->algo = $algo;
        $key->generate_at = time();
        $key->updated_by = $uid;
        $key->save();
        return $this->publicData($key);
    }

    private function getOrCreate(int $orgId, int $groupId): GroupSshKey
    {
        $this->assertGroup($orgId, $groupId);
        $key = GroupSshKey::where('org_id', $orgId)->where('group_id', $groupId)->first();
        if ($key !== null) {
            return $key;
        }
        $material = $this->generate($orgId, $groupId, 'ed25519');
        try {
            return GroupSshKey::create([
                'org_id' => $orgId,
                'group_id' => $groupId,
                'pubkey' => $material['pubkey'],
                'privatekey_encrypted' => $this->cipher->encrypt($material['privatekey']),
                'algo' => 'ed25519',
                'generate_at' => time(),
                'updated_by' => 0,
            ]);
        } catch (Throwable $e) {
            // Concurrent first reads may both generate a key. The unique
            // group constraint elects one canonical identity.
            $key = GroupSshKey::where('org_id', $orgId)->where('group_id', $groupId)->first();
            if ($key !== null) {
                return $key;
            }
            throw $e;
        }
    }

    private function generate(int $orgId, int $groupId, string $algo): array
    {
        if (! isset(Git::$sshKeyAlgos[$algo])) {
            throw new AppException(422, '不支持的 SSH 密钥算法');
        }
        $directory = BASE_PATH . '/runtime/tmp_ssh/group-' . $groupId . '-' . bin2hex(random_bytes(6));
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new AppException(500, '无法创建项目组 SSH 密钥临时目录');
        }
        $path = $directory . '/id_key';
        try {
            if (! Git::createSshKey('codegalaxy-group-' . $orgId . '-' . $groupId, $path, $algo)) {
                throw new AppException(500, '项目组 SSH 密钥生成失败');
            }
            $privateKey = file_get_contents($path);
            $publicKey = file_get_contents($path . '.pub');
            if ($privateKey === false || $publicKey === false) {
                throw new AppException(500, '项目组 SSH 密钥读取失败');
            }
            return ['privatekey' => $privateKey, 'pubkey' => trim($publicKey)];
        } finally {
            @unlink($path);
            @unlink($path . '.pub');
            @rmdir($directory);
        }
    }

    private function publicData(GroupSshKey $key): array
    {
        return [
            'pubkey' => (string) $key->pubkey,
            'algo' => (string) $key->algo,
            'generate_at' => (int) $key->generate_at,
            'fingerprint' => [
                'sha256' => Functions::calcuSshKeySha256Fingerprint((string) $key->pubkey),
            ],
        ];
    }

    private function assertGroup(int $orgId, int $groupId): void
    {
        if (! Group::where('id', $groupId)->where('org_id', $orgId)->exists()) {
            throw new AppException(404, '项目组不存在');
        }
    }
}
