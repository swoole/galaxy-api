<?php

namespace App\Services\Gateway\CertificateProvider;

use App\Exception\AppException;
use App\Model\CloudAccount;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Ssl\V20191205\Models\DescribeCertificatesRequest;
use TencentCloud\Ssl\V20191205\Models\DownloadCertificateRequest;
use TencentCloud\Ssl\V20191205\SslClient;
use Throwable;
use ZipArchive;

class TencentCloudCertificateProvider implements CertificateCloudProvider
{
    public function name(): string
    {
        return CloudAccount::PROVIDER_TENCENT_CLOUD;
    }

    public function certificates(
        CloudAccount $account,
        string $accessKeyId,
        string $secret,
        ?string $keyword = null
    ): array
    {
        try {
            $request = new DescribeCertificatesRequest();
            $request->Limit = 100;
            $request->Offset = 0;
            $request->CertificateType = 'SVR';
            if ($keyword !== null && trim($keyword) !== '') {
                $request->SearchKey = trim($keyword);
            }
            $response = $this->client($account, $accessKeyId, $secret)->DescribeCertificates($request);
            $result = [];
            foreach ((array) ($response->Certificates ?? []) as $certificate) {
                $result[] = [
                    'ref' => (string) ($certificate->CertificateId ?? ''),
                    'title' => (string) ($certificate->Alias ?? $certificate->Domain ?? ''),
                    'domain' => (string) ($certificate->Domain ?? ''),
                    'sans' => array_values((array) ($certificate->SubjectAltName ?? $certificate->CertSANs ?? [])),
                    'issuer' => (string) ($certificate->ProductZhName ?? $certificate->From ?? ''),
                    'status' => (int) ($certificate->Status ?? -1),
                    'valid_from' => (string) ($certificate->CertBeginTime ?? ''),
                    'valid_to' => (string) ($certificate->CertEndTime ?? ''),
                ];
            }
            return $result;
        } catch (Throwable $e) {
            throw new AppException(502, '腾讯云证书 API 请求失败：' . $e->getMessage(), [], $e);
        }
    }

    public function download(
        CloudAccount $account,
        string $accessKeyId,
        string $secret,
        string $certificateRef
    ): array
    {
        if ($certificateRef === '' || strlen($certificateRef) > 255) {
            throw new AppException(422, '腾讯云 Certificate ID 无效');
        }
        try {
            $request = new DownloadCertificateRequest();
            $request->CertificateId = $certificateRef;
            $response = $this->client($account, $accessKeyId, $secret)->DownloadCertificate($request);
            $archive = base64_decode((string) ($response->Content ?? ''), true);
            if ($archive === false || $archive === '') {
                throw new AppException(502, '腾讯云返回的证书 ZIP 内容无效');
            }
            [$certificate, $key] = $this->extractNginxKeyPair($archive);
            return [
                'certificate_pem' => $certificate,
                'private_key_pem' => $key,
                'metadata' => ['cloud_request_id' => (string) ($response->RequestId ?? '')],
            ];
        } catch (AppException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new AppException(502, '下载腾讯云证书失败：' . $e->getMessage(), [], $e);
        }
    }

    private function client(CloudAccount $account, string $accessKeyId, string $secret): SslClient
    {
        $credential = new Credential($accessKeyId, $secret);
        $http = new HttpProfile();
        $http->setEndpoint('ssl.tencentcloudapi.com');
        $http->setReqTimeout(30);
        $profile = new ClientProfile();
        $profile->setHttpProfile($http);
        // API routing region, not an attribute of the reusable cloud account.
        return new SslClient($credential, 'ap-guangzhou', $profile);
    }

    private function extractNginxKeyPair(string $archive): array
    {
        $path = tempnam(sys_get_temp_dir(), 'galaxy-tencent-cert-');
        if ($path === false || file_put_contents($path, $archive) === false) {
            throw new AppException(500, '无法创建腾讯云证书临时 ZIP');
        }
        $zip = new ZipArchive();
        $opened = false;
        try {
            if ($zip->open($path) !== true) {
                throw new AppException(502, '腾讯云证书 ZIP 无法打开');
            }
            $opened = true;
            $certificate = '';
            $fallbackCertificate = '';
            $key = '';
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = (string) $zip->getNameIndex($i);
                $contents = (string) $zip->getFromIndex($i);
                $lower = strtolower($name);
                if ($key === '' && str_ends_with($lower, '.key') && str_contains($contents, 'PRIVATE KEY')) {
                    $key = $contents;
                }
                if (str_contains($contents, 'BEGIN CERTIFICATE')) {
                    if (str_contains($lower, 'nginx') && ! str_contains($lower, 'root')
                        && (str_contains($lower, 'bundle') || str_ends_with($lower, '.crt'))) {
                        $certificate = $contents;
                    } elseif ($fallbackCertificate === '') {
                        $fallbackCertificate = $contents;
                    }
                }
            }
            $certificate = $certificate ?: $fallbackCertificate;
            if ($certificate === '' || $key === '') {
                throw new AppException(422, '腾讯云证书包中找不到 Nginx 证书链或未加密私钥');
            }
            return [$certificate, $key];
        } finally {
            if ($opened) {
                $zip->close();
            }
            @unlink($path);
        }
    }
}
