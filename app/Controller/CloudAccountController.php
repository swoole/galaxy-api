<?php

namespace App\Controller;

use App\Services\CloudAccountService;
use App\Support\Functions;

final class CloudAccountController extends AbstractController
{
    public function __construct(private CloudAccountService $accounts) {}

    public function list()
    {
        return $this->success(['accounts' => $this->accounts->list((int) Functions::getContextValue('org_id'))]);
    }

    public function create()
    {
        $params = $this->validate([
            'title' => 'required|string|max:120',
            'provider' => 'required|string|in:aliyun,tencent_cloud,aws_s3',
            'access_key_id' => 'required|string|max:255',
            'access_key_secret' => 'required|string|max:4096',
            'app_id' => 'nullable|string|max:64',
        ]);
        return $this->success(['account' => $this->accounts->create(
            (int) Functions::getLoginUser()->getId(), (int) Functions::getContextValue('org_id'), $params
        )]);
    }

    public function delete()
    {
        $params = $this->validate(['cloud_account_id' => 'required|integer|min:1']);
        $this->accounts->delete((int) Functions::getContextValue('org_id'), (int) $params['cloud_account_id']);
        return $this->success();
    }
}
