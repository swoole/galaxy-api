<?php

$timezones = DateTimeZone::listIdentifiers(DateTimeZone::ALL);
if (! in_array('UTC', $timezones, true)) {
    array_unshift($timezones, 'UTC');
}

$config = [
    'version' => '3',
    'timezones' => $timezones,
    'dockerignore' => <<<'IGNORE'
# CodeGalaxy default-secure-v1
.git
.gitignore
.gitattributes
.env
.env.*
!.env.example
*.key
*.p12
*.pfx
.ssh
**/.ssh
id_rsa*
id_ed25519*
**/id_rsa*
**/id_ed25519*
**/.docker/config.json
.idea
.vscode
.DS_Store
coverage
*.log
IGNORE,
    'mirrors' => [
        'os' => [
            [
                'key' => 'official', 'label' => '镜像默认源',
                'sources' => [],
            ],
            [
                'key' => 'aliyun', 'label' => '阿里云（推荐）',
                'sources' => [
                    'debian' => [
                        'url' => 'https://mirrors.aliyun.com/debian',
                        'security_url' => 'https://mirrors.aliyun.com/debian-security',
                        'bootstrap_url' => 'http://mirrors.aliyun.com/debian',
                        'bootstrap_security_url' => 'http://mirrors.aliyun.com/debian-security',
                    ],
                    'ubuntu' => [
                        'url' => 'https://mirrors.aliyun.com/ubuntu',
                        'security_url' => 'https://mirrors.aliyun.com/ubuntu',
                        'bootstrap_url' => 'http://mirrors.aliyun.com/ubuntu',
                        'bootstrap_security_url' => 'http://mirrors.aliyun.com/ubuntu',
                    ],
                    'alpine' => ['url' => 'https://mirrors.aliyun.com/alpine'],
                    'centos-stream' => ['url' => 'https://mirrors.aliyun.com/centos-stream'],
                ],
            ],
            [
                'key' => 'tencent', 'label' => '腾讯云',
                'sources' => [
                    'debian' => [
                        'url' => 'https://mirrors.cloud.tencent.com/debian',
                        'security_url' => 'https://mirrors.cloud.tencent.com/debian-security',
                        'bootstrap_url' => 'http://mirrors.cloud.tencent.com/debian',
                        'bootstrap_security_url' => 'http://mirrors.cloud.tencent.com/debian-security',
                    ],
                    'ubuntu' => [
                        'url' => 'https://mirrors.cloud.tencent.com/ubuntu',
                        'security_url' => 'https://mirrors.cloud.tencent.com/ubuntu',
                        'bootstrap_url' => 'http://mirrors.cloud.tencent.com/ubuntu',
                        'bootstrap_security_url' => 'http://mirrors.cloud.tencent.com/ubuntu',
                    ],
                    'alpine' => ['url' => 'https://mirrors.cloud.tencent.com/alpine'],
                    'centos-stream' => ['url' => 'https://mirrors.cloud.tencent.com/centos-stream'],
                ],
            ],
            [
                'key' => 'huawei', 'label' => '华为云',
                'sources' => [
                    'debian' => [
                        'url' => 'https://repo.huaweicloud.com/debian',
                        'security_url' => 'https://repo.huaweicloud.com/debian-security',
                        'bootstrap_url' => 'http://repo.huaweicloud.com/debian',
                        'bootstrap_security_url' => 'http://repo.huaweicloud.com/debian-security',
                    ],
                    'ubuntu' => [
                        'url' => 'https://repo.huaweicloud.com/ubuntu',
                        'security_url' => 'https://repo.huaweicloud.com/ubuntu',
                        'bootstrap_url' => 'http://repo.huaweicloud.com/ubuntu',
                        'bootstrap_security_url' => 'http://repo.huaweicloud.com/ubuntu',
                    ],
                    'alpine' => ['url' => 'https://repo.huaweicloud.com/alpine'],
                    'centos-stream' => ['url' => 'https://repo.huaweicloud.com/centos-stream'],
                ],
            ],
            [
                'key' => 'custom', 'label' => '自定义',
                'sources' => [],
            ],
        ],
        'composer' => [
            ['key' => 'official', 'label' => '官方源', 'url' => 'https://repo.packagist.org'],
            ['key' => 'aliyun', 'label' => '阿里云', 'url' => 'https://mirrors.aliyun.com/composer/'],
            ['key' => 'tencent', 'label' => '腾讯云', 'url' => 'https://mirrors.cloud.tencent.com/composer/'],
            ['key' => 'huawei', 'label' => '华为云', 'url' => 'https://repo.huaweicloud.com/repository/php/'],
            ['key' => 'custom', 'label' => '自定义', 'url' => ''],
        ],
        'go' => [
            ['key' => 'official', 'label' => '官方源', 'url' => 'https://proxy.golang.org,direct'],
            ['key' => 'goproxy-cn', 'label' => 'goproxy.cn', 'url' => 'https://goproxy.cn,direct'],
            ['key' => 'aliyun', 'label' => '阿里云', 'url' => 'https://mirrors.aliyun.com/goproxy/,direct'],
            ['key' => 'custom', 'label' => '自定义', 'url' => ''],
        ],
        'npm' => [
            ['key' => 'official', 'label' => '官方源', 'url' => 'https://registry.npmjs.org'],
            ['key' => 'npmmirror', 'label' => 'npmmirror', 'url' => 'https://registry.npmmirror.com'],
            ['key' => 'custom', 'label' => '自定义', 'url' => ''],
        ],
        'pip' => [
            ['key' => 'official', 'label' => '官方源', 'url' => 'https://pypi.org/simple'],
            ['key' => 'tsinghua', 'label' => '清华大学', 'url' => 'https://pypi.tuna.tsinghua.edu.cn/simple'],
            ['key' => 'aliyun', 'label' => '阿里云', 'url' => 'https://mirrors.aliyun.com/pypi/simple/'],
            ['key' => 'tencent', 'label' => '腾讯云', 'url' => 'https://mirrors.cloud.tencent.com/pypi/simple/'],
            ['key' => 'custom', 'label' => '自定义', 'url' => ''],
        ],
        'maven' => [
            ['key' => 'official', 'label' => 'Maven Central', 'url' => 'https://repo.maven.apache.org/maven2'],
            ['key' => 'aliyun', 'label' => '阿里云', 'url' => 'https://maven.aliyun.com/repository/public'],
            ['key' => 'huawei', 'label' => '华为云', 'url' => 'https://repo.huaweicloud.com/repository/maven/'],
            ['key' => 'custom', 'label' => '自定义', 'url' => ''],
        ],
    ],
    'templates' => [
        ['key' => 'php-laravel', 'version' => '1.0.0', 'language' => 'php', 'language_label' => 'PHP', 'framework' => 'laravel', 'framework_label' => 'Laravel', 'title' => 'Laravel Web', 'summary' => 'Composer 多阶段构建；默认 Nginx + PHP-FPM，也可选择 FrankenPHP。', 'runtime_versions' => ['8.3', '8.4', '8.5'], 'framework_versions' => ['11', '12'], 'runtime_servers' => [['key' => 'nginx-fpm', 'label' => 'Nginx + PHP-FPM'], ['key' => 'frankenphp', 'label' => 'FrankenPHP']], 'default_server' => 'nginx-fpm', 'default_port' => 80, 'extensions' => ['opcache', 'pdo_mysql', 'pdo_pgsql', 'redis', 'mongodb', 'gd', 'intl', 'zip'], 'default_extensions' => ['opcache', 'pdo_mysql'], 'package_manager' => 'composer', 'package_managers' => ['composer'], 'required_files' => ['composer.json', 'artisan']],
        ['key' => 'php-hyperf', 'version' => '1.0.0', 'language' => 'php', 'language_label' => 'PHP', 'framework' => 'hyperf', 'framework_label' => 'Hyperf', 'title' => 'Hyperf Service', 'summary' => 'Swoole CLI Runtime 与 Composer 多阶段构建。', 'runtime_versions' => ['8.3', '8.4'], 'framework_versions' => ['3'], 'runtime_servers' => [['key' => 'swoole', 'label' => 'Swoole CLI']], 'default_server' => 'swoole', 'default_port' => 9501, 'extensions' => ['swoole', 'opcache', 'pdo_mysql', 'pdo_pgsql', 'redis', 'mongodb', 'gd', 'intl', 'zip'], 'default_extensions' => ['swoole', 'opcache', 'pdo_mysql'], 'package_manager' => 'composer', 'package_managers' => ['composer'], 'required_files' => ['composer.json', 'bin/hyperf.php']],
        ['key' => 'php-composer', 'version' => '1.0.0', 'language' => 'php', 'language_label' => 'PHP', 'framework' => 'generic', 'framework_label' => '通用 Composer', 'title' => 'PHP Composer', 'summary' => '适合自定义 PHP Web/CLI 项目。', 'runtime_versions' => ['8.3', '8.4', '8.5'], 'framework_versions' => ['any'], 'runtime_servers' => [['key' => 'cli', 'label' => 'PHP CLI']], 'default_server' => 'cli', 'default_port' => 8080, 'extensions' => ['opcache', 'pdo_mysql', 'pdo_pgsql', 'redis', 'mongodb', 'gd', 'intl', 'zip'], 'default_extensions' => ['opcache', 'pdo_mysql'], 'package_manager' => 'composer', 'package_managers' => ['composer'], 'required_files' => ['composer.json']],
        ['key' => 'go-modules', 'version' => '1.0.0', 'language' => 'go', 'language_label' => 'Go', 'framework' => 'generic', 'framework_label' => 'Gin / go-zero / Fiber / Echo / Hertz / 通用', 'title' => 'Go Modules', 'summary' => '多阶段编译为精简镜像，可选择 CGO。', 'runtime_versions' => ['1.24', '1.25'], 'framework_versions' => ['any'], 'runtime_servers' => [['key' => 'binary', 'label' => 'Native Binary']], 'default_server' => 'binary', 'default_port' => 8080, 'options' => ['cgo'], 'package_manager' => 'go', 'package_managers' => ['go'], 'required_files' => ['go.mod']],
        ['key' => 'node-web', 'version' => '1.0.0', 'language' => 'node', 'language_label' => 'Node.js', 'framework' => 'generic', 'framework_label' => 'Express / Fastify / NestJS / Next.js / Nuxt / 通用', 'title' => 'Node.js Web', 'summary' => 'npm/pnpm/yarn 依赖缓存与生产构建。', 'runtime_versions' => ['20', '22', '24'], 'framework_versions' => ['any'], 'runtime_servers' => [['key' => 'node', 'label' => 'Node.js']], 'default_server' => 'node', 'default_port' => 3000, 'package_manager' => 'npm', 'package_managers' => ['npm', 'pnpm', 'yarn'], 'required_files' => ['package.json']],
        ['key' => 'python-web', 'version' => '1.0.0', 'language' => 'python', 'language_label' => 'Python', 'framework' => 'generic', 'framework_label' => 'Django / Flask / FastAPI / WSGI / ASGI', 'title' => 'Python Web', 'summary' => 'pip/uv/Poetry 多阶段虚拟环境与非 root 运行。', 'runtime_versions' => ['3.11', '3.12', '3.13'], 'framework_versions' => ['any'], 'runtime_servers' => [['key' => 'gunicorn', 'label' => 'Gunicorn'], ['key' => 'uvicorn', 'label' => 'Uvicorn']], 'default_server' => 'gunicorn', 'default_port' => 8000, 'package_manager' => 'pip', 'package_managers' => ['pip', 'uv', 'poetry'], 'required_any_files' => ['requirements.txt', 'pyproject.toml']],
        ['key' => 'java-spring', 'version' => '1.0.0', 'language' => 'java', 'language_label' => 'Java', 'framework' => 'spring-boot', 'framework_label' => 'Spring Boot / Quarkus / JAR', 'title' => 'Java Application', 'summary' => 'Maven/Gradle 多阶段构建与 JRE 精简运行镜像。', 'runtime_versions' => ['17', '21', '22'], 'framework_versions' => ['3', 'any'], 'runtime_servers' => [['key' => 'jar', 'label' => 'Executable JAR']], 'default_server' => 'jar', 'default_port' => 8080, 'package_manager' => 'maven', 'package_managers' => ['maven', 'gradle'], 'required_any_files' => ['pom.xml', 'build.gradle', 'build.gradle.kts']],
        ['key' => 'static-nginx', 'version' => '1.0.0', 'language' => 'static', 'language_label' => 'Static', 'framework' => 'frontend', 'framework_label' => 'Vue / React / Vite / 静态目录', 'title' => 'Static Site', 'summary' => 'Node 构建并由 Nginx 提供静态资源。', 'runtime_versions' => ['20', '22', '24'], 'framework_versions' => ['any'], 'runtime_servers' => [['key' => 'nginx', 'label' => 'Nginx']], 'default_server' => 'nginx', 'default_port' => 80, 'package_manager' => 'npm', 'package_managers' => ['npm', 'pnpm', 'yarn'], 'required_files' => ['package.json']],
        ['key' => 'static-directory', 'version' => '1.0.0', 'language' => 'static', 'language_label' => 'Static', 'framework' => 'directory', 'framework_label' => '静态目录', 'title' => '静态目录 + Nginx', 'summary' => '无需 Node.js 或 package.json，直接将已有 HTML/CSS/JS 目录复制到 Nginx。', 'runtime_versions' => ['1.27'], 'framework_versions' => ['any'], 'runtime_servers' => [['key' => 'nginx', 'label' => 'Nginx']], 'default_server' => 'nginx', 'default_port' => 80, 'package_manager' => 'none', 'package_managers' => ['none'], 'required_files' => ['index.html']],
    ],
];

$baseTemplates = [];
foreach ($config['templates'] as $template) {
    $baseTemplates[$template['key']] = $template;
}
$variants = [
    ['php-laravel', ['key' => 'php-symfony', 'framework' => 'symfony', 'framework_label' => 'Symfony', 'title' => 'Symfony Web', 'summary' => 'Symfony Web/API 的 Composer 多阶段构建。', 'framework_versions' => ['7', '8'], 'required_files' => ['composer.json']]],
    ['php-laravel', ['key' => 'php-thinkphp', 'framework' => 'thinkphp', 'framework_label' => 'ThinkPHP', 'title' => 'ThinkPHP Web', 'summary' => 'ThinkPHP Web/API 的 Nginx + PHP-FPM 构建。', 'framework_versions' => ['8', '9'], 'required_files' => ['composer.json']]],
    ['go-modules', ['key' => 'go-gin', 'framework' => 'gin', 'framework_label' => 'Gin', 'title' => 'Gin API', 'summary' => 'Gin Web/API 多阶段原生编译。']],
    ['go-modules', ['key' => 'go-zero', 'framework' => 'go-zero', 'framework_label' => 'go-zero', 'title' => 'go-zero Service', 'summary' => 'go-zero 微服务多阶段原生编译。']],
    ['go-modules', ['key' => 'go-fiber', 'framework' => 'fiber', 'framework_label' => 'Fiber', 'title' => 'Fiber API', 'summary' => 'Fiber Web/API 多阶段原生编译。']],
    ['go-modules', ['key' => 'go-echo', 'framework' => 'echo', 'framework_label' => 'Echo', 'title' => 'Echo API', 'summary' => 'Echo Web/API 多阶段原生编译。']],
    ['go-modules', ['key' => 'go-hertz', 'framework' => 'hertz', 'framework_label' => 'Hertz', 'title' => 'Hertz Service', 'summary' => 'CloudWeGo Hertz 服务多阶段原生编译。']],
    ['node-web', ['key' => 'node-express', 'framework' => 'express', 'framework_label' => 'Express', 'title' => 'Express API', 'summary' => 'Express Web/API 生产依赖构建。']],
    ['node-web', ['key' => 'node-fastify', 'framework' => 'fastify', 'framework_label' => 'Fastify', 'title' => 'Fastify API', 'summary' => 'Fastify Web/API 生产依赖构建。']],
    ['node-web', ['key' => 'node-nestjs', 'framework' => 'nestjs', 'framework_label' => 'NestJS', 'title' => 'NestJS Service', 'summary' => 'NestJS TypeScript 构建与生产运行阶段。', 'framework_versions' => ['10', '11']]],
    ['node-web', ['key' => 'node-nextjs', 'framework' => 'nextjs', 'framework_label' => 'Next.js', 'title' => 'Next.js Web', 'summary' => 'Next.js SSR/Standalone 项目构建。', 'framework_versions' => ['14', '15', '16']]],
    ['node-web', ['key' => 'node-nuxt', 'framework' => 'nuxt', 'framework_label' => 'Nuxt', 'title' => 'Nuxt Web', 'summary' => 'Nuxt SSR 项目构建。', 'framework_versions' => ['3', '4']]],
    ['python-web', ['key' => 'python-django', 'framework' => 'django', 'framework_label' => 'Django', 'title' => 'Django Web', 'summary' => 'Django + Gunicorn 多阶段 Python 构建。', 'framework_versions' => ['5', '6'], 'required_any_files' => ['requirements.txt', 'pyproject.toml'], 'required_files' => ['manage.py'], 'runtime_servers' => [['key' => 'gunicorn', 'label' => 'Gunicorn']], 'default_server' => 'gunicorn']],
    ['python-web', ['key' => 'python-flask', 'framework' => 'flask', 'framework_label' => 'Flask', 'title' => 'Flask API', 'summary' => 'Flask + Gunicorn 多阶段 Python 构建。', 'framework_versions' => ['3']]],
    ['python-web', ['key' => 'python-fastapi', 'framework' => 'fastapi', 'framework_label' => 'FastAPI', 'title' => 'FastAPI Service', 'summary' => 'FastAPI + Uvicorn ASGI 多阶段构建。', 'runtime_servers' => [['key' => 'uvicorn', 'label' => 'Uvicorn']], 'default_server' => 'uvicorn']],
    ['java-spring', ['key' => 'java-quarkus', 'framework' => 'quarkus', 'framework_label' => 'Quarkus', 'title' => 'Quarkus Service', 'summary' => 'Quarkus Maven JVM 项目多阶段构建。', 'framework_versions' => ['3']]],
    ['java-spring', ['key' => 'java-jar', 'framework' => 'generic', 'framework_label' => '通用 JAR', 'title' => 'Java JAR', 'summary' => '通用 Maven 可执行 JAR 构建。', 'framework_versions' => ['any']]],
    ['static-nginx', ['key' => 'static-vue', 'framework' => 'vue', 'framework_label' => 'Vue', 'title' => 'Vue Static', 'summary' => 'Vue/Vite 构建并由 Nginx 提供静态资源。', 'framework_versions' => ['3']]],
    ['static-nginx', ['key' => 'static-react', 'framework' => 'react', 'framework_label' => 'React', 'title' => 'React Static', 'summary' => 'React 构建并由 Nginx 提供静态资源。', 'framework_versions' => ['18', '19']]],
    ['static-nginx', ['key' => 'static-vite', 'framework' => 'vite', 'framework_label' => 'Vite', 'title' => 'Vite Static', 'summary' => 'Vite 通用前端构建并由 Nginx 提供静态资源。', 'framework_versions' => ['6', '7']]],
];
foreach ($variants as [$baseKey, $overrides]) {
    $config['templates'][] = array_replace($baseTemplates[$baseKey], $overrides);
}
foreach ($config['templates'] as &$template) {
    $template['platforms'] = ['linux/amd64', 'linux/arm64'];
    $template['default_platforms'] = ['linux/amd64'];
    $template['workload'] = $template['language'] === 'static' ? 'static' : 'web';
    $template['tags'] = array_values(array_filter([
        'multi-stage', $template['workload'], $template['framework'] ?? '',
    ]));
    $template['os_package_managers'] = match ($template['language']) {
        'static' => ['apt', 'apk'],
        'go' => ['apt', 'apk'],
        default => ['apt'],
    };
    $template['os_distributions'] = match ($template['language']) {
        'go', 'static' => ['debian', 'alpine'],
        'java' => ['ubuntu'],
        default => ['debian'],
    };
    if ($template['key'] === 'static-directory') {
        $template['os_package_managers'] = ['apk'];
        $template['os_distributions'] = ['alpine'];
    }
    $template['common_options'] = [
        ['key' => 'app_dir', 'label' => '项目目录', 'type' => 'absolute_path', 'default' => '/app'],
        ['key' => 'port', 'label' => '监听端口', 'type' => 'port', 'default' => (int) $template['default_port']],
        ['key' => 'timezone', 'label' => '时区', 'type' => 'single_select', 'allowed_ref' => 'timezones', 'default' => 'UTC'],
        ['key' => 'non_root', 'label' => '非 root 运行', 'type' => 'boolean',
            'default' => ! ($template['language'] === 'php' && $template['default_server'] === 'nginx-fpm')
                && $template['language'] !== 'static'],
        ['key' => 'healthcheck_path', 'label' => '健康检查路径', 'type' => 'http_path', 'default' => ''],
        ['key' => 'start_command', 'label' => '启动参数', 'type' => 'argument_list', 'default' => []],
        ['key' => 'builder_packages', 'label' => 'Builder 系统包', 'type' => 'package_list', 'default' => []],
        ['key' => 'runtime_packages', 'label' => 'Runtime 系统包', 'type' => 'package_list', 'default' => []],
    ];
    $template['language_options'] = $template['key'] === 'static-directory' ? [] : match ($template['language']) {
        'go' => [
            ['key' => 'runtime_variant', 'label' => 'Runtime 基础镜像', 'type' => 'single_select',
                'allowed' => ['alpine', 'debian', 'distroless'], 'default' => 'alpine'],
            ['key' => 'build_tags', 'label' => 'Build Tags', 'type' => 'identifier_list', 'default' => []],
            ['key' => 'ldflags', 'label' => '链接参数', 'type' => 'safe_text', 'default' => '-s -w'],
            ['key' => 'private_modules', 'label' => '私有模块域名', 'type' => 'domain_list', 'default' => []],
            ['key' => 'target_package', 'label' => '目标包', 'type' => 'go_package', 'default' => './'],
        ],
        'node', 'static' => [
            ['key' => 'build_script', 'label' => '构建 Script', 'type' => 'script_name', 'default' => 'build'],
            ['key' => 'start_script', 'label' => '启动 Script', 'type' => 'script_name', 'default' => 'start'],
        ],
        'python' => [
            ['key' => 'entry_module', 'label' => 'WSGI/ASGI 入口', 'type' => 'python_entry',
                'default' => ($template['framework'] ?? '') === 'django' ? 'app.wsgi:application' : 'app:app'],
            ['key' => 'workers', 'label' => 'Worker 数量', 'type' => 'integer', 'min' => 1, 'max' => 64, 'default' => 2],
        ],
        'java' => [
            ['key' => 'jvm_options', 'label' => 'JVM 参数', 'type' => 'argument_list', 'default' => []],
            ['key' => 'layered_jar', 'label' => 'Spring Boot Layered JAR', 'type' => 'boolean',
                'default' => ($template['framework'] ?? '') === 'spring-boot'],
        ],
        default => [],
    };
}
unset($template);
$config['mirrors']['pnpm'] = $config['mirrors']['npm'];
$config['mirrors']['yarn'] = $config['mirrors']['npm'];
$config['mirrors']['uv'] = $config['mirrors']['pip'];
$config['mirrors']['poetry'] = $config['mirrors']['pip'];
$config['mirrors']['gradle'] = $config['mirrors']['maven'];

return $config;
