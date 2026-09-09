<?php

declare (strict_types=1);
namespace App\Model;

use App\Services\ObjectStorageService;
use App\Support\Functions;
use Hyperf\HttpMessage\Upload\UploadedFile;
use Hyperf\Di\Annotation\Inject;

/**
 */
class Upload
{
    #[Inject]
    protected ObjectStorageService $storageService;

    public function image($uid, UploadedFile $file)
    {
        $orgId = (int) Functions::getContextValue('org_id');
        $filename = sha1(uniqid()) . '.' . $file->getExtension();
        $filepath = '/uploads/images/' . $filename;

        $resolved = $this->storageService->resolveForOrg($orgId);
        $stream = fopen($file->getRealPath(), 'r+');
        $resolved['filesystem']->writeStream($filepath, $stream);
        fclose($stream);

        return $resolved['base_url'] . $filepath;
    }
}
