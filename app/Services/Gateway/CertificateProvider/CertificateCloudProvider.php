<?php

namespace App\Services\Gateway\CertificateProvider;

use App\Model\CloudAccount;

interface CertificateCloudProvider
{
    public function name(): string;

    /** @return array<int, array<string, mixed>> */
    public function certificates(
        CloudAccount $account,
        string $accessKeyId,
        string $secret,
        ?string $keyword = null
    ): array;

    /** @return array{certificate_pem:string,private_key_pem:string,metadata:array} */
    public function download(
        CloudAccount $account,
        string $accessKeyId,
        string $secret,
        string $certificateRef
    ): array;
}
