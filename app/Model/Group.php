<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Model;

use App\Constants\ErrorCode;
use App\Exception\AppException;
use App\Services\Alias\Alias;
use App\Services\Notify as ServicesNotify;
use App\Support\Functions;
use App\Support\MySQL;
use Exception;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Request;
use Hyperf\Context\ApplicationContext;
use Hyperf\Context\Context;
use Throwable;

/**
 * @property int $id
 * @property int $org_id
 * @property string $alias
 * @property string $title
 * @property int $creator
 * @property int $created_at
 */
class Group extends Model
{
    use TraitRelationCreatorInfo;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected ?string $table = 'group';

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
    protected array $casts = [
    ];

    /**
     * 获取别名ID.
     */
    public static function getAliasId()
    {
        $orgId = Context::get('org_id');

        $request = ApplicationContext::getContainer()->get(Request::class);
        if (array_key_exists('group_id', $request->getQueryParams())) {
            throw new AppException(ErrorCode::INVALID_PARAMS, 'parameter group_id has been renamed to group');
        }
        $input = $request->input('group', null);
        if (empty($input)) {
            $groupId = null;
        } elseif (is_numeric($input)) {
            $groupId = (int) $input;
        } else {
            $groupId = (int) Group::where('org_id', $orgId)->where('alias', $input)->value('id');
            if (!$groupId) {
                throw new AppException(ErrorCode::INVALID_PARAMS, 'invalid parameter group');
            }
        }
        Context::set('group_id', $groupId);

        return $groupId;
    }

    /**
     * 项目组列表.
     */
    public function list($uid, $orgId, $keyword = null, $page = 1, $pageSize = 20)
    {
        $builder = $this->where('org_id', $orgId)
            ->select('id', 'org_id', 'alias', 'title', 'creator', 'created_at')
            ->orderBy('id', 'asc')
            ->with([
                'creatorInfo' => function ($query) use ($orgId) {
                    $query->where('org_id', $orgId);
                }
            ]);

        // ------------------- 权限过滤 begin -----------------------
        $roles = Context::get('roles', [0]);
        if (!in_array((int) $roles[0], [OrgMember::ROLE_MANAGER])) {
            // 非组织管理员进行项目过滤
            $builder->where(function ($query) use ($uid, $orgId) {
                $groupIds = GroupMember::where('org_id', $orgId)
                    ->where('uid', $uid)
                    ->pluck('group_id')
                    ->toArray();
                $query->whereIn('id', $groupIds);
            });
        }
        // ------------------- 权限过滤 end -----------------------

        if (!empty($keyword)) {
            $builder->where(function ($query) use ($keyword) {
                $query->where('title', 'like', '%' . $keyword . '%');
                if (is_numeric($keyword)) {
                    $query->orWhere('id',  (int) $keyword);
                } else {
                    $query->orWhere('alias',  $keyword);
                }
            });
        }

        $data = MySQL::jsonPaginate($builder, $page, $pageSize);

        $membersCount = $this->getMembersCount($data['data']);
        $projectsCount = $this->getProjectsCount($data['data']);

        foreach ($data['data'] as &$item) {
            $item['member_count'] = $membersCount[$item['id']] ?? 0;
            $item['project_count'] = $projectsCount[$item['id']] ?? 0;
        }
        unset($item);

        return $data;
    }

    /**
     * 简单列表.
     */
    public function simpleList($uid, $orgId, $keyword = null, $requireWrite = false)
    {
        $builder = $this->where('org_id', $orgId)
            ->select('id', 'org_id', 'title', 'alias')
            ->orderBy('id', 'asc');

        // ------------------- 权限过滤 begin -----------------------
        $roles = Context::get('roles', [0]);
        if (!in_array((int) $roles[0], [OrgMember::ROLE_MANAGER])) {
            // 非组织管理员进行项目过滤
            $builder->where(function ($query) use ($uid, $orgId, $requireWrite) {
                $builder = GroupMember::where('org_id', $orgId)
                    ->where('uid', $uid);
                // 针对创建项目的项目组列表做优化
                if ($requireWrite) {
                    $builder->whereIn('role', [GroupMember::ROLE_DIRECTOR]);
                }
                $groupIds = $builder->pluck('group_id')->toArray();
                $query->whereIn('id', $groupIds);
            });
        }
        // ------------------- 权限过滤 end -----------------------

        if (!empty($keyword)) {
            $builder->where(function ($query) use ($keyword) {
                $query->where('title', 'like', '%' . $keyword . '%');
                if (is_numeric($keyword)) {
                    $query->orWhere('id', (int) $keyword);
                } else {
                    $query->orWhere('alias', $keyword);
                }
            });
        }

        return $builder->get();
    }

    /**
     * 创建项目组.
     */
    public function createGroup($uid, $orgId, $params)
    {
        // 校验项目组名称和标识在组织内唯一
        $dupliTitle = $this->where('org_id', $orgId)
            ->where('title', $params['title'])
            ->exists();
        if ($dupliTitle) {
            throw new AppException(
                ErrorCode::GROUP_TITLE_DUPLICATE,
                ErrorCode::getMessage(ErrorCode::GROUP_TITLE_DUPLICATE)
            );
        }

        // 验证alias唯一性
        if (!empty($params['alias'])) {
            $dupliAlias = $this->where('org_id', $orgId)
                ->where('alias', $params['alias'])
                ->exists();
            if ($dupliAlias) {
                throw new AppException(
                    ErrorCode::INVALID_PARAMS,
                    '别名已被使用，请更改'
                );
            }
        } else {
            $params['alias'] = $this->calcuDefaultAlias($orgId, $params['title']);
        }

        // 开启事务
        Db::beginTransaction();
        try {
            $group = Group::create([
                'org_id' => $orgId,
                'alias' => $params['alias'],
                'title' => $params['title'],
                'desc' => $params['desc'],
                'creator' => $uid,
                'created_at' => time(),
            ]);
            // 创建项目组负责人成员记录
            $groupMember = GroupMember::create([
                'org_id' => $orgId,
                'uid' => $uid,
                'group_id' => $group['id'],
                'role' => GroupMember::ROLE_DIRECTOR,
                'join_at' => time(),
            ]);
            Db::commit();

            $group->setVisible(['id', 'alias', 'title', 'desc', 'creator', 'created_at']);

            return $group;
        } catch (Exception $e) {
            Db::rollBack();
            throw $e;
        }
    }

    /**
     * 更新项目组.
     */
    public function updateGroup($uid, $orgId, $groupId, $params)
    {
        // 项目组是否存在
        $group = $this->where('id', $groupId)
            ->where('org_id', $orgId)
            ->first();
        if (empty($group)) {
            throw new AppException(
                ErrorCode::NOT_FOUND,
                '项目组不存在'
            );
        }
        
        // 校验项目组名称和标识在组织内唯一
        $dupliTitle = $this->where('org_id', $orgId)
            ->where('title', $params['title'])
            ->where('id', '<>', $groupId)
            ->exists();
        if ($dupliTitle) {
            throw new AppException(
                ErrorCode::GROUP_TITLE_DUPLICATE,
                ErrorCode::getMessage(ErrorCode::GROUP_TITLE_DUPLICATE)
            );
        }

        // // 验证alias唯一性
        // if (!empty($params['alias'])) {
        //     $dupliAlias = $this->where('org_id', $orgId)
        //         ->where('alias', $params['alias'])
        //         ->where('id', '<>', $groupId)
        //         ->exists();
        //     if ($dupliAlias) {
        //         throw new AppException(
        //             ErrorCode::INVALID_PARAMS,
        //             '别名已被使用，请更改'
        //         );
        //     }
        // } elseif ($group->alias) {
        //     $params['alias'] = $group->alias;
        // } else {
        //     $params['alias'] = $this->calcuDefaultAlias($orgId, $params['title'], $groupId);
        // }

        $group->title = $params['title'];
        $group->desc = $params['desc'];
        // $group->alias = $params['alias']; // 不允许更新alias
        $group->save();

        $group->setVisible(['id', 'alias', 'title', 'desc', 'creator', 'created_at']);

        return $group;
    }

    /**
     * 详情.
     */
    public function profile($orgId, $groupId)
    {
        $group = $this->where('id', $groupId)
            ->where('org_id', $orgId)
            ->select('id', 'title', 'alias', 'desc', 'creator', 'created_at')
            ->with([
                'creatorInfo' => function ($query) use ($orgId) {
                    $query->where('org_id', $orgId);
                }
            ])->first();
        if (empty($group)) {
            throw new AppException(
                ErrorCode::GROUP_NO_EXIST,
                ErrorCode::getMessage(ErrorCode::GROUP_NO_EXIST)
            );
        }

        $roles = Context::get('roles');

        $group['org_role'] = $roles[0];
        $group['role'] = $roles[1];
   
        return $group;
    }

    /**
     * 删除项目组.
     */
    public function deleteGroup($uid, $orgId, $groupId)
    {
        $group = Group::where('id', $groupId)
            ->where('org_id', $orgId)
            ->first();
        if (empty($group)) {
            throw new AppException(404, '项目组不存在');
        }

        // 存在项目不允许删除
        $projectExists = Project::where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->exists();
        if ($projectExists) {
            throw new AppException(422, '该项目组下存在项目，删除所有项目之后才能删除项目组');
        }
        if (Workspace::where('org_id', $orgId)->where('group_id', $groupId)->exists()) {
            throw new AppException(422, '该项目组下仍有开发环境，请由各用户删除自己的 Workspace 后再删除项目组');
        }

        // 开启事务
        Db::beginTransaction();
        try {
            GroupMember::where('org_id', $orgId)
                ->where('group_id', $groupId)
                ->delete();
            GroupResourceGrant::where('org_id', $orgId)->where('group_id', $groupId)->delete();

            $groupProfile = $group->toArray();

            $group->delete();

            Db::commit();
        } catch (Throwable $e) {
            Db::rollBack();

            $this->logger->error('catch unknown exception on deleteGroup', [
                'params' => [
                    'uid' => $uid,
                    'orgId' => $orgId,
                    'groupId' => $groupId,
                ],
                'exception' => Functions::exceptionContext($e),
            ]);

            throw $e;
        }

        // 发送删除通知.
        if ($uid) {
            $this->sendDeleteNotify($uid, $groupProfile);
        }
    }

    /**
     * 发送删除通知.
     */
    protected function sendDeleteNotify($operator, $group)
    {
        try {
            // 消息接收人
            $tos = [(int) $group['creator'], (int) $operator];
            $members = GroupMember::where('org_id', $group['org_id'])
                ->where('group_id', $group['id'])
                ->whereIn('role', [GroupMember::ROLE_DIRECTOR])
                ->select('role', 'uid')
                ->get();
            foreach ($members as $member) {
                $tos[] = (int) $member['uid'];
            }
            $tos = array_unique($tos);

            $org = Org::where('id', $group['org_id'])
                ->select('id', 'title')
                ->first();
            $operatorInfo = $this->userInfo($operator, $group['org_id']);

            $params = [
                'org' => $org->toArray(),
                'group' => $group,
                'operator_info' => $operatorInfo->toArray(),
                'operated_at' => time(),
            ];

            /** @var ServicesNotify */
            $notify = $this->getInstance(ServicesNotify::class);
            $notify->send($tos, 'group_delete', $params);
        } catch (Throwable $e) {
            $this->logger->warning('send group delete notify failed', [
                'params' => [
                    'operator' => $operator,
                    'orgId' => $group['org_id'],
                    'groupId' => $group['id'],
                ],
                'exception' => Functions::exceptionContext($e),
            ]);
        }
    }

    /**
     * 创建人关联用户表.
     * @return \Hyperf\Database\Model\Relations\BelongsTo
     */
    public function User()
    {
        return $this->belongsTo(User::class, 'creator', 'id');
    }

    /**
     * 创建人关联用户信息表.
     * @return \Hyperf\Database\Model\Relations\BelongsTo
     */
    public function userProfile()
    {
        return $this->belongsTo(UserProfile::class, 'creator', 'uid');
    }

    /**
     * 关联项目组成员.
     * @return \Hyperf\Database\Model\Relations\HasMany
     */
    public function groupMember()
    {
        return $this->hasMany(GroupMember::class, 'group_id', 'id');
    }

    /**
     * 关联项目.
     * @return \Hyperf\Database\Model\Relations\HasMany
     */
    public function project()
    {
        return $this->hasMany(Project::class, 'group_id', 'id');
    }

    /**
     * 模型关联.
     */
    public function org()
    {
        return $this->hasOne(Org::class, 'id', 'org_id')->select('id', 'title', 'alias', 'desc', 'logo');
    }

    /**
     * 获取项目组成员数量.
     */
    protected function getMembersCount($groups)
    {
        $builder = null;
        foreach ($groups as $group) {
            if (!$builder) {
                $builder = GroupMember::where('org_id', $group['org_id'])
                ->where('group_id', $group['id'])
                ->select('group_id', Db::raw('COUNT(1) AS cnt'))
                ->groupBy('group_id');
            } else {
                $unionBuilder = GroupMember::where('org_id', $group['org_id'])
                ->where('group_id', $group['id'])
                ->select('group_id', Db::raw('COUNT(1) AS cnt'))
                ->groupBy('group_id');
                $builder->union($unionBuilder);
            }
        }

        if (empty($builder)) {
            return [];
        }

        return $builder->pluck('cnt', 'group_id')->toArray();
    }

    /**
     * 获取项目组项目数量.
     */
    protected function getProjectsCount($groups)
    {
        $builder = null;
        foreach ($groups as $group) {
            if (!$builder) {
                $builder = Project::where('org_id', $group['org_id'])
                ->where('group_id', $group['id'])
                ->select('group_id', Db::raw('COUNT(1) AS cnt'))
                ->groupBy('group_id');
            } else {
                $unionBuilder = Project::where('org_id', $group['org_id'])
                ->where('group_id', $group['id'])
                ->select('group_id', Db::raw('COUNT(1) AS cnt'))
                ->groupBy('group_id');
                $builder->union($unionBuilder);
            }
        }

        if (empty($builder)) {
            return [];
        }

        return $builder->pluck('cnt', 'group_id')->toArray();
    }

    /**
     * 计算默认alias.
     */
    protected function calcuDefaultAlias($orgId, $title, $groupId = null)
    {
        return Alias::calcuDefault($this->where('org_id', $orgId), $title, $groupId);
    }
}
