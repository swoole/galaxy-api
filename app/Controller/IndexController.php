<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Controller;

use App\Exception\AppException;
use App\Support\Functions;

class IndexController extends AbstractController
{
    public function index()
    {
        $user = $this->request->input('user', 'Hyperf');
        $method = $this->request->getMethod();

        return [
            'method' => $method,
            'message' => "Hello {$user}.",
        ];
    }

    public function apiSucc()
    {
        $ret = [
            'user' => [
                'id' => 123,
                'nickname' => 'John',
            ],
        ];

        return $this->success($ret);
    }

    public function apiFail()
    {
        $ret = [
            'errors' => [
                'username' => 'username is required.',
            ],
        ];

        return $this->failed('invalid failed', $ret, 422);
    }

    public function apiExcep()
    {
        throw new AppException(101018, 'test exception', [
            'orgId' => 12,
            'projectId' => 18,
        ]);
    }

    public function apiValid()
    {
        $orgId = Functions::getContextValue('org_id', false, null);
        $param = $this->validate([
            'search' => 'nullable|string',
            'page' => 'nullable|integer|min:1',
            'pagesize' => 'nullable|integer|min:1|max:100',
            'order' => 'nullable',
        ]);
        $param = Functions::arrNull2default($param, [
            'search' => null,
            'page' => 1,
            'pageSize' => 20,
            'order' => [],
        ]);

        return $this->success($param);
    }

    public function apiValidFail()
    {
        $orgId = Functions::getContextValue('org_id');
        $param = $this->validate([
            'search' => 'nullable|string',
            'page' => 'nullable|integer|min:1',
            'pagesize' => 'nullable|integer|min:1|max:100',
            'order' => 'nullable',
        ]);
        $param = Functions::arrNull2default($param, [
            'search' => null,
            'page' => 1,
            'pageSize' => 20,
            'order' => [],
        ]);

        return $this->success($param);
    }
}
