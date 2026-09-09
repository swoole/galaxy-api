<?php

/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
use Hyperf\Contract\SessionInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Server\ServerFactory;
use Hyperf\Context\ApplicationContext;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WebSocketServer;

if (! function_exists('env')) {
    /**
     * Hyperf 3 将 env() 移入了 Hyperf\Support 命名空间；兼容旧配置文件的全局调用。
     */
    function env($key, $default = null)
    {
        return \Hyperf\Support\env($key, $default);
    }
}

if (! function_exists('make')) {
    /**
     * 兼容 Hyperf 2 的全局 make() 调用。
     */
    function make(string $name, array $parameters = [])
    {
        return \Hyperf\Support\make($name, $parameters);
    }
}

if (! function_exists('config')) {
    /**
     * Hyperf 3 将 config() 移入了 Hyperf\Config 命名空间；兼容旧代码的全局调用。
     */
    function config(string $key, $default = null)
    {
        return \Hyperf\Config\config($key, $default);
    }
}

if (! function_exists('container')) {
    /**
     * 容器实例.
     * @return \Psr\Container\ContainerInterface
     */
    function container()
    {
        return ApplicationContext::getContainer();
    }
}

if (! function_exists('redis')) {
    /**
     * redis 客户端实例.
     * @return \Hyperf\Redis\Redis|mixed
     */
    function redis()
    {
        return container()->get(Hyperf\Redis\Redis::class);
    }
}

if (! function_exists('server')) {
    /**
     * server 实例 基于 swoole server.
     * @return \Swoole\Coroutine\Server|\Swoole\Server
     */
    function server()
    {
        return container()->get(ServerFactory::class)->getServer()->getServer();
    }
}

if (! function_exists('frame')) {
    /**
     * websocket frame 实例.
     * @return Frame|mixed
     */
    function frame()
    {
        return container()->get(Frame::class);
    }
}

if (! function_exists('websocket')) {
    /**
     * websocket 实例.
     * @return mixed|WebSocketServer
     */
    function websocket()
    {
        return container()->get(WebSocketServer::class);
    }
}

if (! function_exists('cache')) {
    /**
     * 缓存实例 简单的缓存.
     * @return mixed|\Psr\SimpleCache\CacheInterface
     */
    function cache()
    {
        return container()->get(Psr\SimpleCache\CacheInterface::class);
    }
}

if (! function_exists('stdLog')) {
    /**
     * 向控制台输出日志.
     * @return mixed|StdoutLoggerInterface
     */
    function stdLog()
    {
        return container()->get(StdoutLoggerInterface::class);
    }
}

if (! function_exists('logger')) {
    /**
     * 向日志文件记录日志.
     * @return \Psr\Log\LoggerInterface
     */
    function logger()
    {
        return container()->get(LoggerFactory::class)->make();
    }
}

if (! function_exists('request')) {
    /**
     * 请求对象
     * @return mixed|RequestInterface
     */
    function request()
    {
        return container()->get(RequestInterface::class);
    }
}

if (! function_exists('response')) {
    /**
     * 请求回应对象
     * @return mixed|ResponseInterface
     */
    function response()
    {
        return container()->get(ResponseInterface::class);
    }
}

if (! function_exists('session')) {
    /**
     * session 对象
     * @return mixed|SessionInterface
     */
    function session()
    {
        return container()->get(SessionInterface::class);
    }
}

if (! function_exists('emptyObj')) {
    /**
     * 返回空对象 {}.
     * @return stdClass
     */
    function emptyObj()
    {
        return new \stdClass();
    }
}

if (! function_exists('getUserIp')) {
    /**
     * 获取用户ip.
     * @return mixed|string
     */
    function getUserIp()
    {
        $req = request();
        if ($ip = $req->header('x-real-ip')) {
            return $ip;
        } elseif ($ip = $req->header('x-forwarded-for')) {
            return $ip;
        }

        $res = request()->getServerParams();
        if (isset($res['http_client_ip'])) {
            return $res['http_client_ip'];
        }
        if (isset($res['http_x_real_ip'])) {
            return $res['http_x_real_ip'];
        }
        if (isset($res['http_x_forwarded_for'])) {
            $arr = explode(',', $res['http_x_forwarded_for']);
            return $arr[0];
        }
        return $res['remote_addr'];
    }
}

if (! function_exists('getUserAgent')) {
    /**
     * 获取user agent.
     * @return mixed|string
     */
    function getUserAgent()
    {
        return request()->header('user-agent');
    }
}

if (! function_exists('queryIpAddress')) {
    /**
     * 查询ip地址
     * @param bool $onlyAddress 只返回地址 不包含其他信息
     * @return array|mixed|string
     */
    function queryIpAddress(string $ip, bool $onlyAddress = true)
    {
        // 原 zxipdb 数据源已停止稳定分发。保留调用契约，待接入新的 IP 地理位置服务。
        return $onlyAddress ? '' : [];
    }
}

function is_internal(): bool
{
    $ip = getUserIp();
    $gateway = env('GATEWAY_IP', '');
    if ($ip == $gateway) {
        return true;
    }
    return str_starts_with($ip, '192')
        or str_starts_with($ip, '127')
        or str_starts_with($ip, '10')
        or str_starts_with($ip, '172');
}
