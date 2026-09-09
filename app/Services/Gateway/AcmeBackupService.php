<?php

namespace App\Services\Gateway;

use App\Exception\AppException;
use App\Model\AcmeBackupPolicy;
use App\Model\AcmeBackupRecord;
use App\Model\Cluster;
use App\Model\ClusterWebGateway;
use App\Model\ObjectStorageBucket;
use App\Services\Docker\SwarmContainerExecService;
use App\Services\Encrypt\CredentialCipher;
use App\Services\ObjectStorageService;
use Hyperf\Redis\Redis;
use Throwable;

class AcmeBackupService
{
    public const DEFAULT_DIRECTORY = 'galaxy/acme-backups';

    public const INTERVALS = [3600, 21600, 43200, 86400, 259200, 604800];

    public function __construct(
        private SwarmContainerExecService $containerFiles,
        private ObjectStorageService $storage,
        private CredentialCipher $cipher,
        private Redis $redis
    ) {}

    public function profile(int $orgId): array
    {
        $policy = AcmeBackupPolicy::where('org_id', $orgId)->with('bucket')->first();
        $records = $policy === null ? [] : AcmeBackupRecord::where('policy_id', (int) $policy->id)
            ->orderByDesc('id')->limit(20)->get();
        return [
            'policy' => $policy,
            'records' => $records,
            'interval_options' => self::INTERVALS,
            'defaults' => [
                'enabled' => false,
                'bucket_id' => 0,
                'directory' => self::DEFAULT_DIRECTORY,
                'interval_seconds' => 86400,
            ],
        ];
    }

    public function save(int $uid, int $orgId, array $input): array
    {
        $enabled = (bool) ($input['enabled'] ?? false);
        $bucketId = (int) ($input['bucket_id'] ?? 0);
        $interval = (int) ($input['interval_seconds'] ?? 86400);
        $directory = $this->normalizeDirectory((string) ($input['directory'] ?? self::DEFAULT_DIRECTORY));
        if (! in_array($interval, self::INTERVALS, true)) {
            throw new AppException(422, '自动备份周期不受支持');
        }
        if ($enabled && $bucketId <= 0) {
            throw new AppException(422, '启用自动备份时必须选择对象存储桶');
        }
        if ($bucketId > 0 && ! ObjectStorageBucket::where('org_id', $orgId)->where('id', $bucketId)->exists()) {
            throw new AppException(422, '所选对象存储桶不存在');
        }

        /** @var AcmeBackupPolicy $policy */
        $policy = AcmeBackupPolicy::firstOrNew(['org_id' => $orgId]);
        $creating = ! $policy->exists;
        $scheduleChanged = $creating
            || (bool) $policy->enabled !== $enabled
            || (int) $policy->bucket_id !== $bucketId
            || (int) $policy->interval_seconds !== $interval
            || (string) $policy->directory !== $directory;
        $policy->enabled = $enabled;
        $policy->bucket_id = $bucketId;
        $policy->directory = $directory;
        $policy->interval_seconds = $interval;
        if ($scheduleChanged) {
            // 保存设置只负责安排下一次周期任务；“保存并立即备份”会在保存后显式触发。
            // 避免定时进程在这两个请求之间抢先取得锁，导致手动备份收到“正在执行”。
            $policy->next_backup_at = $enabled ? time() + $interval : 0;
            $policy->last_error = null;
        }
        if ($creating) {
            $policy->creator = $uid;
            $policy->created_at = time();
        }
        $policy->updated_at = time();
        $policy->save();
        return $this->profile($orgId);
    }

    public function runDue(): array
    {
        $results = [];
        $policies = AcmeBackupPolicy::where('enabled', 1)
            ->where('next_backup_at', '<=', time())
            ->orderBy('next_backup_at')->limit(50)->get();
        foreach ($policies as $policy) {
            try {
                $results[] = $this->runPolicy($policy, false);
            } catch (Throwable $e) {
                $policy->last_attempt_at = time();
                $policy->last_error = mb_substr($e->getMessage(), 0, 4000);
                $policy->next_backup_at = time() + max(300, min(3600, (int) $policy->interval_seconds));
                $policy->updated_at = time();
                $policy->save();
                $results[] = ['policy_id' => (int) $policy->id, 'error' => $policy->last_error];
            }
        }
        return $results;
    }

    public function runNow(int $orgId): array
    {
        $policy = AcmeBackupPolicy::where('org_id', $orgId)->first();
        if ($policy === null || ! $policy->enabled) {
            throw new AppException(422, '请先启用 ACME 自动备份');
        }
        return $this->runPolicy($policy, true);
    }

    private function runPolicy(AcmeBackupPolicy $policy, bool $manual): array
    {
        $lockKey = 'cg:acme-backup:policy:' . $policy->id;
        $token = bin2hex(random_bytes(16));
        if (! $this->redis->set($lockKey, $token, ['nx', 'ex' => 600])) {
            if ($manual) {
                throw new AppException(409, '该组织的 ACME 备份正在执行');
            }
            return ['policy_id' => (int) $policy->id, 'skipped' => true];
        }
        try {
            return $this->perform($policy);
        } finally {
            $this->redis->eval(
                "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) end return 0",
                [$lockKey, $token],
                1
            );
        }
    }

    private function perform(AcmeBackupPolicy $policy): array
    {
        $now = time();
        $policy->last_attempt_at = $now;
        $policy->updated_at = $now;
        $policy->save();
        $gateways = ClusterWebGateway::where('org_id', (int) $policy->org_id)
            ->where('acme_enabled', 1)->where('service_id', '<>', '')->orderBy('cluster_id')->get();
        if ($gateways->isEmpty()) {
            throw new AppException(422, '当前组织没有启用 ACME 的 Swarm Web 网关');
        }

        $success = 0;
        $errors = [];
        $records = [];
        foreach ($gateways as $gateway) {
            $cluster = Cluster::where('org_id', (int) $policy->org_id)
                ->where('id', (int) $gateway->cluster_id)
                ->where('orchestrator_type', Cluster::ORCHESTRATOR_DOCKER_SWARM)
                ->first();
            if ($cluster === null) {
                $errors[] = '集群 #' . $gateway->cluster_id . ' 不存在或不是 Swarm 集群';
                continue;
            }
            try {
                $contents = $this->containerFiles->readServiceFile(
                    $cluster,
                    (string) $gateway->service_id,
                    '/letsencrypt/acme.json'
                );
                $this->assertValidAcme($contents);
                $sourceHash = hash('sha256', $contents);
                $envelope = json_encode([
                    'format' => 'galaxy-acme-backup-v1',
                    'cipher' => 'xchacha20poly1305',
                    'org_id' => (int) $policy->org_id,
                    'cluster_id' => (int) $cluster->id,
                    'gateway_id' => (int) $gateway->id,
                    'created_at' => $now,
                    'source_sha256' => $sourceHash,
                    'payload_ciphertext' => $this->cipher->encrypt($contents),
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $objectKey = $this->objectKey(
                    (string) $policy->directory,
                    (int) $policy->org_id,
                    (int) $cluster->id,
                    $now,
                    $sourceHash
                );
                $this->storage->writeSystemObject(
                    (int) $policy->org_id,
                    (int) $policy->bucket_id,
                    $objectKey,
                    $envelope
                );
                $record = AcmeBackupRecord::create([
                    'policy_id' => (int) $policy->id,
                    'org_id' => (int) $policy->org_id,
                    'cluster_id' => (int) $cluster->id,
                    'gateway_id' => (int) $gateway->id,
                    'bucket_id' => (int) $policy->bucket_id,
                    'object_key' => $objectKey,
                    'source_sha256' => $sourceHash,
                    'source_size' => strlen($contents),
                    'backup_size' => strlen($envelope),
                    'status' => AcmeBackupRecord::STATUS_SUCCESS,
                    'error' => null,
                    'created_at' => $now,
                ]);
                $records[] = $record;
                ++$success;
            } catch (Throwable $e) {
                $message = '集群 #' . $cluster->id . '：' . mb_substr($e->getMessage(), 0, 1000);
                $errors[] = $message;
                $records[] = AcmeBackupRecord::create([
                    'policy_id' => (int) $policy->id,
                    'org_id' => (int) $policy->org_id,
                    'cluster_id' => (int) $cluster->id,
                    'gateway_id' => (int) $gateway->id,
                    'bucket_id' => (int) $policy->bucket_id,
                    'object_key' => '',
                    'source_sha256' => '',
                    'source_size' => 0,
                    'backup_size' => 0,
                    'status' => AcmeBackupRecord::STATUS_ERROR,
                    'error' => $message,
                    'created_at' => $now,
                ]);
            }
        }
        $policy->last_success_at = $success > 0 ? $now : (int) $policy->last_success_at;
        $policy->last_error = $errors === [] ? null : implode("\n", $errors);
        $policy->next_backup_at = $now + (int) $policy->interval_seconds;
        $policy->updated_at = time();
        $policy->save();
        return [
            'policy_id' => (int) $policy->id,
            'success' => $success,
            'failed' => count($errors),
            'records' => $records,
            'next_backup_at' => (int) $policy->next_backup_at,
            'errors' => $errors,
        ];
    }

    private function assertValidAcme(string $contents): void
    {
        if ($contents === '' || strlen($contents) > 16 * 1024 * 1024) {
            throw new AppException(422, 'acme.json 为空或超过 16 MiB');
        }
        $document = json_decode($contents, true);
        if (! is_array($document) || $document === []) {
            throw new AppException(422, 'acme.json 不是有效的 ACME JSON');
        }
        $hasAccount = false;
        foreach ($document as $resolver) {
            if (is_array($resolver) && ! empty($resolver['Account'] ?? $resolver['account'] ?? [])) {
                $hasAccount = true;
                break;
            }
        }
        if (! $hasAccount) {
            throw new AppException(422, 'acme.json 尚未包含已注册的 ACME 账户');
        }
    }

    private function objectKey(string $directory, int $orgId, int $clusterId, int $timestamp, string $hash): string
    {
        return sprintf(
            '%s/org-%d/cluster-%d/acme-%s-%s-%s.json.enc',
            $this->normalizeDirectory($directory),
            $orgId,
            $clusterId,
            date('Ymd-His', $timestamp),
            substr($hash, 0, 12),
            bin2hex(random_bytes(3))
        );
    }

    private function normalizeDirectory(string $directory): string
    {
        $directory = trim(str_replace('\\', '/', $directory), '/');
        if ($directory === '' || strlen($directory) > 512 || str_contains($directory, "\0")) {
            throw new AppException(422, '备份目录不能为空且不能超过 512 个字符');
        }
        foreach (explode('/', $directory) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new AppException(422, '备份目录包含无效路径片段');
            }
        }
        return $directory;
    }
}
