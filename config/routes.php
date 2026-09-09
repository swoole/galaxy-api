<?php

declare(strict_types=1);
/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
use App\Model\ProjectMember;
use App\Model\OrgMember;
use App\Model\GroupMember;
use Hyperf\HttpServer\Router\Router;
use App\Server\SwarmTerminalServer;
use App\Server\DockerAgentServer;
use App\Server\KubernetesTerminalServer;

Router::get('/favicon.ico', function () {
    return '';
});

// WebSocket 握手由 Hyperf WebSocket Server 路由到终端处理器。
Router::addServer('http', function () {
    Router::get('/cluster/swarm/terminal', SwarmTerminalServer::class);
    Router::get('/agent/connect', DockerAgentServer::class);
    Router::get('/cluster/k8s/terminal', KubernetesTerminalServer::class);
});

Router::get('/healthz', 'App\Controller\HealthzController@healthz'); //获取图形验证码
// Only the co-located SSH relay may call these endpoints. They deliberately do
// not use browser login middleware and require SSH_RELAY_INTERNAL_TOKEN instead.
Router::post('/internal/ssh/authenticate', 'App\Controller\InternalSshRelayController@authenticate');
Router::post('/internal/ssh/terminal-session', 'App\Controller\InternalSshRelayController@terminalSession');
Router::get('/captcha', 'App\Controller\CommonController@captcha'); //获取图形验证码
Router::get('/captcha/slider', 'App\Controller\CommonController@sliderCaptcha'); //获取滑动验证码挑战
Router::post('/captcha/slider/verify', 'App\Controller\CommonController@verifySliderCaptcha'); //校验滑动验证码
Router::post('/login/code/send', 'App\Controller\CommonController@getAccountCode'); //获取邮箱验证码
Router::post('/forgetpassword', 'App\Controller\CommonController@forgetpassword'); //忘记密码-重置密码
Router::post('/cli/upgrade', 'App\Controller\CliAppController@upgrade'); // 升级cli工具
Router::post('/cli/create_version', 'App\Controller\CliAppController@createVersion'); // 创建版本
Router::get('/cli/get_latest', 'App\Controller\CliAppController@getLatest'); // 获取最新版本
Router::get('/cli/download', 'App\Controller\CliAppController@download'); // 直接下载版本

//设置header token
Router::addGroup('', function () {
    Router::post('/register', 'App\Controller\LoginController@register'); //用户注册
    Router::post('/register/emailcode/send', 'App\Controller\LoginController@sendRegisterEmailCode'); //发送注册邮件验证码
    Router::post('/login', 'App\Controller\LoginController@pwdLogin'); //账号密码登录
    Router::post('/cli/login', 'App\Controller\LoginController@cliLogin'); //cli客户端登录
}, ['middleware' => [\App\Middleware\SetHeaderTokenMiddleware::class]]);

//登录校验路由
Router::addGroup('', function () {
    Router::addRoute(['GET', 'POST'], '/logout', 'App\Controller\LoginController@logout'); //退出登录
    // 账户相关接口
    Router::addGroup('/account/', function () {
        Router::get('idauth/status', 'App\Controller\AccountController@getStatus'); //获取身份校验状态
        Router::post('idauth/password', 'App\Controller\AccountController@pwdAuth'); //密码身份校验
        Router::post('emailcode/send', 'App\Controller\AccountController@sendEmailCode'); //发送邮件验证码
        Router::post('idauth/emailcode', 'App\Controller\AccountController@emailCodeAuth'); //邮件验证码身份校验
        Router::post('newemailcode/send', 'App\Controller\AccountController@newEmailCode'); //发送新邮箱验证码
        Router::post('idauth/newemailcode', 'App\Controller\AccountController@newEmailCodeAuth'); //新邮箱验证码校验-修改邮箱
        Router::post('resetpassword', 'App\Controller\AccountController@resetPwd'); //设置密码
        Router::get('gitauth', 'App\Controller\AccountController@gitAuths'); // Git授权列表
        Router::post('gitauth', 'App\Controller\AccountController@createGitAuth'); // 创建Git授权
        Router::put('gitauth', 'App\Controller\AccountController@updateGitAuth'); // 更新Git授权
        Router::delete('gitauth', 'App\Controller\AccountController@removeGitAuth'); // 删除Git授权
        Router::post('checkgitrepoauth', 'App\Controller\AccountController@checkGitRepoAuth'); // 检查Git授权
        Router::post('idconfirm', 'App\Controller\AccountController@idConfirm'); // 身份验证
    });

    // 通知相关接口
    Router::addGroup('/notify', function () {
        Router::get('/channels', 'App\Controller\NotifyController@channels'); // 通知渠道
        Router::get('', 'App\Controller\NotifyController@list'); // 通知列表
        Router::get('/simple', 'App\Controller\NotifyController@simpleList'); // 简单列表
        Router::get('/profile', 'App\Controller\NotifyController@profile'); // 通知详情
        Router::get('/next', 'App\Controller\NotifyController@next'); // 下一条消息
        Router::get('/prev', 'App\Controller\NotifyController@prev'); // 上一条消息
        Router::post('/setread', 'App\Controller\NotifyController@setRead'); // 标为已读
        Router::post('/setreadall', 'App\Controller\NotifyController@setReadAll'); // 全部标为已读
        Router::delete('', 'App\Controller\NotifyController@delete'); // 删除消息
        Router::get('/listen', 'App\Controller\NotifyController@listen'); // 监听消息
    });

    // 项目组信息
    Router::addGroup('/group', function () {
        Router::get('', 'App\Controller\GroupController@list', [
            'permission' => [
                ['*' => 1],
            ],
        ]); // 项目组列表
        Router::post('', 'App\Controller\GroupController@createGroup', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
            ],
        ]); // 创建项目组
        Router::put('', 'App\Controller\GroupController@updateGroup', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
            ],
        ]); // 更新项目组信息
        Router::get('/profile', 'App\Controller\GroupController@detailGroup', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                ['*' => 1],
            ],
        ]); // 项目组详情
        Router::post('/exit', 'App\Controller\GroupController@exitGroup', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                ['*' => 1],
            ],
        ]); // 退出项目组
        Router::delete('', 'App\Controller\GroupController@deleteGroup', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
            ],
        ]); // 删除项目组
        Router::get('/simple', 'App\Controller\GroupController@simpleList', [
            'permission' => [
                ['*' => 1],
            ],
        ]); // 简单项目组列表
        Router::get('/sshkey', 'App\Controller\GroupSshKeyController@profile', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                ['*' => 1],
            ],
        ]); // 项目组平台 SSH 公钥
        Router::post('/sshkey/reset', 'App\Controller\GroupSshKeyController@reset', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
            ],
        ]); // 重置项目组平台 SSH 密钥
    });

    // 项目组成员
    Router::addGroup('/group/member', function () {
        Router::get('', 'App\Controller\GroupMemberController@list', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                ['*' => 1],
                [], // 覆写必须长度为3，否则因路由自带的属性合并可能导致bug
            ],
        ]); // 项目组成员列表
        Router::post('', 'App\Controller\GroupMemberController@createMember'); // 新增项目组成员
        Router::put('/role', 'App\Controller\GroupMemberController@updateMember'); // 更新项目组成员角色
        Router::put('/batchrole', 'App\Controller\GroupMemberController@batchUpdateMember'); // 批量更新项目组成员角色
        Router::delete('', 'App\Controller\GroupMemberController@deleteMember'); // 移除成员
        Router::delete('/batch', 'App\Controller\GroupMemberController@batchDeleteMember'); // 批量移除成员
        Router::get('/searchfromorg', 'App\Controller\GroupMemberController@searchfromorg'); // 从组织成员搜索
    }, [
        'permission' => [
            [OrgMember::ROLE_MANAGER => 1],
            [GroupMember::ROLE_DIRECTOR => 1],
        ],
    ]);

    // 流水线管理
    Router::addGroup('/pipeline', function () {
        Router::get('/options', 'App\Controller\PipelineController@options', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
                ['*' => 1],
            ],
        ]);
        Router::get('/profile', 'App\Controller\PipelineController@profile', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
                ['*' => 1],
            ],
        ]); // 流水线详情
        Router::get('/secrets', 'App\Controller\PipelineController@secrets', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
                ['*' => 1],
            ],
        ]); // 仅返回构建 Secret 名称和配置状态
        Router::get('', 'App\Controller\PipelineController@list', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
                ['*' => 1],
            ],
        ]); // 流水线列表
        Router::get('/hook', 'App\Controller\PipelineController@hooks', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
                ['*' => 1],
            ],
        ]); // 流水线Git钩子列表
        Router::get('/simple', 'App\Controller\PipelineController@simple', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
                ['*' => 1],
            ],
        ]); // 简单流水线列表
        Router::post('', 'App\Controller\PipelineController@create'); // 新建流水线
        Router::put('', 'App\Controller\PipelineController@update'); // 更新流水线
        Router::delete('', 'App\Controller\PipelineController@delete'); // 删除流水线
        Router::put('/secrets', 'App\Controller\PipelineController@putSecret'); // 新增或轮换构建 Secret
        Router::delete('/secrets', 'App\Controller\PipelineController@deleteSecret'); // 删除构建 Secret
        Router::post('/hook', 'App\Controller\PipelineController@createHook'); // 创建流水线Git钩子
        Router::put('/hook', 'App\Controller\PipelineController@updateHook'); // 更新流水线Git钩子
        Router::delete('/hook', 'App\Controller\PipelineController@deleteHook'); // 删除流水线Git钩子
        Router::put('/hook/enable', 'App\Controller\PipelineController@enableHook'); // 启用流水线Git钩子
        Router::put('/hook/disable', 'App\Controller\PipelineController@disableHook'); // 禁用流水线Git钩子
        Router::post('/hook/secret/rotate', 'App\Controller\PipelineController@rotateHookSecret'); // 轮换 webhook secret
    }, [
        'permission' => [
            [OrgMember::ROLE_MANAGER => 1],
            [GroupMember::ROLE_DIRECTOR => 1],
            [ProjectMember::ROLE_DIRECTOR => 1],
        ],
    ]);

    // 个人信息
    Router::addGroup('/user/', function () {
        Router::get('sshkey', 'App\Controller\UserController@sshkey'); // 个人 SSH 密钥对公钥
        Router::post('sshkey/reset', 'App\Controller\UserController@sshkeyReset'); // 重置个人 SSH 密钥对
        Router::get('profile', 'App\Controller\UserController@profile'); // 用户信息详细
        Router::put('profile', 'App\Controller\UserController@profile'); // 用户信息更新
        Router::get('simpleprofile', 'App\Controller\UserController@simpleprofile'); // 用户简要信息
        Router::post('avatar/upload', 'App\Controller\UserController@avatarUpload'); // 头像上传
        Router::get('personal/sshkeys', 'App\Controller\UserController@personalSshKeys'); // 私人公钥列表
        Router::post('personal/sshkey', 'App\Controller\UserController@createPersonalSshKey'); // 创建私人公钥
        Router::delete('personal/sshkey', 'App\Controller\UserController@deletePersonalSshKey'); // 删除私人公钥
        Router::get('personal/sshkey', 'App\Controller\UserController@profilePersonalSshKey'); // 私人公钥详情
        Router::get('login/history', 'App\Controller\UserController@loginHistory'); // 登录日志
    });

    // 组织信息
    Router::addGroup('/org', function () {
        Router::post('/switch', 'App\Controller\OrgController@switch', [
            'permission' => [
                ['*' => 1],
            ],
        ]); // 切换组织
        Router::get('', 'App\Controller\OrgController@index'); // 组织列表
        Router::get('/createprops', 'App\Controller\OrgController@createProps'); // 获取创建组织属性
        Router::post('', 'App\Controller\OrgController@create'); // 创建组织
        Router::get('/simple', 'App\Controller\OrgController@simple'); // 简单组织列表
        Router::post('/logo/upload', 'App\Controller\OrgController@logoUpload'); // 组织LOGO上传
        Router::get('/profile', 'App\Controller\OrgController@profile', [
            'permission' => [
                ['*' => 1],
            ],
        ]); // 组织详情
        Router::put('/profile', 'App\Controller\OrgController@updateProfile', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
            ],
        ]); // 组织信息更新
        Router::get('/simpleprofile', 'App\Controller\OrgController@simpleprofile', [
            'permission' => [
                ['*' => 1],
            ],
        ]); // 组织简要信息
        Router::post('/exit', 'App\Controller\OrgController@exit'); // 退出组织
        // 获取组织内所有项目、项目组、环境、实例的名字
        Router::get('/names', 'App\Controller\OrgController@getNames', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
            ],
        ]);
        Router::get('/search/advance', 'App\Controller\OrgController@advanceSearch', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
            ],
        ]); // 精确搜索
    });

    // 组织成员
    Router::addGroup('/org/member', function () {
        Router::get('', 'App\Controller\OrgMemberController@index', [
            'permission' => [
                ['*' => 1],
                [],
                [],
            ],
        ]); // 组织成员列表：任意组织成员可读
        Router::post('', 'App\Controller\OrgMemberController@create'); // 添加组织成员
        Router::post('/invite', 'App\Controller\OrgMemberController@invite'); // 兼容旧版客户端
        Router::put('/profile', 'App\Controller\OrgMemberController@profile_upd'); // 更新组织成员信息
        Router::put('/role', 'App\Controller\OrgMemberController@role'); // 更新组织成员角色
        Router::get('/profile', 'App\Controller\OrgMemberController@profile', [
            'permission' => [
                ['*' => 1],
                [],
                [],
            ],
        ]); // 组织成员信息：任意组织成员可读
        Router::put('/batch-role', 'App\Controller\OrgMemberController@batchRole'); // 批量更新成员角色
        Router::delete('', 'App\Controller\OrgMemberController@deleteMember'); // 移除组织成员
        Router::delete('/batch', 'App\Controller\OrgMemberController@batchDeleteMember'); // 批量移除组织成员
        Router::get('/search', 'App\Controller\OrgMemberController@search'); // 搜索用户
    }, [
        'permission' => [
            [OrgMember::ROLE_MANAGER => 1],
        ],
    ]);

    Router::addGroup('/dockerfile-template', function () {
        Router::get('/catalog', 'App\Controller\DockerfileTemplateController@catalog');
        Router::get('/profile', 'App\Controller\DockerfileTemplateController@template');
        Router::post('/render', 'App\Controller\DockerfileTemplateController@render');
        Router::post('/validate', 'App\Controller\DockerfileTemplateController@validateTemplate');
    });

    // 用户在项目组内的独立开发环境，不属于单个项目。
    Router::addGroup('/workspace', function () {
        Router::get('/list', 'App\Controller\WorkspaceController@list', [
            'permission' => [['*' => 1]],
        ]);
        Router::get('/options', 'App\Controller\WorkspaceController@options', [
            'permission' => [['*' => 1], ['*' => 1]],
        ]);
        Router::get('', 'App\Controller\WorkspaceController@profile', [
            'permission' => [['*' => 1], ['*' => 1]],
        ]);
        Router::post('', 'App\Controller\WorkspaceController@create', [
            'permission' => [['*' => 1], ['*' => 1]],
        ]);
        Router::put('/state', 'App\Controller\WorkspaceController@state', [
            'permission' => [['*' => 1], ['*' => 1]],
        ]);
        Router::delete('', 'App\Controller\WorkspaceController@delete', [
            'permission' => [['*' => 1], ['*' => 1]],
        ]);
        Router::post('/access', 'App\Controller\WorkspaceController@access', [
            'permission' => [['*' => 1], ['*' => 1]],
        ]);
        Router::post('/terminal', 'App\Controller\WorkspaceController@terminal', [
            'permission' => [['*' => 1], ['*' => 1]],
        ]);
        Router::get('/project', 'App\Controller\WorkspaceProjectRepositoryController@profile', [
            'permission' => [['*' => 1], ['*' => 1], ['*' => 1]],
        ]);
        Router::post('/project/open', 'App\Controller\WorkspaceProjectRepositoryController@open', [
            'permission' => [['*' => 1], ['*' => 1], ['*' => 1]],
        ]);
    });

    // 项目信息
    Router::addGroup('/project', function () {
        Router::get('', 'App\Controller\ProjectController@list', [
            'permission' => [
                ['*' => 1],
                ['*' => 1],
                ['*' => 1],
            ],
        ]); // 项目列表
        Router::post('', 'App\Controller\ProjectController@createProject', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
                [],
            ],
        ]); // 创建项目
        Router::put('', 'App\Controller\ProjectController@updateProject', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
                [ProjectMember::ROLE_DIRECTOR => 1],
            ],
        ]); // 更新项目
        Router::get('/profile', 'App\Controller\ProjectController@profile'); // 项目详情
        Router::get('/basic', 'App\Controller\ProjectController@basicInfo'); // 项目简单信息
        Router::post('/exit', 'App\Controller\ProjectController@exitProject'); // 退出项目
        Router::get('/deleteoverview', 'App\Controller\ProjectController@getDeleteOverview', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
                [ProjectMember::ROLE_DIRECTOR => 1],
            ],
        ]); // 获取删除项目资源概览
        Router::delete('', 'App\Controller\ProjectController@delete', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
                [ProjectMember::ROLE_DIRECTOR => 1],
            ],
        ]); // 删除项目
        Router::get('/overview', 'App\Controller\ProjectController@overview'); // 概览
        Router::get('/overview/source', 'App\Controller\ProjectController@overviewSource'); // 概览源码统计
        Router::get('/simple', 'App\Controller\ProjectController@simpleList'); // 简单列表
        Router::get('/registries', 'App\Controller\ProjectController@registries'); // 简单列表
        Router::get('/registry/catalog', 'App\Controller\ProjectController@registryCatalog');
        Router::get('/registry/tags', 'App\Controller\ProjectController@registryTags');
        Router::get('/import/sources', 'App\Controller\ProjectController@importSources');
        Router::post('/import/source-profile', 'App\Controller\ProjectController@importSourceProfile');
        Router::get('/repository', 'App\Controller\ProjectController@repository'); // 项目源码仓库
        Router::get('/build-profile', 'App\Controller\DockerfileTemplateController@profile');
        Router::get('/build-profile/revisions', 'App\Controller\DockerfileTemplateController@revisions');
        Router::post('/build-profile/upgrade-preview', 'App\Controller\DockerfileTemplateController@upgradePreview');
        Router::post('/build-profile/rollback', 'App\Controller\DockerfileTemplateController@rollback', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
                [ProjectMember::ROLE_DIRECTOR => 1],
            ],
        ]);
        Router::put('/build-profile', 'App\Controller\DockerfileTemplateController@save', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
                [ProjectMember::ROLE_DIRECTOR => 1],
            ],
        ]);
        Router::get('/governance/audit', 'App\Controller\ProjectGovernanceController@audit', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
                [ProjectMember::ROLE_DIRECTOR => 1],
            ],
        ]);
        Router::get('/governance/retention', 'App\Controller\ProjectGovernanceController@policy', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
                [ProjectMember::ROLE_DIRECTOR => 1],
            ],
        ]);
        Router::put('/governance/retention', 'App\Controller\ProjectGovernanceController@savePolicy', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
                [ProjectMember::ROLE_DIRECTOR => 1],
            ],
        ]);
    }, [
        'permission' => [
            [OrgMember::ROLE_MANAGER => 1],
            [GroupMember::ROLE_DIRECTOR => 1],
            ['*' => 1],
        ],
    ]);

    // 项目信息-无权限验证
    Router::addGroup('/project', function () {
        Router::get('/createprops', 'App\Controller\ProjectController@getCreateProps'); // 获取项目创建/编辑特殊属性
    });

    // 项目成员
    Router::addGroup('/project/member', function () {
        Router::get('', 'App\Controller\ProjectMemberController@list', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
                ['*' => 1],
            ],
        ]); // 成员列表
        Router::post('', 'App\Controller\ProjectMemberController@create'); // 添加成员
        Router::put('/role', 'App\Controller\ProjectMemberController@update'); // 更新成员角色
        Router::put('/batchrole', 'App\Controller\ProjectMemberController@batchUpdate'); // 批量更新成员角色
        Router::delete('', 'App\Controller\ProjectMemberController@delete'); // 移除成员
        Router::delete('/batch', 'App\Controller\ProjectMemberController@batchDelete'); // 批量移除成员
        Router::get('/searchfromgroup', 'App\Controller\ProjectMemberController@search'); // 从项目组成员中搜索
    }, [
        'permission' => [
            [OrgMember::ROLE_MANAGER => 1],
            [GroupMember::ROLE_DIRECTOR => 1],
            [ProjectMember::ROLE_DIRECTOR => 1],
        ],
    ]);

    // 集群管理
    Router::addGroup('/cluster', function () {
        $projectScoped = [
            [OrgMember::ROLE_MANAGER => 1],
            [GroupMember::ROLE_DIRECTOR => 1],
            ['*' => 1],
        ];
        $projectOperation = [
            [OrgMember::ROLE_MANAGER => 1],
            [GroupMember::ROLE_DIRECTOR => 1],
            ['*' => 1],
        ];
        // Provider resource modules use independent namespaces. Project
        // deployment still only enables providers with an installed runtime driver.
        Router::get('/k8s', 'App\Controller\Cluster\KubernetesClusterController@index');
        Router::post('/k8s', 'App\Controller\Cluster\KubernetesClusterController@create');
        Router::put('/k8s/credential', 'App\Controller\Cluster\KubernetesClusterController@updateCredential');
        Router::delete('/k8s', 'App\Controller\Cluster\KubernetesClusterController@delete');
        Router::get('/k8s/profile', 'App\Controller\Cluster\KubernetesClusterController@profile');
        Router::put('/k8s/ingress-settings', 'App\Controller\Cluster\KubernetesClusterController@updateIngressPorts');
        Router::get('/k8s/check', 'App\Controller\Cluster\KubernetesClusterController@check');
        Router::get('/k8s/overview', 'App\Controller\Cluster\KubernetesClusterController@overview');
        Router::get('/k8s/nodes', 'App\Controller\Cluster\KubernetesClusterController@nodes');
        Router::get('/k8s/nodes/detail', 'App\Controller\Cluster\KubernetesClusterController@nodeDetail');
        Router::get('/k8s/namespaces', 'App\Controller\Cluster\KubernetesClusterController@namespaces');
        Router::get('/k8s/namespaces/detail', 'App\Controller\Cluster\KubernetesClusterController@namespaceDetail');
        Router::put('/k8s/namespaces/resources', 'App\Controller\Cluster\KubernetesClusterController@namespaceResources');
        Router::get('/k8s/pods', 'App\Controller\Cluster\KubernetesClusterController@pods');
        Router::get('/k8s/deployments', 'App\Controller\Cluster\KubernetesClusterController@deployments');
        Router::get('/k8s/services', 'App\Controller\Cluster\KubernetesClusterController@services');
        // Kubernetes 资源配置 CRUD：ConfigMap / Secret / Namespace / Deployment / Service / Ingress / Pod
        Router::get('/k8s/configmaps', 'App\Controller\Cluster\KubernetesClusterController@configmaps');
        Router::get('/k8s/configmaps/detail', 'App\Controller\Cluster\KubernetesClusterController@configMap');
        Router::post('/k8s/configmaps', 'App\Controller\Cluster\KubernetesClusterController@configMapCreate');
        Router::put('/k8s/configmaps', 'App\Controller\Cluster\KubernetesClusterController@configMapUpdate');
        Router::delete('/k8s/configmaps', 'App\Controller\Cluster\KubernetesClusterController@configMapDelete');
        Router::get('/k8s/secrets', 'App\Controller\Cluster\KubernetesClusterController@secrets');
        Router::get('/k8s/secrets/detail', 'App\Controller\Cluster\KubernetesClusterController@secret');
        Router::post('/k8s/secrets', 'App\Controller\Cluster\KubernetesClusterController@secretCreate');
        Router::put('/k8s/secrets', 'App\Controller\Cluster\KubernetesClusterController@secretUpdate');
        Router::delete('/k8s/secrets', 'App\Controller\Cluster\KubernetesClusterController@secretDelete');
        Router::post('/k8s/namespaces', 'App\Controller\Cluster\KubernetesClusterController@namespaceCreate');
        Router::delete('/k8s/namespaces', 'App\Controller\Cluster\KubernetesClusterController@namespaceDelete');
        Router::get('/k8s/deployments/detail', 'App\Controller\Cluster\KubernetesClusterController@deployment');
        Router::post('/k8s/deployments', 'App\Controller\Cluster\KubernetesClusterController@deploymentCreate');
        Router::put('/k8s/deployments', 'App\Controller\Cluster\KubernetesClusterController@deploymentUpdate');
        Router::delete('/k8s/deployments', 'App\Controller\Cluster\KubernetesClusterController@deploymentDelete');
        Router::post('/k8s/deployments/scale', 'App\Controller\Cluster\KubernetesClusterController@deploymentScale');
        Router::post('/k8s/deployments/restart', 'App\Controller\Cluster\KubernetesClusterController@deploymentRestart');
        Router::post('/k8s/deployments/resources', 'App\\Controller\\Cluster\\KubernetesClusterController@deploymentResources');
        Router::post('/k8s/deployments/apply', 'App\\Controller\\Cluster\\KubernetesClusterController@deploymentApply');
        Router::get('/k8s/services/detail', 'App\Controller\Cluster\KubernetesClusterController@service');
        Router::post('/k8s/services', 'App\Controller\Cluster\KubernetesClusterController@serviceCreate');
        Router::put('/k8s/services', 'App\Controller\Cluster\KubernetesClusterController@serviceUpdate');
        Router::delete('/k8s/services', 'App\Controller\Cluster\KubernetesClusterController@serviceDelete');
        Router::get('/k8s/ingresses', 'App\Controller\Cluster\KubernetesClusterController@ingresses');
        Router::get('/k8s/ingresses/detail', 'App\Controller\Cluster\KubernetesClusterController@ingress');
        Router::post('/k8s/ingresses', 'App\Controller\Cluster\KubernetesClusterController@ingressCreate');
        Router::put('/k8s/ingresses', 'App\Controller\Cluster\KubernetesClusterController@ingressUpdate');
        Router::delete('/k8s/ingresses', 'App\Controller\Cluster\KubernetesClusterController@ingressDelete');
        Router::get('/k8s/pods/detail', 'App\Controller\Cluster\KubernetesClusterController@pod');
        Router::get('/k8s/pods/logs', 'App\Controller\Cluster\KubernetesClusterController@podLogs');
        Router::delete('/k8s/pods', 'App\Controller\Cluster\KubernetesClusterController@podDelete');
        Router::post('/k8s/pods/terminal-ticket', 'App\Controller\Cluster\KubernetesClusterController@podTerminalTicket', ['permission' => $projectOperation]); // Pod Web 终端一次性票据
        Router::get('/k8s/statefulsets', 'App\Controller\Cluster\KubernetesClusterController@statefulsets');
        Router::get('/k8s/statefulsets/detail', 'App\Controller\Cluster\KubernetesClusterController@statefulSet');
        Router::post('/k8s/statefulsets/scale', 'App\Controller\Cluster\KubernetesClusterController@statefulSetScale');
        Router::delete('/k8s/statefulsets', 'App\Controller\Cluster\KubernetesClusterController@statefulSetDelete');
        Router::get('/k8s/daemonsets', 'App\Controller\Cluster\KubernetesClusterController@daemonsets');
        Router::get('/k8s/daemonsets/detail', 'App\Controller\Cluster\KubernetesClusterController@daemonSet');
        Router::delete('/k8s/daemonsets', 'App\Controller\Cluster\KubernetesClusterController@daemonSetDelete');
        Router::get('/k8s/jobs', 'App\Controller\Cluster\KubernetesClusterController@jobs');
        Router::get('/k8s/jobs/detail', 'App\Controller\Cluster\KubernetesClusterController@job');
        Router::delete('/k8s/jobs', 'App\Controller\Cluster\KubernetesClusterController@jobDelete');
        Router::get('/k8s/cronjobs', 'App\Controller\Cluster\KubernetesClusterController@cronjobs');
        Router::get('/k8s/cronjobs/detail', 'App\Controller\Cluster\KubernetesClusterController@cronjob');
        Router::delete('/k8s/cronjobs', 'App\Controller\Cluster\KubernetesClusterController@cronjobDelete');
        Router::get('/k8s/persistentvolumes', 'App\Controller\Cluster\KubernetesClusterController@persistentVolumes');
        Router::get('/k8s/persistentvolumes/detail', 'App\Controller\Cluster\KubernetesClusterController@persistentVolume');
        Router::delete('/k8s/persistentvolumes', 'App\Controller\Cluster\KubernetesClusterController@persistentVolumeDelete');
        Router::get('/k8s/persistentvolumeclaims', 'App\Controller\Cluster\KubernetesClusterController@persistentVolumeClaims');
        Router::get('/k8s/persistentvolumeclaims/detail', 'App\Controller\Cluster\KubernetesClusterController@persistentVolumeClaim');
        Router::delete('/k8s/persistentvolumeclaims', 'App\Controller\Cluster\KubernetesClusterController@persistentVolumeClaimDelete');
        Router::get('/k8s/storageclasses', 'App\Controller\Cluster\KubernetesClusterController@storageClasses');
        Router::get('/k8s/storageclasses/detail', 'App\Controller\Cluster\KubernetesClusterController@storageClass');
        Router::delete('/k8s/storageclasses', 'App\Controller\Cluster\KubernetesClusterController@storageClassDelete');
        Router::get('/k8s/events', 'App\Controller\Cluster\KubernetesClusterController@events');
        Router::get('/swarm', 'App\Controller\ClusterController@swarmList', [
            'permission' => $projectScoped,
        ]);
        Router::post('/swarm', 'App\Controller\ClusterController@createSwarm');
        Router::delete('/swarm', 'App\Controller\ClusterController@deleteSwarm');
        Router::get('/swarm/deleteoverview', 'App\Controller\ClusterController@swarmDeleteOverview');
        Router::get('/swarm/simpleprofile', 'App\Controller\ClusterController@simpleProfile');
        Router::get('/swarm/profile', 'App\Controller\ClusterController@profile');
        Router::post('', 'App\Controller\ClusterController@create'); // 创建集群
        Router::get('/deleteoverview', 'App\Controller\ClusterController@getDeleteOverview'); // 获取删除预览
        Router::delete('', 'App\Controller\ClusterController@delete'); // 删除集群
        Router::get('/simpleprofile', 'App\Controller\ClusterController@simpleProfile'); // 集群简单详情
        Router::get('/profile', 'App\Controller\ClusterController@profile'); // 集群详情
        Router::get('/swarm/check', 'App\Controller\ClusterController@checkConnectivity'); // Docker Swarm 连通性检查
        Router::get('/swarm/web-gateway', 'App\Controller\ClusterController@swarmWebGateway', ['permission' => $projectScoped]); // 七层 Web 网关状态
        Router::get('/swarm/web-gateway/metrics', 'App\Controller\ClusterController@swarmWebGatewayMetrics'); // 管理员域名流量统计
        Router::put('/swarm/web-gateway', 'App\Controller\ClusterController@deploySwarmWebGateway'); // 部署或更新七层 Web 网关
        Router::delete('/swarm/web-gateway', 'App\Controller\ClusterController@removeSwarmWebGateway'); // 删除七层 Web 网关 Service
        Router::put('/swarm/web-gateway/workspace-domain', 'App\Controller\ClusterController@setSwarmWorkspaceDomain'); // 设置 Workspace 泛域名分配规则
        Router::delete('/swarm/web-gateway/workspace-domain', 'App\Controller\ClusterController@clearSwarmWorkspaceDomain'); // 删除 Workspace 泛域名分配规则
        Router::get('/swarm/prometheus', 'App\Controller\ClusterController@swarmPrometheus'); // 内置 Prometheus 状态
        Router::put('/swarm/prometheus', 'App\Controller\ClusterController@deploySwarmPrometheus'); // 部署或更新内置 Prometheus
        Router::post('/swarm/prometheus/query', 'App\Controller\ClusterController@swarmPrometheusQuery'); // Prometheus 范围查询
        Router::get('/swarm/web-gateway/vhosts', 'App\Controller\ClusterController@swarmWebGatewayVhosts', ['permission' => $projectScoped]); // 列出网关路由规则
        Router::post('/swarm/web-gateway/vhosts', 'App\Controller\ClusterController@swarmWebGatewayVhostCreate', ['permission' => $projectOperation]); // 创建网关路由规则
        Router::put('/swarm/web-gateway/vhosts', 'App\Controller\ClusterController@swarmWebGatewayVhostUpdate', ['permission' => $projectOperation]); // 更新网关路由规则
        Router::put('/swarm/web-gateway/vhosts/certificates', 'App\Controller\ClusterController@swarmWebGatewayVhostAssignCertificates'); // 批量切换网关路由证书
        Router::delete('/swarm/web-gateway/vhosts', 'App\Controller\ClusterController@swarmWebGatewayVhostDelete', ['permission' => $projectOperation]); // 删除网关路由规则
        Router::get('/swarm/web-gateway/services', 'App\Controller\ClusterController@swarmWebGatewayServices', ['permission' => $projectScoped]); // 列出 Swarm 服务（用于路由目标选择）
        Router::get('/swarm/containers', 'App\Controller\ClusterController@swarmContainers', ['permission' => $projectScoped]); // 容器列表
        Router::get('/swarm/configs', 'App\Controller\ClusterController@swarmConfigs'); // Config 列表
        Router::get('/swarm/configs/{config_id}', 'App\Controller\ClusterController@swarmConfig'); // Config 内容
        Router::post('/swarm/configs', 'App\Controller\ClusterController@swarmConfigCreate'); // 新建 Config
        Router::delete('/swarm/config', 'App\Controller\ClusterController@swarmConfigDelete'); // 删除 Config
        Router::post('/swarm/secrets', 'App\Controller\ClusterController@swarmSecretCreate'); // 新建 Secret
        Router::get('/swarm/secrets', 'App\Controller\ClusterController@swarmSecrets'); // Secret 列表
        Router::delete('/swarm/secret', 'App\Controller\ClusterController@swarmSecretDelete'); // 删除 Secret
        Router::get('/swarm/container-logs', 'App\Controller\ClusterController@swarmContainerLogs', ['permission' => $projectScoped]); // 容器日志
        Router::get('/swarm/service-logs', 'App\Controller\ClusterController@swarmServiceLogs', ['permission' => $projectScoped]); // Service 日志
        Router::get('/swarm/container-inspect', 'App\Controller\ClusterController@swarmContainerInspect', ['permission' => $projectScoped]); // 容器详情
        Router::get('/swarm/container-stats', 'App\Controller\ClusterController@swarmContainerStats', ['permission' => $projectScoped]); // 容器实时统计
        Router::get('/swarm/container-top', 'App\Controller\ClusterController@swarmContainerTop', ['permission' => $projectScoped]); // 容器进程列表
        Router::post('/swarm/container-start', 'App\Controller\ClusterController@swarmContainerStart', ['permission' => $projectOperation]); // 启动容器
        Router::post('/swarm/container-stop', 'App\Controller\ClusterController@swarmContainerStop', ['permission' => $projectOperation]); // 停止容器
        Router::post('/swarm/container-restart', 'App\Controller\ClusterController@swarmContainerRestart', ['permission' => $projectOperation]); // 重启容器
        Router::delete('/swarm/container', 'App\Controller\ClusterController@swarmContainerRemove', ['permission' => $projectOperation]); // 删除容器
        Router::post('/swarm/container-pause', 'App\Controller\ClusterController@swarmContainerPause', ['permission' => $projectOperation]); // 暂停容器
        Router::post('/swarm/container-resume', 'App\Controller\ClusterController@swarmContainerResume', ['permission' => $projectOperation]); // 恢复容器
        Router::post('/swarm/container-kill', 'App\Controller\ClusterController@swarmContainerKill', ['permission' => $projectOperation]); // 强制杀死容器
        Router::post('/swarm/container-exec', 'App\Controller\ClusterController@swarmContainerExec', ['permission' => $projectOperation]); // 容器执行命令
        Router::get('/swarm/container-list-files', 'App\Controller\ClusterController@swarmContainerListFiles', ['permission' => $projectScoped]); // 容器文件列表
        Router::get('/swarm/container-read-file', 'App\Controller\ClusterController@swarmContainerReadFile', ['permission' => $projectScoped]);
        Router::post('/swarm/container-write-file', 'App\Controller\ClusterController@swarmContainerWriteFile', ['permission' => $projectOperation]); // 容器写入文件
        Router::post('/swarm/terminal-ticket', 'App\Controller\ClusterController@createSwarmTerminalTicket', ['permission' => $projectOperation]); // Web 终端一次性票据
        Router::get('/swarm/terminal-ssh-command', 'App\Controller\ClusterController@swarmTerminalSshCommand', ['permission' => $projectScoped]); // 原生 SSH 终端命令
        Router::get('/swarm/images', 'App\Controller\ClusterController@swarmImages'); // 镜像列表
        Router::get('/swarm/image-info', 'App\Controller\ClusterController@swarmImageInfo'); // Redis 缓存中的节点镜像详情
        Router::delete('/swarm/image', 'App\Controller\ClusterController@swarmImageRemove'); // 删除镜像
        Router::post('/swarm/image-run', 'App\Controller\ClusterController@swarmImageRun'); // 镜像运行（创建 Service）
        Router::post('/swarm/image-pull', 'App\Controller\ClusterController@swarmImagePull'); // 拉取镜像（支持私有仓库认证）
        Router::get('/swarm/networks', 'App\Controller\ClusterController@swarmNetworks'); // 网络列表
        Router::post('/swarm/networks/create', 'App\Controller\ClusterController@swarmNetworkCreate'); // 创建网络
        Router::put('/swarm/network', 'App\Controller\ClusterController@swarmNetworkUpdate'); // 更新网络
        Router::delete('/swarm/network', 'App\Controller\ClusterController@swarmNetworkDelete'); // 删除网络
        Router::get('/swarm/network/containers', 'App\Controller\ClusterController@swarmNetworkContainers'); // 网络关联容器列表
        Router::post('/swarm/network/disconnect', 'App\Controller\ClusterController@swarmNetworkDisconnect'); // 容器退出网络
        Router::get('/swarm/services', 'App\Controller\ClusterController@swarmServices', ['permission' => $projectScoped]); // Services 列表
        Router::get('/swarm/service-inspect', 'App\Controller\ClusterController@swarmServiceInspect', ['permission' => $projectScoped]); // Service 详情
        Router::post('/swarm/service-scale', 'App\Controller\ClusterController@swarmServiceScale', ['permission' => $projectOperation]); // Service 伸缩
        Router::post('/swarm/service-force-update', 'App\Controller\ClusterController@swarmServiceForceUpdate', ['permission' => $projectOperation]); // Service 强制更新
        Router::post('/swarm/service-rollback', 'App\Controller\ClusterController@swarmServiceRollback'); // Service 回滚
        Router::post('/swarm/service-resources', 'App\Controller\ClusterController@swarmServiceUpdateResources', ['permission' => $projectOperation]); // Service 调整资源
        Router::post('/swarm/service-image', 'App\Controller\ClusterController@swarmServiceUpdateImage', ['permission' => $projectOperation]); // 拉取并更新 Service 镜像
        Router::post('/swarm/service-env', 'App\Controller\ClusterController@swarmServiceUpdateEnv', ['permission' => $projectOperation]); // Service 更新环境变量
        Router::post('/swarm/service-ports', 'App\Controller\ClusterController@swarmServiceUpdatePorts', ['permission' => $projectOperation]); // Service 更新端口
        Router::post('/swarm/service-mounts', 'App\Controller\ClusterController@swarmServiceUpdateMounts', ['permission' => $projectOperation]); // Service 更新挂载
        Router::post('/swarm/service-update-config', 'App\Controller\ClusterController@swarmServiceUpdateConfig', ['permission' => $projectOperation]); // Service 更新部署配置
        Router::get('/swarm/service-tasks', 'App\Controller\ClusterController@swarmServiceTasks', ['permission' => $projectScoped]); // Service 任务列表
        Router::get('/swarm/service-containers', 'App\Controller\ClusterController@swarmServiceContainers', ['permission' => $projectScoped]); // Service 容器列表
        Router::get('/swarm/nodes', 'App\Controller\ClusterController@swarmNodes'); // Swarm 节点列表（只读）
        Router::get('/swarm/nodes/runtime', 'App\Controller\ClusterController@swarmNodesRuntime'); // Swarm 节点运行时指标（容器统计/磁盘）
        Router::get('/swarm/node/{node_id}', 'App\Controller\ClusterController@swarmNode'); // Swarm 节点详情（只读）
        Router::get('/swarm/node-tasks', 'App\Controller\ClusterController@swarmNodeTasks'); // 节点运行的任务（只读）
        Router::get('/swarm/node-containers', 'App\Controller\ClusterController@swarmNodeContainers'); // 节点容器列表（只读）
        Router::get('/swarm/service-events', 'App\Controller\ClusterController@swarmServiceEvents', ['permission' => $projectScoped]); // Service 事件列表
        Router::get('/swarm/service/configs-secrets', 'App\Controller\ClusterController@swarmServiceConfigsSecrets', ['permission' => $projectScoped]); // Service 的 Config/Secret 映射
        Router::post('/swarm/service/config-add', 'App\Controller\ClusterController@swarmServiceConfigAdd'); // 新增 Config 映射
        Router::post('/swarm/service/secret-add', 'App\Controller\ClusterController@swarmServiceSecretAdd'); // 新增 Secret 映射
        Router::post('/swarm/service/config-remove', 'App\Controller\ClusterController@swarmServiceConfigRemove'); // 移除 Config 映射
        Router::post('/swarm/service/secret-remove', 'App\Controller\ClusterController@swarmServiceSecretRemove'); // 移除 Secret 映射
        Router::get('/swarm/service-config', 'App\Controller\ClusterController@swarmServiceConfig', ['permission' => $projectScoped]); // 获取 Service Config
        Router::post('/swarm/service-config', 'App\Controller\ClusterController@swarmServiceConfigUpdate'); // 更新 Service Config
        Router::delete('/swarm/service', 'App\Controller\ClusterController@swarmServiceRemove'); // 删除 Service
        Router::post('/swarm/service-prune', 'App\Controller\ClusterController@swarmServicePrune'); // Prune Service 容器
        Router::get('/swarm/volumes', 'App\Controller\ClusterController@swarmVolumes'); // 数据卷列表
        Router::post('/swarm/volume-create', 'App\Controller\ClusterController@swarmVolumeCreate'); // 创建数据卷
        Router::delete('/swarm/volume', 'App\Controller\ClusterController@swarmVolumeRemove'); // 删除数据卷
        Router::get('/swarm/overview', 'App\Controller\ClusterController@swarmOverview'); // Docker Swarm 集群概览
        Router::get('/swarm/overview/core', 'App\Controller\ClusterController@swarmOverviewCore'); // 快速核心信息
        Router::get('/swarm/overview/topology', 'App\Controller\ClusterController@swarmOverviewTopology'); // 节点、服务和任务
        Router::get('/swarm/overview/runtime', 'App\Controller\ClusterController@swarmOverviewRuntime'); // 容器和存储慢指标
        Router::get('/swarm/settings', 'App\Controller\ClusterController@swarmSettings'); // Docker Swarm 连接设置
        Router::put('/swarm/settings', 'App\Controller\ClusterController@updateSwarmSettings'); // 更新 Docker Swarm 连接设置
        Router::post('/swarm/agent-token', 'App\Controller\ClusterController@rotateSwarmAgentToken'); // 轮换集群专属 Agent Token
        Router::post('/swarm/agent-reset', 'App\Controller\ClusterController@resetSwarmAgentRegistration'); // 解除 Agent 绑定并重新签发 Bootstrap Token
        Router::get('/swarm/join-commands', 'App\Controller\ClusterController@swarmJoinCommands'); // 获取 Swarm 节点加入命令
        Router::get('', 'App\Controller\ClusterController@index', [
            'permission' => $projectScoped,
        ]); // 管理员查看组织全集；项目视图按项目组授权过滤
        Router::get('/simple', 'App\Controller\ClusterController@simple', [
            'permission' => $projectScoped,
        ]); // 集群简单列表
        Router::post('/bindenvs', 'App\Controller\ClusterController@bindEnvs'); // 绑定环境
        Router::get('/group-grants', 'App\Controller\ClusterController@groupGrants'); // 查看项目组资源授权
        Router::put('/group-grants', 'App\Controller\ClusterController@syncGroupGrants'); // 设置项目组资源授权
    }, [
        'permission' => [
            [OrgMember::ROLE_MANAGER => 1],
        ],
    ]);

    // SSL 证书（组织级别，与集群无关）
    Router::addGroup('/resources', function () {
        Router::get('/usage-ranking', 'App\Controller\ResourceUsageController@ranking');
        Router::get('/domains/options', 'App\Controller\ManagedDomainController@options', [
            'permission' => [[OrgMember::ROLE_MANAGER => 1], [], ['*' => 1]],
        ]);
        Router::get('/domains/group-grants', 'App\Controller\ManagedDomainController@groups');
        Router::put('/domains/group-grants', 'App\Controller\ManagedDomainController@syncGroups');
        Router::get('/domains', 'App\Controller\ManagedDomainController@list');
        Router::post('/domains', 'App\Controller\ManagedDomainController@create');
        Router::put('/domains', 'App\Controller\ManagedDomainController@update');
        Router::delete('/domains', 'App\Controller\ManagedDomainController@delete');
        Router::get('/certificates', 'App\Controller\TlsCertificateController@list');
        Router::get('/certificates/profile', 'App\Controller\TlsCertificateController@profile');
        Router::get('/certificates/options', 'App\Controller\TlsCertificateController@options', [
            'permission' => [[OrgMember::ROLE_MANAGER => 1], [], ['*' => 1]],
        ]);
        Router::post('/certificates/import', 'App\Controller\TlsCertificateController@import');
        Router::post('/certificates/self-signed', 'App\Controller\TlsCertificateController@selfSigned');
        Router::post('/certificates/lets-encrypt', 'App\Controller\TlsCertificateController@letsEncrypt');
        Router::get('/certificates/acme-backup', 'App\Controller\TlsCertificateController@acmeBackup');
        Router::put('/certificates/acme-backup', 'App\Controller\TlsCertificateController@saveAcmeBackup');
        Router::post('/certificates/acme-backup/run', 'App\Controller\TlsCertificateController@runAcmeBackup');
        Router::delete('/certificates', 'App\Controller\TlsCertificateController@delete');
        Router::get('/cloud-accounts', 'App\Controller\CloudAccountController@list');
        Router::post('/cloud-accounts', 'App\Controller\CloudAccountController@create');
        Router::delete('/cloud-accounts', 'App\Controller\CloudAccountController@delete');
        Router::get('/cloud-accounts/certificates', 'App\Controller\TlsCertificateController@cloudCertificates');
        Router::post('/cloud-accounts/certificates/import', 'App\Controller\TlsCertificateController@importCloudCertificate');

        // 对象存储
        Router::get('/storage-buckets', 'App\Controller\ObjectStorageController@listBuckets');
        Router::get('/storage-buckets/discover', 'App\Controller\ObjectStorageController@discoverBuckets');
        Router::get('/storage-buckets/{id}', 'App\Controller\ObjectStorageController@showBucket');
        Router::post('/storage-buckets', 'App\Controller\ObjectStorageController@createBucket');
        Router::put('/storage-buckets/{id}', 'App\Controller\ObjectStorageController@updateBucket');
        Router::delete('/storage-buckets/{id}', 'App\Controller\ObjectStorageController@deleteBucket');
        Router::post('/storage-buckets/{id}/default', 'App\Controller\ObjectStorageController@setDefault');
        Router::get('/storage-files', 'App\Controller\ObjectStorageController@listFiles');
        Router::get('/storage-files/download', 'App\Controller\ObjectStorageController@downloadFile');
        Router::post('/storage-files/upload', 'App\Controller\ObjectStorageController@uploadFile');
        Router::post('/storage-directories', 'App\Controller\ObjectStorageController@createDirectory');
        Router::delete('/storage-files', 'App\Controller\ObjectStorageController@deleteFile');

        // FRP 网络穿透（组织级配置，运行资源可落在 Swarm 或 Kubernetes）
        Router::get('/network-tunnels', 'App\Controller\NetworkTunnelController@topology');
        Router::get('/network-tunnels/source-services', 'App\Controller\NetworkTunnelController@sourceServices');
        Router::post('/network-tunnels/rules/automatic', 'App\Controller\NetworkTunnelController@saveAutomaticTunnel');
        Router::post('/network-tunnels/servers', 'App\Controller\NetworkTunnelController@createServer');
        Router::put('/network-tunnels/servers', 'App\Controller\NetworkTunnelController@updateServer');
        Router::delete('/network-tunnels/servers', 'App\Controller\NetworkTunnelController@deleteServer');
        Router::post('/network-tunnels/clients', 'App\Controller\NetworkTunnelController@createClient');
        Router::put('/network-tunnels/clients', 'App\Controller\NetworkTunnelController@updateClient');
        Router::delete('/network-tunnels/clients', 'App\Controller\NetworkTunnelController@deleteClient');
        Router::post('/network-tunnels/rules', 'App\Controller\NetworkTunnelController@saveTunnel');
        Router::delete('/network-tunnels/rules', 'App\Controller\NetworkTunnelController@deleteTunnel');
        Router::post('/network-tunnels/sync', 'App\Controller\NetworkTunnelController@sync');
        Router::post('/network-tunnels/sync-all', 'App\Controller\NetworkTunnelController@syncAll');
    }, [
        'permission' => [
            [OrgMember::ROLE_MANAGER => 1],
        ],
    ]);

    // 环境管理
    Router::addGroup('/env', function () {
        Router::get('', 'App\Controller\EnvController@index'); // 环境列表
        Router::get('/relinstances', 'App\Controller\EnvController@relInstances'); // 关联实例
        Router::post('', 'App\Controller\EnvController@create'); // 创建环境
        Router::put('', 'App\Controller\EnvController@update'); // 更新环境
        Router::put('/archive', 'App\Controller\EnvController@archive'); // 归档环境
        Router::put('/restore', 'App\Controller\EnvController@restore'); // 恢复环境
        Router::get('/profile', 'App\Controller\EnvController@profile'); // 环境详情
        Router::get('/simple', 'App\Controller\EnvController@simple', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
                ['*' => 1],
            ],
        ]); // 环境简单列表
    }, [
        'permission' => [
            [OrgMember::ROLE_MANAGER => 1],
        ],
    ]);

    // 构建
    Router::addGroup('/build', function () {
        Router::get('', 'App\Controller\BuildController@list', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
                ['*' => 1],
            ],
        ]); // 构建记录
        Router::get('/log', 'App\Controller\BuildController@log', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
                ['*' => 1],
            ],
        ]); // 构建日志
        Router::get('/artifact/profile', 'App\Controller\BuildController@artifactProfile', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
                ['*' => 1],
            ],
        ]); // 镜像制品详情
        Router::get('/artifacts', 'App\Controller\BuildController@artifacts', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
                ['*' => 1],
            ],
        ]); // 镜像制品列表
        Router::post('', 'App\Controller\BuildController@create'); // 发起构建
        Router::post('/rebuild', 'App\Controller\BuildController@rebuild'); // 重新构建
        Router::post('/artifact/import', 'App\Controller\BuildController@importArtifact'); // 登记已有 OCI 镜像
        Router::post('/cancel', 'App\Controller\BuildController@cancelBuild'); // 取消构建
        Router::get('/gitbranches', 'App\Controller\BuildController@gitBranches'); // 获取Git分支
        Router::get('/gittags', 'App\Controller\BuildController@gitTags'); // 获取Git标签
        Router::get('/gitcommits', 'App\Controller\BuildController@gitCommits'); // 获取Git标签/Commits列表
    }, [
        'permission' => [
            [OrgMember::ROLE_MANAGER => 1],
            [GroupMember::ROLE_DIRECTOR => 1],
            [ProjectMember::ROLE_DIRECTOR => 1],
        ],
    ]);

    // 项目级配置中心；配置源与集群无关，发布时冻结并物化到目标 Swarm。
    Router::addGroup('/project/configuration', function () {
        Router::get('', 'App\Controller\ProjectConfigurationController@list', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
                ['*' => 1],
            ],
        ]);
        Router::post('', 'App\Controller\ProjectConfigurationController@create');
        Router::put('', 'App\Controller\ProjectConfigurationController@update');
        Router::delete('', 'App\Controller\ProjectConfigurationController@delete');
    }, [
        'permission' => [
            [OrgMember::ROLE_MANAGER => 1],
            [GroupMember::ROLE_DIRECTOR => 1],
            [ProjectMember::ROLE_DIRECTOR => 1],
        ],
    ]);

    // 部署
    Router::addGroup('/deploy', function () {
        Router::get('/options', 'App\Controller\DeployController@options', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
                ['*' => 1],
            ],
        ]);
        Router::get('/profile', 'App\Controller\DeployController@profile', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
                ['*' => 1],
            ],
        ]);
        Router::get('/runtime', 'App\Controller\DeployController@runtimes', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
                ['*' => 1],
            ],
        ]);
        Router::get('/monitoring', 'App\Controller\DeployController@monitoring', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
                ['*' => 1],
            ],
        ]);
        Router::get('/monitoring/http', 'App\Controller\DeployController@httpMonitoring', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
                ['*' => 1],
            ],
        ]);
        Router::get('', 'App\Controller\DeployController@list', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
                ['*' => 1],
            ],
        ]); // 部署记录
        Router::post('', 'App\Controller\DeployController@newDeploy'); // 发起部署
        Router::post('/network', 'App\Controller\DeployController@createNetwork'); // 创建项目 Overlay 网络
        Router::post('/artifact/update', 'App\Controller\DeployController@updateArtifact'); // 更新实例镜像版本
        Router::post('/rollback', 'App\Controller\DeployController@rollback'); // 回滚
        Router::put('/runtime', 'App\Controller\DeployController@renameRuntime');
        Router::put('/runtime/scale', 'App\Controller\DeployController@scaleRuntime');
        Router::post('/runtime/restart', 'App\Controller\DeployController@restartRuntime');
        Router::delete('/runtime', 'App\Controller\DeployController@removeRuntime', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
                [],
            ],
        ]);
        Router::get('/route', 'App\Controller\ProjectRouteController@list', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
                ['*' => 1],
            ],
        ]);
        Router::get('/route/profile', 'App\Controller\ProjectRouteController@profile', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
                ['*' => 1],
            ],
        ]);
        Router::get('/route/import-candidates', 'App\Controller\ProjectRouteController@importCandidates', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
                ['*' => 1],
            ],
        ]);
        Router::post('/route/import', 'App\Controller\ProjectRouteController@import');
        Router::post('/route', 'App\Controller\ProjectRouteController@create');
        Router::put('/route', 'App\Controller\ProjectRouteController@update');
        Router::delete('/route', 'App\Controller\ProjectRouteController@delete');
        Router::get('/alert', 'App\Controller\ProjectAlertController@profile', [
            'permission' => [
                [OrgMember::ROLE_MANAGER => 1],
                [GroupMember::ROLE_DIRECTOR => 1],
                ['*' => 1],
            ],
        ]);
        Router::put('/alert', 'App\Controller\ProjectAlertController@save');
    }, [
        'permission' => [
            [OrgMember::ROLE_MANAGER => 1],
            [GroupMember::ROLE_DIRECTOR => 1],
            [ProjectMember::ROLE_DIRECTOR => 1],
        ],
    ]);

    // 项目市场
    Router::addGroup('/appmarket', function () {
        Router::get('/queryprogress', 'App\Controller\AppMarketController@queryProgress'); // 查询进度
        Router::addRoute(['GET', 'POST'], '/tpls', 'App\Controller\AppMarketController@tpls'); // 模板列表
        Router::get('/tplprofile', 'App\Controller\AppMarketController@tplProfile'); // 模板详情
        Router::post('/tpluse', 'App\Controller\AppMarketController@tplUse'); // 使用模板
        Router::get('/swarm/clusters', 'App\Controller\AppMarketController@swarmClusters');
        Router::get('/swarm/networks', 'App\Controller\AppMarketController@swarmNetworks');
        Router::post('/swarm/networks/create', 'App\Controller\AppMarketController@swarmNetworkCreate');
        Router::get('/swarm/containers', 'App\Controller\AppMarketController@swarmContainers');
        Router::get('/swarm/installations', 'App\Controller\AppMarketController@swarmInstallations');
        Router::get('/swarm/find-installation', 'App\Controller\AppMarketController@swarmFindInstallation');
        Router::post('/swarm/reconfigure', 'App\Controller\AppMarketController@swarmReconfigure');
        Router::get('/kubernetes/clusters', 'App\Controller\AppMarketController@kubernetesClusters');
        Router::get('/kubernetes/installations', 'App\Controller\AppMarketController@kubernetesInstallations');
        Router::post('/kubernetes/resend-credentials', 'App\Controller\AppMarketController@kubernetesResendCredentials');
        Router::get('/filtermetadata', 'App\Controller\AppMarketController@filterMetadata'); // 用于筛选的元数据
        Router::get('/tags', 'App\Controller\AppMarketController@tags'); // 标签列表
    });

    // 文件上传
    Router::addGroup('/upload', function () {
        Router::post('/image', 'App\Controller\UploadController@image'); // 上传图片
    });

    // 镜像仓库
    Router::addGroup('/registry', function () {
        Router::get('', 'App\Controller\RegistryController@list'); // 帐号列表
        Router::post('', 'App\Controller\RegistryController@create'); // 创建帐号
        Router::put('', 'App\Controller\RegistryController@update'); // 更新帐号
        Router::delete('', 'App\Controller\RegistryController@delete'); // 删除帐号
        Router::put('/push', 'App\Controller\RegistryController@setPush'); // 设置推送镜像的帐号
        Router::get('/password', 'App\Controller\RegistryController@showPassword'); // 查看密码
        Router::get('/catalog', 'App\Controller\RegistryController@catalog'); // 仓库目录
        Router::get('/tags', 'App\Controller\RegistryController@tagsList'); // 镜像标签
        Router::delete('/image', 'App\Controller\RegistryController@deleteImage'); // 删除镜像
        Router::get('/group-grants', 'App\Controller\RegistryController@groups'); // 项目组授权
        Router::put('/group-grants', 'App\Controller\RegistryController@syncGroups'); // 更新项目组授权
        Router::get('/image-mappings', 'App\Controller\RegistryController@imageMappings'); // 镜像映射
        Router::post('/image-mappings', 'App\Controller\RegistryController@saveImageMapping'); // 新增或更新镜像映射
        Router::delete('/image-mappings', 'App\Controller\RegistryController@deleteImageMapping'); // 删除镜像映射
    }, [
        'permission' => [
            [OrgMember::ROLE_MANAGER => 1],
        ],
    ]);

}, ['middleware' => [
    \App\Middleware\RefreshTokenMiddleware::class,
    \App\Middleware\PermissionMiddleware::class,
    \App\Middleware\ProjectAuditMiddleware::class,
]]);

// Explicitly disabled by default. These development-only control-plane routes
// accept short-lived Redis-backed cgdev_* tokens, never normal user JWTs.
Router::addGroup('/_devtools', function () {
    Router::post('/docker/request', 'App\\Controller\\DevtoolController@dockerRequest');
    Router::post('/agent/command', 'App\\Controller\\DevtoolController@agentCommand');
}, ['middleware' => [
    \App\Middleware\RefreshTokenMiddleware::class,
    \App\Middleware\DevtoolAccessMiddleware::class,
]]);

// Webhooks.
Router::addGroup('/webhooks', function () {
    Router::post('/githook/{org}/{project}', 'App\Controller\WebhooksController@githook'); // Git webhook
});
