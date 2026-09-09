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
        实例部署通知：
    </div>

    <div style="padding-left: 2em;">
        <p>
            组织：{{$org['title']}} <br>
            项目：{{$group['title']}} / {{$project['title']}} <br>
            部署版本：<span class="version-note">
                <span class="version-note-branch">
                    {{$image['commit_id'] ? $image['branch'] : 'tag'}}
                </span>
                <span class="version-note-commitid">
                {{$image['commit_id'] ? substr($image['commit_id'], 0, 7) : $image['branch']}}
                </span>
            </span><br>
            备注：{{$remark}}<br>
            操作人：{{$operator_info['realname']}}({{$operator_info['nickname']}})<br>
            操作时间：{{date('Y-m-d H:i:s', $operated_at)}}<br>
            部署对象：<br>
            <p style="padding-left: 2em;">
                @foreach ($pods as $pod)
                    {{$pod['env']['title']}} / {{$pod['cluster']['title']}} / {{$pod['pod']['group']}} <br>
                @endforeach
            </p>
        </p>
    </div>
</div>
</body>
</html>
