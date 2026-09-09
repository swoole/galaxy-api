<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Model;

use App\Exception\AppException;
use App\Support\MySQL;
use Hyperf\DbConnection\Db;
use Throwable;

/**
 * @property int $id
 * @property int $org_id
 * @property int $uid
 * @property int $role
 * @property string $realname
 * @property string $workcode
 * @property int $join_at
 */
class OrgMember extends Model
{
    /**
     * 组织成员角色.
     */
    public const ROLE_GENERAL = 0; // 普通成员
    public const ROLE_MANAGER = 1; // 管理员

    public static $roles = [
        self::ROLE_GENERAL => '普通成员',
        self::ROLE_MANAGER => '管理员',
    ];

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected ?string $table = 'org_member';

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
    protected array $casts = ['id' => 'integer', 'org_id' => 'integer', 'uid' => 'integer', 'role' => 'integer', 'join_at' => 'integer'];

    public function role()
    {
        return $this->hasOne(Org::class, 'org_id', 'id');
    }

    public static function getOneByWhere($where)
    {
        return self::where($where)->first();
    }

    public function userProfile()
    {
        return $this->hasOne(UserProfile::class, 'uid', 'uid');
    }

    /**
     * 成员列表.
     * @param int $orgId
     * @param null|int $tagId
     * @param null|int $role
     * @param null|strng $keyword
     * @param int $page
     * @param int $pageSize
     */
    public function list($orgId, $role = null, $keyword = null, $page = 1, $pageSize = 20)
    {
        $builder = $this->where('org_id', $orgId)
            ->select(
                'org_id',
                'uid',
                'role',
                'realname',
                'workcode',
                'join_at'
            )
            ->with('user');
        if (! is_null($role)) {
            $builder->where('role', $role);
        }
        if ($keyword) {
            $builder->where(function ($query) use ($keyword) {
                $uids = User::where('email', 'like', "%{$keyword}%")
                    ->orWhere('phone', 'like', "%{$keyword}%")
                    ->pluck('id')
                    ->toArray();
                $uids[] = $keyword;
                $query->where('realname', 'like', "%{$keyword}%")
                    ->orWhere('workcode', 'like', "%{$keyword}%")
                    ->orWhereIn('uid', $uids);
            });
        }

        return MySQL::jsonPaginate($builder, $page, $pageSize);
    }

    /**
     * 退出组织.
     */
    public function exitOrg($uid, $orgId)
    {
        $member = $this->where('org_id', $orgId)
            ->where('uid', $uid)
            ->first();
        if (empty($member)) {
            throw new AppException(422, '您不在此组织中，退出失败');
        }
        if (in_array((int) $member['role'], [OrgMember::ROLE_MANAGER])) {
            throw new AppException(422, '组织管理员不能退出组织，请转移当前组织成员身份之后再退出');
        }

        // 在组织的项目组中担任项目负责人，也不允许退出
        $groupMembers = GroupMember::where('org_id', $orgId)
            ->where('uid', $uid)
            ->where('role', GroupMember::ROLE_DIRECTOR)
            ->get();
        if (!$groupMembers->isEmpty()) {
            $groupIds = $groupMembers->pluck('group_id')->toArray();
            $groups = Group::whereIn('id', $groupIds)->select('id', 'title')->get();
            $groupTitles = $groups->pluck('title')->toArray();
            throw new AppException(
                422,
                sprintf('您是项目组 %s 的负责人，无法自动退出，请转移这些项目组的负责人身份之后再退出组织', implode('、', $groupTitles))
            );
        }

        // 在组的的项目中担任负责人，也不允许退出
        $projectMembers = ProjectMember::where('org_id', $orgId)
            ->where('uid', $uid)
            ->where('role', ProjectMember::ROLE_DIRECTOR)
            ->get();
        if (!$projectMembers->isEmpty()) {
            $projectIds = $projectMembers->pluck('project_id')->toArray();
            $projects = Project::whereIn('id', $projectIds)->select('id', 'title')->get();
            $projectTitles = $projects->pluck('title')->toArray();
            throw new AppException(
                422,
                sprintf('您是项目 %s 的负责人，无法自动退出，请转移这些项目的负责人身份之后再退出组织', implode('、', $projectTitles))
            );
        }

        $this->removeFromOrg($member);
    }

    /**
     * 删除组织成员.
     */
    public function deleteMember($uid, $orgId)
    {
        $member = $this->where('org_id', $orgId)
            ->where('uid', $uid)
            ->first();
        if (empty($member)) {
            throw new AppException(422, '该用户不在此组织中，移除失败');
        }
        if (in_array((int) $member['role'], [OrgMember::ROLE_MANAGER])) {
            throw new AppException(422, '组织管理员不能被移除');
        }

        // 在组织的项目组中担任项目负责人，也不允许删除
        $groupMembers = GroupMember::where('org_id', $orgId)
            ->where('uid', $uid)
            ->where('role', GroupMember::ROLE_DIRECTOR)
            ->get();
        if (!$groupMembers->isEmpty()) {
            $groupIds = $groupMembers->pluck('group_id')->toArray();
            $groups = Group::whereIn('id', $groupIds)->select('id', 'title')->get();
            $groupTitles = $groups->pluck('title')->toArray();
            throw new AppException(
                422,
                sprintf('该用户是项目组 %s 的负责人，无法移除，请转移这些项目组的负责人身份之后再移除', implode('、', $groupTitles))
            );
        }

        // 在项目组的的项目中担任负责人，也不允许删除
        $projectMembers = ProjectMember::where('org_id', $orgId)
            ->where('uid', $uid)
            ->where('role', ProjectMember::ROLE_DIRECTOR)
            ->get();
        if (!$projectMembers->isEmpty()) {
            $projectIds = $projectMembers->pluck('project_id')->toArray();
            $projects = Project::whereIn('id', $projectIds)->select('id', 'title')->get();
            $projectTitles = $projects->pluck('title')->toArray();
            throw new AppException(
                422,
                sprintf('该用户是项目 %s 的负责人，无法移除，请转移这些项目的负责人身份之后再移除', implode('、', $projectTitles))
            );
        }

        $this->removeFromOrg($member);
    }

    /**
     * 批量移除组织成员.
     */
    public function batchDeleteMember($uids, $orgId)
    {
        foreach ($uids as $uid) {
            $this->deleteMember($uid, $orgId);
        }
    }

    /**
     * 将用户从组织移除.
     */
    protected function removeFromOrg(OrgMember $member)
    {
        Db::beginTransaction();
        try {
            // 退出所有项目
            ProjectMember::where('org_id', $member['org_id'])
                ->where('uid', $member['uid'])
                ->delete();

            // 退出所有项目组
            GroupMember::where('org_id', $member['org_id'])
                ->where('uid', $member['uid'])
                ->delete();

            // 删除组织成员
            $member->delete();

            Db::commit();
        } catch (Throwable $e) {
            Db::rollBack();
            throw $e;
        }
    }

    /**
     * 模型关联: user.
     */
    public function user()
    {
        return $this->hasOne(User::class, 'id', 'uid')
            ->leftJoin('user_profile', 'user.id', '=', 'user_profile.uid')
            ->select(
                'user.id',
                'user.email',
                'user.phone',
                'user_profile.avatar',
                'user_profile.nickname'
            );
    }

    /**
     * @param $query
     * @return array
     */
    public function search(int $orgId, string $query)
    {
        $query = trim($query);
        return User::query()
            ->leftJoin('user_profile', 'user.id', '=', 'user_profile.uid')
            ->where('user.status', User::STATUS_NORMAL)
            ->whereNotIn('user.id', OrgMember::where('org_id', $orgId)->select('uid'))
            ->where(function ($builder) use ($query): void {
                $pattern = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';
                $builder->where('user_profile.nickname', 'like', $pattern)
                    ->orWhere('user.username', 'like', $pattern)
                    ->orWhere('user.email', 'like', $pattern)
                    ->orWhere('user.phone', 'like', $pattern);
            })
            ->select(
                'user.id',
                'user_profile.avatar',
                'user_profile.nickname',
                'user.username',
                'user.email'
            )->orderBy('user.id')->limit(20)->get()->toArray();
    }

    /**
     * 是否超出成员数.
     */
    public function exceedCount($orgId, ?Org $org = null)
    {
        // CodeGalaxy is a self-hosted tool and does not impose paid member limits.
    }
}
