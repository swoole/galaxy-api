<?php

namespace App\Services\Gateway\CertificateProvider;

use AlibabaCloud\SDK\Cas\V20200407\Cas;
use AlibabaCloud\SDK\Cas\V20200407\Models\GetUserCertificateDetailRequest;
use AlibabaCloud\SDK\Cas\V20200407\Models\ListUserCertificateOrderRequest;
use App\Exception\AppException;
use App\Model\CloudAccount;
use Darabonba\OpenApi\Models\Config;
use Throwable;

class AliyunCertificateProvider implements CertificateCloudProvider
{
    public function name(): string
    {
        return CloudAccount::PROVIDER_ALIYUN;
    }

    public function certificates(
        CloudAccount $account,
        string $accessKeyId,
        string $secret,
        ?string $keyword = null
    ): array
    {
        try {
            $request = new ListUserCertificateOrderRequest([
                'currentPage' => 1, 'showSize' => 100,
                'keyword' => $keyword !== null && trim($keyword) !== '' ? trim($keyword) : null,
            ]);
            $response = $this->client($account, $accessKeyId, $secret)->listUserCertificateOrder($request);
            $result = [];
            foreach ((array) ($response->body->certificateOrderList ?? []) as $certificate) {
                $result[] = [
                    'ref' => (string) ($certificate->certificateId ?? ''),
                    'title' => (string) ($certificate->name ?? $certificate->commonName ?? ''),
                    'domain' => (string) ($certificate->domain ?? $certificate->commonName ?? ''),
                    'sans' => array_values(array_filter(array_map('trim', explode(',', (string) ($certificate->sans ?? ''))))),
                    'issuer' => (string) ($certificate->issuer ?? ''),
                    'status' => (string) ($certificate->status ?? ''),
                    'valid_from' => (int) ($certificate->certStartTime ?? 0),
                    'valid_to' => (int) ($certificate->certEndTime ?? 0),
                ];
            }
            return $result;
        } catch (Throwable $e) {
            throw new AppException(502, '阿里云证书 API 请求失败：' . $e->getMessage(), [], $e);
        }
    }

    public function download(
        CloudAccount $account,
        string $accessKeyId,
        string $secret,
        string $certificateRef
    ): array
    {
        if (! ctype_digit($certificateRef) || (int) $certificateRef <= 0) {
            throw new AppException(422, '阿里云 Certificate ID 必须是正整数');
        }
        try {
            $request = new GetUserCertificateDetailRequest(['certId' => (int) $certificateRef]);
            $response = $this->client($account, $accessKeyId, $secret)->getUserCertificateDetail($request);
            $body = $response->body;
            $certificate = trim((string) ($body->cert ?? ''));
            $key = trim((string) ($body->key ?? ''));
            if ($certificate === '' || $key === '') {
                throw new AppException(422, '阿里云证书尚未签发、不可下载或不包含私钥');
            }
            return [
                'certificate_pem' => $certificate,
                'private_key_pem' => $key,
                'metadata' => ['cloud_name' => (string) ($body->name ?? ''), 'cloud_request_id' => (string) ($body->requestId ?? '')],
            ];
        } catch (AppException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new AppException(502, '下载阿里云证书失败：' . $e->getMessage(), [], $e);
        }
    }

    private function client(CloudAccount $account, string $accessKeyId, string $secret): Cas
    {
        return new Cas(new Config([
            'accessKeyId' => $accessKeyId,
            'accessKeySecret' => $secret,
            // API routing region, not an attribute of the reusable cloud account.
            'regionId' => 'cn-hangzhou',
            'endpoint' => 'cas.aliyuncs.com',
            'connectTimeout' => 5000,
            'readTimeout' => 30000,
        ]));
    }
}
