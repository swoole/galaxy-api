# Default Dockerfile
#
# @link     https://www.hyperf.io
# @document https://hyperf.wiki
# @contact  group@hyperf.io
# @license  https://github.com/hyperf/hyperf/blob/master/LICENSE

FROM golang:1.24-alpine AS ssh-relay-builder

ENV GOPROXY=https://goproxy.cn,direct
WORKDIR /src
COPY ssh-relay/go.mod ssh-relay/go.sum ./
RUN go mod download
COPY ssh-relay/ ./
RUN CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /out/galaxy-ssh-relay .

FROM golang:1.24-alpine AS helm-service-builder

ENV GOPROXY=https://goproxy.cn,direct
WORKDIR /src
COPY helm-service/go.mod helm-service/go.sum ./
RUN go mod download
COPY helm-service/ ./
RUN CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /out/galaxy-helm-service .

FROM hyperf/hyperf:8.4-alpine-v3.21-swoole

##
# ---------- env settings ----------
##
# --build-arg timezone=Asia/Shanghai
ARG timezone \
    SSH_PRIVATE_KEY

ENV TIMEZONE=${timezone:-"Asia/Shanghai"} \
    APP_ENV=prod \
    SCAN_CACHEABLE=(true)

# update
RUN set -ex \
    # add openssh
    && sed -i 's/dl-cdn.alpinelinux.org/mirrors.aliyun.com/g' /etc/apk/repositories \
    && apk add openssh \
    # show php version and extensions
    && php -v \
    && php -m \
    && php --ri swoole \
    #  ---------- some config ----------
    && composer --version \
    && composer config -g -l \
    # 配置PHP
    && { \
        echo "upload_max_filesize=128M"; \
        echo "post_max_size=128M"; \
        echo "memory_limit=1G"; \
        echo "date.timezone=Asia/Shanghai"; \
    } | tee /etc/php84/conf.d/overrides.ini \
    && echo "swoole.use_shortname = 'Off'" >> /etc/php84/conf.d/50_swoole.ini \
    # 设置阿里云国内镜像源，可以注释或者改成其他的国内镜像源
    && composer config -g repo.packagist composer https://mirrors.aliyun.com/composer/ \
    # - config timezone
    && ln -sf /usr/share/zoneinfo/${TIMEZONE} /etc/localtime \
    && echo "${TIMEZONE}" > /etc/timezone \
    # - config ssh private key
    && mkdir -p ~/.ssh/ \
    && echo "$SSH_PRIVATE_KEY" > ~/.ssh/id_rsa \
    && chmod 0600 ~/.ssh/id_rsa \
    # disable ssh known hosts check
    && [ `grep StrictHostKeyChecking /etc/ssh/ssh_config | wc -l` -gt 0 ] && sed -i 's/^ *#*[[:space:]]*StrictHostKeyChecking.*/StrictHostKeyChecking no/g' /etc/ssh/ssh_config || echo 'StrictHostKeyChecking no' >> /etc/ssh/ssh_config \
    # ---------- clear works ----------
    && rm -rf /var/cache/apk/* /tmp/* /usr/share/man \
    && echo -e "\033[42;37m Build Completed :).\033[0m\n"

WORKDIR /opt/www

COPY . .
COPY --from=ssh-relay-builder /out/galaxy-ssh-relay /usr/local/bin/galaxy-ssh-relay
COPY --from=helm-service-builder /out/galaxy-helm-service /usr/local/bin/galaxy-helm-service
RUN [ -f .env.example ] && cp .env.example .env
RUN composer install --no-dev -o && php bin/hyperf.php \
    && chmod +x docker/entrypoint-api.sh

EXPOSE 9501 9522

ENTRYPOINT ["docker/entrypoint-api.sh"]
