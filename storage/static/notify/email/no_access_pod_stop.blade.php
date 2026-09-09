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
        域名无访问实例停止通知：
    </div>

    <div style="padding-left: 2em;">
        <p>
            组织：{{$org['title']}} <br>
            项目：{{$group['title']}} / {{$project['title']}} <br>
            原因：{{$reason}}<br>
            停止时间：{{date('Y-m-d H:i:s', $stopped_at)}}<br>
            实例：<br>
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
