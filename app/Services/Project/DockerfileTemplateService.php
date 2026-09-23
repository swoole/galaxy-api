<?php

namespace App\Services\Project;

use App\Exception\AppException;
use App\Model\DockerfileTemplate;

class DockerfileTemplateService
{
    public function catalog(array $filters = []): array
    {
        $config = (array) config('dockerfile_templates');
        $active = DockerfileTemplate::where('source', 'builtin')->where('org_id', 0)
            ->where('status', 'active')->pluck('template_version', 'template_key')->toArray();
        $templates = array_map(function (array $template): array {
            $template['dockerignore_profile'] = 'default-secure-v1';
            return $template;
        }, array_values(array_filter(
            (array) ($config['templates'] ?? []),
            static fn (array $template): bool => isset($active[(string) ($template['key'] ?? '')])
        )));
        $languages = [];
        foreach ($templates as $template) {
            $key = (string) $template['language'];
            $languages[$key] = ['key' => $key, 'label' => (string) $template['language_label']];
        }
        $templates = array_values(array_filter($templates, static function (array $template) use ($filters): bool {
            foreach (['language', 'framework', 'workload'] as $field) {
                if (($filters[$field] ?? '') !== '' && (string) ($template[$field] ?? '') !== (string) $filters[$field]) {
                    return false;
                }
            }
            $keyword = mb_strtolower(trim((string) ($filters['keyword'] ?? '')));
            if ($keyword !== '' && ! str_contains(mb_strtolower(implode(' ', [
                (string) ($template['title'] ?? ''), (string) ($template['summary'] ?? ''),
                (string) ($template['language_label'] ?? ''), (string) ($template['framework_label'] ?? ''),
            ])), $keyword)) {
                return false;
            }
            return true;
        }));
        $total = count($templates);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = max(1, min(100, (int) ($filters['pagesize'] ?? 100)));
        $templates = array_slice($templates, ($page - 1) * $pageSize, $pageSize);
        return [
            'schema_version' => (string) ($config['version'] ?? '1'),
            'timezones' => (array) ($config['timezones'] ?? ['UTC']),
            'languages' => array_values($languages),
            'templates' => $templates,
            'mirrors' => (array) ($config['mirrors'] ?? []),
            'dockerignore_profile' => 'default-secure-v1',
            'pagination' => ['page' => $page, 'pagesize' => $pageSize, 'total' => $total],
        ];
    }

    public function render(string $templateKey, array $options): array
    {
        $template = $this->find($templateKey);
        $runtimeVersion = $this->oneOf(
            (string) ($options['runtime_version'] ?? ($template['runtime_versions'][0] ?? '')),
            (array) $template['runtime_versions'],
            '运行时版本'
        );
        $frameworkVersion = $this->oneOf(
            (string) ($options['framework_version'] ?? ($template['framework_versions'][0] ?? 'any')),
            (array) $template['framework_versions'],
            '框架版本'
        );
        $serverKeys = array_column((array) $template['runtime_servers'], 'key');
        $runtimeServer = $this->oneOf(
            (string) ($options['runtime_server'] ?? $template['default_server']),
            $serverKeys,
            '运行方式'
        );
        $extensions = [];
        foreach ((array) ($options['extensions'] ?? ($template['default_extensions'] ?? [])) as $extension) {
            $extensions[] = $this->oneOf((string) $extension, (array) ($template['extensions'] ?? []), '扩展');
        }
        $extensions = array_values(array_unique($extensions));
        $packageManager = $this->oneOf(
            (string) ($options['package_manager'] ?? $template['package_manager'] ?? ''),
            (array) ($template['package_managers'] ?? [$template['package_manager'] ?? '']),
            '包管理器'
        );
        $mirror = $this->mirror($packageManager, (array) ($options['mirror'] ?? []));
        $platforms = [];
        foreach ((array) ($options['platforms'] ?? $template['default_platforms'] ?? ['linux/amd64']) as $platform) {
            $platforms[] = $this->oneOf((string) $platform, (array) ($template['platforms'] ?? []), '构建平台');
        }
        $platforms = array_values(array_unique($platforms));
        if ($platforms === []) {
            throw new AppException(422, '至少选择一个构建平台');
        }
        $defaults = $this->optionDefaults($template);
        $normalized = [
            'runtime_version' => $runtimeVersion,
            'framework_version' => $frameworkVersion,
            'runtime_server' => $runtimeServer,
            'package_manager' => $packageManager,
            'extensions' => $extensions,
            'mirror' => $mirror,
            'os_mirror' => $this->osMirror(
                (array) ($options['os_mirror'] ?? []),
                (array) ($template['os_distributions'] ?? [])
            ),
            'cgo' => (bool) ($options['cgo'] ?? false),
            'platforms' => $platforms,
            'app_dir' => $this->absolutePath((string) ($options['app_dir'] ?? $defaults['app_dir'] ?? '/app')),
            'port' => $this->integer(
                $options['port'] ?? $defaults['port'] ?? $template['default_port'],
                1,
                65535,
                '监听端口'
            ),
            'timezone' => $this->oneOf(
                (string) ($options['timezone'] ?? $defaults['timezone'] ?? 'UTC'),
                $this->optionAllowed($template, 'timezone', ['UTC']),
                '时区'
            ),
            'non_root' => (bool) ($options['non_root'] ?? $defaults['non_root'] ?? true),
            'healthcheck_path' => $this->httpPath((string) ($options['healthcheck_path'] ?? '')),
            'start_command' => $this->arguments((array) ($options['start_command'] ?? []), '启动参数'),
            'builder_packages' => $this->packages((array) ($options['builder_packages'] ?? []), 'Builder 系统包'),
            'runtime_packages' => $this->packages((array) ($options['runtime_packages'] ?? []), 'Runtime 系统包'),
        ];
        $normalized += $this->languageOptions($template, $options, $defaults);
        if ($normalized['healthcheck_path'] !== '' && ! in_array('wget', $normalized['runtime_packages'], true)) {
            $normalized['runtime_packages'][] = 'wget';
        }
        if ($template['language'] === 'php' && $runtimeServer === 'nginx-fpm' && $normalized['non_root']) {
            throw new AppException(422, 'Nginx + PHP-FPM 单容器模式需要 root 启动进程管理器；如需非 root，请选择 FrankenPHP');
        }
        [$dockerfile, $buildArgs, $warnings] = $this->generate($template, $normalized);
        if (($template['_status'] ?? 'active') === 'deprecated') {
            $warnings[] = '该模板版本已弃用；已有项目可以继续构建，但建议升级到受支持版本。';
        }
        if (str_starts_with(strtolower((string) $mirror['url']), 'http://')) {
            $warnings[] = '当前软件镜像源使用未加密 HTTP，只应在可信内网中使用。';
        }
        foreach ((array) ($normalized['os_mirror']['sources'] ?? []) as $source) {
            if (str_starts_with(strtolower((string) ($source['url'] ?? '')), 'http://')) {
                $warnings[] = '操作系统软件源最终地址使用未加密 HTTP，只应在可信内网中使用。';
                break;
            }
        }
        $dockerignore = rtrim((string) config('dockerfile_templates.dockerignore')) . "\n";
        preg_match_all('/^FROM\s+([^\s]+)(?:\s+AS\s+([^\s]+))?/mi', $dockerfile, $fromMatches, PREG_SET_ORDER);
        preg_match_all('/^EXPOSE\s+([^\s]+)/mi', $dockerfile, $portMatches);
        preg_match_all('/^USER\s+([^\s]+)/mi', $dockerfile, $userMatches);
        preg_match_all('/^(?:CMD|ENTRYPOINT)\s+(.+)$/mi', $dockerfile, $commandMatches);
        $argDetails = [];
        foreach ($buildArgs as $name => $value) {
            $argDetails[] = [
                'name' => (string) $name,
                'value' => (string) $value,
                'source' => 'template options',
                'sensitive' => false,
            ];
        }
        return [
            'template' => [
                'key' => (string) $template['key'], 'version' => (string) $template['version'],
                'title' => (string) $template['title'], 'language' => (string) $template['language'],
                'framework' => (string) $template['framework'],
            ],
            'options' => $normalized,
            'build_args' => $buildArgs,
            'build_arg_details' => $argDetails,
            'dockerfile' => $dockerfile,
            'dockerfile_checksum' => hash('sha256', $dockerfile),
            'dockerignore' => $dockerignore,
            'dockerignore_profile' => 'default-secure-v1',
            'dockerignore_checksum' => hash('sha256', $dockerignore),
            'renderer_version' => (string) config('dockerfile_templates.version', '1'),
            'warnings' => $warnings,
            'summary' => [
                'multi_stage' => count($fromMatches) > 1,
                'stages' => array_map(static fn (array $match): array => [
                    'image' => $match[1], 'name' => $match[2] ?? 'unnamed',
                ], $fromMatches),
                'final_user' => empty($userMatches[1]) ? 'root (implicit)' : end($userMatches[1]),
                'exposed_ports' => array_values(array_unique($portMatches[1] ?? [])),
                'package_manager' => $packageManager,
                'runtime_server' => $runtimeServer,
                'app_dir' => $normalized['app_dir'],
                'port' => $normalized['port'],
                'timezone' => $normalized['timezone'],
                'non_root' => $normalized['non_root'],
                'healthcheck_path' => $normalized['healthcheck_path'],
                'start_command' => empty($commandMatches[1]) ? null : end($commandMatches[1]),
                'builder_packages' => $normalized['builder_packages'],
                'runtime_packages' => $normalized['runtime_packages'],
                'os_mirror' => $normalized['os_mirror']['key'],
                'dockerignore_profile' => 'default-secure-v1',
                'platforms' => $platforms,
            ],
        ];
    }

    private function find(string $key): array
    {
        foreach ((array) config('dockerfile_templates.templates') as $template) {
            if ((string) ($template['key'] ?? '') === $key) {
                $status = (string) (DockerfileTemplate::where('source', 'builtin')->where('org_id', 0)
                    ->where('template_key', $key)->where('template_version', (string) $template['version'])
                    ->value('status') ?? 'disabled');
                if (! in_array($status, ['active', 'deprecated'], true)) {
                    throw new AppException(404, 'Dockerfile 模板不存在或已停用');
                }
                $template['_status'] = $status;
                return $template;
            }
        }
        throw new AppException(404, 'Dockerfile 模板不存在或已停用');
    }

    public function detail(string $key): array
    {
        $template = $this->find($key);
        $template['mirrors'] = (array) config(
            'dockerfile_templates.mirrors.' . (string) ($template['package_manager'] ?? ''),
            []
        );
        $template['dockerignore_profile'] = 'default-secure-v1';
        return $template;
    }

    public function compatibility(string $key, array $probe, array $options = []): array
    {
        $template = $this->find($key);
        $files = (array) ($probe['files'] ?? []);
        $missing = [];
        foreach ((array) ($template['required_files'] ?? []) as $path) {
            if (! (bool) ($files[$path]['exists'] ?? false)) {
                $missing[] = $path;
            }
        }
        $requiredAny = (array) ($template['required_any_files'] ?? []);
        if (($template['language'] ?? '') === 'java') {
            $requiredAny = ($options['package_manager'] ?? $template['package_manager'] ?? 'maven') === 'gradle'
                ? ['build.gradle', 'build.gradle.kts']
                : ['pom.xml'];
        }
        if ($requiredAny !== [] && ! array_filter(
            $requiredAny,
            static fn (string $path): bool => (bool) ($files[$path]['exists'] ?? false)
        )) {
            $missing[] = implode(' 或 ', $requiredAny);
        }
        $languageDetected = false;
        $exactDetected = false;
        foreach ((array) ($probe['detections'] ?? []) as $detection) {
            if (($detection['language'] ?? '') === ($template['language'] ?? '')) {
                $languageDetected = true;
            }
            if (($detection['template_key'] ?? '') === $key) {
                $exactDetected = true;
            }
        }
        $generic = in_array((string) ($template['framework'] ?? ''), ['generic', 'frontend'], true);
        $compatible = $missing === [] && ($exactDetected || ($generic && $languageDetected));
        return [
            'compatible' => $compatible,
            'template_key' => $key,
            'missing_files' => $missing,
            'exact_detection' => $exactDetected,
            'language_detection' => $languageDetected,
            'recommendation' => $probe['recommendation'] ?? null,
            'message' => $compatible
                ? '仓库结构与所选模板匹配'
                : ($missing !== [] ? '仓库缺少模板要求的文件：' . implode('、', $missing) : '仓库技术栈与所选模板不匹配'),
        ];
    }

    public function validate(string $key, array $options): array
    {
        $rendered = $this->render($key, $options);
        $dockerfile = (string) $rendered['dockerfile'];
        $errors = [];
        $warnings = (array) $rendered['warnings'];
        if (preg_match('/^(?:ARG|ENV)\s+[^\n]*(?:PASSWORD|TOKEN|SECRET|PRIVATE_KEY)/mi', $dockerfile)) {
            $errors[] = 'Dockerfile 试图通过 ARG 或 ENV 接收敏感值';
        }
        if (substr_count(strtoupper($dockerfile), "\nFROM ") + (str_starts_with(strtoupper($dockerfile), 'FROM ') ? 1 : 0) < 2) {
            $warnings[] = '当前模板不是多阶段构建';
        }
        if (! str_contains($dockerfile, '--mount=type=cache')) {
            $warnings[] = '当前模板没有声明包管理器缓存挂载';
        }
        $lastUser = null;
        if (preg_match_all('/^USER\s+([^\s#]+)/mi', $dockerfile, $matches) && $matches[1] !== []) {
            $lastUser = end($matches[1]);
        }
        if ($lastUser === null || in_array(strtolower((string) $lastUser), ['root', '0', '0:0'], true)) {
            $warnings[] = '最终 Runtime 以 root 身份运行；请确认监听低端口或进程管理器确实需要该权限';
        }
        return [
            'valid' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'checks' => [
                'multi_stage' => substr_count(strtoupper($dockerfile), 'FROM ') >= 2,
                'cache_mount' => str_contains($dockerfile, '--mount=type=cache'),
                'runtime_user' => $lastUser ?? 'root (implicit)',
                'secret_in_arg_or_env' => $errors !== [],
                'dockerfile_checksum' => (string) $rendered['dockerfile_checksum'],
                'dockerignore_checksum' => (string) $rendered['dockerignore_checksum'],
            ],
            'rendered' => $rendered,
        ];
    }

    private function oneOf(string $value, array $allowed, string $label): string
    {
        if ($value === '' || ! in_array($value, $allowed, true)) {
            throw new AppException(422, $label . '不受当前模板支持');
        }
        return $value;
    }

    private function mirror(string $manager, array $selection): array
    {
        $presets = (array) config('dockerfile_templates.mirrors.' . $manager, []);
        if ($presets === []) {
            return ['key' => 'official', 'url' => ''];
        }
        $key = (string) ($selection['key'] ?? 'official');
        foreach ($presets as $preset) {
            if ((string) $preset['key'] !== $key) {
                continue;
            }
            $url = $key === 'custom' ? trim((string) ($selection['url'] ?? '')) : (string) $preset['url'];
            if ($url !== '' && filter_var(strtok($url, ','), FILTER_VALIDATE_URL) === false) {
                throw new AppException(422, '自定义软件源 URL 不合法');
            }
            return ['key' => $key, 'url' => $url];
        }
        throw new AppException(422, '软件源预置不存在');
    }

    private function osMirror(array $selection, array $requiredDistributions): array
    {
        $key = (string) ($selection['key'] ?? 'aliyun');
        $presets = (array) config('dockerfile_templates.mirrors.os', []);
        $sources = [];
        foreach ($presets as $preset) {
            if ((string) ($preset['key'] ?? '') === $key) {
                $sources = $key === 'custom'
                    ? (array) ($selection['sources'] ?? [])
                    : (array) ($preset['sources'] ?? []);
                break;
            }
        }
        if (! in_array($key, array_column($presets, 'key'), true)) {
            throw new AppException(422, '操作系统软件源预置不存在');
        }
        $allowedDistributions = ['debian', 'ubuntu', 'alpine', 'centos-stream'];
        $allowedFields = ['url', 'security_url', 'bootstrap_url', 'bootstrap_security_url'];
        $normalized = [];
        foreach ($sources as $distribution => $source) {
            if (! in_array((string) $distribution, $allowedDistributions, true) || ! is_array($source)) {
                throw new AppException(422, '操作系统软件源类型不受支持');
            }
            foreach ($source as $field => $url) {
                if (! in_array((string) $field, $allowedFields, true)) {
                    throw new AppException(422, '操作系统软件源字段不受支持');
                }
                $url = rtrim(trim((string) $url), '/');
                if ($url !== '' && (! filter_var($url, FILTER_VALIDATE_URL)
                    || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true))) {
                    throw new AppException(422, '操作系统软件源 URL 不合法');
                }
                $normalized[(string) $distribution][(string) $field] = $url;
            }
        }
        foreach (['debian', 'ubuntu'] as $distribution) {
            if (! isset($normalized[$distribution]['url'])) {
                continue;
            }
            $normalized[$distribution]['security_url'] = $normalized[$distribution]['security_url']
                ?? $normalized[$distribution]['url'];
            if (($normalized[$distribution]['bootstrap_url'] ?? '') === ''
                && str_starts_with($normalized[$distribution]['url'], 'https://')) {
                $normalized[$distribution]['bootstrap_url'] = 'http://' . substr($normalized[$distribution]['url'], 8);
            } elseif (($normalized[$distribution]['bootstrap_url'] ?? '') === ''
                && str_starts_with($normalized[$distribution]['url'], 'http://')) {
                $normalized[$distribution]['bootstrap_url'] = $normalized[$distribution]['url'];
            }
            if (($normalized[$distribution]['bootstrap_security_url'] ?? '') === '') {
                $security = $normalized[$distribution]['security_url'];
                $normalized[$distribution]['bootstrap_security_url'] = str_starts_with($security, 'https://')
                    ? 'http://' . substr($security, 8)
                    : $security;
            }
        }
        foreach (['alpine', 'centos-stream'] as $distribution) {
            if (! isset($normalized[$distribution]['url'])) {
                continue;
            }
            if (($normalized[$distribution]['bootstrap_url'] ?? '') === ''
                && str_starts_with($normalized[$distribution]['url'], 'https://')) {
                $normalized[$distribution]['bootstrap_url'] = 'http://' . substr($normalized[$distribution]['url'], 8);
            } elseif (($normalized[$distribution]['bootstrap_url'] ?? '') === ''
                && str_starts_with($normalized[$distribution]['url'], 'http://')) {
                $normalized[$distribution]['bootstrap_url'] = $normalized[$distribution]['url'];
            }
        }
        if ($key === 'custom') {
            foreach ($requiredDistributions as $distribution) {
                if (($normalized[(string) $distribution]['url'] ?? '') === '') {
                    throw new AppException(422, '自定义操作系统源缺少 ' . $distribution . ' 镜像地址');
                }
            }
        }
        return ['key' => $key, 'sources' => $normalized];
    }

    private function osBuildArgs(array $options): array
    {
        $sources = (array) ($options['os_mirror']['sources'] ?? []);
        $debian = (array) ($sources['debian'] ?? []);
        $ubuntu = (array) ($sources['ubuntu'] ?? []);
        $alpine = (array) ($sources['alpine'] ?? []);
        $centos = (array) ($sources['centos-stream'] ?? []);
        return [
            'CG_APT_MIRROR' => (string) ($debian['url'] ?? ''),
            'CG_APT_SECURITY_MIRROR' => (string) ($debian['security_url'] ?? ''),
            'CG_APT_BOOTSTRAP_MIRROR' => (string) ($debian['bootstrap_url'] ?? ''),
            'CG_APT_BOOTSTRAP_SECURITY_MIRROR' => (string) ($debian['bootstrap_security_url'] ?? ''),
            'CG_APT_UBUNTU_MIRROR' => (string) ($ubuntu['url'] ?? ''),
            'CG_APT_UBUNTU_SECURITY_MIRROR' => (string) ($ubuntu['security_url'] ?? ''),
            'CG_APT_UBUNTU_BOOTSTRAP_MIRROR' => (string) ($ubuntu['bootstrap_url'] ?? ''),
            'CG_APT_UBUNTU_BOOTSTRAP_SECURITY_MIRROR' => (string) ($ubuntu['bootstrap_security_url'] ?? ''),
            'CG_APK_MIRROR' => (string) ($alpine['url'] ?? ''),
            'CG_APK_BOOTSTRAP_MIRROR' => (string) ($alpine['bootstrap_url'] ?? ''),
            'CG_DNF_MIRROR' => (string) ($centos['url'] ?? ''),
            'CG_DNF_BOOTSTRAP_MIRROR' => (string) ($centos['bootstrap_url'] ?? ''),
        ];
    }

    private function debianStageArgs(): string
    {
        return <<<'DOCKER'
ARG CG_TIMEZONE
ARG CG_APT_MIRROR=""
ARG CG_APT_SECURITY_MIRROR=""
ARG CG_APT_BOOTSTRAP_MIRROR=""
ARG CG_APT_BOOTSTRAP_SECURITY_MIRROR=""
ARG CG_APT_UBUNTU_MIRROR=""
ARG CG_APT_UBUNTU_SECURITY_MIRROR=""
ARG CG_APT_UBUNTU_BOOTSTRAP_MIRROR=""
ARG CG_APT_UBUNTU_BOOTSTRAP_SECURITY_MIRROR=""
ENV DEBIAN_FRONTEND=noninteractive TZ=${CG_TIMEZONE}
DOCKER;
    }

    private function debianInstall(string $packages): string
    {
        $script = <<<'DOCKER'
RUN set -eux; \
    set_apt_sources() { \
        distribution="$1"; main="$2"; security="$3"; \
        [ -n "$main" ] || return 0; \
        for file in /etc/apt/sources.list /etc/apt/sources.list.d/*.list /etc/apt/sources.list.d/*.sources; do \
            [ -f "$file" ] || continue; \
            if [ "$distribution" = debian ]; then \
                sed -ri "s#https?://[^[:space:]]+/debian-security#${security:-$main}#g; s#https?://[^[:space:]]+/debian([[:space:]]|$)#${main}\1#g" "$file"; \
            else \
                sed -ri "s#https?://[^[:space:]]+/ubuntu#${main}#g" "$file"; \
            fi; \
        done; \
    }; \
    if [ -n "$CG_APT_BOOTSTRAP_MIRROR" ] || [ -n "$CG_APT_UBUNTU_BOOTSTRAP_MIRROR" ]; then \
        set_apt_sources debian "$CG_APT_BOOTSTRAP_MIRROR" "$CG_APT_BOOTSTRAP_SECURITY_MIRROR"; \
        set_apt_sources ubuntu "$CG_APT_UBUNTU_BOOTSTRAP_MIRROR" "$CG_APT_UBUNTU_BOOTSTRAP_SECURITY_MIRROR"; \
        apt-get update; \
        apt-get install -y --no-install-recommends ca-certificates; \
        update-ca-certificates; \
        set_apt_sources debian "$CG_APT_MIRROR" "$CG_APT_SECURITY_MIRROR"; \
        set_apt_sources ubuntu "$CG_APT_UBUNTU_MIRROR" "$CG_APT_UBUNTU_SECURITY_MIRROR"; \
    fi; \
    area="${CG_TIMEZONE%%/*}"; zone="${CG_TIMEZONE#*/}"; \
    if [ "$area" = "$zone" ]; then area=Etc; zone=UTC; fi; \
    if command -v debconf-set-selections >/dev/null 2>&1; then \
        echo "tzdata tzdata/Areas select $area" | debconf-set-selections; \
        echo "tzdata tzdata/Zones/$area select $zone" | debconf-set-selections; \
    fi; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates tzdata __PACKAGES__; \
    ln -snf "/usr/share/zoneinfo/$CG_TIMEZONE" /etc/localtime; \
    echo "$CG_TIMEZONE" > /etc/timezone; \
    rm -rf /var/lib/apt/lists/*
DOCKER;
        return str_replace('__PACKAGES__', trim($packages), $script);
    }

    private function alpineStageArgs(): string
    {
        return <<<'DOCKER'
ARG CG_TIMEZONE
ARG CG_APK_MIRROR=""
ARG CG_APK_BOOTSTRAP_MIRROR=""
ENV TZ=${CG_TIMEZONE}
DOCKER;
    }

    private function alpineInstall(string $packages): string
    {
        $script = <<<'DOCKER'
RUN set -eux; \
    if [ -n "$CG_APK_BOOTSTRAP_MIRROR" ]; then \
        sed -i "s#https\?://dl-cdn.alpinelinux.org/alpine#${CG_APK_BOOTSTRAP_MIRROR}#g" /etc/apk/repositories; \
        apk add --no-cache ca-certificates; \
        update-ca-certificates; \
        sed -i "s#${CG_APK_BOOTSTRAP_MIRROR}#${CG_APK_MIRROR}#g" /etc/apk/repositories; \
    elif [ -n "$CG_APK_MIRROR" ]; then \
        sed -i "s#https\?://dl-cdn.alpinelinux.org/alpine#${CG_APK_MIRROR}#g" /etc/apk/repositories; \
    fi; \
    apk add --no-cache ca-certificates tzdata __PACKAGES__; \
    cp "/usr/share/zoneinfo/$CG_TIMEZONE" /etc/localtime; \
    echo "$CG_TIMEZONE" > /etc/timezone
DOCKER;
        return str_replace('__PACKAGES__', trim($packages), $script);
    }

    private function optionDefaults(array $template): array
    {
        $defaults = [];
        foreach (array_merge((array) ($template['common_options'] ?? []), (array) ($template['language_options'] ?? [])) as $option) {
            $defaults[(string) $option['key']] = $option['default'] ?? null;
        }
        return $defaults;
    }

    private function option(array $template, string $key): array
    {
        foreach (array_merge((array) ($template['common_options'] ?? []), (array) ($template['language_options'] ?? [])) as $option) {
            if ((string) ($option['key'] ?? '') === $key) {
                return $option;
            }
        }
        return [];
    }

    private function optionAllowed(array $template, string $key, array $fallback = []): array
    {
        $option = $this->option($template, $key);
        if (isset($option['allowed_ref'])) {
            return (array) config('dockerfile_templates.' . (string) $option['allowed_ref'], $fallback);
        }
        return (array) ($option['allowed'] ?? $fallback);
    }

    private function languageOptions(array $template, array $options, array $defaults): array
    {
        return match ((string) $template['language']) {
            'go' => [
                'runtime_variant' => $this->oneOf(
                    (string) ($options['runtime_variant'] ?? $defaults['runtime_variant'] ?? 'alpine'),
                    (array) ($this->option($template, 'runtime_variant')['allowed'] ?? ['alpine']),
                    'Go Runtime 基础镜像'
                ),
                'build_tags' => $this->identifiers((array) ($options['build_tags'] ?? []), 'Go Build Tags'),
                'ldflags' => $this->safeText((string) ($options['ldflags'] ?? $defaults['ldflags'] ?? '-s -w'), 'Go ldflags'),
                'private_modules' => $this->domains((array) ($options['private_modules'] ?? [])),
                'target_package' => $this->goPackage((string) ($options['target_package'] ?? $defaults['target_package'] ?? './')),
            ],
            'node', 'static' => [
                'build_script' => $this->scriptName((string) ($options['build_script'] ?? $defaults['build_script'] ?? 'build')),
                'start_script' => $this->scriptName((string) ($options['start_script'] ?? $defaults['start_script'] ?? 'start')),
            ],
            'python' => [
                'entry_module' => $this->pythonEntry((string) ($options['entry_module'] ?? $defaults['entry_module'] ?? 'app:app')),
                'workers' => $this->integer($options['workers'] ?? $defaults['workers'] ?? 2, 1, 64, 'Python Worker 数量'),
            ],
            'java' => [
                'jvm_options' => $this->arguments((array) ($options['jvm_options'] ?? []), 'JVM 参数'),
                'layered_jar' => (bool) ($options['layered_jar'] ?? $defaults['layered_jar'] ?? false),
            ],
            default => [],
        };
    }

    private function integer(mixed $value, int $min, int $max, string $label): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < $min || (int) $value > $max) {
            throw new AppException(422, $label . "必须在 {$min}-{$max} 之间");
        }
        return (int) $value;
    }

    private function absolutePath(string $path): string
    {
        $path = rtrim(trim($path), '/');
        if (! preg_match('#^/[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*$#', $path) || str_contains($path, '..')) {
            throw new AppException(422, '项目目录必须是安全的绝对路径');
        }
        return $path;
    }

    private function httpPath(string $path): string
    {
        $path = trim($path);
        if ($path !== '' && (! preg_match('#^/[A-Za-z0-9._~!$&()*+,;=:@%/-]{0,255}$#', $path) || str_contains($path, '..'))) {
            throw new AppException(422, '健康检查路径格式不合法');
        }
        return $path;
    }

    private function packages(array $values, string $label): array
    {
        if (count($values) > 50) {
            throw new AppException(422, $label . '最多允许 50 项');
        }
        $result = [];
        foreach ($values as $value) {
            $value = trim((string) $value);
            if (! preg_match('/^[a-z0-9][a-z0-9+_.:@~-]{0,79}$/i', $value)) {
                throw new AppException(422, $label . '包含不合法的包名');
            }
            $result[] = $value;
        }
        return array_values(array_unique($result));
    }

    private function arguments(array $values, string $label): array
    {
        if (count($values) > 32) {
            throw new AppException(422, $label . '最多允许 32 项');
        }
        $result = [];
        foreach ($values as $value) {
            $value = (string) $value;
            if ($value === '' || strlen($value) > 256 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new AppException(422, $label . '包含空参数、控制字符或过长参数');
            }
            $result[] = $value;
        }
        return $result;
    }

    private function identifiers(array $values, string $label): array
    {
        if (count($values) > 32) {
            throw new AppException(422, $label . '最多允许 32 项');
        }
        foreach ($values as $value) {
            if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/', (string) $value)) {
                throw new AppException(422, $label . '包含不合法标识符');
            }
        }
        return array_values(array_unique(array_map('strval', $values)));
    }

    private function safeText(string $value, string $label): string
    {
        $value = trim($value);
        if (strlen($value) > 256 || preg_match('/[^A-Za-z0-9_.,=:+\/@% -]/', $value)) {
            throw new AppException(422, $label . '包含不支持的字符');
        }
        return $value;
    }

    private function domains(array $values): array
    {
        if (count($values) > 32) {
            throw new AppException(422, '私有模块域名最多允许 32 项');
        }
        foreach ($values as $value) {
            if (! preg_match('/^(?:\*\.)?(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$/', (string) $value)) {
                throw new AppException(422, '私有模块域名格式不合法');
            }
        }
        return array_values(array_unique(array_map('strval', $values)));
    }

    private function goPackage(string $value): string
    {
        $value = trim($value);
        if (! preg_match('#^(?:\./)?[A-Za-z0-9_./-]*$#', $value) || str_contains($value, '..')) {
            throw new AppException(422, 'Go 目标包路径不合法');
        }
        return $value === '' ? './' : $value;
    }

    private function scriptName(string $value): string
    {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9:_-]{0,63}$/', $value)) {
            throw new AppException(422, 'Package Script 名称不合法');
        }
        return $value;
    }

    private function pythonEntry(string $value): string
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_.]*:[A-Za-z_][A-Za-z0-9_]*$/', $value)) {
            throw new AppException(422, 'Python 入口必须使用 module:callable 格式');
        }
        return $value;
    }

    private function command(array $default, array $options): string
    {
        return json_encode(
            $options['start_command'] !== [] ? $options['start_command'] : $default,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private function healthcheck(array $options): string
    {
        if ($options['healthcheck_path'] === '') {
            return '';
        }
        return 'HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 CMD wget -q -O /dev/null "http://127.0.0.1:${CG_APP_PORT}'
            . $options['healthcheck_path'] . '" || exit 1' . "\n";
    }

    private function generate(array $template, array $options): array
    {
        return match ((string) $template['language']) {
            'php' => $this->php($template, $options),
            'go' => $this->go($options),
            'node' => $this->node($template, $options, false),
            'static' => (string) ($template['framework'] ?? '') === 'directory'
                ? $this->staticDirectory($options)
                : $this->node($template, $options, true),
            'python' => $this->python($template, $options),
            'java' => $this->java($template, $options),
            default => throw new AppException(422, '模板语言尚未实现渲染器'),
        };
    }

    private function staticDirectory(array $options): array
    {
        if ($options['non_root'] && $options['port'] < 1024) {
            throw new AppException(422, '静态 Nginx 使用非 root 用户时，监听端口必须大于等于 1024');
        }
        $args = array_merge([
            'CG_NGINX_VERSION' => $options['runtime_version'],
            'CG_APP_PORT' => (string) $options['port'],
            'CG_TIMEZONE' => $options['timezone'],
            'CG_RUNTIME_PACKAGES' => implode(' ', $options['runtime_packages']),
        ], $this->osBuildArgs($options));
        $dockerfile = "# syntax=registry.cn-shanghai.aliyuncs.com/swoole-public/dockerfile:1.7\nARG CG_NGINX_VERSION\n"
            . "FROM registry.cn-shanghai.aliyuncs.com/swoole-public/nginx:\${CG_NGINX_VERSION}-alpine AS runtime\nARG CG_APP_PORT\nARG CG_RUNTIME_PACKAGES=\"\"\n"
            . $this->alpineStageArgs() . "\n"
            . $this->alpineInstall('$CG_RUNTIME_PACKAGES') . "\n"
            . "RUN printf '%s\\n' 'server {' \" listen \${CG_APP_PORT};\" ' root /usr/share/nginx/html;' ' index index.html;' ' location / { try_files \$uri \$uri/ /index.html; }' '}' > /etc/nginx/conf.d/default.conf"
            . ($options['non_root'] ? "; chown -R nginx:nginx /var/cache/nginx /var/run /etc/nginx/conf.d\nUSER nginx\n" : "\n")
            . "COPY . /usr/share/nginx/html\nEXPOSE \${CG_APP_PORT}\n"
            . $this->healthcheck($options)
            . 'CMD ' . $this->command(['nginx', '-g', 'daemon off;'], $options) . "\n";
        return [$dockerfile, $args, ['该模板不会执行前端编译，请确保构建上下文已包含 index.html 和最终静态资源。']];
    }

    private function php(array $template, array $options): array
    {
        $args = array_merge([
            'CG_PHP_VERSION' => $options['runtime_version'],
            'CG_COMPOSER_MIRROR' => $options['mirror']['url'],
            'CG_PHP_EXTENSIONS' => implode(' ', $options['extensions']),
            'CG_APP_DIR' => $options['app_dir'],
            'CG_APP_PORT' => (string) $options['port'],
            'CG_TIMEZONE' => $options['timezone'],
            'CG_BUILDER_PACKAGES' => implode(' ', $options['builder_packages']),
            'CG_RUNTIME_PACKAGES' => implode(' ', $options['runtime_packages']),
        ], $this->osBuildArgs($options));
        $builderPackages = ['git', 'unzip'];
        $runtimePackages = [];
        $extensionPackages = [
            'intl' => [['libicu-dev'], ['libicu72']],
            'zip' => [['libzip-dev'], ['libzip4']],
            'pdo_pgsql' => [['libpq-dev'], ['libpq5']],
            'gd' => [['libpng-dev', 'libjpeg62-turbo-dev', 'libfreetype6-dev'], ['libpng16-16', 'libjpeg62-turbo', 'libfreetype6']],
            'swoole' => [['libssl-dev'], ['libssl3']],
            'mongodb' => [['libssl-dev'], ['libssl3']],
        ];
        foreach ($options['extensions'] as $extension) {
            $builderPackages = array_merge($builderPackages, $extensionPackages[$extension][0] ?? []);
            $runtimePackages = array_merge($runtimePackages, $extensionPackages[$extension][1] ?? []);
        }
        if ($options['extensions'] !== []) {
            $builderPackages[] = '$PHPIZE_DEPS';
        }
        $builderPackages[] = '$CG_BUILDER_PACKAGES';
        $runtimePackages = array_values(array_unique(array_merge($runtimePackages, ['$CG_RUNTIME_PACKAGES'])));
        $extensionInstall = $options['extensions'] === [] ? '' : <<<'SH'
RUN set -eux; \
    for ext in $CG_PHP_EXTENSIONS; do \
        case "$ext" in \
            pdo_mysql|opcache|intl|zip) docker-php-ext-install "$ext";; \
            pdo_pgsql) docker-php-ext-install pdo_pgsql;; \
            gd) docker-php-ext-configure gd --with-freetype --with-jpeg && docker-php-ext-install gd;; \
            redis|mongodb|swoole) pecl install "$ext" && docker-php-ext-enable "$ext";; \
        esac; \
    done; \
    rm -rf /tmp/pear
SH;
        $extensionCopy = $options['extensions'] === [] ? ''
            : "COPY --from=builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/\n"
                . "COPY --from=builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/\n";
        $header = "# syntax=registry.cn-shanghai.aliyuncs.com/swoole-public/dockerfile:1.7\nARG CG_PHP_VERSION\nARG CG_COMPOSER_IMAGE=registry.cn-shanghai.aliyuncs.com/swoole-public/composer:2\nFROM \${CG_COMPOSER_IMAGE} AS composer\n";
        $builder = "FROM registry.cn-shanghai.aliyuncs.com/swoole-public/php:\${CG_PHP_VERSION}-cli-bookworm AS builder\n"
            . "ARG CG_COMPOSER_MIRROR\nARG CG_PHP_EXTENSIONS\nARG CG_APP_DIR\nARG CG_BUILDER_PACKAGES=\"\"\n"
            . $this->debianStageArgs() . "\n"
            . $this->debianInstall(implode(' ', array_unique($builderPackages))) . "\n"
            . $extensionInstall . ($extensionInstall === '' ? '' : "\n")
            . "COPY --from=composer /usr/bin/composer /usr/local/bin/composer\n"
            . "WORKDIR \${CG_APP_DIR}\nCOPY composer.* ./\n"
            . "RUN --mount=type=cache,target=/tmp/composer-cache set -eux; "
            . "if [ -n \"\$CG_COMPOSER_MIRROR\" ]; then composer config -g repos.packagist composer \"\$CG_COMPOSER_MIRROR\"; fi; "
            . "composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-progress\n"
            . "COPY . .\nRUN --mount=type=cache,target=/tmp/composer-cache composer install --no-dev --prefer-dist --no-interaction --no-progress --classmap-authoritative\n";
        $server = $options['runtime_server'];
        if ($server === 'frankenphp') {
            $runtime = "FROM registry.cn-shanghai.aliyuncs.com/swoole-public/frankenphp:php\${CG_PHP_VERSION}-bookworm AS runtime\nARG CG_APP_DIR\nARG CG_APP_PORT\nARG CG_RUNTIME_PACKAGES=\"\"\n"
                . $this->debianStageArgs() . "\n"
                . $this->debianInstall(implode(' ', $runtimePackages)) . "\n"
                . $extensionCopy
                . "ENV SERVER_NAME=:\${CG_APP_PORT}\nWORKDIR \${CG_APP_DIR}\n"
                . "COPY --from=builder --chown=www-data:www-data \${CG_APP_DIR} \${CG_APP_DIR}\n"
                . ($options['non_root'] ? "USER www-data\n" : '')
                . "EXPOSE \${CG_APP_PORT}\n" . $this->healthcheck($options)
                . 'CMD ' . $this->command(['frankenphp', 'run', '--config', '/etc/caddy/Caddyfile'], $options) . "\n";
        } elseif ($server === 'nginx-fpm') {
            $runtimePackages = array_values(array_unique(array_merge(['nginx', 'supervisor'], $runtimePackages)));
            $runtime = "FROM registry.cn-shanghai.aliyuncs.com/swoole-public/php:\${CG_PHP_VERSION}-fpm-bookworm AS runtime\nARG CG_APP_DIR\nARG CG_APP_PORT\nARG CG_RUNTIME_PACKAGES=\"\"\n"
                . $this->debianStageArgs() . "\n"
                . $this->debianInstall(implode(' ', $runtimePackages)) . "\n"
                . $extensionCopy
                . "RUN printf '%s\\n' '#!/bin/sh' \"exec nginx -g 'daemon off; error_log /dev/stderr info;'\" > /usr/local/bin/start-nginx; chmod +x /usr/local/bin/start-nginx; "
                . "printf '%s\\n' '[supervisord]' 'nodaemon=true' 'user=root' '[program:php-fpm]' 'command=php-fpm -F' 'autorestart=true' 'redirect_stderr=true' 'stdout_logfile=/dev/fd/1' 'stdout_logfile_maxbytes=0' '[program:nginx]' 'command=/usr/local/bin/start-nginx' 'autorestart=true' 'redirect_stderr=true' 'stdout_logfile=/dev/fd/1' 'stdout_logfile_maxbytes=0' > /etc/supervisor/conf.d/app.conf; "
                . "printf '%s\\n' 'server {' \" listen \${CG_APP_PORT};\" \" root \${CG_APP_DIR}/public;\" ' index index.php;' ' location / { try_files \$uri \$uri/ /index.php?\$query_string; }' ' location ~ \\.php$ { include fastcgi_params; fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name; fastcgi_pass 127.0.0.1:9000; }' '}' > /etc/nginx/sites-enabled/default\n"
                . "WORKDIR \${CG_APP_DIR}\nCOPY --from=builder --chown=www-data:www-data \${CG_APP_DIR} \${CG_APP_DIR}\n"
                . "EXPOSE \${CG_APP_PORT}\n" . $this->healthcheck($options)
                . 'CMD ' . $this->command(['supervisord', '-c', '/etc/supervisor/supervisord.conf'], $options) . "\n";
        } else {
            $command = (string) $template['framework'] === 'hyperf'
                ? ['php', 'bin/hyperf.php', 'start']
                : ['php', 'index.php'];
            $runtime = "FROM registry.cn-shanghai.aliyuncs.com/swoole-public/php:\${CG_PHP_VERSION}-cli-bookworm AS runtime\nARG CG_APP_DIR\nARG CG_APP_PORT\nARG CG_RUNTIME_PACKAGES=\"\"\n"
                . $this->debianStageArgs() . "\n"
                . $this->debianInstall(implode(' ', $runtimePackages)) . "\n"
                . $extensionCopy . "WORKDIR \${CG_APP_DIR}\n"
                . "COPY --from=builder --chown=www-data:www-data \${CG_APP_DIR} \${CG_APP_DIR}\n"
                . ($options['non_root'] ? "USER www-data\n" : '')
                . "EXPOSE \${CG_APP_PORT}\n" . $this->healthcheck($options)
                . 'CMD ' . $this->command($command, $options) . "\n";
        }
        return [$header . $builder . $runtime, $args, []];
    }

    private function go(array $options): array
    {
        $variant = (string) $options['runtime_variant'];
        if ($variant === 'distroless' && ($options['cgo'] || $options['runtime_packages'] !== []
            || $options['healthcheck_path'] !== '' || $options['timezone'] !== 'UTC')) {
            throw new AppException(422, 'Distroless Runtime 不支持 CGO、额外系统包、命令型健康检查或非 UTC 时区');
        }
        $runtimeImage = match ($variant) {
            'alpine' => 'registry.cn-shanghai.aliyuncs.com/swoole-public/alpine:3.22',
            'debian' => 'registry.cn-shanghai.aliyuncs.com/swoole-public/debian:bookworm-slim',
            'distroless' => 'registry.cn-shanghai.aliyuncs.com/swoole-public/static-debian12:' . ($options['non_root'] ? 'nonroot' : 'latest'),
        };
        $args = array_merge([
            'CG_GO_VERSION' => $options['runtime_version'],
            'CG_GOPROXY' => $options['mirror']['url'],
            'CGO_ENABLED' => $options['cgo'] ? '1' : '0',
            'CG_GO_BUILD_TAGS' => implode(',', $options['build_tags']),
            'CG_GO_LDFLAGS' => $options['ldflags'],
            'CG_GOPRIVATE' => implode(',', $options['private_modules']),
            'CG_GO_TARGET' => $options['target_package'],
            'CG_APP_DIR' => $options['app_dir'],
            'CG_APP_PORT' => (string) $options['port'],
            'CG_TIMEZONE' => $options['timezone'],
            'CG_BUILDER_PACKAGES' => implode(' ', $options['builder_packages']),
            'CG_RUNTIME_PACKAGES' => implode(' ', $options['runtime_packages']),
            'CG_GO_RUNTIME_IMAGE' => $runtimeImage,
        ], $this->osBuildArgs($options));
        $runtime = "FROM \${CG_GO_RUNTIME_IMAGE} AS runtime\nARG CG_APP_DIR\nARG CG_APP_PORT\nARG CG_RUNTIME_PACKAGES=\"\"\n";
        if ($variant === 'alpine') {
            $runtime .= $this->alpineStageArgs() . "\n"
                . $this->alpineInstall('$CG_RUNTIME_PACKAGES') . "\n"
                . "RUN addgroup -S app && adduser -S -u 10001 -G app app\n";
        } elseif ($variant === 'debian') {
            $runtime .= $this->debianStageArgs() . "\n"
                . $this->debianInstall('$CG_RUNTIME_PACKAGES') . "\n"
                . "RUN useradd -r -u 10001 app\n";
        }
        $runtime .= "WORKDIR \${CG_APP_DIR}\nCOPY --from=builder /out/app \${CG_APP_DIR}/app\n"
            . ($variant === 'distroless'
                ? ($options['non_root'] ? "USER 65532:65532\n" : "USER 0:0\n")
                : ($options['non_root'] ? "USER app\n" : ''))
            . "EXPOSE \${CG_APP_PORT}\n" . $this->healthcheck($options)
            . 'ENTRYPOINT ' . $this->command([$options['app_dir'] . '/app'], $options) . "\n";
        $dockerfile = "# syntax=registry.cn-shanghai.aliyuncs.com/swoole-public/dockerfile:1.7\nARG CG_GO_VERSION\nARG CG_GO_RUNTIME_IMAGE\n"
            . "FROM registry.cn-shanghai.aliyuncs.com/swoole-public/golang:\${CG_GO_VERSION}-bookworm AS builder\n"
            . "ARG CG_GOPROXY\nARG CGO_ENABLED\nARG CG_GO_BUILD_TAGS\nARG CG_GO_LDFLAGS\nARG CG_GOPRIVATE\nARG CG_GO_TARGET\nARG CG_BUILDER_PACKAGES=\"\"\n"
            . $this->debianStageArgs() . "\n"
            . "ENV GOPROXY=\${CG_GOPROXY} CGO_ENABLED=\${CGO_ENABLED} GOPRIVATE=\${CG_GOPRIVATE}\n"
            . ($options['builder_packages'] === [] ? '' : $this->debianInstall('$CG_BUILDER_PACKAGES') . "\n")
            . "WORKDIR /src\nCOPY go.mod go.sum* ./\nRUN --mount=type=cache,target=/go/pkg/mod go mod download\nCOPY . .\n"
            . "RUN --mount=type=cache,target=/go/pkg/mod --mount=type=cache,target=/root/.cache/go-build set -eux; tags=; if [ -n \"\$CG_GO_BUILD_TAGS\" ]; then tags=\"-tags=\$CG_GO_BUILD_TAGS\"; fi; go build -trimpath \$tags -ldflags=\"\$CG_GO_LDFLAGS\" -o /out/app \"\$CG_GO_TARGET\"\n"
            . $runtime;
        return [$dockerfile, $args, $options['cgo'] ? ['CGO 构建可能需要在 Builder 系统包中补充编译依赖。'] : []];
    }

    private function node(array $template, array $options, bool $static): array
    {
        $manager = (string) $options['package_manager'];
        $args = array_merge([
            'CG_NODE_VERSION' => $options['runtime_version'],
            'CG_NODE_PACKAGE_MANAGER' => $manager,
            'CG_NPM_REGISTRY' => $options['mirror']['url'],
            'CG_NODE_BUILD_SCRIPT' => $options['build_script'],
            'CG_NODE_START_SCRIPT' => $options['start_script'],
            'CG_APP_DIR' => $options['app_dir'],
            'CG_APP_PORT' => (string) $options['port'],
            'CG_TIMEZONE' => $options['timezone'],
            'CG_BUILDER_PACKAGES' => implode(' ', $options['builder_packages']),
            'CG_RUNTIME_PACKAGES' => implode(' ', $options['runtime_packages']),
        ], $this->osBuildArgs($options));
        $framework = (string) ($template['framework'] ?? 'generic');
        $usesCorepack = in_array($manager, ['pnpm', 'yarn'], true);
        $dependencyInstall = match ($manager) {
            'npm' => 'npm config set registry "$CG_NPM_REGISTRY"; if [ -f package-lock.json ]; then npm ci; else npm install; fi',
            'pnpm' => 'pnpm config set registry "$CG_NPM_REGISTRY"; if [ -f pnpm-lock.yaml ]; then pnpm install --frozen-lockfile; else pnpm install; fi',
            'yarn' => 'major="$(yarn --version | cut -d. -f1)"; if [ "$major" -ge 2 ]; then yarn config set npmRegistryServer "$CG_NPM_REGISTRY"; else yarn config set registry "$CG_NPM_REGISTRY"; fi; if [ -f yarn.lock ]; then if [ "$major" -ge 2 ]; then yarn install --immutable; else yarn install --frozen-lockfile; fi; else yarn install; fi',
        };
        if ($static && $options['non_root'] && $options['port'] < 1024) {
            throw new AppException(422, '静态 Nginx 使用非 root 用户时，监听端口必须大于等于 1024');
        }
        $defaultCommand = $framework === 'nuxt'
            ? ['node', '.output/server/index.mjs']
            : [$manager, 'run', $options['start_script']];
        if ($static) {
            $outputDirectory = $framework === 'react' ? 'build' : 'dist';
            $runtime = "FROM registry.cn-shanghai.aliyuncs.com/swoole-public/nginx:1.27-alpine AS runtime\nARG CG_APP_DIR\nARG CG_APP_PORT\nARG CG_RUNTIME_PACKAGES=\"\"\n"
                . $this->alpineStageArgs() . "\n"
                . $this->alpineInstall('$CG_RUNTIME_PACKAGES') . "\n"
                . "RUN printf '%s\\n' 'server {' \" listen \${CG_APP_PORT};\" ' root /usr/share/nginx/html;' ' index index.html;' ' location / { try_files \$uri \$uri/ /index.html; }' '}' > /etc/nginx/conf.d/default.conf"
                . ($options['non_root'] ? "; chown -R nginx:nginx /var/cache/nginx /var/run /etc/nginx/conf.d\nUSER nginx\n" : "\n")
                . "COPY --from=builder \${CG_APP_DIR}/{$outputDirectory} /usr/share/nginx/html\nEXPOSE \${CG_APP_PORT}\n"
                . $this->healthcheck($options)
                . 'CMD ' . $this->command(['nginx', '-g', 'daemon off;'], $options) . "\n";
        } else {
            $runtime = "FROM registry.cn-shanghai.aliyuncs.com/swoole-public/node:\${CG_NODE_VERSION}-bookworm-slim AS runtime\nARG CG_NODE_PACKAGE_MANAGER\nARG CG_NPM_REGISTRY\nARG CG_APP_DIR\nARG CG_APP_PORT\nARG CG_RUNTIME_PACKAGES=\"\"\n"
                . $this->debianStageArgs() . "\n"
                . "ENV NODE_ENV=production CG_NODE_PACKAGE_MANAGER=\${CG_NODE_PACKAGE_MANAGER}"
                . ($usesCorepack ? " COREPACK_HOME=/opt/corepack COREPACK_NPM_REGISTRY=\${CG_NPM_REGISTRY}" : '') . "\n"
                . $this->debianInstall('$CG_RUNTIME_PACKAGES') . "\nRUN corepack enable\n"
                . ($usesCorepack ? "COPY --from=builder /opt/corepack /opt/corepack\n" : '')
                . "WORKDIR \${CG_APP_DIR}\nCOPY --from=builder --chown=node:node \${CG_APP_DIR} \${CG_APP_DIR}\n"
                . ($options['non_root'] ? "USER node\n" : '')
                . "EXPOSE \${CG_APP_PORT}\n" . $this->healthcheck($options)
                . 'CMD ' . $this->command($defaultCommand, $options) . "\n";
        }
        $dockerfile = "# syntax=registry.cn-shanghai.aliyuncs.com/swoole-public/dockerfile:1.7\nARG CG_NODE_VERSION\nFROM registry.cn-shanghai.aliyuncs.com/swoole-public/node:\${CG_NODE_VERSION}-bookworm AS builder\nARG CG_NPM_REGISTRY\nARG CG_NODE_PACKAGE_MANAGER\nARG CG_NODE_BUILD_SCRIPT\nARG CG_APP_DIR\nARG CG_BUILDER_PACKAGES=\"\"\n"
            . $this->debianStageArgs() . "\n"
            . ($usesCorepack ? "ENV COREPACK_HOME=/opt/corepack COREPACK_NPM_REGISTRY=\${CG_NPM_REGISTRY}\n" : '')
            . ($options['builder_packages'] === [] ? '' : $this->debianInstall('$CG_BUILDER_PACKAGES') . "\n")
            . "WORKDIR \${CG_APP_DIR}\nCOPY package.json package-lock.json* pnpm-lock.yaml* yarn.lock* ./\nRUN --mount=type=cache,target=/root/.npm --mount=type=cache,target=/root/.local/share/pnpm/store --mount=type=cache,target=/usr/local/share/.cache/yarn set -eux; corepack enable; {$dependencyInstall}\nCOPY . .\nRUN if node -e \"process.exit(require('./package.json').scripts?.[process.argv[1]] ? 0 : 1)\" \"\$CG_NODE_BUILD_SCRIPT\"; then \"\$CG_NODE_PACKAGE_MANAGER\" run \"\$CG_NODE_BUILD_SCRIPT\"; fi\n" . $runtime;
        return [$dockerfile, $args, []];
    }

    private function python(array $template, array $options): array
    {
        $args = array_merge([
            'CG_PYTHON_VERSION' => $options['runtime_version'],
            'CG_PYTHON_PACKAGE_MANAGER' => $options['package_manager'],
            'CG_PIP_INDEX_URL' => $options['mirror']['url'],
            'CG_APP_DIR' => $options['app_dir'],
            'CG_APP_PORT' => (string) $options['port'],
            'CG_TIMEZONE' => $options['timezone'],
            'CG_BUILDER_PACKAGES' => implode(' ', $options['builder_packages']),
            'CG_RUNTIME_PACKAGES' => implode(' ', $options['runtime_packages']),
        ], $this->osBuildArgs($options));
        $defaultCommand = $options['runtime_server'] === 'uvicorn'
            ? ['uvicorn', $options['entry_module'], '--host', '0.0.0.0', '--port', (string) $options['port'], '--workers', (string) $options['workers']]
            : ['gunicorn', '--bind', '0.0.0.0:' . $options['port'], '--workers', (string) $options['workers'], $options['entry_module']];
        $dockerfile = "# syntax=registry.cn-shanghai.aliyuncs.com/swoole-public/dockerfile:1.7\nARG CG_PYTHON_VERSION\nFROM registry.cn-shanghai.aliyuncs.com/swoole-public/python:\${CG_PYTHON_VERSION}-slim-bookworm AS builder\nARG CG_PIP_INDEX_URL\nARG CG_PYTHON_PACKAGE_MANAGER\nARG CG_APP_DIR\nARG CG_BUILDER_PACKAGES=\"\"\n"
            . $this->debianStageArgs() . "\n"
            . "ENV PIP_INDEX_URL=\${CG_PIP_INDEX_URL} UV_DEFAULT_INDEX=\${CG_PIP_INDEX_URL} PIP_DISABLE_PIP_VERSION_CHECK=1 VIRTUAL_ENV=/opt/venv PATH=/opt/venv/bin:\$PATH\n"
            . ($options['builder_packages'] === [] ? '' : $this->debianInstall('$CG_BUILDER_PACKAGES') . "\n")
            . "WORKDIR \${CG_APP_DIR}\nCOPY requirements*.txt pyproject.toml* poetry.lock* uv.lock* ./\nCOPY . .\nRUN --mount=type=cache,target=/root/.cache/pip --mount=type=cache,target=/root/.cache/uv --mount=type=cache,target=/root/.cache/pypoetry python -m venv /opt/venv && case \"\$CG_PYTHON_PACKAGE_MANAGER\" in pip) if [ -f requirements.txt ]; then pip install -r requirements.txt; else pip install .; fi;; uv) pip install uv && if [ -f requirements.txt ]; then uv pip install --python /opt/venv/bin/python -r requirements.txt; else uv pip install --python /opt/venv/bin/python .; fi;; poetry) pip install poetry && poetry config virtualenvs.create false && if [ \"\$CG_PIP_INDEX_URL\" != \"https://pypi.org/simple\" ]; then poetry source add --priority=primary galaxy \"\$CG_PIP_INDEX_URL\"; fi && poetry install --only main --no-interaction --no-root;; esac\nFROM registry.cn-shanghai.aliyuncs.com/swoole-public/python:\${CG_PYTHON_VERSION}-slim-bookworm AS runtime\nARG CG_APP_DIR\nARG CG_APP_PORT\nARG CG_RUNTIME_PACKAGES=\"\"\n"
            . $this->debianStageArgs() . "\n"
            . "ENV PATH=/opt/venv/bin:\$PATH PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1\n"
            . $this->debianInstall('$CG_RUNTIME_PACKAGES') . "\nRUN useradd -r -u 10001 app\nWORKDIR \${CG_APP_DIR}\nCOPY --from=builder /opt/venv /opt/venv\nCOPY --from=builder --chown=app:app \${CG_APP_DIR} \${CG_APP_DIR}\n"
            . ($options['non_root'] ? "USER app\n" : '')
            . "EXPOSE \${CG_APP_PORT}\n" . $this->healthcheck($options)
            . 'CMD ' . $this->command($defaultCommand, $options) . "\n";
        return [$dockerfile, $args, []];
    }

    private function java(array $template, array $options): array
    {
        $manager = (string) $options['package_manager'];
        $framework = (string) ($template['framework'] ?? 'generic');
        if ($options['layered_jar'] && $framework !== 'spring-boot') {
            throw new AppException(422, 'Layered JAR 只适用于 Spring Boot 模板');
        }
        $args = array_merge([
            'CG_JAVA_VERSION' => $options['runtime_version'],
            'CG_JAVA_PACKAGE_MANAGER' => $manager,
            'CG_JAVA_MIRROR' => $options['mirror']['url'],
            'CG_JAVA_TOOL_OPTIONS' => implode(' ', $options['jvm_options']),
            'CG_APP_DIR' => $options['app_dir'],
            'CG_APP_PORT' => (string) $options['port'],
            'CG_TIMEZONE' => $options['timezone'],
            'CG_BUILDER_PACKAGES' => implode(' ', $options['builder_packages']),
            'CG_RUNTIME_PACKAGES' => implode(' ', $options['runtime_packages']),
        ], $this->osBuildArgs($options));
        $javaRuntimeSetup = $options['runtime_packages'] === []
            ? "RUN set -eux; test -e \"/usr/share/zoneinfo/\$CG_TIMEZONE\"; ln -snf \"/usr/share/zoneinfo/\$CG_TIMEZONE\" /etc/localtime; echo \"\$CG_TIMEZONE\" > /etc/timezone"
            : $this->debianInstall('$CG_RUNTIME_PACKAGES');
        $runtimeHeader = "FROM registry.cn-shanghai.aliyuncs.com/swoole-public/eclipse-temurin:\${CG_JAVA_VERSION}-jre AS runtime\nARG CG_APP_DIR\nARG CG_APP_PORT\nARG CG_JAVA_TOOL_OPTIONS\nARG CG_RUNTIME_PACKAGES=\"\"\n"
            . $this->debianStageArgs() . "\nENV JAVA_TOOL_OPTIONS=\${CG_JAVA_TOOL_OPTIONS}\n"
            . $javaRuntimeSetup . "\nRUN useradd -r -u 10001 app\nWORKDIR \${CG_APP_DIR}\n";
        $runtimeFooter = ($options['non_root'] ? "USER app\n" : '')
            . "EXPOSE \${CG_APP_PORT}\n" . $this->healthcheck($options);
        if ($manager === 'gradle') {
            $gradleTag = $options['runtime_version'] === '22' ? '8.8.0-jdk22' : '8-jdk' . $options['runtime_version'];
            $dockerfile = "# syntax=registry.cn-shanghai.aliyuncs.com/swoole-public/dockerfile:1.7\nARG CG_JAVA_VERSION\nFROM registry.cn-shanghai.aliyuncs.com/swoole-public/gradle:{$gradleTag} AS builder\nARG CG_JAVA_MIRROR\nARG CG_BUILDER_PACKAGES=\"\"\n"
                . $this->debianStageArgs() . "\n"
                . ($options['builder_packages'] === [] ? '' : $this->debianInstall('$CG_BUILDER_PACKAGES') . "\n")
                . "RUN printf '%s\\n' 'allprojects { repositories { maven { url = uri(System.getenv(\"CG_JAVA_MIRROR\")) }; mavenCentral() } }' > /tmp/galaxy.init.gradle\nWORKDIR /src\nCOPY gradle* settings.gradle* build.gradle* ./\nRUN --mount=type=cache,target=/home/gradle/.gradle gradle --init-script /tmp/galaxy.init.gradle dependencies --no-daemon\nCOPY . .\nRUN --mount=type=cache,target=/home/gradle/.gradle set -eux; gradle --init-script /tmp/galaxy.init.gradle build -x test --no-daemon; jar=\$(find build/libs -maxdepth 1 -type f -name '*.jar' ! -name '*-plain.jar' -print -quit); test -n \"\$jar\"; cp \"\$jar\" /out.jar\n"
                . $runtimeHeader . "COPY --from=builder --chown=app:app /out.jar \${CG_APP_DIR}/app.jar\n"
                . $runtimeFooter . 'ENTRYPOINT ' . $this->command(['java', '-jar', $options['app_dir'] . '/app.jar'], $options) . "\n";
            return [$dockerfile, $args, []];
        }
        $maven = "# syntax=registry.cn-shanghai.aliyuncs.com/swoole-public/dockerfile:1.7\nARG CG_JAVA_VERSION\nFROM registry.cn-shanghai.aliyuncs.com/swoole-public/maven:3.9-eclipse-temurin-\${CG_JAVA_VERSION} AS builder\nARG CG_JAVA_MIRROR\nARG CG_BUILDER_PACKAGES=\"\"\n"
            . $this->debianStageArgs() . "\n"
            . ($options['builder_packages'] === [] ? '' : $this->debianInstall('$CG_BUILDER_PACKAGES') . "\n")
            . "RUN printf '%s' '<settings><mirrors><mirror><id>galaxy</id><mirrorOf>*</mirrorOf><url>'\"\$CG_JAVA_MIRROR\"'</url></mirror></mirrors></settings>' > /tmp/settings.xml\nWORKDIR /src\nCOPY pom.xml ./\nRUN --mount=type=cache,target=/root/.m2 mvn -s /tmp/settings.xml -B -DskipTests dependency:go-offline\nCOPY . .\n";
        if ($framework === 'quarkus') {
            $dockerfile = $maven
                . "RUN --mount=type=cache,target=/root/.m2 mvn -s /tmp/settings.xml -B -DskipTests package\n"
                . $runtimeHeader
                . "COPY --from=builder --chown=app:app /src/target/quarkus-app \${CG_APP_DIR}/quarkus-app\n"
                . $runtimeFooter
                . 'ENTRYPOINT ' . $this->command(['java', '-jar', $options['app_dir'] . '/quarkus-app/quarkus-run.jar'], $options) . "\n";
            return [$dockerfile, $args, []];
        }
        $maven .= "RUN --mount=type=cache,target=/root/.m2 set -eux; mvn -s /tmp/settings.xml -B -DskipTests package; jar=\$(find target -maxdepth 1 -type f -name '*.jar' ! -name '*.original' -print -quit); test -n \"\$jar\"; cp \"\$jar\" /out.jar\n";
        if ($options['layered_jar']) {
            $dockerfile = $maven
                . "RUN mkdir /layers && cd /layers && java -Djarmode=layertools -jar /out.jar extract\n"
                . $runtimeHeader
                . "COPY --from=builder --chown=app:app /layers/dependencies/ ./\nCOPY --from=builder --chown=app:app /layers/spring-boot-loader/ ./\nCOPY --from=builder --chown=app:app /layers/snapshot-dependencies/ ./\nCOPY --from=builder --chown=app:app /layers/application/ ./\n"
                . $runtimeFooter
                . 'ENTRYPOINT ' . $this->command(['java', 'org.springframework.boot.loader.launch.JarLauncher'], $options) . "\n";
        } else {
            $dockerfile = $maven . $runtimeHeader
                . "COPY --from=builder --chown=app:app /out.jar \${CG_APP_DIR}/app.jar\n"
                . $runtimeFooter
                . 'ENTRYPOINT ' . $this->command(['java', '-jar', $options['app_dir'] . '/app.jar'], $options) . "\n";
        }
        return [$dockerfile, $args, []];
    }
}
