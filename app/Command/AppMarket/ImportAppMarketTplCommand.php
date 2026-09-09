<?php

namespace App\Command\AppMarket;

use App\Model\AppMarketTag;
use App\Model\AppMarketTpl;
use App\Model\AppMarketTplTagRel;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\DbConnection\Db;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Import a declarative Docker Swarm application template.
 *
 * The catalog stores only declarative data that the generic
 * SwarmAppInstaller understands.
 *
 */
#[Command]
class ImportAppMarketTplCommand extends HyperfCommand
{
    public function __construct()
    {
        parent::__construct('import:app-market-tpl');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('Import or update a Docker Swarm app-market template');
        $this->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'YAML template file');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate without writing to MySQL');
    }

    public function handle(): int
    {
        $file = (string) $this->input->getOption('file');
        if ($file === '' || ! is_file($file)) {
            $this->output->error('请通过 --file 指定存在的 YAML 模板文件');
            return 1;
        }
        try {
            $raw = Yaml::parseFile($file);
            if (! is_array($raw)) {
                throw new \InvalidArgumentException('模板根节点必须是对象');
            }
            [$template, $tags] = $this->normalize($raw);
            if ((bool) $this->input->getOption('dry-run')) {
                $this->output->success('Docker Swarm 模板校验通过（dry-run）');
                return 0;
            }
            $tpl = Db::transaction(function () use ($template, $tags): AppMarketTpl {
                /** @var AppMarketTpl|null $tpl */
                $tpl = AppMarketTpl::where('uuid', $template['uuid'])->first();
                $now = time();
                if ($tpl === null) {
                    $tpl = AppMarketTpl::create(array_merge($template, [
                        'used' => 0, 'viewed' => 0, 'created_at' => $now, 'updated_at' => $now,
                    ]));
                } else {
                    foreach ($template as $key => $value) {
                        $tpl->{$key} = $value;
                    }
                    $tpl->updated_at = $now;
                    $tpl->save();
                }
                AppMarketTplTagRel::where('tpl_id', (int) $tpl->id)->delete();
                foreach ($tags as $tag) {
                    $model = AppMarketTag::firstOrCreate(
                        ['type' => $tag['type'], 'title' => $tag['title']],
                        ['style' => [], 'sort' => 0, 'created_at' => $now]
                    );
                    AppMarketTplTagRel::create(['tpl_id' => (int) $tpl->id, 'tag_id' => (int) $model->id]);
                }
                return $tpl;
            });
            $this->output->success(sprintf('模板 #%d %s (%s) 已导入', $tpl->id, $tpl->title, $tpl->uuid));
            return 0;
        } catch (Throwable $e) {
            $this->output->error('导入失败：' . $e->getMessage());
            return 1;
        }
    }

    private function normalize(array $raw): array
    {
        foreach (['uuid', 'title', 'version', 'intro', 'details'] as $field) {
            if (! is_string($raw[$field] ?? null) || trim($raw[$field]) === '') {
                throw new \InvalidArgumentException($field . ' 不能为空');
            }
        }
        $uuid = trim($raw['uuid']);
        if (! preg_match('/^[a-z0-9][a-z0-9._-]{2,254}$/i', $uuid)) {
            throw new \InvalidArgumentException('uuid 格式不合法');
        }
        $type = (int) ($raw['type'] ?? AppMarketTpl::TYPE_SERVICE);
        if (! isset(AppMarketTpl::$types[$type])) {
            throw new \InvalidArgumentException('type 不在允许范围内');
        }
        $pipeline = is_array($raw['pipeline'] ?? null) ? $raw['pipeline'] : [];
        if (($pipeline['orchestrator'] ?? null) !== 'docker-swarm' || ($pipeline['status'] ?? null) !== 'ready') {
            throw new \InvalidArgumentException('pipeline 必须声明 orchestrator=docker-swarm 且 status=ready');
        }
        $clientConfig = is_array($raw['client_config'] ?? null) ? $raw['client_config'] : [];
        $serverConfig = is_array($raw['server_config'] ?? null) ? $raw['server_config'] : [];
        if (! is_array($clientConfig['form'] ?? null)) {
            throw new \InvalidArgumentException('client_config.form 必须是字段 Schema 对象');
        }
        if (! is_array($serverConfig['swarm'] ?? null) || trim((string) ($serverConfig['swarm']['image'] ?? '')) === '') {
            throw new \InvalidArgumentException('server_config.swarm.image 不能为空');
        }
        $this->validateSwarmSchema($clientConfig, $serverConfig['swarm']);
        $logo = trim((string) ($raw['logo'] ?? ''));
        if (strlen($logo) > 255 || mb_strlen($raw['title']) > 255 || mb_strlen($raw['intro']) > 255) {
            throw new \InvalidArgumentException('logo、title 或 intro 超过数据库长度限制');
        }
        $tags = [];
        foreach ((array) ($raw['tags'] ?? []) as $item) {
            $item = is_string($item) ? ['title' => $item, 'type' => 'tag'] : $item;
            if (! is_array($item) || trim((string) ($item['title'] ?? '')) === '') {
                throw new \InvalidArgumentException('tags 项必须包含 title');
            }
            $typeName = (string) ($item['type'] ?? 'tag');
            $tagType = array_search($typeName, AppMarketTag::$types, true);
            if ($tagType === false) {
                throw new \InvalidArgumentException('未知标签类型：' . $typeName);
            }
            $tags[] = ['title' => trim((string) $item['title']), 'type' => (int) $tagType];
        }
        return [[
            'uuid' => $uuid, 'type' => $type, 'title' => trim($raw['title']),
            'version' => trim($raw['version']), 'logo' => $logo, 'intro' => trim($raw['intro']),
            'details' => (string) $raw['details'], 'depends' => is_array($raw['depends'] ?? null) ? $raw['depends'] : [],
            'client_config' => $clientConfig, 'server_config' => $serverConfig, 'pipeline' => $pipeline,
            'sort' => max(0, (int) ($raw['sort'] ?? 0)),
        ], $tags];
    }

    private function validateSwarmSchema(array $clientConfig, array $swarm): void
    {
        $fields = $clientConfig['form'];
        $secretFields = [];
        foreach ($fields as $name => $field) {
            if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/', (string) $name) || ! is_array($field)) {
                throw new \InvalidArgumentException('表单字段名称或 Schema 不合法：' . $name);
            }
            if (! in_array($field['type'] ?? null, ['text', 'textarea', 'number', 'secret', 'select', 'boolean'], true)) {
                throw new \InvalidArgumentException('表单字段类型不支持：' . $name);
            }
            if (($field['type'] ?? null) === 'secret') {
                $secretFields[$name] = true;
            }
        }

        foreach (['args', 'entrypoint'] as $key) {
            if (isset($swarm[$key]) && (! is_array($swarm[$key]) || array_filter(
                $swarm[$key], static fn ($value): bool => ! is_string($value)
            ))) {
                throw new \InvalidArgumentException('server_config.swarm.' . $key . ' 必须是字符串数组');
            }
        }
        $install = $swarm['install'] ?? null;
        if (! is_array($install)
            || ! is_array($install['resources'] ?? null)
            || ! is_array($install['network'] ?? null)
            || ! is_array($install['ports'] ?? null)
            || ! is_array($install['mounts'] ?? null)) {
            throw new \InvalidArgumentException('swarm.install 必须完整声明 resources/network/ports/mounts');
        }
        $modes = $install['network']['allowed_modes'] ?? [];
        if (! is_array($modes) || $modes === [] || array_diff($modes, ['overlay', 'host'])) {
            throw new \InvalidArgumentException('network.allowed_modes 仅支持 overlay、host');
        }
        if (! in_array($install['network']['default_mode'] ?? null, $modes, true)) {
            throw new \InvalidArgumentException('network.default_mode 必须包含在 allowed_modes 中');
        }
        foreach ((array) ($swarm['secrets'] ?? []) as $secret) {
            $source = (string) ($secret['source'] ?? '');
            $field = str_starts_with($source, 'form.') ? substr($source, 5) : '';
            if ($field === '' || ! isset($secretFields[$field]) || ! str_starts_with((string) ($secret['target'] ?? ''), '/')) {
                throw new \InvalidArgumentException('Docker Secret 必须引用 secret 类型表单字段并声明绝对挂载路径');
            }
        }
        foreach ((array) ($swarm['configs'] ?? []) as $config) {
            if (! str_starts_with((string) ($config['target'] ?? ''), '/')) {
                throw new \InvalidArgumentException('Docker Config 必须声明绝对挂载路径');
            }
            $this->validateTemplateReferences((string) ($config['template'] ?? ''), $fields, $secretFields, 'Docker Config');
        }
        foreach ((array) ($swarm['environment'] ?? []) as $name => $value) {
            if (! preg_match('/^[A-Z_][A-Z0-9_]*$/', (string) $name)) {
                throw new \InvalidArgumentException('环境变量名称不合法：' . $name);
            }
            $this->validateTemplateReferences((string) $value, $fields, $secretFields, '环境变量');
        }
    }

    private function validateTemplateReferences(string $template, array $fields, array $secretFields, string $source): void
    {
        preg_match_all('/{{\s*([a-z][a-z0-9_]*)\s*}}/', $template, $matches);
        foreach ($matches[1] ?? [] as $name) {
            if (! array_key_exists($name, $fields)) {
                throw new \InvalidArgumentException($source . ' 引用了不存在的表单字段：' . $name);
            }
            if (isset($secretFields[$name])) {
                throw new \InvalidArgumentException($source . ' 不得展开 Secret 字段：' . $name);
            }
        }
    }
}
