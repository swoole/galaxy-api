<?php

namespace App\Controller;

use App\Exception\AppException;
use App\Services\Docker\SwarmSshRelayService;

final class InternalSshRelayController extends AbstractController
{
    public function __construct(private SwarmSshRelayService $relay) {}

    public function authenticate()
    {
        $this->assertRelayToken();
        $params = $this->validate(['fingerprint' => 'required|string|max:128']);
        return $this->success(['uid' => $this->relay->authenticate($params['fingerprint'])]);
    }

    public function terminalSession()
    {
        $this->assertRelayToken();
        $params = $this->validate([
            'uid' => 'required|integer|min:1',
            'org_id' => 'required|integer|min:1',
            'cluster_id' => 'required|integer|min:1',
            'node_id' => 'required|string|max:128',
            'container_id' => 'required|string|max:64',
            'project_id' => 'nullable|integer|min:1',
        ]);
        return $this->success($this->relay->issueTicket(
            (int) $params['uid'],
            (int) $params['org_id'],
            (int) $params['cluster_id'],
            (string) $params['node_id'],
            (string) $params['container_id'],
            (int) ($params['project_id'] ?? 0)
        ));
    }

    private function assertRelayToken(): void
    {
        $expected = (string) config('ssh_relay.internal_token', '');
        $authorization = trim((string) $this->request->header('Authorization', ''));
        $provided = str_starts_with($authorization, 'Bearer ') ? substr($authorization, 7) : '';
        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            throw new AppException(401, 'SSH relay authentication failed');
        }
    }
}
