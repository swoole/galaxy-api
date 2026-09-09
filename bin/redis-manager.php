<?php

use Swoole\Http\Server;
use Swoole\Http\Response;
use Swoole\Http\Request;
use Swoole\Process;
use Swoole\Coroutine\System;

const REDIS_SERVER_BIN = '/usr/bin/redis-server';

class AppException extends RuntimeException
{

}

class RedisInstance
{
    static string $root_path = '/tmp';
    static array $instances = [];
    static array $pids = [];
    const PORT_BEGIN = 16379;
    static int $port_next = self::PORT_BEGIN;

    protected string $name;
    protected $proc;
    protected string $conf_file;
    protected int $port = 0;
    protected bool $running = true;

    /**
     * @param $name
     * @return self
     */
    static function getInstance($name): RedisInstance
    {
        if (!self::exists($name)) {
            throw new AppException("instance is not exists", 10404);
        }
        return self::$instances[$name];
    }

    /**
     * @param $name
     * @return bool
     */
    static function exists($name): bool
    {
        return isset(self::$instances[$name]);
    }

    static function setRootPath($path)
    {
        self::$root_path = $path;
        if (!is_dir(self::$root_path)) {
            mkdir(self::$root_path, 0777, true);
        }
    }

    static function makeNewConf(string $name, string $password, $memory_size)
    {
        $port = RedisInstance::selectPort();
        $root = self::$root_path . '/' . $name;
        mkdir($root);
        mkdir($root . '/etc', 0777);
        mkdir($root . '/run', 0777);
        mkdir($root . '/lib', 0777);
        mkdir($root . '/log', 0777);

        ob_start();
        require dirname(__DIR__) . '/config/redis.conf';
        $conf = ob_get_clean();
        $file = $root . '/etc/redis.conf';
        file_put_contents($file, $conf);
    }

    /**
     * @param $name
     */
    function __construct($name)
    {
        $this->name = $name;
        $this->conf_file = self::$root_path . '/' . $name . '/etc/redis.conf';

        $descriptorspec = array(
            0 => array("file", "/dev/null", 'a'),
            1 => array("file", "/dev/null", 'a'),
            2 => array("file", "/dev/null", 'a'),
        );
        $proc = proc_open(REDIS_SERVER_BIN . ' ' . $this->conf_file, $descriptorspec, $pipes);
        if (!$proc) {
            throw new RuntimeException("failed to start process");
        }
        $this->proc = $proc;
        self::$instances[$name] = $this;
        $pid = proc_get_status($proc)['pid'];
        if ($this->getPort() > self::$port_next) {
            self::$port_next = $this->getPort() + 1;
        }

        Co\go(function () use ($pid, $name) {
            while ($this->running) {
                if (!System::waitPid($pid, 1)) {
                    continue;
                }
                $this->close();
                if ($this->running) {
                    echo "old process[$pid] exited, restart instance[$name]\n";
                    new RedisInstance($name);
                }
            }
        });
    }

    function stop()
    {
        Process::kill($this->getPid(), SIGINT);
        $this->running = false;
        $this->close();
    }

    function drop()
    {
        shell_exec('rm -rf ' . self::$root_path . '/' . $this->name);
    }

    function close()
    {
        unset(RedisInstance::$instances[$this->name]);
        if ($this->proc) {
            proc_close($this->proc);
            echo "instance[name={$this->name}] stop\n";
            $this->proc = null;
        }
    }

    static function selectPort(): int
    {
        while (true) {
            $port = self::$port_next++;
            $server = @stream_socket_server('tcp://127.0.0.1:' . $port);
            if (!$server) {
                continue;
            }
            break;
        }

        return $port;
    }

    function getPort(): int|false
    {
        if ($this->port > 0) {
            return $this->port;
        }
        if (preg_match('/^port (\d+)$/m', file_get_contents($this->conf_file), $match)) {
            $this->port = intval($match[1]);
            return $this->port;
        }
        return false;
    }

    function getProcStatus()
    {
        if ($this->proc) {
            return proc_get_status($this->proc);
        }
        return false;
    }

    function getPid(): int
    {
        $root = self::$root_path . '/' . $this->name;
        return intval(file_get_contents($root . '/run/redis-server.pid'));
    }
}

class RedisManageController
{
    protected Request $request;
    protected Response $response;

    function send($data, int $code = 0, string $error = '')
    {
        return $this->response->end(json_encode(['code' => $code, 'error' => $error, 'data' => $data]));
    }

    function actionCreate() {
        if (empty($this->request->post['name']) or
            empty($this->request->post['password']) or
            empty($this->request->post['memory_size'])) {
            return $this->send([], 403, "bad request");
        }
        $name = $this->request->post['name'];
        $password = $this->request->post['password'];
        $memory_size = $this->request->post['memory_size'];
        $root = RedisInstance::$root_path . '/' . $name;
        clearstatcache();
        if (is_dir($root)) {
            return $this->send([], 500, '实例已存在');
        }
        RedisInstance::makeNewConf($name, $password, $memory_size);
        $instance = new RedisInstance($name);
        return $this->send(['port' => $instance->getPort(),]);
    }

    protected function checkRequest()
    {
        if (empty($this->request->post['name'])) {
            throw new AppException("bad request", 10403);
        }
    }

    function actionDelete()
    {
        $this->checkRequest();
        $name = $this->request->post['name'];
        $instance = RedisInstance::getInstance($name);
        $instance->stop();
        $instance->drop();
        return $this->send([]);
    }

    function actionStop()
    {
        $this->checkRequest();
        $name = $this->request->post['name'];
        $instance = RedisInstance::getInstance($name);
        $instance->stop();
        return $this->send([]);
    }

    function actionStart()
    {
        $this->checkRequest();
        if (RedisInstance::exists($this->request->post['name'])) {
            return $this->send([], 10404, "实例已存在");
        }
        new RedisInstance($this->request->post['name']);
        return $this->send([]);
    }

    function actionList()
    {
        $result = [];
        foreach (RedisInstance::$instances as $name => $instance) {
            $result[$name] = $instance->getProcStatus();
        }
        return $this->send($result);
    }

    function dispatch(Request $request, Response $response)
    {
        $this->response = $response;
        $this->request = $request;

        echo "[".date('Y-m-d H:i:s')."]\t".$request->getMethod()."\t".$request->server['request_uri']."\n";

        try {
            if ($request->server['request_uri'] == '/create' and $request->getMethod() == 'POST') {
                return $this->actionCreate();
            } elseif ($request->server['request_uri'] == '/list' and $request->getMethod() == 'GET') {
                return $this->actionList();
            } elseif ($request->server['request_uri'] == '/delete' and $request->getMethod() == 'DELETE') {
                return $this->actionDelete();
            } elseif ($request->server['request_uri'] == '/stop' and $request->getMethod() == 'POST') {
                return $this->actionStop();
            }  elseif ($request->server['request_uri'] == '/start' and $request->getMethod() == 'POST') {
                return $this->actionStart();
            }
        } catch (AppException $e) {
            $this->response->setStatusCode(500);
            return $this->send([], $e->getCode(), $e->getMessage());
        }
        $this->response->setStatusCode(404);
        return $this->response->end();
    }
}

$http = new Server('0.0.0.0', 9503, SWOOLE_BASE);

$http->on('start', function ($server) {
    echo "Swoole http server is started at http://127.0.0.1:{$server->port}\n";

    $list = scandir(RedisInstance::$root_path);
    foreach ($list as $name) {
        $root = RedisInstance::$root_path . '/' . $name;
        $file = $root . '/etc/redis.conf';
        if (is_dir($root) and is_file($file)) {
            $instance = new RedisInstance($name);
            echo "Start a redis instance[$name] with port {" . $instance->getPort() . "}\n";
        }
    }
});

$http->on('request', function (Request $request, Response $response) {
    (new RedisManageController)->dispatch($request, $response);
});

$http->on('beforeShutdown', function ($server) {
    foreach (RedisInstance::$instances as $instance) {
        $instance->stop();
    }
});

RedisInstance::setRootPath(dirname(__DIR__) . '/redis');

$http->set(['hook_flags' => SWOOLE_HOOK_ALL]);
$http->start();
