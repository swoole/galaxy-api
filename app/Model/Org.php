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
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Request;
use Hyperf\Context\ApplicationContext;
use Hyperf\Context\Context;
use League\Flysystem\Filesystem;
use Throwable;

/**
 * @property int $id
 * @property int $type
 * @property string $alias
 * @property string $title
 * @property string $realname
 * @property string $logo
 * @property string $desc
 * @property int $creator
 * @property int $created_at
 * @property int $status
 * @property int $build_timeout
 */
class Org extends Model
{
    use TraitRelationCreatorInfo;

    /**
     * 类型.
     */
    public const TYPE_PERSONAL = 0; // 个人空间

    public const TYPE_COMPANY = 1; // 团队空间

    public static $types = [
        self::TYPE_PERSONAL => '个人空间',
        self::TYPE_COMPANY => '团队空间',
    ];

    /**
     * 组织状态.
     */
    public const STATUS_NORMAL = 1;

    public static $statuses = [
        self::STATUS_NORMAL => '正常',
    ];

    /**
     * 管理员组织ID.
     * @var int
     */
    protected static $adminOrgId = null;

    /**
     * @Inject
     */
    #[Inject]
    protected Filesystem $filesystem;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected ?string $table = 'org';

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
    protected array $casts = ['id' => 'integer', 'type' => 'integer', 'creator' => 'integer', 'created_at' => 'integer', 'status' => 'integer',
        'build_timeout' => 'integer',
    ];

    /**
     * 组织列表.
     * @param int $uid
     */
    public function list($uid)
    {
        return $this->where('org_member.uid', $uid)
            ->join('org_member', 'org_member.org_id', '=', 'org.id')
            ->select(
                'org.id',
                'org.type',
                'org.alias',
                'org.title',
                'org.logo',
                'org.desc',
                'org.creator',
                'org.created_at',
                'org.status',
                'org_member.role',
                'org_member.join_at'
            )->get();
    }

    /**
     * 获取创建组织属性.
     */
    public function createProps($uid)
    {
        return [];
    }

    /**
     * 创建组织.
     */
    public function createOrg($uid, $params)
    {
        // 验证alias唯一性
        if (!empty($params['alias'])) {
            $dupliAlias = $this->where('alias', $params['alias'])->exists();
            if ($dupliAlias) {
                throw new AppException(
                    ErrorCode::INVALID_PARAMS,
                    '别名已被使用，请更改'
                );
            }
        } else {
            $params['alias'] = $this->calcuDefaultAlias($params['title']);
        }

        $now = time();

        Db::beginTransaction();
        try {
            // 创建组织
            $org = self::create([
                'type' => $params['type'],
                'alias' => $params['alias'],
                'title' => $params['title'],
                'logo' => $params['logo'],
                'desc' => $params['desc'],
                'creator' => $uid,
                'created_at' => $now,
            ]);

            // 同步组织成员
            $orgMember = OrgMember::create([
                'org_id' => $org['id'],
                'uid' => $uid,
                'role' => OrgMember::ROLE_MANAGER,
                'join_at' => $now,
            ]);

            // 创建默认项目组
            $group = Group::create([
                'org_id' => $org['id'],
                'title' => '默认项目组',
                'alias' => 'default',
                'desc' => '默认项目组（创建组织时由系统自动创建）',
                'creator' => $uid,
                'created_at' =>  $now,
            ]);
            // 创建项目成员
            $groupMember = GroupMember::create([
                'org_id' => $org['id'],
                'group_id' => $group['id'],
                'uid' => $uid,
                'role' => GroupMember::ROLE_DIRECTOR,
                'join_at' => $now,
            ]);

            Db::commit();
        } catch (Throwable $e) {
            Db::rollBack();
            throw $e;
        }

        return $org;
    }

    /**
     * 组织详情.
     */
    public function profile($orgId)
    {
        $org = $this->where('id', $orgId)
            ->select('id', 'type', 'title', 'alias', 'logo', 'desc', 'creator', 'created_at', 'status')
            ->with([
                'creatorInfo' => function ($query) use ($orgId) {
                    $query->where('org_id', $orgId);
                }
            ])->first();
        if (empty($org)) {
            throw new AppException(404, '组织不存在');
        }

        $roles = Context::get('roles');
        $org->role = $roles[0];
        $org->is_admin_org = $org->id == Org::adminOrg();

        return $org;
    }

    /**
     * 组织简单信息.
     */
    public function simpleProfile($orgId)
    {
        $org = $this->where('id', $orgId)
            ->select('id', 'type', 'title', 'alias', 'logo', 'status', 'creator')
            ->first();
        if (empty($org)) {
            throw new AppException(404, '组织不存在');
        }

        $roles = Context::get('roles');
        $org->role = $roles[0];
        $org->is_admin_org = $org->id == Org::adminOrg();

        return $org;
    }

    /**
     * 简单组织列表.
     */
    public function simpleList($uid)
    {
        $members = OrgMember::where('uid', $uid)
            ->pluck('role', 'org_id');

        $orgs = $this->whereIn('id', $members->keys()->toArray())
            ->select('id', 'type', 'title', 'logo', 'status')
            ->get();
        $adminOrg = Org::adminOrg();
        foreach ($orgs as $org) {
            $org->role = $members[$org['id']];
            $org->is_admin_org = $org->id == $adminOrg;
            $org->type_s = static::$types[$org->type] ?? null;
            $org->status_s = static::$statuses[$org->status] ?? null;
            $org->role_s = OrgMember::$roles[$org->role] ?? null;
        }

        return $orgs;
    }

    /**
     * 更新组织信息.
     */
    public function updateProfile($uid, $orgId, $params)
    {
        $org = $this->where('id', $orgId)
            ->select('id', 'title', 'alias', 'logo', 'desc')
            ->first();
        if (empty($org)) {
            throw new AppException(
                ErrorCode::ORG_NO_EXIST,
                '组织不存在'
            );
        }

        // // 验证alias唯一性
        // if (!empty($params['alias'])) {
        //     $dupliAlias = Org::where('alias', $params['alias'])->where('id', '<>', $orgId)->exists();
        //     if ($dupliAlias) {
        //         throw new AppException(
        //             ErrorCode::INVALID_PARAMS,
        //             '别名已被使用，请更改'
        //         );
        //     }
        // } elseif ($org->alias) {
        //     $params['alias'] = $org->alias;
        // } else {
        //     $params['alias'] = $this->calcuDefaultAlias($params['title'], $orgId);
        // }

        $org->title = $params['title'];
        // $org->alias = $params['alias'] ?: ''; // 不允许更新alias
        $org->logo = $params['logo'] ?: '';
        $org->desc = $params['desc'] ?: '';
        $org->save();

        return $org;
    }


    public function findOrg($orgId)
    {
        return self::where(['id' => $orgId])->first();
    }

    public function orgMember()
    {
        return $this->hasOne(OrgMember::class, 'org_id', 'id');
    }

    public function role()
    {
        return $this->hasOne(User::class, 'id', 'catetor');
    }

    public function user()
    {
        return $this->hasOne(User::class, 'id', 'creator')->select(['id', 'username', 'avatar']);
    }

    /**
     * 管理员组织
     * @return int
     */
    public static function adminOrg()
    {
        if (is_null(static::$adminOrgId)) {
            static::$adminOrgId = (int) config('app.admin_org');
        }
        return static::$adminOrgId;
    }

    /**
     * 获取别名ID.
     */
    public static function getAliasId()
    {
        $request = ApplicationContext::getContainer()->get(Request::class);
        if (array_key_exists('org_id', $request->getQueryParams())) {
            throw new AppException(ErrorCode::INVALID_PARAMS, 'parameter org_id has been renamed to org');
        }
        $input = $request->input('org', null);
        if (empty($input)) {
            $orgId = null;
        } elseif (is_numeric($input)) {
            $orgId = (int) $input;
        } else {
            $orgId = (int) Org::where('alias', $input)->value('id');
            if (!$orgId) {
                throw new AppException(ErrorCode::INVALID_PARAMS, 'invalid parameter org');
            }
        }
        Context::set('org_id', $orgId);

        return $orgId;
    }

    /**
     * 精确搜索.
     */
    public function advanceSearch($orgId, $searchOrgId, $searchOrgTitle)
    {
        $org = $this->where(function ($query) use ($searchOrgId) {
            if (is_numeric($searchOrgId)) {
                $query->where('id', (int) $searchOrgId);
            } else {
                $query->where('alias', $searchOrgId);
            }
        })->where('title', $searchOrgTitle)
            ->select('id', 'alias', 'title')
            ->first();

        return $org;
    }

    /**
     * 计算默认alias.
     */
    protected function calcuDefaultAlias($title, $orgId = null)
    {
        return Alias::calcuDefault($this, $title, $orgId);
    }
}
