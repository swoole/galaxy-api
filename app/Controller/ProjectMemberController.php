<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Controller;

use App\Constants\ErrorCode;
use App\Exception\AppException;
use App\Model\ProjectMember;
use App\Model\Org;
use App\Model\GroupMember;
use App\Services\ProjectMemberService;
use App\Services\OrgService;
use App\Services\GroupService;
use App\Services\PermissionService;
use App\Support\Functions;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;

class ProjectMemberController extends AbstractController
{
    #[Inject]
    protected GroupService $groupService;

    #[Inject]
    protected OrgService $orgService;

    #[Inject]
    protected ProjectMemberService $projectMemberService;

    /**
     * @Inject
     */
    #[Inject]
    protected ProjectMember $projectMember;

    #[Inject]
    protected PermissionService $permissionService;

    /**
     * 成员列表.
     */
    public function list()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $projectId = Functions::getContextValue('project_id');
        $params = $this->validate([
            'role' => 'nullable|integer',
            'keyword' => 'nullable|string|max:50',
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

        $data = $this->projectMember->list(
            $uid,
            $orgId,
            $groupId,
            $projectId,
            $params['role'],
            $params['keyword'],
            $params['page'],
            $params['pagesize']
        );

        return $this->success($data);
    }

    /**
     * 添加项目成员.
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function create()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $projectId = Functions::getContextValue('project_id');
        $params = $this->validateAll([
            'uid' => 'required|integer',
            'role' => 'required|integer',
        ]);

        //检验项目成员是否已存在
        $existingProjectMember = ProjectMember::where([
            'org_id' => $orgId,
            'group_id' => $groupId,
            'project_id' => $projectId,
            'uid' => $params['uid'],
        ])->first();
        if ($existingProjectMember) {
            throw new AppException(
                ErrorCode::PROJECT_CHECK_MEMBER,
                ErrorCode::getMessage(ErrorCode::PROJECT_CHECK_MEMBER)
            );
        }

        ProjectMember::insert([
            'org_id' => $orgId,
            'group_id' => $groupId,
            'project_id' => $projectId,
            'uid' => $params['uid'],
            'role' => $params['role'],
            'join_at' => time(), ]);
        $this->permissionService->deleteCacheProject(
            (int) $params['uid'],
            (int) $orgId,
            (int) $groupId,
            (int) $projectId
        );

        return $this->success();
    }

    /**
     * 更新项目成员.
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function update()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $projectId = Functions::getContextValue('project_id');
        $params = $this->validateAll([
            'uid' => 'required|integer',
            'role' => 'required|integer',
        ]);

        $this->projectMemberService->existMember($params);

        $update = ProjectMember::where([
            'org_id' => $orgId,
            'group_id' => $groupId,
            'uid' => $params['uid'],
            'project_id' => $projectId, ])->update(['role' => $params['role']]);


        if ($update) {
            $this->permissionService->deleteCacheProject(
                (int) $params['uid'],
                (int) $orgId,
                (int) $groupId,
                (int) $projectId
            );
            return $this->success();
        }

        return $this->failed(ErrorCode::getMessage(ErrorCode::MODIFICATION_FAILED), [], ErrorCode::MODIFICATION_FAILED);
    }

    public function batchUpdate()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $projectId = Functions::getContextValue('project_id');
        $params = $this->validateAll([
            'uids' => 'required|array',
            'role' => 'required|integer',
        ]);

        Db::beginTransaction();
        try {
            //过滤不在项目内的uid
            $uids = ProjectMember::where([
                'org_id' => $orgId,
                'group_id' => $groupId,
                'project_id' => $projectId, ])
                ->whereIn('uid', $params['uids'])
                ->pluck('uid')->toArray();
            $failed = array_diff($params['uids'], $uids);
            if ($uids) {
                ProjectMember::where([
                    'org_id' => $orgId,
                    'group_id' => $groupId,
                    'project_id' => $projectId, ])
                    ->whereIn('uid', $uids)->update(['role' => $params['role']]);
            }
            foreach ($uids as $uid) {
                $this->permissionService->deleteCacheProject(
                    (int) $uid,
                    (int) $orgId,
                    (int) $groupId,
                    (int) $projectId
                );
            }

            Db::commit();
            if (! empty($failed)) {
                return $this->success(['failed' => ['uids' => $failed]]);
            }
            return $this->success();
        } catch (\Throwable $e) {
            Db::rollBack();
            throw new AppException((int) $e->getCode(), $e->getMessage());
        }
    }

    /**
     * 删除项目成员.
     */
    public function delete()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $projectId = Functions::getContextValue('project_id');
        $params = $this->validateAll([
            'uid' => 'required|integer',
        ]);

        $this->projectMember->deleteMember(
            $params['uid'],
            $orgId,
            $groupId,
            $projectId
        );
        $this->permissionService->deleteCacheProject(
            (int) $params['uid'],
            (int) $orgId,
            (int) $groupId,
            (int) $projectId
        );

        return $this->success();
    }

    /**
     * 批量删除.
     */
    public function batchDelete()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $projectId = Functions::getContextValue('project_id');
        $params = $this->validateAll([
            'uids' => 'required|array',
        ]);

        $this->projectMember->batchDeleteMembers(
            $params['uids'],
            $orgId,
            $groupId,
            $projectId
        );
        foreach ($params['uids'] as $uid) {
            $this->permissionService->deleteCacheProject(
                (int) $uid,
                (int) $orgId,
                (int) $groupId,
                (int) $projectId
            );
        }

        return $this->success();
    }

    /**
     * 搜索项目内成员.
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function search()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $params = $this->validateAll([
            'role' => 'integer',
            'keyword' => 'string',
        ]);

        $groupMember = GroupMember::where([
            'org_id' => $orgId,
            'group_id' => $groupId,
        ]);

        if (! empty($params['role'])) {
            $groupMember->where('role', $params['role']);
        }

        if (! empty($params['keyword'])) {
            $keyword = $params['keyword'];
            $groupMember->where(function ($q) use ($keyword, $orgId) {
                $q->whereHas('user', function ($query) use ($keyword) {
                    $query->where('email', 'like', '%' . $keyword . '%');
                });
                $q->orWhereHas('orgMember', function ($query) use ($orgId, $keyword) {
                    $query->where('org_id', $orgId);
                    $query->where('realname', 'like', '%' . $keyword . '%');
                });
            });
        }

        $members = $groupMember->with(['user', 'user.userInfo' => function ($query) {
            $query->select(['uid', 'nickname', 'avatar']);
        }])->limit(20)->get();
        $data = $this->projectMemberService->handlerList($members->toArray());

        return $this->success(['members' => $data]);
    }
}
