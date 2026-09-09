<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Controller;

use App\Constants\ErrorCode;
use App\Exception\AppException;
use App\Model\Org;
use App\Model\OrgMember;
use App\Model\GroupMember;
use App\Services\OrgService;
use App\Services\GroupService;
use App\Services\PermissionService;
use App\Support\Functions;
use Hyperf\Di\Annotation\Inject;

class GroupMemberController extends AbstractController
{
    private const PAGE = 1;

    private const PAGE_SIZE = 20;

    /**
     * @Inject
     * @var OrgService
     */
    #[Inject]
    private $orgService;

    /**
     * @Inject
     * @var GroupService
     */
    #[Inject]
    private $groupService;

    #[Inject]
    protected PermissionService $permissionService;

    /**
     * @Inject
     */
    #[Inject]
    protected GroupMember $groupMember;

    /**
     * 成员列表.
     */
    public function list()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $params = $this->validate([
            'role' => 'nullable|integer',
            'keyword' => 'nullable|string',
            'page' => 'nullable|integer|min:1',
            'pagesize' => 'nullable|integer|min:1|max:100',
        ]);
        $params = Functions::arrNull2default($params, [
            'role' => null,
            'keyword' => null,
            'page' => 1,
            'pagesize' => 20,
        ]);
        $uid = Functions::getLoginUser()->getId();

        $data = $this->groupMember->list(
            $uid,
            $orgId,
            $groupId,
            $params['role'],
            $params['keyword'],
            $params['page'],
            $params['pagesize']
        );
        
        return $this->success($data);
    }

    /**
     * 新增项目组成员.
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function createMember()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $param = $this->validate([
            'uid' => 'integer|required',
            'role' => 'integer|required',
        ], [
            'uid.required' => '用户id uid 必传',
            'role.required' => '角色 role 必传',
        ]);

        $role = request()->input('role', '');
        //查看角色是否存在
        $this->groupService->existRole($role);
        //获取用户
        $user = Functions::getLoginUser();
        // 被添加用户是否已经是项目组成员
        $existingGroupMember = GroupMember::where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('uid', $param['uid'])
            ->first();
        if ($existingGroupMember) {
            throw new AppException(
                ErrorCode::GROUP_CHECK_MEMBER,
                ErrorCode::getMessage(ErrorCode::GROUP_CHECK_MEMBER)
            );
        }
        //被添加用户是否为组织成员
        $orgMemberExist = OrgMember::where('org_id', $orgId)->where('uid', $param['uid'])->first();
        if (! $orgMemberExist) {
            throw new AppException(
                ErrorCode::GROUP_CHECK_ORG_MEMBER,
                ErrorCode::getMessage(ErrorCode::GROUP_CHECK_ORG_MEMBER)
            );
        }
        // 添加项目组成员
        $res = GroupMember::create([
            'org_id' => $orgId,
            'uid' => $param['uid'],
            'group_id' => $groupId,
            'role' => $role,
            'join_at' => time(),
        ]);
        if (! $res) {
            throw new AppException(
                ErrorCode::GROUP_CREATE_MEMBER,
                ErrorCode::getMessage(ErrorCode::GROUP_CREATE_MEMBER)
            );
        }

        // Permission roles are cached; invalidate the newly-added user's group role
        // so the member can access projects immediately.
        $this->permissionService->deleteCacheGroup((int) $param['uid'], (int) $orgId, (int) $groupId);

        //返回数据
        return $this->success();
    }

    /**
     * 更新项目组成员角色.
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function updateMember()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $param = $this->validate([
            'uid' => 'integer|required',
            'role' => 'integer|required',
        ], [
            'uid.required' => '用户id uid 必传',
            'role.required' => '角色 role 必传',
        ]);

        $role = request()->input('role', '');
        // 查看角色是否存在
        $this->groupService->existRole($role);
        // 修改的用户是否为项目组成员
        $existingGroupMember = $this->groupService->existingGroupMember($orgId, $groupId, $param['uid']);
        $res = $existingGroupMember->update([
            'role' => $role,
        ]);
        if ($res === false) {
            throw new AppException(
                ErrorCode::GROUP_UPDATE_MEMBER,
                ErrorCode::getMessage(ErrorCode::GROUP_UPDATE_MEMBER)
            );
        }
        $this->permissionService->deleteCacheGroup((int) $param['uid'], (int) $orgId, (int) $groupId);

        //返回数据
        return $this->success();
    }

    /**
     * 批量更新项目组成员角色.
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function batchUpdateMember()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $param = $this->validate([
            'uids' => 'array|required',
            'role' => 'integer|required',
        ], [
            'uids.required' => '用户id uids 必传',
            'role.required' => '角色 role 必传',
        ]);

        $role = request()->input('role', '');
        //查看角色是否存在
        $this->groupService->existRole($role);
        //获取用户
        $user = Functions::getLoginUser();
        //过滤提交的uids
        $uids = GroupMember::where([
            'org_id' => $orgId,
            'group_id' => $groupId,
        ])->whereIn('uid', $param['uids'])->pluck('uid')->toArray();
        if ($uids) {
            // 更新项目组成员角色
            $res = GroupMember::whereIn('uid', $uids)
                ->update([
                    'role' => $role,
                ]);
            if ($res === false) {
                throw new AppException(
                    ErrorCode::GROUP_UPDATE_MEMBER,
                    ErrorCode::getMessage(ErrorCode::GROUP_UPDATE_MEMBER)
                );
            }
            foreach ($uids as $uid) {
                $this->permissionService->deleteCacheGroup((int) $uid, (int) $orgId, (int) $groupId);
            }
        }
        //返回数据
        return $this->success();
    }

    /**
     * 移除项目组成员.
     * @throws \Exception
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function deleteMember()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $params = $this->validate([
            'uid' => 'integer|required',
        ], [
            'uid.required' => '用户id uid 必传',
        ]);

        $this->groupMember->deleteMember(
            $params['uid'],
            $orgId,
            $groupId,
            $this->isOrgManager($orgId)
        );
        $this->permissionService->deleteCacheGroup((int) $params['uid'], (int) $orgId, (int) $groupId);

        return $this->success();
    }

    /**
     * 批量移除项目组成员.
     */
    public function batchDeleteMember()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $params = $this->validate([
            'uids' => 'array|required',
        ], [
            'uids.required' => '用户id uids 必传',
        ]);

        $this->groupMember->batchDeleteMember(
            $params['uids'],
            $orgId,
            $groupId,
            $this->isOrgManager($orgId)
        );
        foreach ($params['uids'] as $uid) {
            $this->permissionService->deleteCacheGroup((int) $uid, (int) $orgId, (int) $groupId);
        }

        return $this->success();
    }

    /**
     * 只有组织管理员可以越过项目组负责人的移除限制。
     */
    private function isOrgManager(int $orgId): bool
    {
        $uid = Functions::getLoginUser()->getId();

        return (int) OrgMember::where('org_id', $orgId)
            ->where('uid', $uid)
            ->value('role') === OrgMember::ROLE_MANAGER;
    }

    public function searchfromorg()
    {
        $orgId = Functions::getContextValue('org_id');
        $role = request()->input('role', '');
        $keyword = request()->input('keyword', '');
        // 获取组织成员列表
        $list = $this->groupService->searchOrgMember($orgId, $role, $keyword);
        //返回数据
        return $this->success(['members' => $list]);
    }
}
