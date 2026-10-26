# galaxy-api

CodeGalaxy 的后端服务，基于 [Hyperf](https://hyperf.io)（PHP + Swoole）构建。

CodeGalaxy 是部署在用户自有环境中的研发管理平台，覆盖开发、构建、发布与容器编排管理。系统本身不代售云资源、不提供托管集群、也不包含支付与计费能力。

## 功能

- **集群管理**：接入并管理用户自有的 Docker Swarm 与 Kubernetes 集群
- **项目与流水线**：项目、项目组与环境的管理，以及构建、版本、发布和回滚
- **网关与域名**：内置 Traefik 集群网关，提供 Service 路由、域名绑定与 TLS 证书闭环
- **WAF 安全防护**：按网关或项目路由接入 ModSecurity + OWASP CRS，提供观察/拦截模式、CC 限速、名单、自定义规则和可视化攻击日志
- **监控与告警**：内置 Prometheus 监控底座，采集集群、Service 与应用指标
- **开发环境**：Web IDE / CLI 工作空间
- **应用市场**：一键部署 Gitea、Registry、MySQL、Redis 等常见应用与中间件
- **终端与隧道**：原生 SSH 容器终端、FRP 内网穿透
- **外部资源**：对象存储、镜像仓库、Git 仓库与云厂商账号的统一配置
- **通知**：邮件、站内信、浏览器通知等渠道
- **授权**：面向 CLI 与 IDE 的 OAuth2 授权

## 环境要求

- PHP >= 8.4
- Swoole PHP 扩展 >= 6.0，并关闭 `Short Name`
- PHP 扩展：`openssl`、`json`、`pdo`、`pdo_mysql`、`redis`
- MySQL 8.0
- Redis

## 安装

```bash
composer install
cp .env.example .env
```

编辑 `.env`，至少需要配置数据库连接（`DB_*`）与 Redis（`REDIS_*`）。全部可用配置项见 `.env.example`。

## 运行

```bash
composer start
```

等价于 `php bin/hyperf.php start`，默认监听 `9501` 端口（由 `SERVER_PORT` 控制）。

| 端口 | 服务 |
| --- | --- |
| 9501 | API 服务 |
| 9522 | ssh-relay（原生 SSH 容器终端） |
| 9530 | Helm 服务（仅监听回环，供 Kubernetes 应用市场使用） |

## 其他命令

```bash
composer test      # 单元测试
composer cs-fix    # 代码风格修复
composer analyse   # 静态分析（PHPStan）
```

## Docker

```bash
docker build -t your-registry/galaxy-api:latest .
```

镜像暴露 `9501` 与 `9522` 端口，入口脚本为 `docker/entrypoint-api.sh`。

## WAF 初始化与使用

模块默认关闭，现有网关和路由维持原有转发行为。已有数据库先应用
`database/migrations/0060_add_waf.sql`（默认表前缀为 `galaxy_`，使用自定义前缀时需相应调整），
以及 `0064_add_waf_gateway_credentials.sql`，再配置 `WAF_ENABLED=true` 并重启 API。
`0064` 为所有新部署/同步的 WAF 策略提供独立网关认证，避免 Overlay 内其他容器伪造来源 IP 绕过防护。
凭据由数据库生成并跨 API 实例共享，不进入策略备份，也不转发给源站。
尚未应用 `0064` 时已有实例继续运行，新部署/同步会明确提示初始化；旧实例需同步后才获得此项保护。
新建数据库的 `init.sql` 已包含这些表。
策略历史与安全告警另需应用 `database/migrations/0061_add_waf_operations.sql`。
尚未应用时页面会显示初始化提示，已有防护、日志和策略保存仍可使用。
自动临时封禁另需应用 `database/migrations/0062_add_waf_dynamic_control.sql`，并配置下述检查服务。
地域与 IP 情报另需应用 `database/migrations/0063_add_waf_data_sources.sql`，并启用动态检查服务与共享数据更新。
可通过 `WAF_IMAGE` 使用镜像仓库副本；默认固定经过开发集群验证的 OWASP 官方镜像 digest。
`WAF_COLLECTOR_ENABLED=false` 可单独停用后台日志采集。

在“资源 → 集群 → WAF 防护”选择已有的网关或项目路由，先启用观察模式，
检查安全日志及误报后再切换拦截模式。配置同步状态、检测实例状态、日志采集状态分别展示。
每条启用的路由部署一个独立的 WAF Service，仅加入网关 Overlay 网络，不发布宿主机端口。
检测实例限制为 1 CPU / 256 MiB，每集群最多同时启用 20 条策略。
包括切换和回收阶段的所有实例在内，每集群最多 24 个、单条策略最多 3 个代次；达到上限时
暂缓新建，待旧实例补采回收后重试，避免频繁保存不断增加资源占用。
实例故障时受保护请求会失败，系统不会自动绕过防护。

项目组也可从“项目 → 部署 → WAF 防护”接入。普通项目成员可查看本项目策略和事件；
组织管理员、项目组负责人、项目负责人可修改配置。项目视图要求集群已分配给项目组，
只允许访问该项目的 ProjectRoute；其他项目、公共 GatewayVhost 的配置及事件均不可见。
已有公共网关路由需先通过项目路由导入功能归属到项目，再在项目中设置 WAF。
集群和项目共用同一组接口、策略校验、配置渲染、部署及日志服务，前端共用 `WafPanel`，
项目页仅负责选择集群与传递作用域；规则条件使用独立的 `RuleCondition` 组件。

支持 SQL 注入、XSS、命令执行、文件包含、路径穿越、协议攻击及扫描检测；
上传扩展名限制、敏感路径、自定义 URI/参数/方法/UA 规则；IPv4/IPv6/CIDR 名单和精确路径、CRS 规则 ID 排除。
HTTP 方法白名单默认允许 GET/HEAD/POST/PUT/PATCH/DELETE/OPTIONS，兼容 REST API。
优先使用“精确路径 + 单条规则 ID”处理误报，该路径的其他攻击检测仍然生效。
攻击详情可直接填入 IP 封禁或路径规则排除项，检查并保存后部署。
CC 使用单实例按来源 IP 的 Nginx 漏桶限速（平均请求数/分钟及突发数），观察模式只记录。
新增路径级 CC：最多 10 条精确路径或目录前缀规则，可独立于整站 CC 启用。
相同规则覆盖的路径共享每个 IP 的额度，重叠规则同时生效；查询参数不参与匹配，编码路径由 Nginx 归一化。
路径限速匹配的是经过前置网关重写后、进入 WAF 的路径。更改策略或重建实例会重置限速计数。
自定义规则支持路径、URI、方法、解析参数、指定请求头、Cookie、Referer、原始查询字符串，
每条最多 5 个条件（AND），多条规则独立生效。支持包含、等于、前缀、后缀及否定匹配，
可单独停用或仅记录规则；指定请求头/Cookie 缺失时该条件不匹配。
“复用与备份”提供网站/API/静态站点模板、同视图内策略复制及版本化 JSON 导入导出。
模板保留名单、自定义规则和排除项；导入/复制须经服务端校验并先进入观察模式，保存后部署。
IP 黑名单优先于白名单，白名单和精确路径例外跳过 Web 检测，但不跳过 CC 和请求体硬上限。
支持手动临时 IP/CIDR 封禁：可从事件详情添加 1 小时封禁，或在访问控制中选择 15 分钟、
1 小时、24 小时或 7 天，API 最长允许未来 30 天。最多 100 项，保存部署后生效；到期由
ModSecurity `TIME_EPOCH` 在请求时判断，不依赖后台清理或重新部署，依据检测节点时钟。
临时封禁与永久黑名单均优先于白名单和路径例外；观察模式只记录命中。永久黑名单中的 IP
不会因为同一 IP 的临时封禁到期而放行。已到期配置可手动清理，避免占用条目数。
请求体超过硬上限时，即使观察模式也返回 413。WebSocket 建连经过检测，SSE 保持防护并关闭响应缓冲；
WebSocket 后续数据帧不进入 HTTP 规则引擎。HTTPS 回源校验证书，内部自签证书需要受信任的镜像 CA。

响应敏感信息检测默认关闭，可在共享策略编辑器中启用私钥标记、AWS 访问密钥标识、
异常堆栈/数据库错误和 PHP 源码标记检测。仅检查 `text/plain`、`text/html`、`application/json`，
每次响应最多检查前 1–256 KiB，默认 64 KiB；超过上限的部分继续返回。
响应检测只记录事件，在拦截模式中也不会因这些响应规则主动拦截。
当前 [Nginx 连接器的响应处理顺序](https://github.com/owasp-modsecurity/ModSecurity-nginx/blob/master/src/ngx_http_modsecurity_body_filter.c)
可能已将前面的数据块发出，因此不能承诺通过阶段 4 规则阻止敏感内容返回。
附件下载、压缩响应、SSE、其他响应类型和精确路径排除项跳过响应检测，请求端攻击防护仍生效。
检测是固定特征匹配，不识别所有密钥、编码变体或泄露场景；有误报时可关闭对应类型或排除响应路径。
事件仅记录类型与路径，沿用统一的载荷清理；原始引擎审计日志仍应按下文要求限制访问。
拦截模式的响应事件处置为“检测命中”，若需纳入安全告警，应勾选告警统计动作“仅记录的规则”。

浏览器验证提供 JS 计算挑战和六位图片验证码，默认关闭。复用 `0062` 的配置快照与动态检查服务，
需要 `WAF_CONTROL_URL`、独立签名密钥和可用的 Redis；图片验证码还需所有 API 实例启用 GD。
集群与项目共用“浏览器验证”设置，负责人修改、普通成员只读；模板复制、备份与历史恢复保留这些设置。

观察模式只记录 `BROWSER` 事件并放行。拦截模式对接受 HTML 的 GET 请求返回自包含验证页，
通过后颁发绑定策略配置、域名、IP 和 User-Agent 的通行凭据；不依赖外部 JS、字体或验证码服务。
验证和提交保留当前页面地址，不转发原始查询参数给检查 API，也不自动重放业务 POST。
单次挑战有效期 30–300 秒，挑战只可使用一次，错误答案也会消耗挑战；通行有效期 60 秒至 24 小时。
JS 难度为 12–20 位 SHA-256 工作量证明，默认 16 位；移动设备耗时可能更久。
通行 Cookie 按策略命名，host-only、HttpOnly、SameSite=Lax；网关报告 HTTPS 时添加 Secure。
凭据可在有效期内复用，改变 IP、User-Agent、域名或策略控制配置后需重新验证；密钥轮换同样使其失效。API 实例时钟需保持同步。

未持有凭据的 API、静态资源和表单请求默认返回 403，可显式选择跳过浏览器验证，或添加精确路径/目录前缀例外。
目录 `/api` 匹配自身和子路径，不匹配 `/apix`；路径以到达 WAF 的路径为准。
白名单和全局路径例外跳过浏览器挑战，其他入站规则与来源封禁仍执行。
WebSocket 和 SSE 不显示验证页；需持有通行凭据，或显式配置端点例外/非页面请求跳过。
仅自报 Upgrade 或 Accept 请求头不能绕过默认验证，避免普通访问伪装为流式握手。
选择非页面请求跳过时，自动化客户端可通过非 HTML 请求绕过此项验证；JS 和验证码也不能保证识别所有机器人。
只有业务确实需要浏览器时才开启，先观察并配置接口例外。

签发和提交合计最多每来源 IP 每分钟 20 次、每策略每分钟 1200 次；先检查策略容量，
避免大量新 IP 生成无界计数键。计数键 60 秒过期，挑战状态按验证期限过期；跨 API 实例通过 Redis Lua 原子消费。
网页刷新次数受限，Cookie 不可保存时提示处理，避免无限验证循环。
`X-Galaxy-Waf-Challenge: solve` 为 WAF 保留请求头，会将小于 4 KiB 的验证提交交给检查服务。
只有该验证 JSON 和自有通行 Cookie 被专门处理；业务 Cookie、Authorization、原始正文不进入动态检查请求。
验证页禁止缓存并设 CSP/Referrer-Policy，内部机器凭据不出现在页面或通行凭据中。

自动临时封禁通过 Nginx `auth_request` 调用 Galaxy 的动态检查接口，触发、到期和解除均无需重建网关。
默认关闭；启用前在所有 API 实例设置相同的独立 `WAF_CONTROL_SECRET`（至少 32 字节），
并设置 `WAF_CONTROL_URL` 为检测容器可访问的 Galaxy API 根地址。可用 `openssl rand -hex 32` 生成密钥。
地址不得携带用户名、密码或查询参数；跨不可信网络应使用 HTTPS，证书需被引擎镜像信任。
部署前会从检测实例探测检查接口，失败时保留原网关配置。启用后的受保护请求依赖检查服务，
只有 HTTP 204/403，或浏览器挑战所需的 401，且带有效协议标记的检查结果会被接受；普通 200 页面、缺失标记及检查异常均返回 503，不自动绕过防护。
严格校验由检测容器内部、仅监听回环地址的 Nginx 检查入口执行，不增加对外端口。`/__galaxy_waf_control` 和 `__galaxy_waf_control_health`
是此功能保留的引擎路径；子请求不转发业务 Cookie、Authorization、正文或查询参数。
启用浏览器验证时仅额外转发自有通行凭据及必要的域名、UA、方法、Accept、Upgrade 和无查询参数的路径。

可按来源 IP 在 60–3600 秒窗口内累计入站规则/CC 命中次数或源站 404 次数，达到阈值后封禁 60 秒至 24 小时。
404 条件默认关闭；其他源站 4xx/5xx 不计入。白名单和精确路径例外不参与计数，
路径例外仅排除计数，已触发的来源封禁仍覆盖整条路由；白名单跳过自动封禁判断。
响应泄露提示、自动封禁命中本身、缺少有效访问时间的记录及过期补采记录均不会触发。
基于已补采日志判断，积压会延后触发。信号持久化并去重，采集重试不会重复累计；
同一次旧日志重放不会续期封禁，手动解除会清空该 IP 的信号并保留解除时间水位。
在“自动封禁记录”中分页查询当前/到期/解除状态；负责人可解除，项目成员只读。
集群与项目共用同一组件和权限过滤，跨项目的封禁不可查看或解除。

观察模式只记录 `AUTO` 命中并放行，切换拦截模式后按到期时间执行已有封禁。
每个检测代次使用带签名的配置快照；尚未成功部署的新设置不会改变旧代次的判断方式。
内部检查凭据只服务于对应组织、集群和策略的一个快照中的检查与验证，不能调用管理接口；不放入 UI 或备份。
配置与封禁判断缓存最多 5 秒，删除策略后的凭据撤销及解除的传播也受此上限约束。
更换签名密钥需要同步重新部署启用此功能的策略，避免新 API 拒绝旧检测实例。

自动封禁最多保留每策略 1000 个活动 IP、10000 条封禁记录和 100000 条信号，达到容量时暂停接纳新来源，
页面会显示容量告警。信号清理超过 2 小时的记录，已过期封禁记录保留 30 天。
动态检查快照每策略最多 500 个；网关同步时分批清理超过一天且未被任何检测实例引用的快照，
仍被运行或待回收实例引用的快照会保留。停用后台采集会暂停计数，既有快照仍可检查未到期封禁。
检查接口最近请求样本的失败会显示在采集状态中，并复用安全告警的异常次数、确认及恢复通知。
没有新的成功样本时不会将检查状态自动当作已恢复；这仍不能覆盖整个 API 服务同时不可用的情况。
持续故障或超过本地日志补采范围的数据仍会缺失；自动封禁不能替代 DDoS 清洗或独立的可用性监控。

配置采用新检测实例就绪后切换的方式部署；开启采集时，旧实例保留至少 120 秒供补采，
追平日志后回收实例和 Config。补采持续失败一小时后回收，并记录缺口；关闭采集时立即回收。
持续超过回收等待期的请求仍可能在退出时丢失最后的日志，不能据此承诺完整审计。
切换会滚动更新网关，已有长连接可能重连。尚未接入 WAF 的项目路由继续使用原来的 Swarm 标签服务。
停用路由、删除路由或将策略切换为关闭后，在网关同步时解除防护并清理实例。
Workspace 自动分配的泛域名路由不在此版本的防护范围内。

事件保留 30 天，后台约每 5 秒轮询，通过 Agent 按容器纳秒时间游标分段补采，按实例与请求 ID 去重。
使用 `tail=all` 和 `since/until`，避免 Docker 先截取末尾日志再过滤时间造成漏采。
每次流式读取限制 8 MiB，超过上限则缩小时间窗口；缺失结束块、日志帧截断或超时不推进游标。
策略、实例代次和容器轮流采集，重建后继续读取仍保留的旧容器；审计与访问记录跨批次关联，
关联缓存不保存查询参数或原始 HTTP 载荷。每轮有时间和请求预算，持续积压会在页面展示。
最多回补 24 小时；首次接入从最近 5 分钟开始。Docker 轮转或清理的日志无法恢复，
已确认的容器清理、超期补采缺口保留 31 天展示，并在最近 24 小时内视为采集异常。
Docker 轮转导致的所有缺失无法仅凭时间游标准确识别；安全统计仍以实际采集数据为准。
安全统计汇总实际采集的规则与浏览器验证事件；未完成浏览器验证不代表恶意访问，源站普通 4xx/5xx 不会仅因状态码计为安全事件。
日志可按路由、处置、IP、请求方法、路径片段、规则 ID（数字、`CC`、`AUTO` 或 `BROWSER`）和自定义时间范围筛选，
最长连续 30 天；趋势、来源排行和高频攻击路径排行使用相同筛选。路径中的 `%`、`_`、`!`
按字面匹配，不充当 SQL 通配符。可导出当前筛选结果的 CSV，最多 5000 条，按入库 ID 倒序，
使用 UTF-8 BOM 和 UTC 时间，包含规则摘要；导出与列表共用组织、集群和项目权限范围。
CSV 对可能的表格公式做文本转义；分批读取限制内存，不导出原始 HTTP 头、查询参数或正文。
事件库和界面仅保存请求路径、来源 IP 和规则摘要，不保存查询字符串、HTTP 请求头或正文。
引擎原始审计日志使用 AHZ 部分，不保留完整请求头或正文，但 H 部分仍可能包含命中内容片段；
原始 Docker 日志按每份 10 MiB、最多 3 份轮转，应限制其管理员访问权限。
前置 CDN/代理的真实 IP 信任配置需先在 Traefik 中正确设置；WAF 仅信任网关 Overlay 子网追加的最后一段转发地址。

“版本”保留最近 100 次策略保存，记录设置、操作人、部署结果及恢复来源。
首次保存旧策略时补存原配置；状态未知的原配置和部署失败版本只能查看。
界面可比较历史与当前设置，恢复成功部署的版本会生成新版本，并复用原有部署与权限检查。
界面保存和恢复携带基础版本号，遇到并发修改返回 409，不覆盖其他人的更新。
旧接口调用方可以省略基础版本号以保持兼容；需要并发保护的调用方应始终携带该值。

“安全告警”支持安全事件突增、检测实例无运行任务、采集失败或积压超过 120 秒。
告警默认关闭，每条策略约 30 秒评估一次；可设置事件窗口、阈值、连续异常次数、重复通知间隔和恢复通知。
复用平台站内、邮件、浏览器通知渠道；渠道未配置或发送失败会显示原因，并遵守通知间隔，避免持续重试。
项目路由通知当前项目成员，公共路由通知组织管理员；集群授权移除后不再向该项目发送。
负责人可确认告警暂停此次异常的重复通知；恢复后再次发生会开启新一轮通知。
仅修改告警设置不会重建引擎或滚动更新网关。告警依赖采集进程与 Redis，不能替代独立的外部可用性监控。

离线与开发集群验证命令（从工作区根目录执行）：

```bash
cd galaxy-api
php -d memory_limit=256M vendor/bin/phpunit -c phpunit.xml --filter 'Waf|GatewayVhostStreamingTest|SwarmWebGatewayServiceTest'
cd ..
php -d memory_limit=256M devtools/tests/waf-engine.php --yes
php -d memory_limit=256M devtools/tests/waf-gateway.php --yes
php -d memory_limit=256M devtools/tests/waf-gateway.php --yes --dynamic
php -d memory_limit=256M devtools/tests/waf-gateway.php --yes --dynamic-monitor
php -d memory_limit=256M devtools/tests/waf-logs.php --yes
```

集成脚本硬性校验开发集群 1 的 Swarm ID，只创建临时测试资源，不发布端口，结束后清理。
网关链路测试使用内存 SQLite 元数据，不修改现有 Galaxy 数据库或业务路由。
日志测试生成 2501 条隔离日志，验证超量补采和重放去重；可在同一开发 Manager 加
`--local-stream` 验证真实 HTTP 分块解码，该选项会再次核验本机 Socket 的 Swarm 与节点身份。
这些脚本不编译镜像或本机二进制。
动态网关验证仅可在已核验的开发 Manager 上运行，检查服务只绑定本机 Docker Bridge，
使用私有临时 SQLite 和内存缓存；验证封禁、解除、检查故障及恢复后清理服务与文件。

产品交互参考[堡塔云 WAF](https://www.bt.cn/new/btwaf)和[雷池 SafeLine](https://github.com/chaitin/SafeLine)；
攻击检测使用[ModSecurity](https://github.com/owasp-modsecurity/ModSecurity)、[OWASP CRS](https://github.com/coreruleset/coreruleset)
及其[官方容器配置](https://github.com/coreruleset/modsecurity-crs-docker)，没有复制商业产品代码。

### 开源项目对照与取舍（2026-10-23）

| 参考项目 | 查看的能力 | 在 Galaxy 中的落实 |
| --- | --- | --- |
| [aaWAF / 宝塔](https://github.com/aaPanel/BT-WAF)（本地 `tmp/aaWAF`） | 全局/站点规则、CC、名单、敏感文件、HTTP 方法、规则命中日志 | 按已有路由接入防护、可视化开关、CC、上传扩展名、名单与攻击详情 |
| [SafeLine](https://github.com/chaitin/SafeLine) | 反向代理接入、Web ACL、基于 IP 的限流、攻击防护交互 | 沿用 Galaxy 的网关/TLS/域名入口，增加独立防护与事件页面 |
| [ModSecurity + OWASP CRS](https://github.com/coreruleset/coreruleset) | 分阶段正文检测、异常分数、严格度、[规则排除调优](https://coreruleset.org/docs/2-how-crs-works/2-3-false-positives-and-tuning/) | 复用实际检测引擎，暴露严格度、阈值、按路径的单规则排除，并在开发集群验证 |
| [Coraza](https://github.com/corazawaf/coraza) | SecLang/CRS 兼容、引擎与连接器分离、Traefik 集成 | 保持策略、配置渲染、路由绑定和事件解析的边界；其 Traefik WASM 接入当前标为实验性，本版选择已验证的独立检测实例 |
| [BunkerWeb](https://github.com/bunkerity/bunkerweb) | Nginx + CRS、Swarm 接入、限流、模块化配置与 Web UI | 独立 Swarm Service、按路由启用、资源硬限制、配置与运行状态分别呈现 |
| [open-appsec](https://github.com/openappsec/openappsec) | 应用/API 防护、声明式策略、观察与学习阶段 | 以观察模式开始，保留可核查的事件与人工调优流程；学习模型未纳入此次实现 |
| [NAXSI](https://github.com/wargio/naxsi) | 基于观察与应用白名单的误报调优 | 从事件详情生成待确认的路径规则排除，避免为了单处误报跳过整条路由检测 |

这些项目的准确率、检测率数据使用各自的样本和配置，不能据此推断 Galaxy 的生产防护效果。
本次验收覆盖实际引擎和独立网关链路的功能正确性；自动临时封禁另有独立检查服务与开发验证。
JS/验证码挑战已接入受限的一次性挑战和签名通行凭据；地域和 IP 情报使用独立数据源更新与失效机制，
来源许可、版本和状态可在共享界面查看。学习模型未纳入此次实现。

### 第二轮功能差距与落实

| 对照项 | 参考依据 | 本轮结果 |
| --- | --- | --- |
| URL 级 CC 与登录接口保护 | [宝塔 URL CC](https://www.aapanel.com/docs/Function/WAF.html)、[BunkerWeb Limit](https://docs.bunkerweb.io/latest/features/#limit) | 已实现精确路径/目录前缀、按 IP 的独立限速及观察模式 |
| 复合访问控制 | 宝塔自定义规则中的路径、请求头、UA、Referer；本地 aaWAF `modules/customize.go` | 已实现 1–5 条 AND 条件、指定 Header/Cookie、启用开关和仅记录动作；兼容旧单条件配置 |
| 策略复用与备份 | 宝塔导入/导出、aaWAF 规则复制 | 已实现模板、当前视图内复制、v1 JSON 备份、服务端校验，不导出资源标识 |
| 项目组自助防护 | Galaxy 既有组织/项目组/项目权限及集群授权模型 | 共用集群服务与页面，按项目过滤策略和全部事件统计；负责人修改、普通成员只读 |
| JS / 验证码挑战 | SafeLine、BunkerWeb Antibot | 已接入 JS 工作量证明/图片验证码、作用域签名凭据、限时单次验证、API/WS/SSE 例外及共享 UI；观察模式只记录 |
| 高频攻击/404 自动临时封禁 | aaWAF 攻击次数/目录扫描、[BunkerWeb Bad behavior](https://docs.bunkerweb.io/latest/features/#bad-behavior) | 已接入持久化信号去重与封禁、到期/手动解除、项目权限及共享 UI；使用动态检查，不为每次封禁更新网关 |
| 地域控制、IP 情报 | 宝塔 Region、BunkerWeb Country/Blacklist | 已接入 MMDB 国家/地区控制、IPv4/IPv6 多情报源、后台更新与署名、数据过期处理、项目共享配置及事件告警 |
| 响应敏感信息检测 | 宝塔 Sensitive information detection、ModSecurity 响应阶段 | 已实现可选固定特征检测和响应路径排除；限制 MIME/体积，跳过附件、压缩及 SSE，仅记录，并在真实引擎验证 |

总请求量、QPS 和流量沿用已有网关监控；WAF 页面统计实际采集的安全事件，避免重复建设监控采集。
规则设置保存在已有策略 JSON 内；策略历史与告警使用新增的 `0061_add_waf_operations.sql`。
代码更新后需重启 API 并更新前端；
已部署的策略在下次保存/同步时生成新配置，不会因更新源代码自动修改线上网关。

自动封禁的回归命令位于 `devtools`，需先配置开发调试凭据：

```bash
# 仅连接本机开发 MySQL；使用会话临时表，不执行实际迁移。
php -d memory_limit=256M -d zend.exception_ignore_args=1 tests/waf-dynamic-schema.php
# 以下命令逐个运行；硬性核验开发集群 1 的 Swarm 身份，只操作带测试标签的隔离资源。
php -d memory_limit=256M -d zend.exception_ignore_args=1 tests/waf-gateway.php --yes --dynamic
php -d memory_limit=256M -d zend.exception_ignore_args=1 tests/waf-gateway.php --yes --dynamic-monitor
php -d memory_limit=256M -d zend.exception_ignore_args=1 tests/waf-gateway.php --yes --browser
php -d memory_limit=256M -d zend.exception_ignore_args=1 tests/waf-gateway.php --yes --captcha
php -d memory_limit=256M -d zend.exception_ignore_args=1 tests/waf-gateway.php --yes --browser-prefix
php -d memory_limit=256M -d zend.exception_ignore_args=1 tests/waf-gateway.php --yes --browser-monitor
php -d memory_limit=256M -d zend.exception_ignore_args=1 tests/waf-gateway.php --yes --network
php -d memory_limit=256M -d zend.exception_ignore_args=1 tests/waf-gateway.php --yes --network-monitor
php -d memory_limit=256M -d zend.exception_ignore_args=1 tests/waf-gateway.php --yes --network-skip
# 隔离元数据；仅下载官方公开数据到 /tmp，跨运行保留每日更新间隔。
php -d memory_limit=256M -d zend.exception_ignore_args=1 tests/waf-data-providers.php --yes
# 回环 HTTP 下载边界与共用 Vue 组件浏览器测试；不编译前端。
python3 tests/fixtures/waf-data-http.py
python3 tests/waf-ui-preview.py
python3 tests/waf-browser-ui.py --network-only
# 下面两项分别验证真实本机 Redis Lua 和无依赖的浏览器计算实现。
php -d memory_limit=256M -d zend.exception_ignore_args=1 tests/waf-browser-store.php
node tests/waf-browser-proof.js
```

回归覆盖真实引擎日志触发封禁、观察/拦截、手动解除、错误 2xx/协议标记缺失、
检查服务中断与恢复、告警状态采样和资源回收。`tests/waf-ui-preview.py` 可生成共用 Vue 组件的本地数据预览，
不连接集群、不编译前端；生产效果和容量仍需独立评估。

### 第三轮：封禁到期与事件运维

本轮补齐手动临时封禁、到期自动失效、封禁/白名单命中日志的优先级、事件高级筛选、
高频攻击路径排行以及 CSV 导出。设置仍存于已有策略 JSON，无需新增表或迁移。
`IpBans` 编辑器和事件筛选、导出继续由集群与项目共用。自动封禁需独立应用 `0062` 并配置检查服务，
不会因升级代码而自动启用；JS/验证码挑战、地域数据和情报订阅复用同一检查基础。

### 地域与 IP 情报共享数据

组织管理员在“WAF 防护 → 共享数据源”添加并检查数据源，项目组负责人或项目负责人
在本项目策略的“地域与 IP 情报”中选择数据。普通成员只读，项目端不返回下载地址、查询密钥或下载错误详情。
未完成首次验证的数据无法用于启用策略；同一组织可共享至已授权的多个项目/集群。
内置 [DB-IP Country Lite](https://db-ip.com/db/download/ip-to-country-lite)（CC BY 4.0，月度地域库）和
[Spamhaus DROP IPv4/IPv6](https://www.spamhaus.org/blocklists/do-not-route-or-peer/)（保留版权、生成时间和来源），
也支持自有/已授权的 MMDB、gzip MMDB 或逐行 IP/CIDR、Spamhaus JSONL。
自定义数据需填写许可地址和署名。没有复制或分发本地 aaWAF 的地域数据库。
管理界面和数据文件元信息保留来源署名；使用地域结果的业务页面需按 DB-IP 条款保留相应链接。

先应用 `0062`、`0063`、`0064`，配置动态检查地址/密钥，再为所有 API 实例挂载同一
`WAF_DATA_DIRECTORY` 持久化目录（默认 `runtime/waf-data`，目录私有，实例需使用相同可读写 UID）。
`WAF_DATA_UPDATER_ENABLED=true` 且 `WAF_ENABLED=true` 时启动后台更新进程。
数据目录缺失的 API 实例会按不可用处理；只共享 SQL 而不共享文件无法运行此功能。
组织管理员的“检查”仅排队，下载在后台执行；状态每 15 秒无加载遮罩刷新。
已验证版本以内容哈希保存并原子发布，失败更新保留有效旧版；304 不延长地域库/情报的实际生成时间。
官方源跨组织共享至少 24 小时的抓取间隔，自定义源至少 1 小时；进程中断、重复点击也不会立即重抓。
DB-IP 默认最长使用 62 天，DROP 默认 3 天、最多 7 天。引用中的数据源拒绝删除，停用或缩短期限会影响关联策略。

默认在文件缺失、过期或读取失败时返回 503；可在每项策略显式选择“跳过此项检查”，
其他防护继续执行，事件和采集状态会标记降级并参与告警。观察模式只记录地域/情报命中，
数据故障仍遵守独立的故障处置。地域支持拒绝所选或仅允许所选地区及未知位置的处理；
使用实际 `country.iso_code`，不以注册地区代替位置。内网 IP 可能未知，定位结果不代表身份或精确位置。
白名单和全局精确路径例外继续生效；策略备份仅保存稳定数据源标识，跨组织导入前需配置相应数据源。

下载仅允许 HTTP(S)，外部源必须 HTTPS；校验证书、不使用代理、不携带业务凭据、不跟随重定向，
解析并固定公共 DNS 地址，拒绝本机、私网、混合 DNS 结果和保留地址。
只有平台 `config/autoload/waf.php` 的 `data_trusted_hosts` 可显式授权内部 HTTP/私网数据主机。
单次下载上限 16 MiB、解压 MMDB 上限 64 MiB、情报上限 10 万网段、每组织最多 16 个源，
每策略最多选择 8 个情报源。情报合并为磁盘区间索引，逐请求查询无需完整加载名单。
旧孤立版本保留 7 天后按批回收；运维需为共享目录预留空间并按实际业务验证容量与误报。

## 许可证

Apache-2.0，详见 [LICENSE](LICENSE)。
