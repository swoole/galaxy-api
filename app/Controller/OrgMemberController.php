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
use App\Model\User;
use App\Model\UserProfile;
use App\Model\UserSshKey;
use App\Services\OrgService;
use App\Support\Functions;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;

class OrgMemberController extends AbstractController
{
    /**
     * @Inject
     * @var OrgMember
     */
    #[Inject]
    protected $orgMember;

    /**
     * @Inject
     * @var OrgService
     */
    #[Inject]
    private $orgService;

    // 组织成员列表
    public function index()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate([
            'role' => 'integer',
            'keyword' => 'string|max:50',
            'page' => 'integer|min:1',
            'pagesize' => 'integer|min:1|max:100',
        ]);
        $params = Functions::arrNull2default($params, [
            'role' => null,
            'keyword' => null,
            'page' => 1,
            'pagesize' => 20,
        ]);

        $data = $this->orgMember->list(
            $orgId,
            $params['role'],
            $params['keyword'],
            $params['page'],
            $params['pagesize']
        );

        return $this->success($data);
    }

    public function search()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate([
            'query' => 'required|string|max:50',
        ]);
        $data = $this->orgMember->search($orgId, $params['query']);
        if (count($data) == 0) {
            return $this->success([]);
        }
        return $this->success($data);
    }

    // 添加已经注册的平台用户为组织成员
    public function create()
    {
        $orgId = Functions::getContextValue('org_id');
        $param = $this->validate([
            'role' => 'required|integer|in:0,1',
            'uid' => 'required|integer|min:1',
        ]);

        $memberUid = (int) $param['uid'];

        // 判断是否超过最大成员数
        $this->orgMember->exceedCount($orgId);

        $user = User::where('id', $memberUid)->where('status', User::STATUS_NORMAL)->first();
        if (!$user) {
            throw new AppException(
                ErrorCode::USER_NOT_EXIST,
                ErrorCode::getMessage(ErrorCode::USER_NOT_EXIST)
            );
        }

        $userProfile = UserProfile::where('uid', $memberUid)->first(['nickname']);
        $realname = trim((string) ($userProfile?->nickname ?? ''));
        if ($realname === '') {
            $realname = trim((string) $user->username) ?: trim((string) $user->email);
        }

        try {
            $member = Db::transaction(function () use ($orgId, $memberUid, $param, $realname): OrgMember {
                // Lock the organization counter so concurrent additions cannot
                // allocate the same workcode or create duplicate memberships.
                Org::where('id', $orgId)->lockForUpdate()->firstOrFail();
                if (OrgMember::where('org_id', $orgId)->where('uid', $memberUid)->exists()) {
                    throw new AppException(409, '该用户已经是当前组织成员');
                }
                $member = OrgMember::create([
                    'org_id' => $orgId,
                    'uid' => $memberUid,
                    'role' => (int) $param['role'],
                    'realname' => $realname,
                    'workcode' => $this->orgService->createWorkcode($orgId),
                    'join_at' => time(),
                ]);
                return $member;
            });
            return $this->success(['member' => $member]);
        } catch (AppException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new AppException(
                ErrorCode::CREATION_FAILED,
                ErrorCode::getMessage(ErrorCode::CREATION_FAILED),
                ['reason' => $exception->getMessage()],
                $exception
            );
        }
    }

    /** @deprecated Use POST /org/member. */
    public function invite()
    {
        return $this->create();
    }

    // 更新组织成员信息
    public function profile_upd()
    {
        $orgId = Functions::getContextValue('org_id');
        $uid = Functions::getLoginUser()->getId();
        $param = $this->validate([
            'uid' => 'integer|required',
            'realname' => 'string|max:50|required',
            'workcode' => 'string',
            'role' => 'integer|required',
        ]);

        $member = OrgMember::getOneByWhere(['org_id' => $orgId, 'uid' => $param['uid']]);
        if (! $member) {
            throw new AppException(
                ErrorCode::NOT_IN_ORGANIZATION,
                ErrorCode::getMessage(ErrorCode::NOT_IN_ORGANIZATION)
            );
        }

        Db::beginTransaction();
        try {
            // 信息修改
            $member->realname = $param['realname'] ?? '';
            $member->workcode = $param['workcode'] ?? '';
            $member->role = $param['role'] ?? '';
            $res = $member->save();

            Db::commit();
            return $this->success(['org' => $res]);
        } catch (AppException $ex) {
            Db::rollBack();
            throw $ex;
        } catch (\Throwable $ex) {
            Db::rollBack();
            throw new AppException(
                ErrorCode::FAILED_TO_UPDATE_MEMBER,
                ErrorCode::getMessage(ErrorCode::FAILED_TO_UPDATE_MEMBER)
            );
        }
    }

    // 更新组织成员角色
    public function role()
    {
        $orgId = Functions::getContextValue('org_id');
        $param = $this->validate([
            'uid' => 'integer|required',
            'role' => 'integer|required',
        ]);

        $member = OrgMember::getOneByWhere(['org_id' => $orgId, 'uid' => $param['uid']]);
        if (! $member) {
            throw new AppException(
                ErrorCode::NOT_IN_ORGANIZATION,
                ErrorCode::getMessage(ErrorCode::NOT_IN_ORGANIZATION)
            );
        }
        // 至少保留一名管理员，避免组织失控
        if ((int) $member['role'] === OrgMember::ROLE_MANAGER
            && (int) $param['role'] === OrgMember::ROLE_GENERAL
            && ! OrgMember::where('org_id', $orgId)
                ->where('role', OrgMember::ROLE_MANAGER)
                ->where('uid', '!=', $param['uid'])
                ->exists()
        ) {
            throw new AppException(422, '组织至少需要保留一名管理员');
        }

        // 角色修改
        $member->role = $param['role'] ?? '';
        $res = $member->save();
        if (! $res) {
            throw new AppException(
                ErrorCode::INVITE_FAILED,
                ErrorCode::getMessage(ErrorCode::INVITE_FAILED)
            );
        }

        return $this->success();
    }

    /**
     * 组织成员信息.
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function profile()
    {
        $orgId = Functions::getContextValue('org_id');
        $param = $this->validate([
            'uid' => 'required',
        ], [
            'uid.required' => '缺少参数 uid',
        ]);
        // 获取组织成员数据
        $memberInfo = OrgMember::where(['org_id' => $orgId, 'uid' => $param['uid']])->first();
        if (! $memberInfo) {
            return $this->success();
        }
        $data['member'] = [
            'id' => $memberInfo->uid,
            'nickname' => $memberInfo->nickname,
            'email' => $memberInfo->user->email,
            'phone' => $memberInfo->user->phone,
            'avatar' => $memberInfo->userProfile->avatar,
            'realname' => $memberInfo->realname,
            'workcode' => $memberInfo->workcode,
            'role' => $memberInfo->role,
            'join_at' => $memberInfo->join_at,
        ];
        return $this->success($data);
    }

    /**
     * 批量更新成员角色.
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function batchRole()
    {
        $orgId = Functions::getContextValue('org_id');
        $param = $this->validate([
            'uids' => 'required',
            'role' => 'required|integer|in:0,1',
        ], [
            'uids.required' => '缺少参数 uids',
            'role.required' => '缺少参数 role',
        ]);

        // 至少保留一名管理员
        $targetRole = (int) $param['role'];
        if ($targetRole === OrgMember::ROLE_GENERAL) {
            $remaining = OrgMember::where('org_id', $orgId)
                ->where('role', OrgMember::ROLE_MANAGER)
                ->whereNotIn('uid', $param['uids'])
                ->count();
            if ($remaining === 0) {
                throw new AppException(422, '组织至少需要保留一名管理员');
            }
        }

        //开启事务
        Db::beginTransaction();
        try {
            //过滤不在组织内的uid
            $uids = OrgMember::where('org_id', $orgId)->whereIn('uid', $param['uids'])->pluck('uid')->toArray();
            if ($uids) {
                OrgMember::where('org_id', $orgId)->whereIn('uid', $uids)->update(['role' => $targetRole]);
            }
            //提交事务
            Db::commit();
            //返回数据
            return $this->success();
        } catch (\Exception $e) {
            //回滚
            Db::rollBack();
            throw new AppException((int) $e->getCode(), $e->getMessage());
        }
    }

    /**
     * 移除组织成员.
     */
    public function deleteMember()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate([
            'uid' => 'required',
        ], [
            'uid.required' => '缺少参数 uid',
        ]);

        $this->orgMember->deleteMember($params['uid'], $orgId);

        return $this->success();
    }

    /**
     * 批量移除组织成员.
     */
    public function batchDeleteMember()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate([
            'uids' => 'required',
        ], [
            'uids.required' => '缺少参数 uids',
        ]);
        
        $this->orgMember->batchDeleteMember($params['uids'], $orgId);

        return $this->success();
    }
}
