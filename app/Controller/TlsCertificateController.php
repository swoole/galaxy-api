<?php

namespace App\Controller;

use App\Services\Gateway\TlsCertificateService;
use App\Services\Gateway\CertificateCloudAccountService;
use App\Services\Gateway\AcmeBackupService;
use App\Support\Functions;

class TlsCertificateController extends AbstractController
{
    public function __construct(
        private TlsCertificateService $certificates,
        private CertificateCloudAccountService $cloudAccounts,
        private AcmeBackupService $acmeBackups
    ) {}

    public function list()
    {
        [$orgId] = $this->scope();
        $params = Functions::arrNull2default($this->validate([
            'keyword' => 'nullable|string|max:255',
            'page' => 'nullable|integer|min:1',
            'pagesize' => 'nullable|integer|min:1|max:100',
        ]), ['keyword' => null, 'page' => 1, 'pagesize' => 20]);
        return $this->success($this->certificates->list(
            $orgId, $params['keyword'], (int) $params['page'], (int) $params['pagesize']
        ));
    }

    public function profile()
    {
        [$orgId] = $this->scope();
        $params = $this->validate(['certificate_id' => 'required|integer|min:1']);
        return $this->success(['certificate' => $this->certificates->profile($orgId, (int) $params['certificate_id'])]);
    }

    public function options()
    {
        [$orgId] = $this->scope();
        $params = $this->validate(['hostname' => 'nullable|string|max:253']);
        return $this->success(['certificates' => $this->certificates->options($orgId, $params['hostname'] ?? null)]);
    }

    public function import()
    {
        [$orgId] = $this->scope();
        $params = $this->validate([
            'title' => 'required|string|max:120',
            'source' => 'required|string|in:manual,cloud_import',
            'provider' => 'nullable|string|max:32',
            'provider_ref' => 'nullable|string|max:255',
            'certificate_pem' => 'required|string|max:1048576',
            'private_key_pem' => 'required|string|max:1048576',
        ]);
        return $this->success(['certificate' => $this->certificates->import(
            (int) Functions::getLoginUser()->getId(), $orgId, $params
        )]);
    }

    public function selfSigned()
    {
        [$orgId] = $this->scope();
        $params = $this->validate([
            'title' => 'required|string|max:120',
            'domains' => 'required|array|min:1|max:100',
            'domains.*' => 'required|string|max:253|distinct',
            'validity_days' => 'required|integer|min:1|max:3650',
            'key_bits' => 'required|integer|in:2048,3072,4096',
        ]);
        return $this->success(['certificate' => $this->certificates->createSelfSigned(
            (int) Functions::getLoginUser()->getId(), $orgId, $params
        )]);
    }

    public function letsEncrypt()
    {
        [$orgId] = $this->scope();
        $params = $this->validate([
            'title' => 'required|string|max:120',
            'domains' => 'required|array|min:1|max:100',
            'domains.*' => 'required|string|max:253|distinct',
        ]);
        return $this->success(['certificate' => $this->certificates->createLetsEncrypt(
            (int) Functions::getLoginUser()->getId(), $orgId, $params
        )]);
    }

    public function delete()
    {
        [$orgId] = $this->scope();
        $params = $this->validate(['certificate_id' => 'required|integer|min:1']);
        $this->certificates->delete($orgId, (int) $params['certificate_id']);
        return $this->success();
    }

    public function acmeBackup()
    {
        [$orgId] = $this->scope();
        return $this->success($this->acmeBackups->profile($orgId));
    }

    public function saveAcmeBackup()
    {
        [$orgId] = $this->scope();
        $params = $this->validate([
            'enabled' => 'required|boolean',
            'bucket_id' => 'required|integer|min:0',
            'directory' => 'required|string|max:512',
            'interval_seconds' => 'required|integer|min:3600|max:604800',
        ]);
        return $this->success($this->acmeBackups->save(
            (int) Functions::getLoginUser()->getId(),
            $orgId,
            $params
        ));
    }

    public function runAcmeBackup()
    {
        [$orgId] = $this->scope();
        return $this->success($this->acmeBackups->runNow($orgId));
    }

    public function cloudCertificates()
    {
        [$orgId] = $this->scope();
        $params = $this->validate([
            'cloud_account_id' => 'required|integer|min:1', 'keyword' => 'nullable|string|max:255',
        ]);
        return $this->success(['certificates' => $this->cloudAccounts->remoteCertificates(
            $orgId, (int) $params['cloud_account_id'], $params['keyword'] ?? null
        )]);
    }

    public function importCloudCertificate()
    {
        [$orgId] = $this->scope();
        $params = $this->validate([
            'cloud_account_id' => 'required|integer|min:1', 'certificate_ref' => 'required|string|max:255',
            'title' => 'required|string|max:120',
        ]);
        return $this->success(['certificate' => $this->cloudAccounts->importCertificate(
            (int) Functions::getLoginUser()->getId(), $orgId, (int) $params['cloud_account_id'],
            $params['certificate_ref'], $params['title']
        )]);
    }

    private function scope(): array
    {
        $orgId = (int) Functions::getContextValue('org_id');
        return [$orgId];
    }
}
