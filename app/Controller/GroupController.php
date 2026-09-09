<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Controller;

use App\Constants\Consts;
use App\Model\Group;
use App\Model\GroupMember;
use App\Services\OrgService;
use App\Services\GroupService;
use App\Support\Functions;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Context\Context;

class GroupController extends AbstractController
{
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

    /**
     * @Inject
     */
    #[Inject]
    protected Group $group;

    /**
     * @Inject
     */
    #[Inject]
    protected GroupMember $groupMember;

    /**
     * 获取项目列表.
     */
    public function list()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate([
            'keyword' => 'nullable|string',
            'page' => 'nullable|integer|min:1',
            'pagesize' => 'nullable|integer|min:1|max:100',
        ]);
        $params = Functions::arrNull2default($params, [
            'keyword' => null,
            'page' => 1,
            'pagesize' => 20,
        ]);
        $uid = Functions::getLoginUser()->getId();

        $data = $this->group->list(
            $uid,
            $orgId,
            $params['keyword'],
            $params['page'],
            $params['pagesize']
        );

        return $this->success($data);
    }

    /**
     * 简单列表.
     */
    public function simpleList()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate([
            'keyword' => 'nullable|string',
            'require_write' => 'nullable|integer|in:0,1',
        ]);
        $params = Functions::arrNull2default($params, [
            'keyword' => null,
            'require_write' => 0,
        ]);
        $uid = Functions::getLoginUser()->getId();
        
        $groups = $this->group->simpleList(
            $uid,
            $orgId,
            $params['keyword'],
            (bool) $params['require_write']
        );

        return $this->success([
            'groups' => $groups,
        ]);
    }

    /**
     * 创建项目组.
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function createGroup()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate(
            [
                'title' => 'required|string|max:50',
                'desc' => 'string|max:200',
                'alias' => 'nullable|string|max:50|regex:' . Consts::ALIAS_REGEX,
            ],
            [
                'title.required' => '请输入项目组名称',
                'title.max' => '项目组名称长度1-50个字符',
                'desc.max' => '项目组描述最多200个字符',
            ]
        );
        $params = Functions::arrNull2default($params, [
            'desc' => '',
            'alias' => '',
        ]);
        $uid = Functions::getLoginUser()->getId();
        
        $group = $this->group->createGroup($uid, $orgId, $params);

        return $this->success([
            'group' => $group,
        ]);
    }

    /**
     * 更新项目组信息.
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function updateGroup()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $params = $this->validate(
            [
                'title' => 'required|string|max:50',
                'desc' => 'string|max:200',
                'alias' => 'nullable|string|max:50|regex:' . Consts::ALIAS_REGEX,
            ],
            [
                'title.required' => '请输入项目组名称',
                'title.max' => '项目组名称长度1-50个字符',
                'desc.max' => '项目组描述最多200个字符',
            ]
        );
        $params = Functions::arrNull2default($params, [
            'desc' => '',
            'alias' => '',
        ]);
        $uid = Functions::getLoginUser()->getId();

        $group = $this->group->updateGroup($uid, $orgId, $groupId, $params);

        return $this->success([
            'group' => $group,
        ]);
    }

    /**
     * 查看项目组详情.
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function detailGroup()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        
        $group = $this->group->profile($orgId, $groupId);

        return $this->success([
            'group' => $group,
        ]);
    }

    /**
     * 退出项目组.
     */
    public function exitGroup()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        
        $uid = Functions::getLoginUser()->getId();

        // 退出项目组
        $this->groupMember->exitGroup($uid, $orgId, $groupId);
       
        return $this->success();
    }

    /**
     * 删除项目组.
     */
    public function deleteGroup()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');

        $uid = Functions::getLoginUser()->getId();

        $this->group->deleteGroup($uid, $orgId, $groupId);

        return $this->success();
    }
}
