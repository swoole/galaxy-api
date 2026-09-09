# galaxy-api

CodeGalaxy 的后端服务，基于 [Hyperf](https://hyperf.io)（PHP + Swoole）构建。

CodeGalaxy 是部署在用户自有环境中的研发管理平台，覆盖开发、构建、发布与容器编排管理。系统本身不代售云资源、不提供托管集群、也不包含支付与计费能力。

## 功能

- **集群管理**：接入并管理用户自有的 Docker Swarm 与 Kubernetes 集群
- **项目与流水线**：项目、项目组与环境的管理，以及构建、版本、发布和回滚
- **网关与域名**：内置 Traefik 集群网关，提供 Service 路由、域名绑定与 TLS 证书闭环
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

## 许可证

Apache-2.0，详见 [LICENSE](LICENSE)。
