<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Model;

use App\Constants\ErrorCode;
use App\Exception\AppException;
use App\Support\MySQL;

/**
 * @property int $id
 * @property int $org_id
 * @property int $group_id
 * @property int $uid
 * @property int $project_id
 * @property int $role
 * @property int $join_at
 */
class ProjectMember extends Model
{
    /**
     * 项目成员角色.
     */
    public const ROLE_GENERAL = 0; // 普通成员
    public const ROLE_DIRECTOR = 9; // 负责人

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected ?string $table = 'project_member';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected array $guarded = [];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected array $casts = ['id' => 'integer', 'org_id' => 'integer', 'group_id' => 'integer', 'uid' => 'integer', 'project_id' => 'integer', 'role' => 'integer', 'join_at' => 'integer'];

    public function user()
    {
        return $this->hasOne(User::class, 'id', 'uid');
    }

    /**
     * 成员列表.
     */
    public function list($uid, $orgId, $groupId, $projectId, $role = null, $keyword = null, $page = 1, $pageSize = 20)
    {
        $builder = $this->where('project_member.org_id', $orgId)
            ->where('project_member.group_id', $groupId)
            ->where('project_member.project_id', $projectId)
            ->select(
                'project_member.id',
                'project_member.org_id',
                'project_member.uid',
                'org_member.realname',
                'user.email',
                'user_profile.avatar',
                'user_profile.nickname',
                'org_member.workcode',
                'project_member.role',
                'project_member.join_at'
            )
            ->orderBy('project_member.id', 'desc')
            ->join('org_member', function ($join) use ($orgId) {
                $join->on('project_member.uid', '=', 'org_member.uid')
                ->where('org_member.org_id', $orgId);
            })
            ->join('user', 'user.id', '=', 'project_member.uid')
            ->join('user_profile', 'user_profile.uid', '=', 'project_member.uid');

        if (!is_null($role)) {
            $builder->where('project_member.role', $role);
        }

        if (!empty($keyword)) {
            $builder->where(function ($query) use ($orgId, $keyword) {
                $query->where('user.email', 'like', '%' . $keyword . '%')
                    ->orWhere('user_profile.nickname', 'like', '%' . $keyword . '%')
                    ->orWhere('org_member.realname', 'like', '%' . $keyword . '%');
                // 增加uid搜索
                if (is_numeric($keyword)) {
                    $query->orWhere('user.id', (int) $keyword);
                }
            });
        }

        return MySQL::jsonPaginate($builder, $page, $pageSize);
    }

    /**
     * 退出项目.
     */
    public function exitProject($uid, $orgId, $groupId, $projectId)
    {
        $member = $this->where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('project_id', $projectId)
            ->where('uid', $uid)
            ->first();
        if (empty($member)) {
            throw new AppException(422, '您不在此项目中，退出失败');
        }
        if (in_array((int) $member['role'], [ProjectMember::ROLE_DIRECTOR])) {
            throw new AppException(422, '项目负责人不能退出项目，请转移当前项目成员身份之后再退出');
        }

        $this->removeFromProject($member);
    }

    /**
     * 删除项目成员.
     */
    public function deleteMember($uid, $orgId, $groupId, $projectId)
    {
        $member = $this->where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('project_id', $projectId)
            ->where('uid', $uid)
            ->first();
        if (empty($member)) {
            throw new AppException(422, '该用户不在此项目中，移除失败');
        }
        if (in_array((int) $member['role'], [ProjectMember::ROLE_DIRECTOR])) {
            throw new AppException(422, '项目负责人不能被移除');
        }

        $this->removeFromProject($member);
    }

    /**
     * 批量删除项目成员.
     */
    public function batchDeleteMembers($uids, $orgId, $groupId, $projectId)
    {
        foreach ($uids as $uid) {
            $this->deleteMember($uid, $orgId, $groupId, $projectId);
        }
    }

    /**
     * 从项目中移除.
     */
    protected function removeFromProject(ProjectMember $member)
    {
        $member->delete();
    }
}
