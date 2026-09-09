<?php

namespace App\Services;

use App\Exception\AppException;
use App\Model\ProjectMember;
use App\Model\OrgMember;
use App\Model\GroupMember;
use Hyperf\HttpServer\Router\Router;
use Hyperf\Redis\Redis;
use Psr\Container\ContainerInterface;
use Redis as LocalRedis;

class PermissionService
{
    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @var Redis
     */
    protected $redis;

    /**
     * ID处理器
     * 
     * @var array
     */
    protected $idHandlers = [];

    /**
     * @var array
     */
    protected $labels = ['组织', '项目组', '项目'];

    /**
     * 权限Map.
     */
    protected $auths = [];

    /**
     * 解析callback.
     */
    public static function parseCallback($callback)
    {
        if (is_array($callback)) {
            return sprintf('%s@%s', $callback[0], $callback[1]);
        } elseif (is_string($callback)) {
            return $callback;
        } else {
            return null;
        }
    }

    /**
     * 解析权限配置.
     */
    public static function parsePermission($permission)
    {
        if (count($permission) > 3) {
            return array_slice($permission, -3, 3);
        }

        return $permission;
    }

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->redis = $container->get(Redis::class);

        // 初始化权限map
        $this->auths = $this->initAuths();

        $this->idHandlers = [
            [
                // index为0的为orgId
                'key' => 'getCacheKeyOrg',
                'value' => 'getOrgRole',
            ],
            [
                // index为1的为groupId
                'key' => 'getCacheKeyGroup',
                'value' => 'getGroupRole',
            ],
            [
                // index为2的为projectId
                'key' => 'getCacheKeyProject',
                'value' => 'getProjectRole',
            ],
        ];
    }

    /**
     * 获取权限验证map.
     */
    public function getAuths()
    {
        return $this->auths;
    }

    /**
     * 是否需要验证权限.
     * @return bool
     */
    public function needValid($callback)
    {
        return isset($this->auths[$callback]);
    }

    /**
     * 获取用户角色信息.
     */
    public function getRoles($uid, $orgId, $groupId = null, $projectId = null)
    {
        // 取redis缓存
        $keys = [];
        foreach ([$orgId, $groupId, $projectId] as $index => $id) {
            if (! is_null($id)) {
                $keys[] = $this->{$this->idHandlers[$index]['key']}($uid, $orgId, $groupId, $projectId);
            }
        }
        // 如果key为空，即取不到orgId等参数，则直接抛出权限错误
        if (empty($keys)) {
            throw new AppException(403, '您无权限进行此操作');
        }
        $values = $this->redis->mGet($keys);

        // 判断缓存是否有值
        $update = [];
        foreach ($values as $index => $value) {
            if ($value === false) {
                $newVal = $this->{$this->idHandlers[$index]['value']}($uid, $orgId, $groupId, $projectId);
                if (! is_null($newVal)) {
                    $update[$keys[$index]] = $newVal;
                    $values[$index] = (int) $newVal;
                } else {
                    $update[$keys[$index]] = false;
                    $values[$index] = false;
                }
            } else {
                $values[$index] = $value == -1 ? false : (int) $value;
            }
        }

        // 判断是否需要重新设置缓存
        if (! empty($update)) {
            $multi = $this->redis->multi(LocalRedis::PIPELINE);
            foreach ($update as $key => $value) {
                if ($value === false) {
                    // key不存在设为-1
                    $multi->set($key, "-1", ['ex' => 300]);
                } else {
                    $multi->set($key, $value, ['ex' => 300]);
                }
            }
            $multi->exec();
        }

        return $values;
    }

    /**
     * 验证权限.
     * 
     * @return int 1: 通过；0: 未通过；-1: 特殊权限
     */
    public function valid(array $roles, $callback)
    {
        // 未定义权限一律允许通过
        if (! isset($this->auths[$callback])) {
            return 1;
        }

        $auth = $this->auths[$callback];
        foreach ($roles as $index => $role) {
            // 用户不在组织/项目组/项目判断为未通过
            if ($role === false) {
                return 0;
            }
            if (! isset($auth[$index])) {
                continue;
            }
            if (isset($auth[$index][$role])) {
                return $auth[$index][$role];
            }
            // 所有角色走此策略
            if (isset($auth[$index]['*'])) {
                return $auth[$index]['*'];
            }
        }

        // 未匹配到认为未通过
        return 0;
    }

    /**
     * 快速验证.
     */
    public function fastValid($callback, $uid, $orgId, $groupId = null, $projectId = null)
    {
        $roles = $this->getRoles($uid, $orgId, $groupId, $projectId);

        $result = $this->valid($roles, $callback);

        return [$result, $roles];
    }

    /**
     * 快速验证并抛出异常.
     */
    public function fastValidThrow($callback, $uid, $orgId, $groupId = null, $projectId = null)
    {
        $roles = $this->getRoles($uid, $orgId, $groupId, $projectId);

        $result = $this->valid($roles, $callback);

        if ($result == 0) {
            throw new AppException(403, '您无权限进行此操作');
        }

        return [$result, $roles];
    }

    /**
     * 删除组织缓存.
     */
    public function deleteCacheOrg($uid, $orgId)
    {
        $this->redis->del($this->getCacheKeyOrg($uid, $orgId));
    }

    /**
     * 删除项目组缓存.
     */
    public function deleteCacheGroup($uid, $orgId, $groupId)
    {
        $this->redis->del($this->getCacheKeyGroup($uid, $orgId, $groupId));
    }

    /**
     * 删除项目缓存.
     */
    public function deleteCacheProject($uid, $orgId, $groupId, $projectId)
    {
        $this->redis->del($this->getCacheKeyProject($uid, $orgId, $groupId, $projectId));
    }

    /**
     * 获取组织缓存key.
     */
    protected function getCacheKeyOrg($uid, $orgId) : string
    {
        if (empty($orgId)) {
            throw new AppException(422, '缺少org参数');
        }

        return "cg.role.u{$uid}.o{$orgId}";
    }

    /**
     * 获取项目组缓存key.
     */
    protected function getCacheKeyGroup($uid, $orgId, $groupId) : string
    {
        if (empty($orgId)) {
            throw new AppException(422, '缺少org参数');
        }
        if (empty($groupId)) {
            throw new AppException(422, '缺少group参数');
        }

        return "cg.role.u{$uid}.o{$orgId}.g{$groupId}";
    }

    /**
     * 获取项目缓存key.
     */
    protected function getCacheKeyProject($uid, $orgId, $groupId, $projectId) : string
    {
        if (empty($orgId)) {
            throw new AppException(422, '缺少org参数');
        }
        if (empty($groupId)) {
            throw new AppException(422, '缺少group参数');
        }
        if (empty($projectId)) {
            throw new AppException(422, '缺少project参数');
        }

        return "cg.role.u{$uid}.o{$orgId}.g{$groupId}.p{$projectId}";
    }

    /**
     * 获取组织role.
     */
    protected function getOrgRole($uid, $orgId)
    {
        return OrgMember::where('org_id', $orgId)->where('uid', $uid)->value('role');
    }

    /**
     * 获取项目组role.
     */
    protected function getGroupRole($uid, $orgId, $groupId)
    {
        return GroupMember::where('org_id', $orgId)->where('group_id', $groupId)->where('uid', $uid)->value('role');
    }

    /**
     * 获取项目role.
     */
    protected function getProjectRole($uid, $orgId, $groupId, $projectId)
    {
        return ProjectMember::where('org_id', $orgId)
            ->where('group_id', $groupId)
            ->where('project_id', $projectId)
            ->where('uid', $uid)
            ->value('role');
    }

    /**
     * 初始化权限map.
     */
    protected function initAuths()
    {
        $auths = [];
        $routers = Router::getData();

        // 解析路径路由
        foreach ($routers[0] as $method => $methodGroups) {
            foreach ($methodGroups as $path => $router) {
                // 没有配置权限跳过
                if (empty($router->options['permission'])) {
                    continue;
                }
                // 没有可解析的callback跳过
                $callback = self::parseCallback($router->callback);
                if (is_null($callback)) {
                    continue;
                }
                $auths[$callback] = self::parsePermission($router->options['permission']);
            }
        }

        // 解析正则路由
        foreach ($routers[1] as $method => $methodGroups) {
            foreach ($methodGroups as $regexRouters) {
                foreach ($regexRouters['routeMap'] as $routerConf) {
                    $router = $routerConf[0];
                    // 没有配置权限跳过
                    if (empty($router->options['permission'])) {
                        continue;
                    }
                    // 没有可解析的callback跳过
                    $callback = self::parseCallback($router->callback);
                    if (is_null($callback)) {
                        continue;
                    }
                    $auths[$callback] = self::parsePermission($router->options['permission']);
                }
            }
        }

        return $auths;
    }
}
