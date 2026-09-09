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
        尊敬的 {{$nickname}}({{$user['email']}})：
    </div>

    <div style="padding-left: 2em;">
        <p>
            由于托管Git服务升级，CodeGalaxy将于今天（2022年08月19日）22:00 ~ 23:00 对托管Git服务进行维护，期间涉及到使用托管Git服务的项目将无法使用构建镜像、访问代码仓库、提交代码、拉取代码等功能，项目的域名访问、实例操作等功能不受影响，建议您提前对涉及到托管Git服务的代码仓库拉取到本地进行备份，备份示例命令如下：
        </p>
        <p>
            <code>
                git clone &lt;your project git src&gt; &lt;backup path&gt; <br>
                cd &lt;backup path&gt; <br>
                git branch -r | grep -v '\->' | while read remote; do git branch --track "${remote#origin/}" "$remote"; done <br>
                git fetch --all && git pull --all --tags
            </code>
        </p>
        <p>
            迁移完成之后，您涉及使用托管Git服务的仓库地址将会变化，请留意您收到的邮件、站内信通知，通知中将会告知您变化前后的仓库地址以及如何更改您本地代码库的地址。
        </p>
        <p>
            给您造成不便深感歉意，感谢您对CodeGalaxy团队的支持。
        </p>
    </div>
</div>
</body>
</html>
