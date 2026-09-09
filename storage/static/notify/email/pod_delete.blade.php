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
    </style>
</head>
<body>
<div>
    <div style="font-weight: 600;">
        实例销毁通知：
    </div>

    <div style="padding-left: 2em;">
        <p>
            组织：{{$org['title']}} <br>
            项目：{{$group['title']}} / {{$project['title']}} <br>
            实例：{{$env['title']}} / {{$cluster['title']}} / {{$pod['group']}} <br>
            涉及域名：{{implode('、', $domains)}}<br>
            操作人：{{$operator_info['realname']}}({{$operator_info['nickname']}})<br>
            操作时间：{{date('Y-m-d H:i:s', $operated_at)}}
        </p>
    </div>
</div>
</body>
</html>
