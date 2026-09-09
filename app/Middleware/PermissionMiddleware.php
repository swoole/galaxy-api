<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Middleware;

use App\Constants\ErrorCode;
use App\Exception\AppException;
use App\Model\Org;
use App\Model\Project;
use App\Services\Project\ProjectMutationLock;
use App\Services\PermissionService;
use App\Support\Functions;
use FastRoute\Dispatcher;
use Hyperf\HttpServer\Router\Dispatched;
use Hyperf\Context\Context;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class PermissionMiddleware implements MiddlewareInterface
{
    /**
     * @var ContainerInterface
     */
    protected $container;

    protected PermissionService $permissionService;

    protected ProjectMutationLock $projectMutationLock;

    /**
     * 组织认证白名单.
     * 
     * @var array
     */
    protected $authBlacklist = [
        'App\Controller\OrgController@profile' => 1,
        'App\Controller\OrgController@updateProfile' => 1,
        'App\Controller\OrgController@simpleprofile' => 1,
        'App\Controller\OrgController@exit' => 1,
        'App\Controller\OrgController@switch' => 1,
        'App\Controller\OrgMemberController' => 1,
    ];

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->permissionService = $container->get(PermissionService::class);
        $this->projectMutationLock = $container->get(ProjectMutationLock::class);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var Dispatched */
        $dispatched = $request->getAttribute(Dispatched::class);

        // 路由未找到不进行权限处理
        if ($dispatched->status != Dispatcher::FOUND) {
            return $handler->handle($request);
        }

        // 公网 URL 作用域参数不暴露数据库列名。旧参数必须显式拒绝，
        // 避免它们被静默忽略后意外放大查询作用域。
        $query = $request->getQueryParams();
        foreach (['org_id' => 'org', 'group_id' => 'group', 'project_id' => 'project'] as $legacy => $public) {
            if (array_key_exists($legacy, $query)) {
                throw new AppException(
                    ErrorCode::INVALID_PARAMS,
                    sprintf('parameter %s has been renamed to %s', $legacy, $public)
                );
            }
        }

        // 不需要权限验证，跳过
        $callback = PermissionService::parseCallback($dispatched->handler->callback);
        if (is_null($callback) || !$this->permissionService->needValid($callback)) {
            return $handler->handle($request);
        }

        // 权限验证
        $uid = Functions::getLoginUser()->getId();

        $orgId = Functions::getContextValue('org_id', false, null);
        $groupId = Functions::getContextValue('group_id', false, null);
        $projectId = Functions::getContextValue('project_id', false, null);

        [$result, $roles] = $this->permissionService->fastValidThrow($callback, $uid, $orgId, $groupId, $projectId);

        // 设置Context
        Context::set('roles', $roles);

        $method = strtoupper($request->getMethod());
        if ($projectId !== null && (int) $projectId > 0 && ! in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $this->projectMutationLock->synchronized(
                (int) $orgId,
                (int) $projectId,
                function () use ($request, $handler, $orgId, $groupId, $projectId): ResponseInterface {
                    if (! Project::where('id', (int) $projectId)->where('org_id', (int) $orgId)
                        ->where('group_id', (int) $groupId)->exists()) {
                        throw new AppException(404, '项目不存在或已被删除');
                    }
                    return $handler->handle($request);
                }
            );
        }

        return $handler->handle($request);
    }
}
