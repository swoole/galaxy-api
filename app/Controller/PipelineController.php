<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Controller;

use App\Model\PipelineGithook;
use App\Services\Project\ProjectPipelineService;
use App\Services\Project\PipelineSecretService;
use App\Support\Functions;
use Hyperf\Di\Annotation\Inject;

class PipelineController extends AbstractController
{
    public function __construct(
        private ProjectPipelineService $projectPipeline,
        private PipelineSecretService $pipelineSecrets
    ) {}

    public function options()
    {
        return $this->success($this->projectPipeline->options(
            (int) Functions::getContextValue('org_id'),
            (int) Functions::getContextValue('group_id'),
            (int) Functions::getContextValue('project_id')
        ));
    }

    /**
     * @Inject
     * @var PipelineGithook
     */
    #[Inject]
    protected $pipelineGithook;

    /**
     * 新建流水线.
     */
    public function create()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $projectId = Functions::getContextValue('project_id');
        $params = $this->validate([
            'title' => 'required|string|max:100',
            'yml' => 'required|string',
            'remark' => 'string|max:200',
            'cluster_id' => 'required|integer|min:1',
        ]);
        $params = Functions::arrNull2default($params, [
            'remark' => '',
        ]);
        $uid = Functions::getLoginUser()->getId();

        $pipeline = $this->projectPipeline->create(
            $uid,
            $orgId,
            $groupId,
            $projectId,
            $params['title'],
            $params['remark'],
            $params['yml'],
            (int) $params['cluster_id']
        );

        return $this->success([
            'pipeline_id' => $pipeline->id,
        ]);
    }

    /**
     * 更新流水线.
     */
    public function update()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $projectId = Functions::getContextValue('project_id');
        $params = $this->validate([
            'pipeline_id' => 'required|integer',
            'title' => 'required|string|max:100',
            'yml' => 'required|string',
            'remark' => 'string|max:200',
            'cluster_id' => 'required|integer|min:1',
        ]);
        $params = Functions::arrNull2default($params, [
            'remark' => '',
        ]);
        $uid = Functions::getLoginUser()->getId();

        $pipeline = $this->projectPipeline->update(
            $uid,
            $params['pipeline_id'],
            $orgId,
            $groupId,
            $projectId,
            $params['title'],
            $params['remark'],
            $params['yml'],
            (int) $params['cluster_id'],
        );

        return $this->success([
            'pipeline_id' => $pipeline->id,
        ]);
    }

    /**
     * 删除流水线.
     */
    public function delete()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $projectId = Functions::getContextValue('project_id');
        $params = $this->validate([
            'pipeline_id' => 'required|integer',
        ]);
        $this->projectPipeline->delete(
            $params['pipeline_id'],
            $orgId,
            $groupId,
            $projectId
        );

        return $this->success();
    }

    /**
     * 流水线详情.
     */
    public function profile()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $projectId = Functions::getContextValue('project_id');
        $params = $this->validate([
            'pipeline_id' => 'required|integer',
        ]);

        $pipeline = $this->projectPipeline->profile(
            $params['pipeline_id'],
            $orgId,
            $groupId,
            $projectId
        );

        return $this->success([
            'pipeline' => $pipeline,
        ]);
    }

    public function secrets()
    {
        $params = $this->validate(['pipeline_id' => 'required|integer']);
        return $this->success(['secrets' => $this->pipelineSecrets->list(
            (int) Functions::getContextValue('org_id'),
            (int) Functions::getContextValue('group_id'),
            (int) Functions::getContextValue('project_id'),
            (int) $params['pipeline_id']
        )]);
    }

    public function putSecret()
    {
        $params = $this->validate([
            'pipeline_id' => 'required|integer',
            'name' => 'required|string|max:64',
            'value' => 'required|string|max:1048576',
        ]);
        return $this->success(['secrets' => $this->pipelineSecrets->put(
            (int) Functions::getLoginUser()->getId(),
            (int) Functions::getContextValue('org_id'),
            (int) Functions::getContextValue('group_id'),
            (int) Functions::getContextValue('project_id'),
            (int) $params['pipeline_id'],
            (string) $params['name'],
            (string) $params['value']
        )]);
    }

    public function deleteSecret()
    {
        $params = $this->validate([
            'pipeline_id' => 'required|integer',
            'name' => 'required|string|max:64',
        ]);
        return $this->success(['secrets' => $this->pipelineSecrets->delete(
            (int) Functions::getContextValue('org_id'),
            (int) Functions::getContextValue('group_id'),
            (int) Functions::getContextValue('project_id'),
            (int) $params['pipeline_id'],
            (string) $params['name']
        )]);
    }

    /**
     * 流水线列表.
     */
    public function list()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $projectId = Functions::getContextValue('project_id');
        $params = $this->validate([
            'keyword' => 'string',
            'page' => 'integer',
            'pagesize' => 'integer',
        ]);
        $params = Functions::arrNull2default($params, [
            'keyword' => null,
            'page' => 1,
            'pagesize' => 20,
        ]);

        $data = $this->projectPipeline->list(
            $orgId,
            $groupId,
            $projectId,
            $params['keyword'],
            (int) $params['page'],
            (int) $params['pagesize']
        );

        return $this->success($data);
    }

    /**
     * 简单流水线列表.
     */
    public function simple()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $projectId = Functions::getContextValue('project_id');
        $params = $this->validate([
            'keyword' => 'string',
        ]);
        $params = Functions::arrNull2default($params, [
            'keyword' => null,
        ]);

        $pipelines = $this->projectPipeline->simple(
            $orgId,
            $groupId,
            $projectId,
            $params['keyword']
        );

        return $this->success([
            'pipelines' => $pipelines,
        ]);
    }

    /**
     * 流水线Git钩子列表.
     */
    public function hooks()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $projectId = Functions::getContextValue('project_id');

        $hooks = $this->pipelineGithook->list(
            $orgId,
            $groupId,
            $projectId
        );
        $webhook = $this->pipelineGithook->getHookUrl(
            $orgId,
            $groupId,
            $projectId
        );

        return $this->success([
            'hooks' => $hooks,
            'webhook' => $webhook,
            'webhook_secret_configured' => $this->pipelineGithook->webhookSecretConfigured($orgId, $groupId, $projectId),
        ]);
    }

    public function rotateHookSecret()
    {
        $orgId = (int) Functions::getContextValue('org_id');
        $groupId = (int) Functions::getContextValue('group_id');
        $projectId = (int) Functions::getContextValue('project_id');
        return $this->success([
            'secret' => $this->pipelineGithook->rotateWebhookSecret($orgId, $groupId, $projectId),
        ]);
    }

    /**
     * 创建流水线Git钩子.
     */
    public function createHook()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $projectId = Functions::getContextValue('project_id');
        $params = $this->validate([
            'pipeline_id' => 'required|integer',
            'branch' => 'string|max:255',
            'status' => 'required|in:0,1',
            'auto_deploy' => 'nullable|in:0,1',
            'auto_deploy_targets' => 'nullable|array',
            'auto_deploy_targets.*' => 'integer|min:1|distinct',
        ]);
        $params = Functions::arrNull2default($params, [
            'branch' => '',
            'auto_deploy' => 0,
            'auto_deploy_targets' => [],
        ]);
        $uid = Functions::getLoginUser()->getId();

        $hook = $this->pipelineGithook->createHook(
            $uid,
            $orgId,
            $groupId,
            $projectId,
            $params['pipeline_id'],
            $params['branch'],
            $params['status'],
            (int) $params['auto_deploy'],
            (array) $params['auto_deploy_targets']
        );

        return $this->success([
            'hook' => $hook,
        ]);
    }

    /**
     * 更新流水线Git钩子.
     */
    public function updateHook()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $projectId = Functions::getContextValue('project_id');
        $params = $this->validate([
            'id' => 'required|integer',
            'pipeline_id' => 'required|integer',
            'branch' => 'string|max:255',
            'status' => 'required|in:0,1',
            'auto_deploy' => 'nullable|in:0,1',
            'auto_deploy_targets' => 'nullable|array',
            'auto_deploy_targets.*' => 'integer|min:1|distinct',
        ]);
        $params = Functions::arrNull2default($params, [
            'branch' => '',
            'auto_deploy' => 0,
            'auto_deploy_targets' => [],
        ]);
        $uid = Functions::getLoginUser()->getId();

        $hook = $this->pipelineGithook->updateHook(
            $uid,
            $params['id'],
            $orgId,
            $groupId,
            $projectId,
            $params['pipeline_id'],
            $params['branch'],
            $params['status'],
            (int) $params['auto_deploy'],
            (array) $params['auto_deploy_targets']
        );

        return $this->success([
            'hook' => $hook,
        ]);
    }

    /**
     * 删除流水线Git钩子.
     */
    public function deleteHook()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $projectId = Functions::getContextValue('project_id');
        $params = $this->validate([
            'id' => 'required|integer',
        ]);
        $uid = Functions::getLoginUser()->getId();

        $this->pipelineGithook->deleteHook(
            $uid,
            $params['id'],
            $orgId,
            $groupId,
            $projectId
        );

        return $this->success();
    }

    /**
     * 启用流水线Git钩子.
     */
    public function enableHook()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $projectId = Functions::getContextValue('project_id');
        $params = $this->validate([
            'id' => 'required|integer',
        ]);
        $uid = Functions::getLoginUser()->getId();

        $this->pipelineGithook->setStatus(
            $uid,
            $params['id'],
            $orgId,
            $groupId,
            $projectId,
            1
        );

        return $this->success();
    }

    /**
     * 禁用流水线Git钩子.
     */
    public function disableHook()
    {
        $orgId = Functions::getContextValue('org_id');
        $groupId = Functions::getContextValue('group_id');
        $projectId = Functions::getContextValue('project_id');
        $params = $this->validate([
            'id' => 'required|integer',
        ]);
        $uid = Functions::getLoginUser()->getId();

        $this->pipelineGithook->setStatus(
            $uid,
            $params['id'],
            $orgId,
            $groupId,
            $projectId,
            0
        );

        return $this->success();
    }

}
