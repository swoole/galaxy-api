<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Controller;

use App\Constants\Consts;
use App\Constants\ErrorCode;
use App\Exception\AppException;
use App\Model\Project;
use App\Model\Env;
use App\Model\Org;
use App\Model\OrgMember;
use App\Model\ProjectRuntime;
use App\Model\Group;
use App\Model\User;
use App\Services\FlysystemService;
use App\Services\OrgService;
use App\Services\GroupService;
use App\Support\Functions;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Collection\Arr;
use Throwable;

class OrgController extends AbstractController
{
    #[Inject]
    protected GroupService $groupService;

    #[Inject]
    protected FlysystemService $flysystemService;

    #[Inject]
    protected OrgService $orgService;

    /**
     * @Inject
     * @var Org
     */
    #[Inject]
    protected $org;

    /**
     * @Inject
     * @var OrgMember
     */
    #[Inject]
    protected $orgMember;

    /**
     * 切换组织.
     */
    public function switch()
    {
        $orgId = Functions::getContextValue('org_id');
        /** @var User */
        $user = Functions::getLoginUser();

        // 切换登录用户组织
        $user->last_org = $orgId;
        $res = $user->save();
        if (! $res) {
            throw new AppException(
                ErrorCode::ORG_SWITCH_ERROR,
                ErrorCode::getMessage(ErrorCode::ORG_SWITCH_ERROR)
            );
        }
        return $this->success();
    }

    /**
     * 组织列表.
     */
    public function index()
    {
        $uid = Functions::getLoginUser()->getId();

        $orgs = $this->org->list($uid);

        return $this->success([
            'orgs' => $orgs,
        ]);
    }

    /**
     * 创建组织属性.
     */
    public function createProps()
    {
        $uid = Functions::getLoginUser()->getId();

        $props = $this->org->createProps($uid);

        return $this->success($props);
    }

    /**
     * 创建组织.
     */
    public function create()
    {
        $message = [
            'title.required' => '请填写组织名称',
            'title.string' => '组织名称不合法',
            'title.max' => '组织名称最大长度为50',
            'logo.required' => '请上传组织LOGO',
            'logo.string' => 'LOGO url不合法',
            'logo.max' => 'LOGO url最大长度为500',
            'desc.string' => '组织描述不合法',
            'desc.max' => '组织描述最大长度为500',
        ];
        $rules = [
            'title' => 'required|string|max:50',
            'logo' => 'required|string|max:500',
            'desc' => 'nullable|string|max:500',
            'alias' => 'nullable|string|max:50|regex:' . Consts::ALIAS_REGEX,
        ];
        $params = $this->validate($rules, $message);
        $params = Functions::arrNull2default($params, [
            'logo' => '',
            'desc' => '',
            'alias' => '',
        ]);
        // The persisted column remains for schema compatibility; organization
        // type no longer changes behavior and all new organizations use one
        // canonical value.
        $params['type'] = Org::TYPE_COMPANY;
        $uid = Functions::getLoginUser()->getId();

        $org = $this->org->createOrg($uid, $params);
        $org->setVisible(['id', 'logo', 'title', 'alias', 'desc']);

        return $this->success([
            'org' => $org,
        ]);
    }

    // logo 上传
    public function logoUpload(\App\Services\ObjectStorageService $storageService)
    {
        $user_id = Functions::getLoginUser()->getId();
        $orgId = (int) Functions::getContextValue('org_id');

        $file = $this->request->file('org_logo');

        $this->validateAll(['org_logo' => 'file|image'], [], ['org_logo' => $file]);

        $logo_path = '/uploads/logos/';
        // 生成用户uid相关的文件名
        $filename = md5(uniqid().$user_id) . '.' . $file->getExtension();
        $filepath = $logo_path . $filename;

        $resolved = $storageService->resolveForOrg($orgId);
        $stream = fopen($file->getRealPath(), 'r+');
        $resolved['filesystem']->writeStream($filepath, $stream);
        fclose($stream);

        $logo = $resolved['base_url'] . $filepath;
        return $this->success(['logo' => $logo]);
    }

    /**
     * 组织详情.
     */
    public function profile()
    {
        $orgId = Functions::getContextValue('org_id');
        
        $org = $this->org->profile($orgId);
        
        return $this->success([
            'org' => $org,
        ]);
    }

    /**
     * 组织简单信息.
     */
    public function simpleprofile()
    {
        $orgId = Functions::getContextValue('org_id');

        $org = $this->org->simpleProfile($orgId);

        return $this->success([
            'org' => $org,
        ]);
    }

    // 组织信息更新
    public function updateProfile()
    {
        $orgId = Functions::getContextValue('org_id');

        $message = [
            'title.required' => '请填写组织名称',
            'title.string' => '组织名称不合法',
            'title.max' => '组织名称最大长度为50',
            'logo.required' => '请上传组织LOGO',
            'logo.string' => 'LOGO url不合法',
            'logo.max' => 'LOGO url最大长度为500',
            'desc.string' => '组织描述不合法',
            'desc.max' => '组织描述最大长度为500',
        ];
        $rules = [
            'title' => 'required|string|max:50',
            'logo' => 'required|string|max:500',
            'desc' => 'nullable|string|max:500',
            'alias' => 'nullable|string|max:50|regex:' . Consts::ALIAS_REGEX,
        ];
        $params = $this->validate($rules, $message);
        $params = Functions::arrNull2default($params, [
            'desc' => null,
            'alias' => null,
            'logo' => null,
        ]);
        $uid = Functions::getLoginUser()->getId();

        $org = $this->org->updateProfile($uid, $orgId, $params);
        
        return $this->success([
            'org' => $org,
        ]);
    }

    /**
     * 退出组织.
     */
    public function exit()
    {
        $orgId = Functions::getContextValue('org_id');
        $uid = Functions::getLoginUser()->getId();

        $this->orgMember->exitOrg($uid, $orgId);

        return $this->success();
    }

    /**
     * 简单组织列表.
     */
    public function simple()
    {
        $uid = Functions::getLoginUser()->getId();

        $orgs = $this->org->simpleList($uid);
   
        return $this->success([
            'orgs' => $orgs,
        ]);
    }

    public function getNames()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate([
            'group_list' => 'nullable|array',
            'project_list' => 'nullable|array',
            'env_list' => 'nullable|array',
            'instance_list' => 'nullable|array',
        ]);
        $params = Functions::arrNull2default($params, [
            'group_list' => [],
            'project_list' => [],
            'env_list' => [],
            'instance_list' => [],
        ]);

        return $this->success([
            'group_list' => Group::query()->whereIn('id', $params['group_list'])->where(['org_id' => $orgId])->select('id', 'title', 'alias')->get()->toArray(),
            'project_list' => Project::query()->whereIn('id', $params['project_list'])->where(['org_id' => $orgId])->select('id', 'title', 'alias')->get()->toArray(),
            'env_list' => Env::query()->whereIn('id', $params['env_list'])->where(['org_id' => $orgId])->select('id', 'title')->get()->toArray(),
            'instance_list' => ProjectRuntime::query()->whereIn('id', $params['instance_list'])->where(['org_id' => $orgId])->select('id', 'name')->get()->map(static function ($runtime): array {
                return ['id' => (int) $runtime->id, 'group' => (string) $runtime->name];
            })->all(),
        ]);
    }

    /**
     * 精确搜索.
     */
    public function advanceSearch()
    {
        $orgId = Functions::getContextValue('org_id');
        $params = $this->validate([
            'search_org_id' => 'required',
            'search_org_title' => 'required|string',
        ]);

        $org = $this->org->advanceSearch(
            $orgId,
            $params['search_org_id'],
            $params['search_org_title']
        );

        return $this->success([
            'org' => $org,
        ]);
    }
}
