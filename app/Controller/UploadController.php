<?php

namespace App\Controller;

use App\Model\Upload;
use App\Support\Functions;
use Hyperf\Di\Annotation\Inject;

class UploadController extends AbstractController
{

    /**
     * @Inject
     */
    #[Inject]
    protected Upload $upload;

    public function image()
    {
        $file = $this->request->file('file');
        $params = $this->validateAll([
            'file' => 'required|file|image',
        ], [], [
            'file' => $file,
        ]);
        $uid = Functions::getLoginUser()->getId();

        $url = $this->upload->image($uid, $file);

        return $this->success([
            'url' => $url,
        ]);
    }
}
