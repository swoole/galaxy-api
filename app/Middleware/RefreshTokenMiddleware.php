<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace App\Middleware;

use App\Constants\ErrorCode;
use App\Exception\UnauthorizedException;
use App\Model\User;
use Hyperf\Context\Context;
use Hyperf\Redis\Redis;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Qbhy\SimpleJwt\Exceptions\TokenExpiredException;
use Qbhy\SimpleJwt\Exceptions\TokenRefreshExpiredException;

class RefreshTokenMiddleware implements MiddlewareInterface
{
//    protected $guards = [null];
    protected $guards = 'jwt';

    /**
     * @var bool|int|string
     */
    protected $authDebug = false;

    /**
     * @var ContainerInterface
     */
    protected $container;

    protected Redis $redis;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->redis = $container->get(Redis::class);
        $this->authDebug = config('auth.debug', false);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authorization = trim($request->getHeaderLine('Authorization'));
        if ((bool) config('devtools.access_enabled', false)
            && preg_match('/^Bearer\s+(cgdev_[a-f0-9]{64})$/i', $authorization, $matches)) {
            return $this->handleDevtoolToken($request, $handler, $matches[1]);
        }
        if ($this->authDebug) {
            $request = $request->withHeader('Authorization', 'Bearer faketoken');
            Context::set(auth()->resultKey('faketoken'), User::find($this->authDebug));
            Context::set('token', 'Bearer faketoken');
            return $handler->handle($request);
        }

        $jwtGuard = auth()->guard($this->guards);
        $token = $jwtGuard->parseToken();
        try {
            if (! $token) {
                $message = ErrorCode::getMessage(ErrorCode::NO_LOGIN_ERROR);
                throw new UnauthorizedException($message);
            }

            $jwtGuard->user($token);
            Context::set('token', 'Bearer ' . $token);
        } catch (\Exception $e) {
            if ($e instanceof TokenExpiredException) {
                try {
                    //过期刷新token
                    $newToken = $jwtGuard->refresh($token);
                    //header头返回 新token
                    return $handler->handle($request)->withHeader('authorization', 'bearer ' . $newToken);
                } catch (\Exception $e) {
                    if ($e instanceof TokenRefreshExpiredException) {
                        // 超出刷新过期时间 重新登录
                        $message = ErrorCode::getMessage(ErrorCode::RE_LOGIN_ERROR);
                        throw new UnauthorizedException($message);
                    }
                }
            } else {
                $errorInfo = $e->getMessage();
                $message = ErrorCode::getMessage(ErrorCode::TOKEN_INVALID);
                throw new UnauthorizedException($message . '(' . $errorInfo . ')');
            }
        }
        return $handler->handle($request);
    }

    private function handleDevtoolToken(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
        string $token
    ): ResponseInterface {
        $key = (string) config('devtools.redis_prefix', 'galaxy:devtool:token:') . hash('sha256', $token);
        $payload = json_decode((string) $this->redis->get($key), true);
        if (! is_array($payload) || (int) ($payload['expires_at'] ?? 0) < time()) {
            throw new UnauthorizedException('调试 Access Token 无效或已过期');
        }
        $path = $request->getUri()->getPath();
        if (! str_starts_with($path, '/_devtools/')
            && ! in_array('api', (array) ($payload['scopes'] ?? []), true)) {
            throw new UnauthorizedException('调试 Access Token 缺少 api scope');
        }
        $user = User::find((int) ($payload['uid'] ?? 0));
        if ($user === null) {
            throw new UnauthorizedException('调试 Access Token 对项目户不存在');
        }
        Context::set(auth()->resultKey($token), $user);
        Context::set('token', 'Bearer ' . $token);
        Context::set('devtool_access', $payload);
        return $handler->handle($request);
    }
}
