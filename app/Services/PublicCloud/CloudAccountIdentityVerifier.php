<?php

namespace App\Services\PublicCloud;

use App\Exception\AppException;
use App\Model\CloudAccount;
use Aws\Sts\StsClient;
use GuzzleHttp\Client;
use Throwable;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Sts\V20180813\Models\GetCallerIdentityRequest;
use TencentCloud\Sts\V20180813\StsClient as TencentStsClient;

/** Validates reusable cloud credentials without accessing a concrete resource. */
class CloudAccountIdentityVerifier
{
    public function verify(string $provider, string $accessKeyId, string $secret): array
    {
        try {
            return match ($provider) {
                CloudAccount::PROVIDER_ALIYUN => $this->verifyAliyun($accessKeyId, $secret),
                CloudAccount::PROVIDER_TENCENT_CLOUD => $this->verifyTencent($accessKeyId, $secret),
                CloudAccount::PROVIDER_AWS_S3 => $this->verifyAws($accessKeyId, $secret),
                default => throw new AppException(422, '不支持的云厂商'),
            };
        } catch (AppException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new AppException(422, '云账户凭证验证失败：' . $this->safeError($e), [], $e);
        }
    }

    private function verifyAws(string $accessKeyId, string $secret): array
    {
        $result = (new StsClient([
            'version' => '2011-06-15',
            'region' => 'us-east-1',
            'credentials' => ['key' => $accessKeyId, 'secret' => $secret],
            'http' => ['connect_timeout' => 5, 'timeout' => 10],
        ]))->getCallerIdentity();

        return array_filter([
            'account_id' => (string) ($result['Account'] ?? ''),
            'principal_id' => (string) ($result['UserId'] ?? ''),
            'arn' => (string) ($result['Arn'] ?? ''),
        ], static fn (string $value): bool => $value !== '');
    }

    private function verifyTencent(string $accessKeyId, string $secret): array
    {
        $httpProfile = new HttpProfile();
        $httpProfile->setEndpoint('sts.tencentcloudapi.com');
        $httpProfile->setReqTimeout(10);
        $clientProfile = new ClientProfile();
        $clientProfile->setHttpProfile($httpProfile);
        // STS requires a request region even though caller identity itself is
        // global; this fixed API routing value is not cloud-account metadata.
        $client = new TencentStsClient(new Credential($accessKeyId, $secret), 'ap-guangzhou', $clientProfile);
        $result = $client->GetCallerIdentity(new GetCallerIdentityRequest());

        return array_filter([
            'account_id' => (string) $result->AccountId,
            'principal_id' => (string) $result->PrincipalId,
            'user_id' => (string) $result->UserId,
            'type' => (string) $result->Type,
            'arn' => (string) $result->Arn,
        ], static fn (string $value): bool => $value !== '');
    }

    private function verifyAliyun(string $accessKeyId, string $secret): array
    {
        $parameters = [
            'AccessKeyId' => $accessKeyId,
            'Action' => 'GetCallerIdentity',
            'Format' => 'JSON',
            'SignatureMethod' => 'HMAC-SHA1',
            'SignatureNonce' => bin2hex(random_bytes(16)),
            'SignatureVersion' => '1.0',
            'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'Version' => '2015-04-01',
        ];
        ksort($parameters);
        $canonicalQuery = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        $stringToSign = 'GET&%2F&' . rawurlencode($canonicalQuery);
        $parameters['Signature'] = base64_encode(hash_hmac('sha1', $stringToSign, $secret . '&', true));

        $response = (new Client(['connect_timeout' => 5, 'timeout' => 10]))->get(
            'https://sts.aliyuncs.com/',
            ['query' => $parameters, 'http_errors' => false]
        );
        $payload = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() >= 400 || ! is_array($payload)) {
            $message = is_array($payload) ? (string) ($payload['Message'] ?? $payload['Code'] ?? '') : '';
            throw new AppException(422, '云账户凭证验证失败' . ($message === '' ? '' : '：' . $message));
        }

        return array_filter([
            'account_id' => (string) ($payload['AccountId'] ?? ''),
            'principal_id' => (string) ($payload['PrincipalId'] ?? ''),
            'user_id' => (string) ($payload['UserId'] ?? ''),
            'identity_type' => (string) ($payload['IdentityType'] ?? ''),
            'arn' => (string) ($payload['Arn'] ?? ''),
        ], static fn (string $value): bool => $value !== '');
    }

    private function safeError(Throwable $error): string
    {
        $message = trim($error->getMessage());
        return mb_substr($message === '' ? '云厂商未返回错误详情' : $message, 0, 500);
    }
}
