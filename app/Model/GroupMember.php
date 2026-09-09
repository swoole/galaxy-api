<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Model;

use App\Exception\AppException;
use App\Support\Functions;
use App\Support\MySQL;
use Hyperf\DbConnection\Db;
use Throwable;

class GroupMember extends Model
{
    /**
     * 项目组成员角色.
     */
    public const ROLE_GENERAL = 0; // 普通成员
    public const ROLE_DIRECTOR = 9; // 负责人

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected ?string $table = 'group_member';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
//    protected $fillable = [];
    protected array $guarded = [];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected array $casts = [];

    /**
     * 关联组织成员的信息.
     * @return \Hyperf\Database\Model\Relations\HasOne
     */
    public function orgMember()
    {
        return $this->hasOne(OrgMember::class, 'uid', 'uid');
    }

    /**
     * 关联用户表.
     * @return \Hyperf\Database\Model\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'uid', 'id');
    }

    /**
     * 关联用户信息表.
     * @return \Hyperf\Database\Model\Relations\BelongsTo
     */
    public function userProfile()
    {
        return $this->belongsTo(UserProfile::class, 'uid', 'uid');
    }

    /**
     * 成员列表.
     */
    public function list($uid, $orgId, $groupId, $role = null, $keyword = null, $page = 1, $pageSize = 20)
    {
        $builder = $this->where('group_member.org_id', $orgId)
            ->where('group_member.group_id', $groupId)
            ->select(
                'group_member.id', 'group_member.org_id', 'group_member.uid', 'org_member.realname', 'user.email', 'user_profile.avatar', 'user_profile.nickname', 'org_member.workcode', 'group_member.role', 'group_member.join_at'
            )
            ->orderBy('group_member.id', 'desc')
            ->join('org_member', function ($join) use ($orgId) {
                $join->on('group_member.uid', '=', 'org_member.uid')
                    ->where('org_member.org_id', $orgId);
            })
            ->join('user', 'user.id', '=', 'group_member.uid')
            ->join('user_profile', 'user_profile.uid', '=', 'group_member.uid');

        if (!is_null($role)) {
            $builder->where('group_member.role', $role);
        }

        if (!empty($keyword)) {
            $builder->where(function ($query) use ($orgId, $keyword) {
                $query->where('user.email', 'like', '%' . $keyword . '%')
                    ->orWhere('user_profile.nickname', 'like', '%' . $keyword . '%')
                    ->orWhere('org_member.realname', 'like', '%' . $keyword . '%');
                });
        }

        return MySQL::jsonPaginate($builder, $page, $pageSize);
    }

    /**
     * 退出项目组.
     */
    public function exitGroup($uid, $orgId, $groupId)
    {
        $member = $this->where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('uid', $uid)
            ->first();
        if (empty($member)) {
            throw new AppException(422, '您不在此项目组中，退出失败');
        }
        if (in_array((int) $member['role'], [GroupMember::ROLE_DIRECTOR])) {
            throw new AppException(422, '项目组负责人不能退出项目组，请转移当前项目组成员身份之后再退出');
        }
        $this->assertWorkspaceRemoved($uid, $orgId, $groupId, '退出');

        // 在项目组的项目中担任负责人，也不允许退出
        $projectMembers = ProjectMember::where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('uid', $uid)
            ->where('role', ProjectMember::ROLE_DIRECTOR)
            ->get();
        if (!$projectMembers->isEmpty()) {
            $projectIds = $projectMembers->pluck('project_id')->toArray();
            $projects = Project::whereIn('id', $projectIds)->select('id', 'title')->get();
            $projectTitles = $projects->pluck('title')->toArray();
            throw new AppException(
                422,
                sprintf('您是项目 %s 的负责人，无法自动退出，请转移这些项目的负责人身份之后再退出项目组', implode('、', $projectTitles))
            );
        }

        $this->removeFromGroup($member);
    }

    /**
     * 删除项目组成员.
     */
    public function deleteMember($uid, $orgId, $groupId, bool $isOrgManager = false)
    {
        $member = $this->where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('uid', $uid)
            ->first();
        if (empty($member)) {
            throw new AppException(422, '该用户不在此项目组中，移除失败');
        }
        if (! $isOrgManager && in_array((int) $member['role'], [GroupMember::ROLE_DIRECTOR])) {
            throw new AppException(422, '项目组负责人不能被移除');
        }
        $this->assertWorkspaceRemoved($uid, $orgId, $groupId, '移除');

        // 项目组负责人只能移除不承担项目负责人角色的成员；
        // 组织管理员可移除任何角色，removeFromGroup 会同步清理项目成员关系。
        if (! $isOrgManager) {
            $projectMembers = ProjectMember::where('org_id', $orgId)
                ->where('group_id', $groupId)
                ->where('uid', $uid)
                ->where('role', ProjectMember::ROLE_DIRECTOR)
                ->get();
            if (! $projectMembers->isEmpty()) {
                $projectIds = $projectMembers->pluck('project_id')->toArray();
                $projects = Project::whereIn('id', $projectIds)->select('id', 'title')->get();
                $projectTitles = $projects->pluck('title')->toArray();
                throw new AppException(
                    422,
                    sprintf('该用户是项目 %s 的负责人，无法移除，请转移这些项目的负责人身份之后再移除', implode('、', $projectTitles))
                );
            }
        }

        $this->removeFromGroup($member);
    }

    /**
     * 批量移除项目组成员.
     */
    public function batchDeleteMember($uids, $orgId, $groupId, bool $isOrgManager = false)
    {
        foreach ($uids as $uid) {
            $this->deleteMember($uid, $orgId, $groupId, $isOrgManager);
        }
    }

    /**
     * 从项目中移除成员.
     */
    protected function removeFromGroup(GroupMember $member)
    {
        Db::beginTransaction();
        try {
            // 退出所有项目
            ProjectMember::where('org_id', $member['org_id'])
                ->where('group_id', $member['group_id'])
                ->where('uid', $member['uid'])
                ->delete();

            $member->delete();

            Db::commit();
        } catch (Throwable $e) {
            Db::rollBack();

            $this->logger->error('catch unknown exception on removeFromGroup', [
                'params' => $member->toArray(),
                'exception' => Functions::exceptionContext($e),
            ]);

            throw $e;
        }
    }

    private function assertWorkspaceRemoved(int $uid, int $orgId, int $groupId, string $action): void
    {
        if (Workspace::where('uid', $uid)->where('org_id', $orgId)->where('group_id', $groupId)->exists()) {
            throw new AppException(422, sprintf('该用户仍有占用项目组资源的 Workspace，请先删除开发环境后再%s项目组', $action));
        }
    }
}
