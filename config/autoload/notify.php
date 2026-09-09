<?php

declare(strict_types=1);

use App\Model\UserNotify;
use App\Services\Notify\Filter\ProjectDelete;
use App\Services\Notify\Filter\BuildComplete;
use App\Services\Notify\Filter\ClusterDelete;
use App\Services\Notify\Filter\ClusterInit;
use App\Services\Notify\Filter\GroupDelete;

return [
    // 通知渠道
    'channels' => explode(',', env('NOTIFY_CHANNELS', 'email,notify,browser')),
    'tpls' => [
        'build_complete' => [
            UserNotify::CHANNEL_EMAIL => [
                'subject' => '镜像构建%s',
                'template' => 'build_complete',
                'before_filter' => [BuildComplete::class, 'beforeEmail'],
                'after_filter' => [BuildComplete::class, 'afterEmail'],
            ],
            UserNotify::CHANNEL_BROWSER => [
                'title' => '镜像构建%s',
                'body' => '#{{id}} 版本：{{version}}，备注：{{remark}}',
                'fields' => [
                    'id', 'result', 'version', 'remark',
                ],
                'before_filter' => [BuildComplete::class, 'beforeBrowser'],
                'after_filter' => [BuildComplete::class, 'afterBrowser'],
            ],
            UserNotify::CHANNEL_NOTIFY => [
                'title' => '镜像构建%s',
                'body' => '组织：{{org.title}}<br>
项目：{{group.title}} / {{project.title}}<br>
版本：{{version}}<br>
流水线：{{build.pipeline.title}}<br>
备注：{{build.remark}}<br>
操作人：{{build.creator_info.realname}}({{build.creator_info.nickname}})<br>
构建时间：{{start_at}}<br>
完成时间：{{end_at}}<br>
构建耗时：{{duration}}<br>
构建结果：{{result}}',
                'fields' => [
                    'org.title', 'group.title', 'project.title', 'version', 'build.pipeline.title', 'build.remark',
                    'build.creator_info.realname', 'build.creator_info.nickname', 'start_at', 'end_at', 'duration',
                    'result',
                ],
                'before_filter' => [BuildComplete::class, 'beforeNotify'],
                'after_filter' => [BuildComplete::class, 'afterNotify'],
            ],
        ],
        'cluster_init' => [
            UserNotify::CHANNEL_EMAIL => [
                'subject' => '集群初始化完成通知',
                'template' => 'cluster_init',
            ],
            UserNotify::CHANNEL_BROWSER => [
                'title' => '集群初始化完成',
                'body' => '集群{{cluster.title}}({{vendor}})已初始化完成',
                'fields' => [
                    'cluster.title', 'vendor',
                ],
            ],
            UserNotify::CHANNEL_NOTIFY => [
                'title' => '集群初始化完成',
                'body' => '组织：{{org.title}}<br>
云厂商：{{vendor}}<br>
集群：{{cluster.title}}<br>
操作人：{{operator}}<br>
操作时间：{{created_at}}',
                'fields' => [
                    'org.title', 'vendor', 'cluster.title', 'operator', 'created_at',
                ],
                'before_filter' => [ClusterInit::class, 'beforeNotify']
            ],
        ],
        'cluster_delete' => [
            UserNotify::CHANNEL_EMAIL => [
                'subject' => '集群删除通知',
                'template' => 'cluster_delete',
            ],
            UserNotify::CHANNEL_BROWSER => [
                'title' => '集群删除通知',
                'body' => '集群{{cluster.title}}({{vendor}})被{{operator}}删除',
                'fields' => [
                    'cluster.title', 'vendor', 'operator',
                ],
                'before_filter' => [ClusterDelete::class, 'beforeBrowser'],
            ],
            UserNotify::CHANNEL_NOTIFY => [
                'title' => '集群删除通知',
                'body' => '组织：{{org.title}}<br>
云厂商：{{vendor}}<br>
集群：{{cluster.title}}<br>
操作人：{{operator}}<br>
操作时间：{{created_at}}',
                'fields' => [
                    'org.title', 'vendor', 'cluster.title', 'operator', 'created_at',
                ],
                'before_filter' => [ClusterDelete::class, 'beforeNotify'],
            ],
        ],
        'group_delete' => [
            UserNotify::CHANNEL_EMAIL => [
                'subject' => '项目组删除通知',
                'template' => 'group_delete',
            ],
            UserNotify::CHANNEL_BROWSER => [
                'title' => '项目组删除通知',
                'body' => '项目组{{group.title}}被{{operator}}删除',
                'fields' => [
                    'group.title', 'operator',
                ],
                'before_filter' => [GroupDelete::class, 'beforeBrowser'],
            ],
            UserNotify::CHANNEL_NOTIFY => [
                'title' => '项目组删除通知',
                'body' => '组织：{{org.title}}<br>
项目组：{{group.title}}<br>
操作人：{{operator}}<br>
操作时间：{{created_at}}',
                'fields' => [
                    'org.title', 'group.title', 'operator', 'created_at',
                ],
                'before_filter' => [GroupDelete::class, 'beforeNotify'],
            ],
        ],
        'project_delete' => [
            UserNotify::CHANNEL_EMAIL => [
                'subject' => '项目删除通知',
                'template' => 'project_delete',
            ],
            UserNotify::CHANNEL_BROWSER => [
                'title' => '项目删除通知',
                'body' => '项目【{{group.title}}/{{project.title}}】被{{operator}}删除',
                'fields' => [
                    'group.title', 'project.title', 'operator',
                ],
                'before_filter' => [ProjectDelete::class, 'beforeBrowser'],
            ],
            UserNotify::CHANNEL_NOTIFY => [
                'title' => '项目删除通知',
                'body' => '组织：{{org.title}}<br>
项目：{{group.title}} / {{project.title}}<br>
Git仓库：{{gitrepo}}<br>
涉及实例：{{instance_count}}个<br>
操作人：{{operator}}<br>
操作时间：{{created_at}}',
                'fields' => [
                    'org.title', 'group.title', 'project.title', 'gitrepo', 'instance_count', 'operator', 'created_at',
                ],
                'before_filter' => [ProjectDelete::class, 'beforeNotify'],
            ],
        ],
    ],
];
