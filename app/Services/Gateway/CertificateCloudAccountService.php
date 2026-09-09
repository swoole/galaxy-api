<?php

namespace App\Services\Gateway;

use App\Model\TlsCertificate;
use App\Services\CloudAccountService;
use App\Services\Gateway\CertificateProvider\CertificateCloudProviderRegistry;

final class CertificateCloudAccountService
{
    public function __construct(
        private CloudAccountService $accounts,
        private CertificateCloudProviderRegistry $providers,
        private TlsCertificateService $certificates
    ) {}

    public function remoteCertificates(int $orgId, int $cloudAccountId, ?string $keyword): array
    {
        $account = $this->accounts->account($orgId, $cloudAccountId);
        $credentials = $this->accounts->credentials($account);
        try {
            $items = $this->providers->get((string) $account->provider)->certificates(
                $account, $credentials['access_key_id'], $credentials['access_key_secret'], $keyword
            );
            $account->status = 'ready';
            $account->last_error = null;
            $account->last_verified_at = time();
            $account->updated_at = time();
            $account->save();
            return $items;
        } catch (\Throwable $e) {
            $account->status = 'error';
            $account->last_error = mb_substr($e->getMessage(), 0, 2000);
            $account->updated_at = time();
            $account->save();
            throw $e;
        }
    }

    public function importCertificate(
        int $uid, int $orgId, int $cloudAccountId, string $certificateRef, string $title
    ): TlsCertificate {
        $account = $this->accounts->account($orgId, $cloudAccountId);
        $credentials = $this->accounts->credentials($account);
        $pair = $this->providers->get((string) $account->provider)->download(
            $account, $credentials['access_key_id'], $credentials['access_key_secret'], trim($certificateRef)
        );
        return $this->certificates->importFromProvider(
            $uid, $orgId, trim($title), (string) $account->provider, trim($certificateRef),
            $pair['certificate_pem'], $pair['private_key_pem'],
            (array) ($pair['metadata'] ?? []) + ['cloud_account_id' => (int) $account->id]
        );
    }
}
