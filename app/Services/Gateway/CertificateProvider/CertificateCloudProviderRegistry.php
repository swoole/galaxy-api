<?php

namespace App\Services\Gateway\CertificateProvider;

use App\Exception\AppException;

class CertificateCloudProviderRegistry
{
    public function __construct(
        private AliyunCertificateProvider $aliyun,
        private TencentCloudCertificateProvider $tencentCloud
    ) {}

    public function get(string $provider): CertificateCloudProvider
    {
        return match ($provider) {
            'aliyun' => $this->aliyun,
            'tencent_cloud' => $this->tencentCloud,
            default => throw new AppException(422, '不支持的证书云厂商：' . $provider),
        };
    }
}
