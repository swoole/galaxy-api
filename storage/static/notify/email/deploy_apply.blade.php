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
        您有一个部署申请待审批：
    </div>

    <div style="padding-left: 2em;">
        <p>
            组织：{{$org['title']}}<br>
            项目：{{$group['title']}} / {{$project['title']}} <br>
            部署版本：<span class="version-note">
                <span class="version-note-branch">
                    {{$version['commit_id'] ? $version['branch'] : 'tag'}}
                </span>
                <span class="version-note-commitid">
                {{$version['commit_id'] ? substr($version['commit_id'], 0, 7) : $version['branch']}}
                </span>
            </span><br>
        </p>
        <p>
            申请ID：{{$audit['id']}}<br>
            申请人：{{$audit['applicant']['realname']}}({{$audit['applicant']['nickname']}})<br>
            申请时间：{{date('Y-m-d H:i:s', $audit['apply_at'])}}<br>
            申请原因：{{$audit['apply_reason']}}
        </p>

        <p>
            部署对象：<br>
            <p style="padding-left: 2em;">
                @foreach ($pods as $pod)
                    {{ $pod['env']['title'] }} / {{ $pod['cluster']['title'] }} / {{ $pod['pod']['group'] }} <br>
                @endforeach
            </p>
        </p>
    </div>
</div>
</body>
</html>
