<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Services;

use App\Constants\ErrorCode;
use App\Constants\Role;
use App\Exception\AppException;
use App\Model\Project;
use App\Model\OrgMember;
use App\Model\Group;
use App\Model\GroupMember;
use Hyperf\Di\Annotation\Inject;

class GroupService
{
    /**
     * @Inject
     * @var OrgService
     */
    #[Inject]
    private $orgService;

    /**
     * 用户是否为组织成员.
     * @param $org_id
     * @param $uid
     * @return null|\Hyperf\Database\Model\Builder|\Hyperf\Database\Model\Model|object
     */
    public function existOrgMember($org_id, $uid)
    {
        $orgMemberExist = OrgMember::where('org_id', $org_id)->where('uid', $uid)->first();
        if (! $orgMemberExist) {
            throw new AppException(
                ErrorCode::ORG_MEMBER_NO_EXIST,
                ErrorCode::getMessage(ErrorCode::ORG_MEMBER_NO_EXIST)
            );
        }
        return $orgMemberExist;
    }

    /**
     * 项目是否存在.
     * @param $org_id
     * @param $group_id
     * @return null|\Hyperf\Database\Model\Builder|\Hyperf\Database\Model\Model|object
     */
    public function existProject($org_id, $group_id)
    {
        $groupExist = Group::where(['id' => $group_id, 'org_id' => $org_id])->first();
        if (! $groupExist) {
            throw new AppException(
                ErrorCode::GROUP_NO_EXIST,
                ErrorCode::getMessage(ErrorCode::GROUP_NO_EXIST)
            );
        }
        return $groupExist;
    }

    /**
     * 是否为项目组成员.
     * @param $org_id
     * @param $group_id
     * @param $uid
     * @return null|\Hyperf\Database\Model\Builder|\Hyperf\Database\Model\Model|object
     */
    public function existingGroupMember($org_id, $group_id, $uid)
    {
        $existingGroupMember = GroupMember::where([
            'org_id' => $org_id,
            'group_id' => $group_id,
            'uid' => $uid,
        ])->first();
        if (! $existingGroupMember) {
            throw new AppException(
                ErrorCode::GROUP_MEMBER_NO_EXIST,
                ErrorCode::getMessage(ErrorCode::GROUP_MEMBER_NO_EXIST)
            );
        }
        return $existingGroupMember;
    }

    /**
     * 项目组名称在组织内唯一.
     * @param $title
     * @param $org_id
     * @return null|\Hyperf\Database\Model\Builder|\Hyperf\Database\Model\Model|object
     */
    public function uniqueTitle($title, $org_id)
    {
        return Group::where(['title' => $title, 'org_id' => $org_id])->first();
    }

    /**
     * 查看角色是否存在.
     * @param $role
     */
    public function existRole($role)
    {
        $roleMessage = Role::getRole($role);
        if (! $roleMessage) {
            throw new AppException(
                ErrorCode::ROLE_NOT_EXIST,
                ErrorCode::getMessage(ErrorCode::ROLE_NOT_EXIST)
            );
        }
    }

    /**
     * 从组织成员搜索.
     * @param $org_id
     * @param $role
     * @param $keyword
     * @return \Hyperf\Collection\Collection
     */
    public function searchOrgMember($org_id, $role, $keyword)
    {
        $model = OrgMember::select('id', 'org_id', 'uid', 'role', 'realname', 'workcode', 'join_at');
        $model->where('org_id', $org_id);
        if ($role) {
            $model->where(function ($query) use ($role) {
                $query->where('role', $role);
            });
        }
        if ($keyword) {
            $model->where(function ($q) use ($keyword) {
                $q->whereHas('user', function ($query) use ($keyword) {
                    $query->where('email', 'like', '%' . $keyword . '%');
                });
                $q->orWhere('realname', 'like', '%' . $keyword . '%');
            });
        } else {
            $model->with('user');
        }

        $model->orderBy('join_at', 'desc');
        return $model->get();
    }
}
