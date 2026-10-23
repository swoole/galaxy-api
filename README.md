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
再配置 `WAF_ENABLED=true` 并重启 API。新建数据库的 `init.sql` 已包含这些表。
可通过 `WAF_IMAGE` 使用镜像仓库副本；默认固定经过开发集群验证的 OWASP 官方镜像 digest。
`WAF_COLLECTOR_ENABLED=false` 可单独停用后台日志采集。

在“资源 → 集群 → WAF 防护”选择已有的网关或项目路由，先启用观察模式，
检查攻击日志及误报后再切换拦截模式。配置同步状态、检测实例状态、日志采集状态分别展示。
每条启用的路由部署一个独立的 WAF Service，仅加入网关 Overlay 网络，不发布宿主机端口。
检测实例限制为 1 CPU / 256 MiB，每集群最多同时启用 20 条策略。
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
请求体超过硬上限时，即使观察模式也返回 413。WebSocket 建连经过检测，SSE 保持防护并关闭响应缓冲；
WebSocket 后续数据帧不进入 HTTP 规则引擎。HTTPS 回源校验证书，内部自签证书需要受信任的镜像 CA。

配置采用新检测实例就绪后切换的方式部署；成功后回收旧实例和 Config。
切换会滚动更新网关，已有长连接可能重连。尚未接入 WAF 的项目路由继续使用原来的 Swarm 标签服务。
停用路由、删除路由或将策略切换为关闭后，在网关同步时解除防护并清理实例。
Workspace 自动分配的泛域名路由不在此版本的防护范围内。

事件保留 30 天，每 30 秒通过 Agent 采集每条策略最近 2000 行日志，并按请求 ID 去重。
高流量、采集故障或离线期间可能存在缺口；页面会显示采集错误。
安全统计只计算实际采集到的攻击事件，不把源站普通 4xx/5xx 计为攻击。
事件库和界面仅保存请求路径、来源 IP 和规则摘要，不保存查询字符串、HTTP 请求头或正文。
引擎原始审计日志使用 AHZ 部分，不保留完整请求头或正文，但 H 部分仍可能包含命中内容片段；
原始 Docker 日志按每份 10 MiB、最多 3 份轮转，应限制其管理员访问权限。
前置 CDN/代理的真实 IP 信任配置需先在 Traefik 中正确设置；WAF 仅信任网关 Overlay 子网追加的最后一段转发地址。

离线与开发集群验证命令（从工作区根目录执行）：

```bash
cd galaxy-api
php -d memory_limit=256M vendor/bin/phpunit -c phpunit.xml --filter 'Waf|GatewayVhostStreamingTest|SwarmWebGatewayServiceTest'
cd ..
php -d memory_limit=256M devtools/tests/waf-engine.php --yes
php -d memory_limit=256M devtools/tests/waf-gateway.php --yes
```

集成脚本硬性校验开发集群 1 的 Swarm ID，只创建临时测试资源，不发布端口，结束后清理。
网关链路测试使用内存 SQLite 元数据，不修改现有 Galaxy 数据库或业务路由。
这些脚本不编译镜像或本机二进制。

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
本次验收覆盖实际引擎和独立网关链路的功能正确性；JS/验证码挑战、地域数据库、自动临时封禁、
情报订阅和学习模型仍需独立的数据源、效果测试及运行机制，未作为已实现功能展示。

### 第二轮功能差距与落实

| 对照项 | 参考依据 | 本轮结果 |
| --- | --- | --- |
| URL 级 CC 与登录接口保护 | [宝塔 URL CC](https://www.aapanel.com/docs/Function/WAF.html)、[BunkerWeb Limit](https://docs.bunkerweb.io/latest/features/#limit) | 已实现精确路径/目录前缀、按 IP 的独立限速及观察模式 |
| 复合访问控制 | 宝塔自定义规则中的路径、请求头、UA、Referer；本地 aaWAF `modules/customize.go` | 已实现 1–5 条 AND 条件、指定 Header/Cookie、启用开关和仅记录动作；兼容旧单条件配置 |
| 策略复用与备份 | 宝塔导入/导出、aaWAF 规则复制 | 已实现模板、当前视图内复制、v1 JSON 备份、服务端校验，不导出资源标识 |
| 项目组自助防护 | Galaxy 既有组织/项目组/项目权限及集群授权模型 | 共用集群服务与页面，按项目过滤策略和全部事件统计；负责人修改、普通成员只读 |
| JS / 验证码挑战 | SafeLine、BunkerWeb Antibot | 待实现：需签名令牌、过期/重放验证、API 与 WebSocket 例外及误报验证 |
| 高频攻击/404 自动临时封禁 | aaWAF 攻击次数/目录扫描、BunkerWeb Bad behavior | 待实现：需独立的低延迟封禁分发、到期解封与持久化；不能为每次封禁滚动重启网关 |
| 地域控制、IP 情报 | 宝塔 Region、BunkerWeb Country/Blacklist | 待实现：需明确数据许可、更新和失效策略，不能把 UA 字符串或 IP 名单当作可靠身份 |
| 响应敏感信息检测 | 宝塔 Sensitive information detection | 待实现：需限定响应类型/体积并验证 SSE、下载与业务兼容性 |

总请求量、QPS 和流量沿用已有网关监控；WAF 页面统计实际采集的安全事件，避免重复建设监控采集。
以上新增设置保存在已有策略 JSON 内，无需新增数据库迁移。代码更新后需重启 API 并更新前端；
已部署的策略在下次保存/同步时生成新配置，不会因更新源代码自动修改线上网关。

## 许可证

Apache-2.0，详见 [LICENSE](LICENSE)。
