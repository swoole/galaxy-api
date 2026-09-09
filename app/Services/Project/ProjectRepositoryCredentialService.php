<?php

namespace App\Services\Project;

use App\Model\ProjectRepository;
use App\Model\GitAuth;
use App\Model\UserSshKey;
use App\Services\Encrypt\CredentialCipher;
use App\Services\GroupSshKeyService;

class ProjectRepositoryCredentialService
{
    public function __construct(
        private CredentialCipher $cipher,
        private GroupSshKeyService $groupSshKeys
    ) {}

    public function runtimeConfig(int $uid, int $repositoryId): array
    {
        /** @var ProjectRepository|null $repository */
        $repository = ProjectRepository::where('id', $repositoryId)->first();
        if ($repository === null || trim((string) $repository->clone_url) === '') {
            return [];
        }
        return $this->runtimeConfigForSnapshot(
            $uid,
            (int) $repository->org_id,
            (int) $repository->group_id,
            trim((string) $repository->clone_url),
            (int) $repository->provider
        );
    }

    /**
     * A Workspace repository mapping belongs to a project. SSH repositories
     * therefore use the same group key that has already passed repository
     * probing/build checks; HTTP provider tokens remain personal.
     */
    public function runtimeConfigForWorkspace(int $uid, int $repositoryId): array
    {
        /** @var ProjectRepository|null $repository */
        $repository = ProjectRepository::where('id', $repositoryId)->first();
        if ($repository === null || trim((string) $repository->clone_url) === '') {
            return [];
        }
        return $this->resolve(
            $uid,
            (int) $repository->org_id,
            (int) $repository->group_id,
            trim((string) $repository->clone_url),
            (int) $repository->provider,
            false
        );
    }

    public function runtimeConfigForSnapshot(
        int $uid,
        int $orgId,
        int $groupId,
        string $cloneUrl,
        int $provider
    ): array
    {
        return $this->resolve($uid, $orgId, $groupId, $cloneUrl, $provider, false);
    }

    private function resolve(
        int $uid,
        int $orgId,
        int $groupId,
        string $cloneUrl,
        int $provider,
        bool $personalSsh
    ): array
    {
        $cloneUrl = trim($cloneUrl);
        if ($cloneUrl === '') {
            return [];
        }
        try {
            [$domain, , $origin] = GitAuth::parseRepositoryUrl($cloneUrl);
        } catch (\Throwable) {
            $domain = '';
            $origin = '';
        }
        $ssh = $this->isSshUrl($cloneUrl);
        /** @var GitAuth|null $auth */
        $auth = $ssh || $domain === '' ? null : GitAuth::where('uid', $uid)
            ->where('vendor', $provider)
            ->whereIn('domain', array_values(array_unique([$origin, $domain])))
            ->first();
        $token = $auth === null ? '' : $this->cipher->decrypt((string) $auth->token);
        $sshKey = [];
        if ($ssh && $personalSsh) {
            /** @var UserSshKey|null $personalKey */
            $personalKey = UserSshKey::where('uid', $uid)->first();
            $privateKey = trim((string) ($personalKey?->privatekey ?? ''));
            if ($privateKey !== '') {
                $privateKey .= "\n";
                $sshKey = [
                    'id' => (int) $personalKey->id,
                    'privatekey' => $privateKey,
                    'generate_at' => (int) $personalKey->generate_at,
                ];
            }
        } elseif ($ssh) {
            $sshKey = $this->groupSshKeys->privateKey($orgId, $groupId);
        }
        $privateKey = (string) ($sshKey['privatekey'] ?? '');
        $credentialAvailable = $ssh ? $privateKey !== '' : $token !== '';
        return [
            'clone_url' => $cloneUrl,
            'domain' => $domain,
            'provider' => $provider,
            'transport' => $ssh ? 'ssh' : 'http',
            'username' => $this->username($provider),
            'token' => $token,
            'ssh_private_key' => $privateKey,
            'credential_available' => $credentialAvailable,
            // A one-way revision marker lets long-lived Workspace services
            // reconcile immutable Docker Secrets without persisting plaintext.
            'credential_revision' => $ssh
                ? ($sshKey === [] || $privateKey === ''
                    ? 'none'
                    : hash('sha256', ($personalSsh ? 'user-ssh:' : 'group-ssh:')
                        . (string) ($sshKey['id'] ?? 0) . ':'
                        . (string) ($sshKey['generate_at'] ?? 0) . ':' . $privateKey))
                : ($auth === null
                    ? 'none'
                    : hash('sha256', 'http:' . (string) $auth->id . ':' . (string) $auth->token)),
        ];
    }

    public function isSshUrl(string $url): bool
    {
        return str_starts_with(strtolower(trim($url)), 'ssh://')
            || preg_match('#^[^@/\s]+@[^:/\s]+:.+$#', trim($url)) === 1;
    }

    private function username(int $vendor): string
    {
        return match ($vendor) {
            GitAuth::VENDOR_GITHUB => 'x-access-token',
            GitAuth::VENDOR_GITLAB => 'oauth2',
            default => 'oauth2',
        };
    }
}
