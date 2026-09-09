<?php

declare(strict_types=1);

namespace App\Server;

use App\Services\Kubernetes\KubernetesTerminalService;
use Hyperf\Contract\OnCloseInterface;
use Hyperf\Contract\OnMessageInterface;
use Hyperf\Contract\OnOpenInterface;
use Hyperf\Logger\LoggerFactory;
use Swoole\Coroutine;
use Swoole\WebSocket\Server;
use Throwable;

/**
 * Bridges browser WebSocket frames to a Kubernetes Pod `exec` stream. Mirrors
 * App\Server\SwarmTerminalServer but the data plane is a KubernetesExecStream
 * instead of a Docker Agent relay stream.
 */
class KubernetesTerminalServer implements OnOpenInterface, OnMessageInterface, OnCloseInterface
{
    private array $sessions = [];

    public function __construct(private KubernetesTerminalService $terminal, private LoggerFactory $loggerFactory) {}

    public function onOpen($server, $request): void
    {
        $fd = (int) $request->fd;
        try {
            if (($request->server['request_uri'] ?? '') !== '/cluster/k8s/terminal') {
                throw new \RuntimeException('WebSocket 路径无效');
            }
            $ticket = (string) ($request->get['ticket'] ?? '');
            $columns = max(20, min(500, (int) ($request->get['cols'] ?? 120)));
            $rows = max(5, min(300, (int) ($request->get['rows'] ?? 40)));
            $session = $this->terminal->open($this->terminal->consumeTicket($ticket), $columns, $rows);
            $this->sessions[$fd] = $session;
            if ($session['initialOutput'] !== '') {
                $server->push($fd, $session['initialOutput'], WEBSOCKET_OPCODE_BINARY);
            }
            Coroutine::create(fn () => $this->pump($server, $fd));
        } catch (Throwable $e) {
            $this->loggerFactory->get('KubernetesTerminal')->warning('Web terminal connection rejected', [
                'fd' => $fd,
                'error' => $e->getMessage(),
            ]);
            $server->push($fd, "\r\n连接终端失败：{$e->getMessage()}\r\n");
            $server->disconnect($fd, 1008, 'terminal rejected');
        }
    }

    public function onMessage($server, $frame): void
    {
        $fd = (int) $frame->fd;
        if (! isset($this->sessions[$fd])) {
            return;
        }
        try {
            if ($frame->opcode === WEBSOCKET_OPCODE_TEXT && str_starts_with($frame->data, '{')) {
                $control = json_decode($frame->data, true);
                if (($control['type'] ?? '') === 'resize') {
                    $this->terminal->resize(
                        $this->sessions[$fd]['client'],
                        (int) ($control['cols'] ?? 120),
                        (int) ($control['rows'] ?? 40)
                    );
                    return;
                }
            }
            $this->sessions[$fd]['client']->send($frame->data);
        } catch (Throwable $e) {
            $server->push($fd, "\r\n终端写入失败：{$e->getMessage()}\r\n");
            $server->disconnect($fd, 1011, 'terminal write failed');
        }
    }

    public function onClose($server, int $fd, int $reactorId): void
    {
        $this->close($fd);
    }

    private function pump(Server $server, int $fd): void
    {
        try {
            while (isset($this->sessions[$fd]) && $server->isEstablished($fd)) {
                $data = $this->sessions[$fd]['client']->recv();
                if ($data === false) {
                    break;
                }
                if ($data === '' || $data === "\x04") {
                    continue;
                }
                if (! $server->push($fd, $data, WEBSOCKET_OPCODE_BINARY)) {
                    break;
                }
            }
        } catch (Throwable $e) {
            $this->loggerFactory->get('KubernetesTerminal')->notice('Web terminal stream closed', [
                'fd' => $fd,
                'error' => $e->getMessage(),
            ]);
        } finally {
            $this->close($fd);
            if ($server->isEstablished($fd)) {
                $server->disconnect($fd, 1000, 'terminal closed');
            }
        }
    }

    private function close(int $fd): void
    {
        if (! isset($this->sessions[$fd])) {
            return;
        }
        $session = $this->sessions[$fd];
        unset($this->sessions[$fd]);
        $this->terminal->close($session);
    }
}
