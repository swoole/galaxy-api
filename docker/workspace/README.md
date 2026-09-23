# CodeGalaxy Workspace image

This image is the common development runtime for both Web IDE and terminal-only workspaces.
Application source and user state are stored in a Docker volume mounted at `/workspace`; source
is checked out under `/workspace/repository`, while user CLI configuration is stored under
`/workspace/.galaxy-home`. The container filesystem is disposable. Git commits pushed to the
configured repository are the durable development output.

Included baseline tools:

- OpenVSCode Server (latest stable image line)
- Git and Git LFS
- Node.js 22, npm and Corepack
- Python 3, pip and venv
- C/C++ build toolchain
- ripgrep and tmux
- Claude Code, Codex CLI and CodeBuddy Code
- curl, wget, jq, rsync, SSH client and common archive tools

Build and publish the image to a registry reachable by every Swarm manager:

```bash
docker build -t registry.cn-shanghai.aliyuncs.com/swoole-public/workspace:2026.07 docker/workspace
docker push registry.cn-shanghai.aliyuncs.com/swoole-public/workspace:2026.07
```

Configure the API with the published reference:

```dotenv
APP_WORKSPACE_IMAGE=registry.cn-shanghai.aliyuncs.com/swoole-public/workspace:2026.07
```

Hyperf 在 Master 启动时读取该配置。修改 `.env` 后需要完整重启服务；仅发送
`SIGUSR1` 重载 Worker 不会刷新 Master 已加载的配置值。

AI CLI packages are resolved when this versioned image is built. Rebuild and publish a new image
tag to upgrade them; never mutate a running Workspace image in place. Authentication is performed
by the user inside the terminal, and the resulting user configuration is persisted in the private
Workspace volume. Provider credentials must never be stored in the image layer, Service command
arguments, or ordinary database JSON.

The default AI CLI versions are pinned by Docker build arguments so the same source produces a
repeatable toolchain. Upgrade them explicitly when publishing a new Workspace image:

```bash
docker build \
  --build-arg CLAUDE_CODE_VERSION=2.1.210 \
  --build-arg CODEX_VERSION=0.144.4 \
  --build-arg CODEBUDDY_CODE_VERSION=2.121.2 \
  -t registry.cn-shanghai.aliyuncs.com/swoole-public/workspace:2026.07 \
  docker/workspace
```

This is the common baseline used by both Web IDE and terminal-only AI development. Projects that
need additional language runtimes (for example PHP, Go, Java or Rust) should publish a derived,
versioned Workspace image and select it when creating the Workspace. Do not install project
toolchains interactively and rely on the container writable layer, because that layer is disposable.
