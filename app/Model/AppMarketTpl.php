<?php

namespace App\Model;

use App\Exception\AppException;
use App\Services\RawRedis;
use App\Support\MySQL;
use Hyperf\DbConnection\Db;
use stdClass;
use Throwable;

class AppMarketTpl extends Model
{
    public const TYPE_SERVICE = 1;
    public const TYPE_APP = 2;
    public const TYPE_FRAMEWORK = 3;
    public const TYPE_CLOUD_NATIVE = 4;
    public const TYPE_OTHER = 9;

    public static $types = [
        self::TYPE_SERVICE => 'service',
        self::TYPE_APP => 'app',
        self::TYPE_FRAMEWORK => 'framework',
        self::TYPE_CLOUD_NATIVE => 'cloudNative',
        self::TYPE_OTHER => 'other',
    ];

    protected ?string $table = 'app_market_tpl';

    protected array $guarded = ['id'];

    protected array $casts = [
        'id' => 'integer',
        'type' => 'integer',
        'used' => 'integer',
        'viewed' => 'integer',
        'sort' => 'integer',
        'depends' => 'array',
        'client_config' => 'array',
        'server_config' => 'array',
        'pipeline' => 'array',
        'created_at' => 'integer',
        'updated_at' => 'integer',
    ];

    public function list(
        ?int $type = null,
        array $tags = [],
        ?string $keyword = null,
        array $sort = [],
        int $page = 1,
        int $pageSize = 20,
        ?string $result = null
    ): array {
        $simple = $result === 'simple';
        $builder = $this->newQuery()
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(`pipeline`, '$.orchestrator')) IN (?, ?)", [
                'docker-swarm',
                'kubernetes',
            ])
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(`pipeline`, '$.status')) = ?", ['ready']);

        if ($simple) {
            $builder->select('id', 'type', 'title', 'uuid', 'version', 'used', 'created_at', 'updated_at');
        } else {
            $builder->select(
                'id', 'type', 'title', 'uuid', 'version', 'logo', 'intro', 'used', 'created_at', 'updated_at'
            )->with('tags');
        }
        if ($type !== null) {
            $builder->where('type', $type);
        }
        if ($tags !== []) {
            $builder->whereIn('id', AppMarketTplTagRel::whereIn('tag_id', $tags)->pluck('tpl_id')->toArray());
        }
        if ($keyword !== null && $keyword !== '') {
            $builder->where(function ($query) use ($keyword): void {
                $query->where('title', 'like', '%' . $keyword . '%')
                    ->orWhere('uuid', 'like', '%' . $keyword . '%');
                if (ctype_digit($keyword)) {
                    $query->orWhere('id', (int) $keyword);
                }
            });
        }

        MySQL::builderSort($builder, $sort, [], ['id', 'sort', 'used', 'viewed', 'updated_at']);
        $data = MySQL::jsonPaginate($builder, $page, $pageSize);
        if (! $simple && ! empty($data['data'])) {
            $data['data']->each(static function (AppMarketTpl $tpl): void {
                $tpl->tags->each(static function (AppMarketTag $tag): void {
                    unset($tag->pivot);
                });
            });
        }
        return $data;
    }

    public function filterMetadata(): array
    {
        $metadata = [
            'tag' => [],
            'service' => [],
            'lang' => [],
            'framework' => [],
            'sortable' => [
                ['label' => '创建时间', 'field' => 'id'],
                ['label' => '更新时间', 'field' => 'updated_at'],
                ['label' => '使用量', 'field' => 'used'],
                ['label' => '浏览量', 'field' => 'viewed'],
            ],
        ];
        $groups = AppMarketTag::select('id', 'type', 'title')
            ->orderBy('sort')->orderBy('id')->get()->groupBy('type');
        foreach ($groups as $type => $tags) {
            if (isset(AppMarketTag::$types[$type])) {
                $metadata[AppMarketTag::$types[$type]] = $tags;
            }
        }
        return $metadata;
    }

    public function profile(int $tplId): AppMarketTpl
    {
        /** @var AppMarketTpl|null $tpl */
        $tpl = $this->newQuery()->where('id', $tplId)
            ->select(
                'id', 'type', 'title', 'version', 'logo', 'intro', 'details', 'depends', 'client_config', 'used',
                'viewed', 'created_at', 'updated_at', 'server_config', 'pipeline'
            )->with('tags')->first();
        if ($tpl === null) {
            throw new AppException(404, '模板不存在');
        }
        $orchestrator = $tpl['pipeline']['orchestrator'] ?? null;
        $ready = in_array($orchestrator, ['docker-swarm', 'kubernetes'], true)
            && ($tpl['pipeline']['status'] ?? null) === 'ready';
        $tpl['extra'] = new stdClass();
        $tpl['orchestrator'] = $orchestrator;
        $tpl['installable'] = $ready;
        $tpl['installer_driver'] = (string) ($tpl['pipeline']['driver'] ?? '');
        $configKey = $orchestrator === 'kubernetes' ? 'kubernetes' : 'swarm';
        $tpl['install_policy'] = $tpl['server_config'][$configKey]['install'] ?? new stdClass();
        if ($orchestrator === 'kubernetes') {
            $kubernetes = (array) ($tpl['server_config']['kubernetes'] ?? []);
            $tpl['kubernetes_install'] = [
                'namespace' => (string) ($kubernetes['namespace'] ?? 'default'),
                'release_name' => (string) ($kubernetes['release_name'] ?? ''),
                'workload_description' => (string) ($kubernetes['workload_description'] ?? 'Deployment / Service / Ingress'),
                'credential_description' => (string) ($kubernetes['credential_description'] ?? 'Galaxy 使用已保存的 Kubernetes API 凭据完成安装'),
                'timeout_seconds' => max(60, (int) (($kubernetes['helm']['timeout_seconds'] ?? 600))),
            ];
        }
        $tpl['depends'] = $tpl['depends'] ?? ['depends' => []];
        unset($tpl['server_config'], $tpl['pipeline']);
        $this->newQuery()->where('id', $tplId)->update(['viewed' => Db::raw('`viewed` + 1')]);
        return $tpl;
    }

    public function queryProgress(int $orgId, string $jobId, float $begin = 0.0): array
    {
        $key = 'cg.am.jobpg.' . $jobId;
        $redis = RawRedis::get();
        $redis->setOption(\Redis::OPT_READ_TIMEOUT, 10);
        if ($redis->type($key) !== \Redis::REDIS_ZSET) {
            throw new AppException(404, '该任务不存在，无法查询进度');
        }
        $results = $this->tryQueryProgress($redis, $key, $begin);
        if ($results !== []) {
            return $results;
        }
        try {
            $redis->subscribe([$key], function ($redis) use (&$results, $key, $begin): void {
                $results = $this->tryQueryProgress($redis, $key, $begin);
                $redis->close();
            });
        } catch (Throwable $e) {
            if (! str_contains($e->getMessage(), 'closed')) {
                $results = $this->tryQueryProgress($redis, $key, $begin);
            }
        }
        return $results;
    }

    private function tryQueryProgress($redis, string $key, float $begin): array
    {
        $sets = $redis->zRangeByScore($key, '(' . $begin, '+inf', ['withscores' => true]);
        $results = [];
        foreach ($sets ?: [] as $value => $score) {
            $item = json_decode($value, true);
            if (is_array($item)) {
                $item['report_at'] = (float) $score;
                $results[] = $item;
            }
        }
        return $results;
    }

    public function tags()
    {
        return $this->belongsToMany(AppMarketTag::class, 'app_market_tpl_tag_rel', 'tpl_id', 'tag_id')
            ->select('app_market_tag.id', 'app_market_tag.title', 'app_market_tag.style');
    }
}
