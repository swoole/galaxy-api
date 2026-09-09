<?php

namespace App\Services;

use App\Exception\AppException;
use App\Model\ObjectStorageBucket;
use App\Model\ObjectStorageFile;
use App\Model\AcmeBackupPolicy;
use App\Services\Encrypt\EncryptService;
use App\Support\Functions;
use Aws\S3\S3Client;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Psr7\Request;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Upload\UploadedFile;
use Iidestiny\Flysystem\Oss\OssAdapter;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\UnableToDeleteFile;
use OSS\OssClient;
use Overtrue\Flysystem\Cos\CosAdapter;
use Qcloud\Cos\Signature as QcloudCosSignature;

class ObjectStorageService
{
    #[Inject]
    protected EncryptService $encryptService;

    #[Inject]
    protected CloudAccountService $cloudAccounts;

    /** @var array<int, Filesystem> cached per bucket_id */
    private array $instances = [];

    /**
     * Resolve the org's default bucket and create its filesystem.
     * Falls back to env COS config if no default bucket is configured.
     *
     * @return array{bucket: ?ObjectStorageBucket, filesystem: Filesystem, base_url: string}
     */
    public function resolveForOrg(int $orgId): array
    {
        $bucket = ObjectStorageBucket::query()
            ->where('org_id', $orgId)
            ->where('is_default', 1)
            ->first();

        if ($bucket) {
            return [
                'bucket' => $bucket,
                'filesystem' => $this->createFilesystem($bucket),
                'base_url' => $bucket->base_url ?: '',
            ];
        }

        // Fallback: use the env-configured COS adapter (existing behavior)
        return [
            'bucket' => null,
            'filesystem' => $this->getDefaultFilesystem(),
            'base_url' => (string) config('file.storage.cos.base_url', ''),
        ];
    }

    /**
     * Build a Flysystem Filesystem for the given bucket using its encrypted credentials.
     */
    public function createFilesystem(ObjectStorageBucket $bucket): Filesystem
    {
        $id = $bucket->id;
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        $config = $this->storageConfig($bucket);

        $adapter = match ($bucket->provider) {
            ObjectStorageBucket::PROVIDER_COS => $this->createCosAdapter($config, $bucket->bucket),
            ObjectStorageBucket::PROVIDER_OSS => $this->createOssAdapter($config, $bucket->bucket),
            ObjectStorageBucket::PROVIDER_S3 => $this->createS3Adapter($config, $bucket->bucket),
            default => throw new AppException(422, '不支持的存储提供商: ' . $bucket->provider),
        };

        return $this->instances[$id] = new Filesystem($adapter);
    }

    private function storageConfig(ObjectStorageBucket $bucket): array
    {
        $config = json_decode($this->encryptService->decryptFast($bucket->config), true);
        if (! is_array($config)) {
            throw new AppException(422, '存储桶凭证配置解析失败');
        }
        if ((int) $bucket->cloud_account_id <= 0) {
            return $config;
        }

        $credentials = $this->cloudAccounts->storageCredentials(
            (int) $bucket->org_id, (int) $bucket->cloud_account_id, (string) $bucket->provider
        );
        return match ((string) $bucket->provider) {
            ObjectStorageBucket::PROVIDER_COS => array_merge($config, [
                'app_id' => $credentials['app_id'],
                'secret_id' => $credentials['access_key_id'],
                'secret_key' => $credentials['access_key_secret'],
                'region' => (string) $bucket->region,
            ]),
            ObjectStorageBucket::PROVIDER_OSS => array_merge($config, [
                'access_key_id' => $credentials['access_key_id'],
                'access_key_secret' => $credentials['access_key_secret'],
                'endpoint' => (string) $bucket->endpoint,
            ]),
            ObjectStorageBucket::PROVIDER_S3 => array_merge($config, [
                'key' => $credentials['access_key_id'],
                'secret' => $credentials['access_key_secret'],
                'region' => (string) $bucket->region,
                'endpoint' => (string) $bucket->endpoint,
            ]),
            default => $config,
        };
    }

    private function createCosAdapter(array $config, string $bucket): CosAdapter
    {
        return new CosAdapter(array_merge($config, [
            'bucket' => $bucket,
            'app_id' => $config['app_id'] ?? $config['project_id'] ?? '',
            'region' => $config['region'] ?? 'ap-guangzhou',
            'secret_id' => $config['secret_id'] ?? '',
            'secret_key' => $config['secret_key'] ?? '',
            'signed_url' => false,
        ]));
    }

    private function createOssAdapter(array $config, string $bucket): OssAdapter
    {
        return new OssAdapter(
            $config['access_key_id'] ?? '',
            $config['access_key_secret'] ?? '',
            $config['endpoint'] ?? '',
            $bucket,
            false,   // isCName
            '',      // prefix
            [],      // buckets
            []       // params
        );
    }

    private function createS3Adapter(array $config, string $bucket): AwsS3V3Adapter
    {
        $client = new S3Client([
            'credentials' => [
                'key' => $config['key'] ?? '',
                'secret' => $config['secret'] ?? '',
            ],
            'region' => $config['region'] ?? 'us-east-1',
            'version' => 'latest',
            'endpoint' => $config['endpoint'] ?: null,
            'use_path_style_endpoint' => ! empty($config['endpoint']),
        ]);

        return new AwsS3V3Adapter($client, $bucket);
    }

    /** Get the DI-injected default COS filesystem from Hyperf container. */
    private function getDefaultFilesystem(): Filesystem
    {
        return make(Filesystem::class);
    }

    // ─── Bucket CRUD ──────────────────────────────────────────────

    public function listBuckets(int $orgId, ?string $keyword = null, int $page = 1, int $pageSize = 20): array
    {
        $query = ObjectStorageBucket::query()->where('org_id', $orgId);
        if ($keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->where('title', 'like', "%{$keyword}%")
                  ->orWhere('bucket', 'like', "%{$keyword}%");
            });
        }
        return $query->orderByDesc('id')
            ->paginate($pageSize, ['*'], 'page', $page)
            ->toArray();
    }

    public function discoverBuckets(int $orgId, int $cloudAccountId, string $region = '', string $endpoint = ''): array
    {
        $provider = $this->storageProviderForAccount($orgId, $cloudAccountId);
        $credentials = $this->cloudAccounts->storageCredentials($orgId, $cloudAccountId, $provider);
        try {
            $buckets = match ($provider) {
                ObjectStorageBucket::PROVIDER_COS => $this->discoverCosBuckets($credentials),
                ObjectStorageBucket::PROVIDER_OSS => $this->discoverOssBuckets($credentials, $endpoint),
                ObjectStorageBucket::PROVIDER_S3 => $this->discoverS3Buckets($credentials, $region, $endpoint),
            };
        } catch (\Throwable $e) {
            throw new AppException(502, '读取云账户存储桶失败：' . mb_substr($e->getMessage(), 0, 500), [], $e);
        }
        usort($buckets, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $buckets;
    }

    private function discoverCosBuckets(array $credentials): array
    {
        $appId = (string) $credentials['app_id'];
        $request = new Request('GET', 'https://service.cos.myqcloud.com/?max-keys=2000');
        $signature = new QcloudCosSignature(
            $credentials['access_key_id'],
            $credentials['access_key_secret'],
            ['timezone' => 'PRC', 'signHost' => true]
        );
        $response = (new HttpClient(['connect_timeout' => 5, 'timeout' => 15]))->send(
            $signature->signRequest($request)
        );
        $xml = simplexml_load_string((string) $response->getBody(), \SimpleXMLElement::class, LIBXML_NONET);
        if ($xml === false) {
            throw new AppException(502, '腾讯云返回了无效的存储桶列表');
        }
        $items = [];
        foreach ($xml->Buckets->Bucket ?? [] as $bucket) {
            $name = trim((string) ($bucket->Name ?? ''));
            if ($name === '') {
                continue;
            }
            $suffix = $appId === '' ? '' : '-' . $appId;
            if ($suffix !== '' && str_ends_with($name, $suffix)) {
                $name = substr($name, 0, -strlen($suffix));
            }
            $items[] = [
                'name' => $name,
                'region' => trim((string) ($bucket->Location ?? $bucket->Region ?? '')),
                'endpoint' => '',
            ];
        }
        return $items;
    }

    private function discoverOssBuckets(array $credentials, string $endpoint): array
    {
        $client = new OssClient(
            $credentials['access_key_id'],
            $credentials['access_key_secret'],
            $endpoint ?: 'oss-cn-hangzhou.aliyuncs.com'
        );
        $result = [];
        foreach ($client->listBuckets()->getBucketList() as $bucket) {
            $result[] = [
                'name' => (string) $bucket->getName(),
                'region' => (string) ($bucket->getRegion() ?: $bucket->getLocation()),
                'endpoint' => (string) $bucket->getExtranetEndpoint(),
            ];
        }
        return $result;
    }

    private function discoverS3Buckets(array $credentials, string $region, string $endpoint): array
    {
        $config = [
            'credentials' => ['key' => $credentials['access_key_id'], 'secret' => $credentials['access_key_secret']],
            'region' => $region ?: 'us-east-1',
            'version' => 'latest',
            'http' => ['connect_timeout' => 5, 'timeout' => 15],
        ];
        if ($endpoint !== '') {
            $config['endpoint'] = $endpoint;
            $config['use_path_style_endpoint'] = true;
        }
        $result = (new S3Client($config))->listBuckets();
        return array_map(static fn (array $bucket): array => [
            'name' => (string) ($bucket['Name'] ?? ''), 'region' => '', 'endpoint' => '',
        ], array_values(array_filter(
            (array) ($result['Buckets'] ?? []),
            static fn (array $bucket): bool => ! empty($bucket['Name'])
        )));
    }

    public function createBucket(int $creatorId, int $orgId, array $data): ObjectStorageBucket
    {
        $cloudAccountId = (int) ($data['cloud_account_id'] ?? 0);
        $provider = $this->storageProviderForAccount($orgId, $cloudAccountId);
        $config = $data['config'] ?? [];
        $encrypted = $this->encryptService->encryptFast(json_encode($config));

        $bucket = new ObjectStorageBucket();
        $bucket->org_id = $orgId;
        $bucket->title = $data['title'];
        $bucket->provider = $provider;
        $bucket->cloud_account_id = $cloudAccountId;
        $bucket->config = $encrypted;
        $bucket->bucket = $data['bucket'] ?? '';
        $bucket->region = $data['region'] ?? '';
        $bucket->endpoint = $data['endpoint'] ?? '';
        $bucket->base_url = $data['base_url'] ?? '';
        $bucket->creator = $creatorId;
        $bucket->created_at = time();
        $bucket->updated_at = time();

        $this->assertUniqueBucket($bucket);
        if (! $this->validateConnection($bucket)) {
            throw new AppException(422, '连接验证失败，请检查凭证和配置是否正确');
        }

        $bucket->save();
        return $bucket;
    }

    public function updateBucket(int $orgId, int $id, array $data): ObjectStorageBucket
    {
        $bucket = $this->findBucketOrFail($orgId, $id);

        if (isset($data['title'])) {
            $bucket->title = $data['title'];
        }
        if (isset($data['config'])) {
            $bucket->config = $this->encryptService->encryptFast(json_encode($data['config']));
        }
        if (isset($data['cloud_account_id'])) {
            $bucket->cloud_account_id = (int) $data['cloud_account_id'];
            $bucket->provider = $this->storageProviderForAccount($orgId, $bucket->cloud_account_id);
        }
        if (isset($data['bucket'])) {
            $bucket->bucket = $data['bucket'];
        }
        if (isset($data['region'])) {
            $bucket->region = $data['region'];
        }
        if (isset($data['endpoint'])) {
            $bucket->endpoint = $data['endpoint'];
        }
        if (isset($data['base_url'])) {
            $bucket->base_url = $data['base_url'];
        }

        unset($this->instances[$id]);

        $this->assertUniqueBucket($bucket);
        if (! $this->validateConnection($bucket)) {
            unset($this->instances[$id]);
            throw new AppException(422, '连接验证失败，请检查凭证和配置是否正确');
        }

        $bucket->updated_at = time();
        $bucket->save();
        unset($this->instances[$id]);

        return $bucket;
    }

    public function getBucket(int $orgId, int $id): ObjectStorageBucket
    {
        $bucket = $this->findBucketOrFail($orgId, $id);
        // Only return non-secret connection options. Credentials stay in the
        // linked cloud account (or encrypted legacy config) and never round-trip to the browser.
        $config = (array) json_decode($this->encryptService->decryptFast($bucket->config), true);
        foreach (['secret_id', 'secret_key', 'access_key_id', 'access_key_secret', 'key', 'secret'] as $secretKey) {
            unset($config[$secretKey]);
        }
        $bucket->config = $config;
        $bucket->makeVisible('config');
        return $bucket;
    }

    public function deleteBucket(int $orgId, int $id): void
    {
        $bucket = $this->findBucketOrFail($orgId, $id);

        if (AcmeBackupPolicy::where('org_id', $orgId)->where('bucket_id', $id)->exists()) {
            throw new AppException(422, '该存储桶正在用于 ACME 自动备份，请先修改备份设置');
        }
        $fileCount = ObjectStorageFile::query()
            ->where('org_id', $orgId)
            ->where('bucket_id', $id)
            ->count();
        if ($fileCount > 0) {
            throw new AppException(422, '该存储桶下还有 ' . $fileCount . ' 个文件记录，请先删除文件');
        }

        $bucket->delete();
        unset($this->instances[$id]);
    }

    public function writeSystemObject(int $orgId, int $bucketId, string $path, string $contents): array
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '' || strlen($path) > 1024 || str_contains($path, "\0")
            || in_array('..', explode('/', $path), true)) {
            throw new AppException(422, '系统对象路径无效');
        }
        if ($contents === '' || strlen($contents) > 32 * 1024 * 1024) {
            throw new AppException(422, '系统对象内容为空或超过 32 MiB');
        }
        $bucket = $this->findBucketOrFail($orgId, $bucketId);
        $filesystem = $this->createFilesystem($bucket);
        if ($filesystem->fileExists($path)) {
            throw new AppException(409, '备份对象已经存在，拒绝覆盖：' . $path);
        }
        $filesystem->write($path, $contents);
        return [
            'bucket_id' => (int) $bucket->id,
            'bucket' => (string) $bucket->bucket,
            'path' => $path,
            'size' => strlen($contents),
        ];
    }

    public function setDefault(int $orgId, int $id): void
    {
        $bucket = $this->findBucketOrFail($orgId, $id);

        ObjectStorageBucket::query()
            ->where('org_id', $orgId)
            ->where('is_default', 1)
            ->update(['is_default' => 0, 'updated_at' => time()]);

        $bucket->is_default = true;
        $bucket->updated_at = time();
        $bucket->save();
    }

    private function validateConnection(ObjectStorageBucket $bucket): bool
    {
        try {
            $fs = $this->createFilesystem($bucket);
        } catch (\Throwable) {
            return false;
        }

        $testPath = '.galaxy_test_' . time() . '.tmp';
        $testContent = 'galaxy-test-' . time();

        try {
            $fs->write($testPath, $testContent);
            $read = $fs->read($testPath);
            return $read === $testContent;
        } catch (\Throwable) {
            return false;
        } finally {
            try {
                $fs->delete($testPath);
            } catch (\Throwable) {
                // ignore cleanup failures
            }
        }
    }

    private function storageProviderForAccount(int $orgId, int $cloudAccountId): string
    {
        $account = $this->cloudAccounts->account($orgId, $cloudAccountId);
        return match ((string) $account->provider) {
            'tencent_cloud' => ObjectStorageBucket::PROVIDER_COS,
            'aliyun' => ObjectStorageBucket::PROVIDER_OSS,
            'aws_s3' => ObjectStorageBucket::PROVIDER_S3,
            default => throw new AppException(422, '该云账户不支持对象存储'),
        };
    }

    private function assertUniqueBucket(ObjectStorageBucket $bucket): void
    {
        $query = ObjectStorageBucket::where('org_id', (int) $bucket->org_id)
            ->where('provider', (string) $bucket->provider)
            ->where('bucket', (string) $bucket->bucket);
        if ((int) $bucket->id > 0) {
            $query->where('id', '<>', (int) $bucket->id);
        }
        if ($query->exists()) {
            throw new AppException(409, '该云厂商的存储桶已经添加，请勿重复添加');
        }
    }

    // ─── File Operations ──────────────────────────────────────────

    public function upload(int $orgId, int $bucketId, UploadedFile $file, string $uploadDir = ''): ObjectStorageFile
    {
        $bucket = $this->findBucketOrFail($orgId, $bucketId);
        $fs = $this->createFilesystem($bucket);

        $ext = $file->getExtension();
        $filename = sha1(uniqid('', true)) . ($ext ? '.' . $ext : '');
        $filepath = ($uploadDir ? rtrim($uploadDir, '/') . '/' : '') . $filename;

        $stream = fopen($file->getRealPath(), 'r+');
        $fs->writeStream($filepath, $stream);
        fclose($stream);

        $size = $file->getSize();
        $mimeType = $file->getClientMediaType() ?: '';

        $url = $bucket->base_url ? rtrim($bucket->base_url, '/') . '/' . ltrim($filepath, '/') : $filepath;
        $uid = Functions::getLoginUser() ? Functions::getLoginUser()->getId() : 0;

        $record = new ObjectStorageFile();
        $record->org_id = $orgId;
        $record->bucket_id = $bucketId;
        $record->filepath = $filepath;
        $record->filename = $file->getClientFilename() ?: $filename;
        $record->size = $size;
        $record->mime_type = $mimeType;
        $record->url = $url;
        $record->creator = $uid;
        $record->created_at = time();
        $record->save();

        return $record;
    }

    public function createDirectory(int $orgId, int $bucketId, string $parent, string $name): array
    {
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '/') || str_contains($name, '\\')) {
            throw new AppException(422, '目录名称不能为空，且不能包含斜杠');
        }
        $parent = trim(str_replace('\\', '/', $parent), '/');
        if ($parent !== '' && in_array('..', explode('/', $parent), true)) {
            throw new AppException(422, '父目录路径无效');
        }
        $path = ($parent === '' ? '' : $parent . '/') . $name;
        $bucket = $this->findBucketOrFail($orgId, $bucketId);
        $fs = $this->createFilesystem($bucket);
        if ($fs->directoryExists($path) || $fs->fileExists($path)) {
            throw new AppException(409, '同名文件或目录已经存在');
        }
        $fs->createDirectory($path);
        return ['type' => 'dir', 'path' => $path, 'filename' => $name];
    }

    public function listFiles(
        int $orgId,
        int $bucketId,
        string $prefix = '',
        int $page = 1,
        int $pageSize = 50,
        string $cursor = ''
    ): array
    {
        $bucket = $this->findBucketOrFail($orgId, $bucketId);
        $location = trim(str_replace('\\', '/', $prefix), '/');
        $location = $location === '' ? '' : $location . '/';
        $token = $this->decodeListCursor(
            $cursor, $bucketId, $location, (string) $bucket->provider, $pageSize, $page
        );

        try {
            $result = match ((string) $bucket->provider) {
                ObjectStorageBucket::PROVIDER_COS => $this->listCosPage($bucket, $location, $pageSize, $token),
                ObjectStorageBucket::PROVIDER_OSS => $this->listOssPage($bucket, $location, $pageSize, $token),
                ObjectStorageBucket::PROVIDER_S3 => $this->listS3Page($bucket, $location, $pageSize, $token),
                default => throw new AppException(422, '不支持的存储提供商: ' . $bucket->provider),
            };
        } catch (AppException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AppException(502, '读取存储文件列表失败：' . mb_substr($e->getMessage(), 0, 500), [], $e);
        }

        $baseUrl = (string) ($bucket->base_url ?: '');
        $items = array_map(static function (array $item) use ($baseUrl): array {
            $path = $item['path'];
            $item['url'] = $baseUrl ? rtrim($baseUrl, '/') . '/' . ltrim($path, '/') : $path;
            return $item;
        }, $result['items']);
        $hasMore = $result['next_token'] !== '';
        $knownBefore = max(0, $page - 1) * $pageSize;
        $total = $hasMore ? $page * $pageSize + 1 : $knownBefore + count($items);

        return [
            'data' => $items,
            // Object storage APIs do not return a total. This is the exact
            // discovered count plus one sentinel item while another page exists.
            'total' => $total,
            'current_page' => $page,
            'per_page' => $pageSize,
            'last_page' => $hasMore ? $page + 1 : max(1, $page),
            'has_more' => $hasMore,
            'next_cursor' => $hasMore
                ? $this->encodeListCursor(
                    $bucketId, $location, (string) $bucket->provider, $pageSize, $page + 1, $result['next_token']
                )
                : '',
        ];
    }

    /** @return array{items: array<int, array>, next_token: string} */
    private function listCosPage(ObjectStorageBucket $bucket, string $prefix, int $pageSize, string $marker): array
    {
        $config = $this->storageConfig($bucket);
        $query = ['prefix' => $prefix, 'delimiter' => '/', 'max-keys' => $pageSize];
        if ($marker !== '') {
            $query['marker'] = $marker;
        }
        $result = (array) $this->createCosAdapter($config, (string) $bucket->bucket)
            ->getBucketClient()->getObjects($query)->toArray();
        $items = [];
        $markerCandidates = [];
        foreach ($this->normalizeCloudList($result['CommonPrefixes'] ?? []) as $directory) {
            $path = (string) ($directory['Prefix'] ?? '');
            if ($path !== '' && $path !== $prefix) {
                $items[] = $this->directoryListItem($path);
                $markerCandidates[] = $path;
            }
        }
        foreach ($this->normalizeCloudList($result['Contents'] ?? []) as $object) {
            $path = (string) ($object['Key'] ?? '');
            if ($path === '' || $path === $prefix || str_ends_with($path, '/')) {
                continue;
            }
            $items[] = $this->fileListItem(
                $path,
                (int) ($object['Size'] ?? 0),
                strtotime((string) ($object['LastModified'] ?? '')) ?: 0
            );
            $markerCandidates[] = $path;
        }
        $truncated = filter_var($result['IsTruncated'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $nextMarker = $truncated ? (string) ($result['NextMarker'] ?? '') : '';
        if ($truncated && $nextMarker === '' && $markerCandidates !== []) {
            sort($markerCandidates, SORT_STRING);
            $nextMarker = (string) end($markerCandidates);
        }
        return ['items' => $items, 'next_token' => $nextMarker];
    }

    /** @return array{items: array<int, array>, next_token: string} */
    private function listOssPage(ObjectStorageBucket $bucket, string $prefix, int $pageSize, string $marker): array
    {
        $config = $this->storageConfig($bucket);
        $client = new OssClient(
            $config['access_key_id'] ?? '',
            $config['access_key_secret'] ?? '',
            $config['endpoint'] ?? ''
        );
        $result = $client->listObjects((string) $bucket->bucket, [
            OssClient::OSS_PREFIX => $prefix,
            OssClient::OSS_DELIMITER => '/',
            OssClient::OSS_MAX_KEYS => $pageSize,
            OssClient::OSS_MARKER => $marker,
        ]);
        $items = [];
        foreach ($result->getPrefixList() as $directory) {
            $path = (string) $directory->getPrefix();
            if ($path !== '' && $path !== $prefix) {
                $items[] = $this->directoryListItem($path);
            }
        }
        foreach ($result->getObjectList() as $object) {
            $path = (string) $object->getKey();
            if ($path === '' || $path === $prefix || str_ends_with($path, '/')) {
                continue;
            }
            $items[] = $this->fileListItem(
                $path,
                (int) $object->getSize(),
                strtotime((string) $object->getLastModified()) ?: 0
            );
        }
        $truncated = filter_var($result->getIsTruncated(), FILTER_VALIDATE_BOOLEAN);
        return ['items' => $items, 'next_token' => $truncated ? (string) $result->getNextMarker() : ''];
    }

    /** @return array{items: array<int, array>, next_token: string} */
    private function listS3Page(ObjectStorageBucket $bucket, string $prefix, int $pageSize, string $token): array
    {
        $config = $this->storageConfig($bucket);
        $clientConfig = [
            'credentials' => ['key' => $config['key'] ?? '', 'secret' => $config['secret'] ?? ''],
            'region' => $config['region'] ?? 'us-east-1',
            'version' => 'latest',
        ];
        if (! empty($config['endpoint'])) {
            $clientConfig['endpoint'] = $config['endpoint'];
            $clientConfig['use_path_style_endpoint'] = true;
        }
        $options = [
            'Bucket' => (string) $bucket->bucket,
            'Prefix' => $prefix,
            'Delimiter' => '/',
            'MaxKeys' => $pageSize,
        ];
        if ($token !== '') {
            $options['ContinuationToken'] = $token;
        }
        $result = (new S3Client($clientConfig))->listObjectsV2($options);
        $items = [];
        foreach ((array) ($result['CommonPrefixes'] ?? []) as $directory) {
            $path = (string) ($directory['Prefix'] ?? '');
            if ($path !== '' && $path !== $prefix) {
                $items[] = $this->directoryListItem($path);
            }
        }
        foreach ((array) ($result['Contents'] ?? []) as $object) {
            $path = (string) ($object['Key'] ?? '');
            if ($path === '' || $path === $prefix || str_ends_with($path, '/')) {
                continue;
            }
            $modified = $object['LastModified'] ?? null;
            $items[] = $this->fileListItem(
                $path,
                (int) ($object['Size'] ?? 0),
                $modified instanceof \DateTimeInterface ? $modified->getTimestamp() : (strtotime((string) $modified) ?: 0)
            );
        }
        return [
            'items' => $items,
            'next_token' => ! empty($result['IsTruncated']) ? (string) ($result['NextContinuationToken'] ?? '') : '',
        ];
    }

    private function directoryListItem(string $path): array
    {
        $path = rtrim($path, '/');
        return [
            'type' => 'dir', 'path' => $path, 'filename' => basename($path),
            'size' => null, 'mime_type' => 'folder', 'created_at' => 0,
        ];
    }

    private function fileListItem(string $path, int $size, int $modified): array
    {
        return [
            'type' => 'file', 'path' => $path, 'filename' => basename($path),
            'size' => $size, 'mime_type' => '', 'created_at' => $modified,
        ];
    }

    private function normalizeCloudList(mixed $value): array
    {
        if (! is_array($value) || $value === []) {
            return [];
        }
        return array_is_list($value) ? $value : [$value];
    }

    private function encodeListCursor(
        int $bucketId,
        string $prefix,
        string $provider,
        int $pageSize,
        int $page,
        string $token
    ): string
    {
        $json = json_encode(compact('bucketId', 'prefix', 'provider', 'pageSize', 'page', 'token'), JSON_THROW_ON_ERROR);
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    private function decodeListCursor(
        string $cursor,
        int $bucketId,
        string $prefix,
        string $provider,
        int $pageSize,
        int $page
    ): string
    {
        if ($cursor === '') {
            if ($page !== 1) {
                throw new AppException(422, '非第一页必须提供分页游标');
            }
            return '';
        }
        $encoded = strtr($cursor, '-_', '+/');
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $json = base64_decode($encoded, true);
        $data = $json === false ? null : json_decode($json, true);
        if (! is_array($data)
            || (int) ($data['bucketId'] ?? 0) !== $bucketId
            || (string) ($data['prefix'] ?? '') !== $prefix
            || (string) ($data['provider'] ?? '') !== $provider
            || (int) ($data['pageSize'] ?? 0) !== $pageSize
            || (int) ($data['page'] ?? 0) !== $page
            || ! is_string($data['token'] ?? null)
        ) {
            throw new AppException(422, '分页游标无效或已失效');
        }
        return $data['token'];
    }

    /**
     * Read an object through Galaxy so private buckets do not need to expose a
     * public base URL to the browser.
     *
     * @return array{content: string, filename: string, mime_type: string}
     */
    public function download(int $orgId, int $bucketId, string $path): array
    {
        $path = ltrim(trim(str_replace('\\', '/', $path)), '/');
        if ($path === '' || str_ends_with($path, '/') || str_contains($path, "\0")) {
            throw new AppException(422, '文件路径无效');
        }

        $bucket = $this->findBucketOrFail($orgId, $bucketId);
        $fs = $this->createFilesystem($bucket);
        if (! $fs->fileExists($path)) {
            throw new AppException(404, '文件不存在');
        }

        try {
            $content = $fs->read($path);
        } catch (\Throwable $e) {
            throw new AppException(502, '读取存储文件失败：' . mb_substr($e->getMessage(), 0, 500), [], $e);
        }

        $record = ObjectStorageFile::query()
            ->where('org_id', $orgId)
            ->where('bucket_id', $bucketId)
            ->where('filepath', $path)
            ->first();
        $filename = trim((string) ($record?->filename ?: basename($path)));
        $mimeType = trim((string) ($record?->mime_type ?: ''));
        if ($mimeType === '') {
            try {
                $mimeType = $fs->mimeType($path);
            } catch (\Throwable) {
                $mimeType = 'application/octet-stream';
            }
        }

        return [
            'content' => $content,
            'filename' => $filename !== '' ? $filename : 'download',
            'mime_type' => $mimeType ?: 'application/octet-stream',
        ];
    }

    public function deleteEntry(int $orgId, int $bucketId, string $path, string $type = 'file'): void
    {
        $bucket = $this->findBucketOrFail($orgId, $bucketId);
        $fs = $this->createFilesystem($bucket);
        if ($type === 'dir') {
            foreach ($fs->listContents(rtrim($path, '/') . '/', false) as $_entry) {
                throw new AppException(409, '目录不为空，请先删除目录中的内容');
            }
            $fs->deleteDirectory($path);
            return;
        }
        try {
            $fs->delete($path);
        } catch (UnableToDeleteFile) {
            // already removed on the remote storage
        }
        ObjectStorageFile::where('org_id', $orgId)
            ->where('bucket_id', $bucketId)
            ->where('filepath', $path)
            ->delete();
    }

    // ─── Internal ─────────────────────────────────────────────────

    private function findBucketOrFail(int $orgId, int $id): ObjectStorageBucket
    {
        $bucket = ObjectStorageBucket::query()
            ->where('org_id', $orgId)
            ->where('id', $id)
            ->first();
        if (! $bucket) {
            throw new AppException(422, '存储桶不存在');
        }
        return $bucket;
    }
}
