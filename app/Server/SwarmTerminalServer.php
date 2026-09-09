<?php

namespace App\Server;

use App\Services\Docker\SwarmTerminalService;
use Hyperf\Contract\OnCloseInterface;
use Hyperf\Contract\OnMessageInterface;
use Hyperf\Contract\OnOpenInterface;
use Hyperf\Logger\LoggerFactory;
use Swoole\Coroutine;
use Swoole\WebSocket\Server;
use Throwable;

class SwarmTerminalServer implements OnOpenInterface, OnMessageInterface, OnCloseInterface
{
    private array $sessions = [];

    public function __construct(private SwarmTerminalService $terminal, private LoggerFactory $loggerFactory) {}

    public function onOpen($server, $request): void
    {
        $fd = (int) $request->fd;
        try {
            if (($request->server['request_uri'] ?? '') !== '/cluster/swarm/terminal') {
                throw new \RuntimeException('WebSocket 路径无效');
            }
            $ticket = (string) ($request->get['ticket'] ?? '');
            $columns = max(20, min(500, (int) ($request->get['cols'] ?? 120)));
            $rows = max(5, min(300, (int) ($request->get['rows'] ?? 40)));
            $session = $this->terminal->open($this->terminal->consumeTicket($ticket), $columns, $rows);
            $this->sessions[$fd] = $session;
            // The WebSocket handshake may complete on the client before this
            // onOpen callback finishes. Apply the initial size here so an early
            // client resize frame cannot be lost while the session is opening.
            $this->terminal->resize($session['cluster'], $session['execId'], $columns, $rows);
            if ($session['initialOutput'] !== '') {
                $server->push($fd, $session['initialOutput'], WEBSOCKET_OPCODE_BINARY);
            }
            Coroutine::create(fn () => $this->pump($server, $fd));
        } catch (Throwable $e) {
            $this->loggerFactory->get('SwarmTerminal')->warning('Web terminal connection rejected', ['fd' => $fd, 'error' => $e->getMessage()]);
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
                    $session = $this->sessions[$fd];
                    $this->terminal->resize($session['cluster'], $session['execId'], (int) ($control['cols'] ?? 120), (int) ($control['rows'] ?? 40));
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
        $pending = '';
        $marker = (string) ($this->sessions[$fd]['exitMarker'] ?? '');
        try {
            while (isset($this->sessions[$fd]) && $server->isEstablished($fd)) {
                $data = $this->sessions[$fd]['client']->recv();
                if (! is_string($data) || $data === '') {
                    if ($pending !== '') {
                        $server->push($fd, $pending, WEBSOCKET_OPCODE_BINARY);
                    }
                    break;
                }
                if ($marker === '') {
                    if (! $server->push($fd, $data, WEBSOCKET_OPCODE_BINARY)) {
                        break;
                    }
                    continue;
                }
                $pending .= $data;
                $markerAt = strpos($pending, $marker);
                if ($markerAt !== false) {
                    $output = substr($pending, 0, $markerAt);
                    if ($output !== '') {
                        $server->push($fd, $output, WEBSOCKET_OPCODE_BINARY);
                    }
                    break;
                }
                $keep = $this->markerPrefixSuffix($pending, $marker);
                $outputLength = strlen($pending) - $keep;
                if ($outputLength > 0) {
                    $output = substr($pending, 0, $outputLength);
                    $pending = $keep > 0 ? substr($pending, -$keep) : '';
                    if (! $server->push($fd, $output, WEBSOCKET_OPCODE_BINARY)) {
                        break;
                    }
                }
            }
        } catch (Throwable $e) {
            $this->loggerFactory->get('SwarmTerminal')->notice('Web terminal stream closed', ['fd' => $fd, 'error' => $e->getMessage()]);
        } finally {
            $this->close($fd);
            if ($server->isEstablished($fd)) {
                $server->disconnect($fd, 1000, 'terminal closed');
            }
        }
    }

    private function markerPrefixSuffix(string $data, string $marker): int
    {
        $maximum = min(strlen($data), strlen($marker) - 1);
        for ($length = $maximum; $length > 0; --$length) {
            if (substr($data, -$length) === substr($marker, 0, $length)) {
                return $length;
            }
        }
        return 0;
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
