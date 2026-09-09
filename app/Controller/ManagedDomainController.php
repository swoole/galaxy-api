<?php

namespace App\Controller;

use App\Services\ManagedDomainService;
use App\Support\Functions;

final class ManagedDomainController extends AbstractController
{
    public function __construct(private ManagedDomainService $domains) {}

    public function list() { $p = Functions::arrNull2default($this->validate(['keyword' => 'nullable|string|max:255', 'page' => 'nullable|integer|min:1', 'pagesize' => 'nullable|integer|min:1|max:100']), ['keyword' => null, 'page' => 1, 'pagesize' => 20]); return $this->success($this->domains->list((int) Functions::getContextValue('org_id'), $p['keyword'], (int) $p['page'], (int) $p['pagesize'])); }
    public function create() { $p = $this->validate(['hostname' => 'required|string|max:253', 'allow_subdomains' => 'nullable|boolean', 'remark' => 'nullable|string|max:255']); return $this->success(['domain' => $this->domains->create((int) Functions::getLoginUser()->getId(), (int) Functions::getContextValue('org_id'), $p['hostname'], (bool) ($p['allow_subdomains'] ?? false), $p['remark'] ?? '')]); }
    public function update() { $p = $this->validate(['domain_id' => 'required|integer|min:1', 'hostname' => 'required|string|max:253', 'allow_subdomains' => 'nullable|boolean', 'remark' => 'nullable|string|max:255']); return $this->success(['domain' => $this->domains->update((int) Functions::getContextValue('org_id'), (int) $p['domain_id'], $p['hostname'], (bool) ($p['allow_subdomains'] ?? false), $p['remark'] ?? '')]); }
    public function delete() { $p = $this->validate(['domain_id' => 'required|integer|min:1']); $this->domains->delete((int) Functions::getContextValue('org_id'), (int) $p['domain_id']); return $this->success(); }
    public function options() { return $this->success(['domains' => $this->domains->options((int) Functions::getContextValue('org_id'), (int) Functions::getContextValue('group_id'))]); }
    public function groups() { $p = $this->validate(['domain_id' => 'required|integer|min:1']); return $this->success(['groups' => $this->domains->groups((int) Functions::getContextValue('org_id'), (int) $p['domain_id'])]); }
    public function syncGroups() { $p = $this->validate(['domain_id' => 'required|integer|min:1', 'group_ids' => 'required|array|max:1000', 'group_ids.*' => 'integer|min:1']); return $this->success(['groups' => $this->domains->syncGroups((int) Functions::getLoginUser()->getId(), (int) Functions::getContextValue('org_id'), (int) $p['domain_id'], $p['group_ids'])]); }
}
