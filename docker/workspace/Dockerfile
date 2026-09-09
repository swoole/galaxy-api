FROM node:22-bookworm-slim AS node-runtime

FROM gitpod/openvscode-server:latest

USER root

COPY --from=node-runtime /usr/local/ /usr/local/

RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        bash \
        build-essential \
        ca-certificates \
        curl \
        git \
        git-lfs \
        jq \
        less \
        openssh-client \
        python3 \
        python3-pip \
        python3-venv \
        python-is-python3 \
        ripgrep \
        rsync \
        sudo \
        tmux \
        unzip \
        wget \
        zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* \
    && git lfs install --system

# These are terminal clients only. Authentication remains user-specific and is
# persisted in the Workspace volume; no provider credential is baked in.
ARG CLAUDE_CODE_VERSION=2.1.210
ARG CODEX_VERSION=0.144.4
ARG CODEBUDDY_CODE_VERSION=2.121.2
RUN npm install -g \
        "@anthropic-ai/claude-code@${CLAUDE_CODE_VERSION}" \
        "@openai/codex@${CODEX_VERSION}" \
        "@tencent-ai/codebuddy-code@${CODEBUDDY_CODE_VERSION}" \
    && npm cache clean --force

RUN mkdir -p /workspace/repository /workspace/.galaxy-home /home/workspace/.config \
    && chown -R openvscode-server:openvscode-server /workspace /home/workspace

COPY galaxy-git-askpass galaxy-workspace-entrypoint /usr/local/bin/
RUN chmod 0755 /usr/local/bin/galaxy-git-askpass /usr/local/bin/galaxy-workspace-entrypoint

LABEL com.codegalaxy.workspace="true" \
      com.codegalaxy.workspace.tools="git,nodejs,npm,python,python3,pip,build-essential,claude,codex,codebuddy" \
      com.codegalaxy.workspace.claude-code.version="${CLAUDE_CODE_VERSION}" \
      com.codegalaxy.workspace.codex.version="${CODEX_VERSION}" \
      com.codegalaxy.workspace.codebuddy-code.version="${CODEBUDDY_CODE_VERSION}"

USER root
ENV HOME=/workspace/.galaxy-home \
    XDG_CONFIG_HOME=/workspace/.galaxy-home/.config
WORKDIR /workspace/repository

EXPOSE 3000
