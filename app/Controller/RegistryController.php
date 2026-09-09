<?php

namespace App\Controller;

use App\Model\Registry;
use App\Model\User;
use App\Services\RegistryService;
use App\Services\RegistryGroupGrantService;
use App\Services\ContainerImageMappingService;
use App\Support\Functions;
use Hyperf\Di\Annotation\Inject;

class RegistryController extends AbstractController
{
    /**
     * @Inject
     */
    #[Inject]
    protected Registry $registry;

    /**
     * @Inject
     */
    #[Inject]
    protected User $user;

    #[Inject]
    protected RegistryService $registryService;

    #[Inject]
    protected RegistryGroupGrantService $registryGroupGrants;

    #[Inject]
    protected ContainerImageMappingService $imageMappings;

    /**
     * 列表.
     */
    public function list()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate([
            'keyword' => 'nullable|string',
            'page' => 'nullable|integer|min:1',
            'pagesize' => 'nullable|integer|min:20|max:100',
        ]);
        $params = Functions::arrNull2default($params, [
            'keyword' => null,
            'page' => 1,
            'pagesize' => 20,
        ]);
        $uid = Functions::getLoginUser()->getId();

        $data = $this->registry->list(
            $orgId,
            $params['keyword'],
            $params['page'],
            $params['pagesize']
        );

        return $this->success($data);
    }

    /**
     * 创建.
     */
    public function create()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate([
            'address' => 'nullable|string|max:200',
            'username' => 'required|string|max:200',
            'password' => 'required|string|max:200',
            'remark' => 'nullable|string|max:200',
            'namespace' => 'nullable|string|max:200',
            'proto' => 'nullable|integer|in:0,1',
        ]);
        $params = Functions::arrNull2default($params, [
            'address' => null,
            'remark' => null,
            'namespace' => null,
            'proto' => Registry::PROTO_HTTPS,
        ]);
        $uid = Functions::getLoginUser()->getId();

        $registry = $this->registry->createRegistry(
            $uid,
            $orgId,
            $params['address'] ?: '',
            $params['username'],
            $params['password'],
            $params['remark'],
            $params['namespace'],
            $params['proto']
        );

        return $this->success([
            'registry_id' => $registry['id'],
        ]);
    }

    /**
     * 更新.
     */
    public function update()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate([
            'registry_id' => 'required|string',
            'address' => 'nullable|string|max:200',
            'username' => 'required|string|max:200',
            'password' => 'nullable|string|max:200',
            'remark' => 'nullable|string|max:200',
            'namespace' => 'nullable|string|max:200',
            'proto' => 'nullable|integer|in:0,1',
        ]);
        $params = Functions::arrNull2default($params, [
            'address' => null,
            'password' => null,
            'remark' => null,
            'namespace' => null,
            'proto' => Registry::PROTO_HTTPS,
        ]);
        $uid = Functions::getLoginUser()->getId();

        $registry = $this->registry->updateRegistry(
            $uid,
            $orgId,
            $params['registry_id'],
            $params['address'] ?: '',
            $params['username'],
            $params['password'],
            $params['remark'],
            $params['namespace'],
            $params['proto']
        );

        return $this->success([
            'registry_id' => $registry['id'],
        ]);
    }

    /**
     * 删除.
     */
    public function delete()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate([
            'registry_id' => 'required|string',
            'confirm_token' => 'required|string',
        ]);
        $uid = Functions::getLoginUser()->getId();

        // 身份安全验证
        $this->user->idConfirm($uid, $params['confirm_token']);

        $this->registry->deleteRegistry(
            $uid,
            $orgId,
            $params['registry_id']
        );

        return $this->success();
    }

    /**
     * 查看密码.
     */
    public function showPassword()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate([
            'registry_id' => 'required|string',
            'confirm_token' => 'required|string',
        ]);
        $uid = Functions::getLoginUser()->getId();

        // 身份安全验证
        $this->user->idConfirm($uid, $params['confirm_token']);

        $password = $this->registry->showPassword(
            $uid,
            $orgId,
            $params['registry_id']
        );

        return $this->success([
            'password' => $password,
        ]);
    }

    /**
     * 仓库目录（Repository 列表）.
     */
    public function catalog()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate([
            'registry_id' => 'required|string',
            'search' => 'nullable|string|max:200',
        ]);
        $uid = Functions::getLoginUser()->getId();

        $registry = $this->registry->resolveRegistry($uid, $orgId, $params['registry_id']);
        $repos = $this->registryService->catalog($registry, $params['search'] ?? '');

        return $this->success(['repos' => $repos]);
    }

    /**
     * 镜像标签列表.
     */
    public function tagsList()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate([
            'registry_id' => 'required|string',
            'repository' => 'required|string|max:500',
        ]);
        $uid = Functions::getLoginUser()->getId();

        $registry = $this->registry->resolveRegistry($uid, $orgId, $params['registry_id']);
        $tagList = $this->registryService->tagsList($registry, $params['repository']);

        return $this->success(['tags' => $tagList]);
    }

    /**
     * 删除镜像.
     */
    public function deleteImage()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate([
            'registry_id' => 'required|string',
            'repository' => 'required|string|max:500',
            'tag' => 'required|string|max:200',
        ]);
        $uid = Functions::getLoginUser()->getId();

        $registry = $this->registry->resolveRegistry($uid, $orgId, $params['registry_id']);
        $this->registryService->deleteImage($registry, $params['repository'], $params['tag']);

        return $this->success();
    }

    /**
     * 设置推送镜像的帐号.
     */
    public function setPush()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate([
            'registry_id' => 'required|string',
        ]);
        $uid = Functions::getLoginUser()->getId();

        $this->registry->setPush($uid, $orgId, $params['registry_id']);

        return $this->success();
    }

    public function groups()
    {
        $params = $this->validate(['registry_id' => 'required|string']);
        $registryId = Functions::decodeID($params['registry_id'], true, true);
        return $this->success([
            'groups' => $this->registryGroupGrants->groups(
                (int) Functions::getContextValue('org_id'),
                $registryId
            ),
        ]);
    }

    public function syncGroups()
    {
        $params = $this->validate([
            'registry_id' => 'required|string',
            'grants' => 'required|array|max:1000',
            'grants.*.group_id' => 'required|integer|min:1',
            'grants.*.namespace' => 'required|string|max:255',
        ]);
        $registryId = Functions::decodeID($params['registry_id'], true, true);
        return $this->success([
            'groups' => $this->registryGroupGrants->sync(
                (int) Functions::getLoginUser()->getId(),
                (int) Functions::getContextValue('org_id'),
                $registryId,
                $params['grants']
            ),
        ]);
    }

    public function imageMappings()
    {
        return $this->success([
            'items' => $this->imageMappings->list((int) Functions::getContextValue('org_id')),
        ]);
    }

    public function saveImageMapping()
    {
        $params = Functions::arrNull2default($this->validate([
            'id' => 'nullable|integer|min:1',
            'cluster_id' => 'nullable|integer|min:0',
            'source_image' => 'required|string|max:1024',
            'target_image' => 'required|string|max:1024',
            'remark' => 'nullable|string|max:255',
            'enabled' => 'nullable|boolean',
        ]), [
            'id' => 0,
            'cluster_id' => 0,
            'remark' => '',
            'enabled' => true,
        ]);
        return $this->success([
            'item' => $this->imageMappings->save(
                (int) Functions::getLoginUser()->getId(),
                (int) Functions::getContextValue('org_id'),
                (int) $params['id'],
                (int) $params['cluster_id'],
                (string) $params['source_image'],
                (string) $params['target_image'],
                (string) $params['remark'],
                (bool) $params['enabled']
            ),
        ]);
    }

    public function deleteImageMapping()
    {
        $params = $this->validate(['id' => 'required|integer|min:1']);
        $this->imageMappings->delete(
            (int) Functions::getContextValue('org_id'),
            (int) $params['id']
        );
        return $this->success();
    }
}
