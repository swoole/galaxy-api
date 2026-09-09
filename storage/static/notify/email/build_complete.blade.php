<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <meta charset="utf-8" />
    <style>
        code {
            color: #5e6d82;
            background-color: #e6effb;
            margin: 0 4px;
            display: inline-block;
            padding: 1px 5px;
            border-radius: 3px;
            height: 1.5em;
            line-height: 1.5em;
        }
        .text-danger {
            color: #f56c6c;
        }
        .text-success {
            color:#67C23A;
        }
        .version-note {
            color: #fff;
            line-height: 20px;
            font-size: 12px;
        }
        .version-note .version-note-branch {
            display: inline-block;
            background: #4B4B4B;
            padding: 0 5px;
            border-radius: 4px 0 0 4px;
        }
        .version-note .version-note-branch + .version-note-commitid {
            border-radius: 0 4px 4px 0;
        }
        .version-note .version-note-commitid {
            display: inline-block;
            background: #59C52D;
            padding: 0 5px;
            border-radius: 4px;
        }
    </style>
</head>
<body>
<div>
    <div style="font-weight: 600;">
        镜像构建{{$result}}通知：
    </div>

    <div style="padding-left: 2em;">
        <p>
            组织：{{$org['title']}} <br>
            版本：<span class="version-note">
                    <span class="version-note-branch">
                        {{$build['commit_id'] ? $build['branch'] : 'tag'}}
                    </span>
                    <span class="version-note-commitid">
                    {{$build['commit_id'] ? substr($build['commit_id'], 0, 7) : $build['branch']}}
                    </span>
                </span><br>
            流水线：{{$build['pipeline'] ? $build['pipeline']['title'] : '已删除'}}<br>
            备注：{{$build['remark']}}<br>
            操作人：{{$build['creator_info']['realname']}}({{$build['creator_info']['nickname']}})<br>
            构建时间：{{date('Y-m-d H:i:s', $build['start_at'])}}<br>
            完成时间：{{date('Y-m-d H:i:s', $build['end_at'])}}<br>
            构建耗时：{{$duration}}<br>
            构建结果：@if($success)
                        <span class="text-success">构建成功</span>
                    @else
                        <span class="text-danger">构建失败</span>
                    @endif<br>
        </p>
    </div>
</div>
</body>
</html>
