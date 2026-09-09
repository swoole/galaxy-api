<?php

namespace App\Controller;

use App\Model\User;
use App\Services\GroupSshKeyService;
use App\Support\Functions;
use App\Support\Git;

class GroupSshKeyController extends AbstractController
{
    public function __construct(
        private GroupSshKeyService $keys,
        private User $user
    ) {}

    public function profile()
    {
        return $this->success([
            'sshkey' => $this->keys->show(
                (int) Functions::getContextValue('org_id'),
                (int) Functions::getContextValue('group_id')
            ),
        ]);
    }

    public function reset()
    {
        $params = $this->validate([
            'confirm_token' => 'required|string',
            'algo' => 'nullable|string|in:' . implode(',', array_keys(Git::$sshKeyAlgos)),
        ]);
        $uid = Functions::getLoginUser()->getId();
        $this->user->idConfirm($uid, $params['confirm_token']);
        return $this->success([
            'sshkey' => $this->keys->reset(
                $uid,
                (int) Functions::getContextValue('org_id'),
                (int) Functions::getContextValue('group_id'),
                (string) ($params['algo'] ?? 'ed25519')
            ),
        ]);
    }
}
