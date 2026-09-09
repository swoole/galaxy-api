<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Model;

use App\Exception\AppException;
use App\Services\Encrypt\CredentialCipher;
use App\Services\Git\GitService;
use App\Support\Functions;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Database\Exception\QueryException;
use Throwable;

/**
 * @property int $id
 * @property int $uid
 * @property int $vendor
 * @property string $domain
 * @property string $token
 * @property int $created_at
 */
class GitAuth extends Model
{
    /**
     * 厂商.
     */
    public const VENDOR_GITHUB = 1;

    public const VENDOR_GITEE = 2;

    public const VENDOR_GITLAB = 3;

    public const VENDOR_GITEA = 4;

    public const VENDOR_CODEUP = 5;

    public static $vendors = [
        self::VENDOR_GITHUB => 'GitHub',
        self::VENDOR_GITEE => '码云',
        self::VENDOR_GITLAB => 'GitLab',
        self::VENDOR_GITEA => 'Gitea',
    ];

    /**
     * @Inject
     * @var GitService
     */
    #[Inject]
    protected $gitService;

    /**
     * @Inject
     */
    #[Inject]
    protected CredentialCipher $credentialCipher;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected ?string $table = 'git_auth';

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
    protected array $casts = ['id' => 'integer', 'uid' => 'integer', 'vendor' => 'integer', 'created_at' => 'integer'];

    // 以下厂商域名强制更改为配置指定域名
    protected static $protectDomains = [
        self::VENDOR_GITHUB => 'github.com',
        self::VENDOR_GITEE => 'gitee.com',
    ];

    /**
     * 授权列表.
     * @param int $uid
     */
    public function list($uid)
    {
        $auths = $this->where('uid', $uid)
            ->select('id', 'vendor', 'domain', 'token', 'created_at')
            ->get()
            ->toArray();

        // 解密
        $tokens = [];
        foreach ($auths as $auth) {
            $tokens[$auth['id']] = $auth['token'];
        }
        $decryptedTokens = array_map(fn (string $token): string => $this->credentialCipher->decrypt($token), $tokens);

        foreach ($auths as &$auth) {
            $auth['token'] = $this->hiddenToken($decryptedTokens[$auth['id']]);
            $auth['protected'] = false;
        }
        unset($auth);

        return $auths;
    }

    /**
     * 创建授权.
     * @param int $uid
     * @param int $vendor
     * @param string $domain
     * @param string $token
     */
    public function createAuth($uid, $vendor, $domain, $token)
    {
        // Github、Gitee等只允许创建一个授权
        if (isset(self::$protectDomains[$vendor])) {
            $domain = self::$protectDomains[$vendor];
        }
        $domain = self::normalizeEndpoint((string) $domain);

        $exists = $this->where('uid', $uid)
            ->where('vendor', $vendor)
            ->where('domain', $domain)
            ->exists();
        if ($exists) {
            throw new AppException(
                1,
                sprintf('厂商%s的域名%s已有授权记录，请勿重复设置', self::$vendors[$vendor], $domain)
            );
        }

        // 校验token是否有效
        try {
            $this->gitService->test($vendor, $domain, $token);
        } catch (Throwable $e) {
            throw new AppException(
                1,
                'token验证失败，请检查是否填写正确'
            );
        }
        $encryptedToken = $this->credentialCipher->encrypt($token);

        try {
            $auth = self::create([
                'uid' => $uid,
                'vendor' => $vendor,
                'domain' => $domain,
                'token' => $encryptedToken ,
                'created_at' => time(),
            ]);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new AppException(409, '该 Git 服务地址已有授权记录，请直接编辑');
            }
            throw $e;
        }

        $auth = $auth->toArray();
        $auth['token'] = $this->hiddenToken($token);

        return $auth;
    }

    /**
     * 更新授权.
     * @param int $uid
     * @param int $vendor
     * @param string $domain
     * @param string $token
     */
    public function updateAuth($uid, $vendor, $domain, $token)
    {
        // Github、Gitee等只允许创建一个授权
        if (isset(self::$protectDomains[$vendor])) {
            $domain = self::$protectDomains[$vendor];
        }
        $domain = self::normalizeEndpoint((string) $domain);

        $auth = $this->where('uid', $uid)
            ->where('vendor', $vendor)
            ->where('domain', $domain)
            ->first();
        if (empty($auth)) {
            throw new AppException(
                1,
                '授权记录不存在'
            );
        }

        // 校验token是否有效
        try {
            $this->gitService->test($vendor, $domain, $token);
        } catch (Throwable $e) {
            throw new AppException(
                1,
                'token验证失败，请检查是否填写正确'
            );
        }
        $encryptedToken = $this->credentialCipher->encrypt($token);

        $auth->token = $encryptedToken;
        $auth->created_at = time();
        $auth->save();

        $auth = $auth->toArray();
        $auth['token'] = $this->hiddenToken($token);

        return $auth;
    }

    /**
     * 删除授权.
     * @param int $uid
     * @param int $vendor
     * @param string $domain
     */
    public function removeAuth($uid, $vendor, $domain)
    {
        // Github、Gitee等只允许创建一个授权
        if (isset(self::$protectDomains[$vendor])) {
            $domain = self::$protectDomains[$vendor];
        }
        $domain = self::normalizeEndpoint((string) $domain);

        $auth = $this->where('uid', $uid)
            ->where('vendor', $vendor)
            ->where('domain', $domain)
            ->first();
        if (empty($auth)) {
            throw new AppException(
                1,
                '授权记录不存在'
            );
        }

        $auth->delete();
    }

    /**
     * 检查创建项目输入的仓库地址是否授权.
     * @param int $uid
     * @param int $vendor
     * @param string $repo
     */
    public function checkGitRepoAuth($uid, $vendor, $repo)
    {
        [$domain, , $origin] = self::parseRepositoryUrl((string) $repo);
        $tryMatchedVendor = $this->getMatchedVendor($domain);

        if ($tryMatchedVendor && $tryMatchedVendor != $vendor) {
            throw new AppException(
                1,
                sprintf('厂商选择错误，您应该选择%s', self::$vendors[$tryMatchedVendor])
            );
        }

        $candidates = array_values(array_unique([$origin, $domain]));
        $auth = $this->where('uid', $uid)
            ->where('vendor', $vendor)
            ->whereIn('domain', $candidates)
            ->first(['domain']);

        return [
            'authed' => $auth !== null,
            'vendor' => $vendor,
            // Prefer the exact repository HTTP origin for new self-hosted
            // credentials, while continuing to match legacy host-only rows.
            'domain' => (string) ($auth?->domain ?? $origin),
        ];
    }

    /**
     * Parse an external repository without accepting local/file transports or
     * credentials embedded in an HTTP URL. Returns
     * [host, project path, HTTP API origin].
     */
    public static function parseRepositoryUrl(string $repository): array
    {
        $repository = trim($repository);
        if ($repository === '' || preg_match('/\s/', $repository)) {
            throw new AppException(422, 'Git仓库地址不合法');
        }
        if (preg_match('#^[^@/]+@([^:\s/]+):(.+)$#', $repository, $matches)) {
            $domain = strtolower($matches[1]);
            $project = ltrim($matches[2], '/');
            $origin = 'https://' . $domain;
        } else {
            $parts = parse_url($repository);
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            if (! is_array($parts)
                || ! in_array($scheme, ['http', 'https', 'ssh'], true)
                || empty($parts['host'])
                || empty($parts['path'])
                || ! empty($parts['pass'])
                || ! empty($parts['query'])
                || ! empty($parts['fragment'])
                || ($scheme !== 'ssh' && ! empty($parts['user']))) {
                throw new AppException(422, 'Git仓库地址仅支持 HTTP、HTTPS 或 SSH，且不能在 URL 中包含密码');
            }
            $domain = strtolower((string) $parts['host']);
            $project = ltrim((string) $parts['path'], '/');
            $origin = ($scheme === 'http' || $scheme === 'https')
                ? $scheme . '://' . $domain . (isset($parts['port']) ? ':' . (int) $parts['port'] : '')
                : 'https://' . $domain;
        }
        $project = (string) preg_replace('/\.git$/i', '', $project);
        if ($domain === '' || $project === '' || preg_match('#(?:^|/)\.\.(?:/|$)#', $project)) {
            throw new AppException(422, 'Git仓库地址缺少有效的仓库路径');
        }
        return [$domain, $project, $origin];
    }

    /**
     * Normalize a credential target. Official providers remain host-only;
     * self-hosted GitLab/Gitea may use an explicit HTTP(S) origin and port.
     */
    public static function normalizeEndpoint(string $endpoint): string
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '' || preg_match('/\s/', $endpoint)) {
            throw new AppException(422, 'Git 服务地址不合法');
        }
        if (! str_contains($endpoint, '://')) {
            if (! preg_match('/^(?:[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?|\[[0-9a-f:]+\])(?::\d{1,5})?$/i', $endpoint)) {
                throw new AppException(422, 'Git 服务地址不合法');
            }
            return strtolower(rtrim($endpoint, '/'));
        }
        $parts = parse_url($endpoint);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $port = (int) ($parts['port'] ?? 0);
        if (! is_array($parts) || ! in_array($scheme, ['http', 'https'], true)
            || empty($parts['host']) || ! empty($parts['user']) || ! empty($parts['pass'])
            || ! empty($parts['query']) || ! empty($parts['fragment'])
            || ($path !== '' && $path !== '/') || $port > 65535) {
            throw new AppException(422, 'Git 服务地址必须是不带路径和凭据的 HTTP/HTTPS Origin');
        }
        return $scheme . '://' . strtolower((string) $parts['host']) . ($port > 0 ? ':' . $port : '');
    }

    /**
     * 写入码云登录授权认证.
     * 暂时性废弃不使用.
     * @param int $uid
     * @param string $token
     * @return null|GitAuth
     */
    public function saveGiteeLoginToken($uid, $token)
    {
        $vendor = self::VENDOR_GITEE;
        $domain = self::$protectDomains[$vendor];

        // 已授权，不写入
        $authed = $this->where('uid', $uid)
            ->where('vendor', $vendor)
            ->exists();
        if ($authed) {
            return null;
        }

        $encryptedToken = $this->credentialCipher->encrypt($token);

        // 未授权，写入新记录
        return self::create([
            'uid' => $uid,
            'vendor' => $vendor,
            'domain' => $domain,
            'token' => $encryptedToken,
            'created_at' => time(),
        ]);
    }

    /**
     * 移除码云登录授权认证.
     * @param int $uid
     * @param string $token
     * @return bool 移除为true，否则为false
     */
    public function removeGiteeLoginToken($uid, $token)
    {
        $vendor = self::VENDOR_GITEE;

        $gitAuth = $this->where('uid', $uid)
            ->where('vendor', $vendor)
            ->first();
        // 无授权记录，无需移除
        if (empty($gitAuth)) {
            return false;
        }
        $decryptedToken = $this->credentialCipher->decrypt((string) $gitAuth['token']);

        // 已授权，检查token是否和登录一致
        if ($decryptedToken != $token) {
            // token不一致，说明此token被修改，认为不是登录自动添加，不移除
            return false;
        }

        // 已授权，且token和登录一致，操作移除
        $gitAuth->delete();

        return true;
    }

    /**
     * 获取域名匹配的厂商.
     * @param string $domain
     */
    public function getMatchedVendor($domain)
    {
        foreach (self::$protectDomains as $vendor => $protectDomain) {
            if ($domain == $protectDomain) {
                return (int) $vendor;
            }
        }

        return null;
    }

    /**
     * 隐藏token.
     */
    protected function hiddenToken($token)
    {
        return Functions::strHidden($token, 5, 5, '*');
    }
}
