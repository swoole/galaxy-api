<?php

namespace App\Model;

use App\Constants\ErrorCode;
use App\Exception\AppException;
use App\Support\Functions;
use App\Support\MySQL;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Throwable;

/**
 * @property int $id 
 * @property int $org_id 
 * @property int $proto 
 * @property string $address 
 * @property string $namespace 
 * @property string $username 
 * @property string $password 
 * @property string $remark 
 * @property int $is_push 
 * @property int $creator 
 * @property int $created_at 
 */
class Registry extends Model
{
    use TraitRelationCreatorInfo;
    use TraitEncrypt;

    public const PROTO_HTTP = 0;
    public const PROTO_HTTPS = 1;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected ?string $table = 'registry';
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    // protected $fillable = [];
    protected array $guarded = [];
    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected array $casts = [
        'id' => 'integer', 'org_id' => 'integer', 'creator' => 'integer',
        'created_at' => 'integer', 'proto' => 'integer', 'is_push' => 'integer',
    ];

    /**
     * 根据镜像名称匹配帐号.
     */
    public static function matchByName($orgId, $name, $throw = true) : ?Registry
    {
        $parts = explode('/', $name, 3);
        switch (count($parts)) {
            case 1:
                $address = '';
                $namespace = 'library';
                break;
            case 2:
                $address = '';
                $namespace = $parts[0];
                break;
            case 3:
                $address = $parts[0];
                $namespace = $parts[1];
                break;
        }

        // docker hub根目录的镜像直接返回null，表示无须授权，不受throw影响
        if ($address == '' && $namespace == 'library') {
            return null;
        }

        $registry = Registry::where('org_id', $orgId)
            ->where('address', $address)
            ->select('id', 'org_id', 'address', 'namespace', 'username', 'password', 'proto')
            ->whereIn('namespace', ['', $namespace])
            ->orderBy('namespace', 'DESC')
            ->first();

        if ($throw && empty($registry)) {
            throw new AppException(ErrorCode::REGISTRY_MISMATCH_BY_NAME, sprintf('您没有 %s 的权限', $name));
        }

        return $registry;
    }

    /**
     * 获取推送镜像的帐号.
     */
    public static function getPush($orgId) : Registry
    {
        $registry = Registry::where('org_id', $orgId)
            ->where('is_push', 1)
            ->select('id', 'org_id', 'address', 'namespace', 'username', 'password', 'proto')
            ->first();
        if (empty($registry)) {
            throw new AppException(ErrorCode::REGISTRY_NOT_FOUND_PUSH, '获取镜像推送Registry帐号失败');
        }

        return $registry;
    }

    /**
     * 列表.
     */
    public function list($orgId, $keyword = null, $page = 1, $pageSize = 20)
    {
        $builder = $this->where('org_id', $orgId)
            ->select('id', 'proto', 'address', 'namespace', 'username', 'remark', 'creator', 'created_at', 'is_push')
            ->withCount('groupGrants')
            ->with([
                'creatorInfo' => function ($query) use ($orgId) {
                    $query->where('org_id', $orgId);
                },
            ]);
        
        if (!empty($keyword)) {
            $builder->where(function ($query) use ($keyword) {
                $like = "%{$keyword}%";
                $query->where('address', 'like', $like)
                    ->orWhere('namespace', 'like', $like)
                    ->orWhere('username', 'like', $like)
                    ->orWhere('remark', 'like', $like);

                $id = Functions::decodeID($keyword, true, false);
                if (!is_null($id)) {
                    $query->orWhere('id', $id);
                }
            });
        }

        return MySQL::jsonPaginate($builder, $page, $pageSize);
    }

    /**
     * 创建.
     */
    public function createRegistry(
        $uid,
        $orgId,
        $address,
        $username,
        $password,
        $remark = null,
        $namespace = null,
        $proto = Registry::PROTO_HTTPS
    ) {
        // 查询数据库是否有相同记录
        $exists = $this->where('org_id', $orgId)
            ->where('address', $address)
            ->where('namespace', $namespace ?: '')
            ->exists();
        if ($exists) {
            throw new AppException(
                ErrorCode::REGISTRY_DUPLICATION,
                sprintf('已存在 %s%s 配置', $address ?: 'DockerHub', $namespace ? '/' . $namespace : '')
            );
        }

        // 验证帐号密码可用性
        $this->validAvailability($address, $namespace, $username, $password, $proto);

        // 加密密码
        $passwordEncrypted = $this->encryptField('password', $password);

        // 入库
        $registry = self::create([
            'org_id' => $orgId,
            'proto' => $proto,
            'address' => $address,
            'namespace' => $namespace ?: '',
            'username' => $username,
            'password' => $passwordEncrypted,
            'remark' => $remark ?: '',
            'creator' => $uid,
            'created_at' => time(),
        ]);

        return $registry;
    }

    /**
     * 更新.
     */
    public function updateRegistry(
        $uid,
        $orgId,
        $registryId,
        $address,
        $username,
        $password,
        $remark = null,
        $namespace = null,
        $proto = Registry::PROTO_HTTPS
    ) {
        $id = Functions::decodeID($registryId, true, true);
        $registry = $this->where('id', $id)
            ->where('org_id', $orgId)
            ->first();
        if (empty($registry)) {
            throw new AppException(ErrorCode::NOT_FOUND, '帐号不存在');
        }

        // 查询数据库是否有相同记录
        $exists = $this->where('org_id', $orgId)
            ->where('address', $address)
            ->where('namespace', $namespace ?: '')
            ->where('id', '<>', $id)
            ->exists();
        if ($exists) {
            throw new AppException(
                ErrorCode::REGISTRY_DUPLICATION,
                sprintf('已存在 %s%s 配置', $address ?: 'docker.io', $namespace ? '/' . $namespace : '')
            );
        }

        $newNamespace = trim((string) $namespace, '/');
        $passwordChanged = $password !== null && $password !== '';
        $connectionChanged = $address != $registry['address']
            || $username != $registry['username']
            || $newNamespace != (string) $registry['namespace']
            || (int) $proto !== (int) $registry['proto'];

        // 未输入新密码时复用数据库中已有的凭据完成连接校验，且不重写密文。
        // 只有明确提供新密码才覆盖原密码。
        if ($connectionChanged || $passwordChanged) {
            $effectivePassword = $passwordChanged
                ? (string) $password
                : (string) $registry->decryptField('password');
            $this->validAvailability($address, $newNamespace, $username, $effectivePassword, $proto);
            $registry->username = $username;
            if ($passwordChanged) {
                $registry->password = $this->encryptField('password', (string) $password);
            }
        }

        foreach (RegistryGroupGrant::where('org_id', $orgId)->where('registry_id', $id)->pluck('namespace') as $grantedNamespace) {
            if ($newNamespace !== ''
                && $grantedNamespace !== $newNamespace
                && ! str_starts_with((string) $grantedNamespace, $newNamespace . '/')) {
                throw new AppException(409, sprintf(
                    '项目组授权 namespace「%s」不在新 namespace「%s」之下，请先调整项目组权限',
                    $grantedNamespace,
                    $newNamespace
                ));
            }
        }

        $registry->proto = $proto;
        $registry->address = $address;
        $registry->namespace = $newNamespace;
        $registry->remark = $remark ?: '';
        $registry->save();

        return $registry;
    }

    /**
     * 删除.
     */
    public function deleteRegistry($uid, $orgId, $registryId)
    {
        $id = Functions::decodeID($registryId, true, true);
        $registry = $this->where('id', $id)
            ->where('org_id', $orgId)
            ->first();
        if (empty($registry)) {
            throw new AppException(ErrorCode::NOT_FOUND, '帐号不存在');
        }

        $projects = ProjectRegistryRel::where('org_id', $orgId)->where('registry_id', $id)->count();
        $pipelines = Pipeline::where('org_id', $orgId)
            ->where('archived_at', 0)
            ->whereRaw("CAST(CASE WHEN JSON_VALID(`definition`) THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`definition`, '$.output.registry_id')), '0') ELSE '0' END AS UNSIGNED) = ?", [$id])
            ->count();
        if ((int) $registry->is_push === 1) {
            $pipelines += Pipeline::where('org_id', $orgId)
                ->where('archived_at', 0)
                ->whereRaw("CAST(CASE WHEN JSON_VALID(`definition`) THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`definition`, '$.output.registry_id')), '0') ELSE '0' END AS UNSIGNED) = 0")
                ->count();
        }
        $artifacts = BuildArtifact::where('org_id', $orgId)
            ->whereRaw("CAST(CASE WHEN JSON_VALID(`metadata`) THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`metadata`, '$.registry_id')), '0') ELSE '0' END AS UNSIGNED) = ?", [$id])
            ->count();
        if ($projects > 0 || $pipelines > 0 || $artifacts > 0) {
            throw new AppException(409, sprintf(
                'Registry 仍被 %d 个项目、%d 条流水线和 %d 个镜像制品引用，请先解除引用',
                $projects,
                $pipelines,
                $artifacts
            ));
        }

        Db::transaction(function () use ($orgId, $id, $registry): void {
            RegistryGroupGrant::where('org_id', $orgId)->where('registry_id', $id)->delete();
            $registry->delete();
        });
    }

    /**
     * 解析仓库记录（复用查询逻辑）.
     */
    public function resolveRegistry($uid, $orgId, $registryId): Registry
    {
        $id = Functions::decodeID($registryId, true, true);
        $registry = $this->where('id', $id)
            ->where('org_id', $orgId)
            ->select('id', 'org_id', 'proto', 'address', 'namespace', 'username', 'password')
            ->first();
        if (empty($registry)) {
            throw new AppException(ErrorCode::NOT_FOUND, '帐号不存在');
        }
        return $registry;
    }

    /**
     * 查看密码.
     */
    public function showPassword($uid, $orgId, $registryId)
    {
        $id = Functions::decodeID($registryId, true, true);
        $registry = $this->where('id', $id)
            ->where('org_id', $orgId)
            ->select('id', 'password')
            ->first();
        if (empty($registry)) {
            throw new AppException(ErrorCode::NOT_FOUND, '帐号不存在');
        }

        return $registry->decryptField('password');
    }

    /**
     * 设置推送镜像的帐号.
     */
    public function setPush($uid, $orgId, $registryId)
    {
        $id = Functions::decodeID($registryId, true, true);
        $registry = $this->where('id', $id)
            ->where('org_id', $orgId)
            ->first();
        if (empty($registry)) {
            throw new AppException(ErrorCode::NOT_FOUND, '帐号不存在');
        }

        if (empty($registry['namespace'])) {
            throw new AppException(ErrorCode::INVALID_PARAMS, '未设置命名空间禁止设为镜像推送仓库');
        }

        Db::beginTransaction();
        try {
            $this->where('org_id', $orgId)->update(['is_push' => 0]);

            $registry->is_push = 1;
            $registry->save();

            Db::commit();
        } catch (Throwable $e) {
            Db::rollBack();
            throw $e;
        }
    }

    /**
     * 验证可用性.
     *
     * 按照 docker login 的认证流程：
     * 1. GET /v2/ 不带认证 → 200 表示无需认证
     * 2. 401 时解析 WWW-Authenticate，按 Bearer 或 Basic 流程认证
     */
    protected function validAvailability($address, $namespace, $username, $password, $proto = Registry::PROTO_HTTPS)
    {
        // Docker Hub 跳过验证（使用 token-based auth，Basic auth 不适用）
        if (empty($address)) {
            return;
        }

        $scheme = $proto == Registry::PROTO_HTTPS ? 'https' : 'http';
        $url = "{$scheme}://{$address}/v2/";

        try {
            $client = new Client([
                'http_errors' => false,
                'connect_timeout' => 10,
                'timeout' => 30,
            ]);

            // Step 1: 不带认证请求，模拟 docker login 的第一步
            $resp = $client->get($url);

            if ($resp->getStatusCode() === 200) {
                return;
            }

            if ($resp->getStatusCode() !== 401) {
                logger('registry')->warning(sprintf(
                    'Registry auth check: %s -> %d, body=%s',
                    $url, $resp->getStatusCode(), (string) $resp->getBody()
                ));
                throw new AppException(
                    ErrorCode::REGISTRY_INVALID_ADDRESS,
                    sprintf('镜像地址 %s 返回 %d: %s', $url, $resp->getStatusCode(), $resp->getReasonPhrase())
                );
            }

            // Step 2: 解析 WWW-Authenticate 并执行对应认证流程
            $authHeader = $resp->getHeaderLine('WWW-Authenticate');

            if (preg_match('/Bearer\s+realm="([^"]+)"/', $authHeader, $m)) {
                // Bearer token 流程（阿里云 ACR 等云 registry）
                $realm = $m[1];
                preg_match('/service="([^"]+)"/', $authHeader, $sm);
                $service = $sm[1] ?? '';
                preg_match('/scope="([^"]+)"/', $authHeader, $scm);
                $scope = $scm[1] ?? '';

                $tokenUrl = $realm . '?' . http_build_query(array_filter([
                    'service' => $service,
                    'scope' => $scope,
                ]));

                logger('registry')->info(sprintf('Bearer auth: realm=%s, tokenUrl=%s', $realm, $tokenUrl));

                // Step 3: 用 Basic Auth 向 realm 获取 token
                $tokenResp = $client->get($tokenUrl, [
                    'auth' => [$username, $password],
                ]);

                if ($tokenResp->getStatusCode() !== 200) {
                    logger('registry')->warning(sprintf(
                        'Bearer token request failed: %s -> %d, body=%s',
                        $tokenUrl, $tokenResp->getStatusCode(), (string) $tokenResp->getBody()
                    ));
                    throw new AppException(
                        ErrorCode::REGISTRY_WRONG_PASSWORD,
                        '帐号密码不匹配'
                    );
                }

                $tokenData = json_decode((string) $tokenResp->getBody(), true);
                $token = $tokenData['token'] ?? $tokenData['access_token'] ?? null;

                if (empty($token)) {
                    logger('registry')->warning('Bearer token not found in response');
                    throw new AppException(
                        ErrorCode::REGISTRY_WRONG_PASSWORD,
                        '帐号密码不匹配'
                    );
                }

                // Step 4: 用 token 重试 /v2/
                $v2Resp = $client->get($url, [
                    'headers' => ['Authorization' => 'Bearer ' . $token],
                ]);

                if ($v2Resp->getStatusCode() !== 200) {
                    throw new AppException(
                        ErrorCode::REGISTRY_WRONG_PASSWORD,
                        '帐号密码不匹配'
                    );
                }

                return;
            }

            // Basic 或其他认证流程：直接带 Basic Auth 重试
            $resp2 = $client->get($url, [
                'auth' => [$username, $password],
            ]);

            if ($resp2->getStatusCode() === 200) {
                return;
            }

            throw new AppException(
                ErrorCode::REGISTRY_WRONG_PASSWORD,
                '帐号密码不匹配'
            );
        } catch (ConnectException $e) {
            throw new AppException(
                ErrorCode::REGISTRY_INVALID_ADDRESS,
                sprintf('镜像地址 %s 不可用', $url)
            );
        } catch (GuzzleException $e) {
            logger('registry')->warning(sprintf('Registry auth check exception: %s -> %s', $url, $e->getMessage()));
            throw new AppException(
                ErrorCode::REGISTRY_INVALID_ADDRESS,
                sprintf('镜像地址 %s 不可用: %s', $url, $e->getMessage())
            );
        }
    }

    public function getIdAttribute($value)
    {
        return Functions::encodeID($value);
    }

    /**
     * 项目可用的镜像仓库列表.
     */
    public function listForProject(int $orgId, int $groupId)
    {
        $grants = RegistryGroupGrant::where('org_id', $orgId)->where('group_id', $groupId)
            ->get(['registry_id', 'namespace'])->keyBy('registry_id');
        return $this->where('org_id', $orgId)->whereIn('id', $grants->keys()->all() ?: [0])
            ->select('id', 'proto', 'address', 'namespace', 'remark')
            ->get()->map(static function (Registry $registry) use ($grants): Registry {
                $grant = $grants->get((int) $registry->getRawOriginal('id'));
                $registry->credential_namespace = (string) $registry->namespace;
                $registry->namespace = (string) $grant->namespace;
                return $registry;
            });
    }

    /**
     * Registries explicitly associated with one project.
     */
    public function listLinkedToProject(int $orgId, int $groupId, int $projectId)
    {
        $ids = ProjectRegistryRel::where('org_id', $orgId)->where('project_id', $projectId)
            ->pluck('registry_id')->all();
        $grants = RegistryGroupGrant::where('org_id', $orgId)->where('group_id', $groupId)
            ->whereIn('registry_id', $ids ?: [0])->get(['registry_id', 'namespace'])->keyBy('registry_id');
        return $this->where('org_id', $orgId)->whereIn('id', $grants->keys()->all() ?: [0])
            ->select('id', 'proto', 'address', 'namespace', 'remark')
            ->orderBy('id')->get()->map(static function (Registry $registry) use ($grants): Registry {
                $grant = $grants->get((int) $registry->getRawOriginal('id'));
                $registry->credential_namespace = (string) $registry->namespace;
                $registry->namespace = (string) $grant->namespace;
                return $registry;
            });
    }

    public function groupGrants()
    {
        return $this->hasMany(RegistryGroupGrant::class, 'registry_id', 'id');
    }
}
