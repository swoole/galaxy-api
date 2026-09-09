<?php

declare(strict_types=1);

namespace App\Services\Kubernetes;

use App\Exception\AppException;
use Swoole\Coroutine\Http\Client;
use Swoole\WebSocket\CloseFrame;
use Throwable;

/**
 * Streaming bridge between a browser WebSocket terminal and a Kubernetes
 * Pod `exec` subresource. Kubernetes serves the exec stream over a WebSocket
 * upgrade (the v5.channel.k8s.io subprotocol): every frame is prefixed with a
 * single channel byte (0 = stdin, 1 = stdout, 2 = stderr, 3 = error,
 * 4 = resize). We forward browser keystrokes on channel 0 and write everything
 * the apiserver returns (after stripping the channel byte) back to the browser.
 */
final class KubernetesExecStream
{
    private const PROTOCOL = 'v5.channel.k8s.io';

    private ?Client $client = null;

    /** @var array<int, string> */
    private array $tempFiles = [];

    private bool $closed = false;

    public function __construct(
        private array $credential,
        private string $namespace,
        private string $pod,
        private string $container,
        private array $command,
        private int $columns = 120,
        private int $rows = 40
    ) {}

    public function open(): void
    {
        $parts = parse_url((string) ($this->credential['server'] ?? ''));
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            throw new AppException(422, 'Kubernetes API 地址无效');
        }
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        $port = isset($parts['port']) ? (int) $parts['port'] : 443;
        $basePath = isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '';
        $secure = ($parts['scheme'] ?? 'https') === 'https';

        $client = new Client($host, $port, $secure);
        $client->set($this->tlsSettings());

        $headers = [];
        if (($token = (string) ($this->credential['token'] ?? '')) !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $headers['Sec-WebSocket-Protocol'] = self::PROTOCOL;

        $execPath = $this->buildExecPath($basePath);
        try {
            // Swoole 6.x: upgrade() accepts only the request path; headers must
            // be set via setHeaders() beforehand (otherwise it throws
            // "expects exactly 1 argument, 2 given").
            $client->setHeaders($headers);
            $upgraded = $client->upgrade($execPath);
        } catch (Throwable $e) {
            $this->cleanup();
            throw new AppException(502, '无法建立 Kubernetes Exec WebSocket：' . $e->getMessage());
        }
        if (! $upgraded || $client->statusCode !== 101) {
            $status = (int) ($client->statusCode ?? 0);
            $body = is_string($client->body ?? null) ? (string) $client->body : '';
            $client->close();
            $this->cleanup();
            throw new AppException(502, 'Kubernetes Exec 升级失败（HTTP ' . $status . '）'
                . ($body !== '' ? '：' . mb_substr($body, 0, 300) : ''));
        }

        $this->client = $client;
        // The exec endpoint accepts terminal size only via the resize channel,
        // so push the initial size right after the handshake completes.
        $this->resize($this->columns, $this->rows);
    }

    public function send(string $data): bool
    {
        if ($this->client === null || $this->closed) {
            return false;
        }
        return (bool) $this->client->push("\x00" . $data, WEBSOCKET_OPCODE_BINARY);
    }

    /** @return string|false false when the stream has closed */
    public function recv(float $timeout = 0): string|false
    {
        if ($this->client === null || $this->closed) {
            return false;
        }
        $frame = $this->client->recv($timeout);
        if ($frame === false || $frame === '') {
            return false;
        }
        if ($frame instanceof CloseFrame) {
            return false;
        }
        $opcode = (int) ($frame->opcode ?? WEBSOCKET_OPCODE_BINARY);
        if ($opcode === WEBSOCKET_OPCODE_PING || $opcode === WEBSOCKET_OPCODE_PONG) {
            return '';
        }
        $payload = (string) ($frame->data ?? '');
        if ($payload === '') {
            return '';
        }
        // Strip the leading channel byte and surface the payload. Channel 3 is
        // the error channel; its text is still useful to show to the user.
        return strlen($payload) > 1 ? substr($payload, 1) : '';
    }

    public function resize(int $columns, int $rows): void
    {
        if ($this->client === null || $this->closed) {
            return;
        }
        $columns = max(1, min(500, $columns));
        $rows = max(1, min(300, $rows));
        $payload = "\x04" . json_encode(
            ['Width' => $columns, 'Height' => $rows],
            JSON_THROW_ON_ERROR
        );
        @$this->client->push($payload, WEBSOCKET_OPCODE_BINARY);
    }

    public function close(): void
    {
        if ($this->client !== null) {
            try {
                $this->client->close();
            } catch (Throwable) {
            }
            $this->client = null;
        }
        $this->closed = true;
        $this->cleanup();
    }

    private function buildExecPath(string $basePath): string
    {
        $query = [];
        foreach ($this->command as $argument) {
            $query[] = 'command=' . rawurlencode((string) $argument);
        }
        $query[] = 'container=' . rawurlencode($this->container);
        $query[] = 'stdin=1&stdout=1&stderr=1&tty=1';
        return $basePath
            . '/api/v1/namespaces/' . rawurlencode($this->namespace)
            . '/pods/' . rawurlencode($this->pod)
            . '/exec?' . implode('&', $query);
    }

    /** @return array<string, mixed> */
    private function tlsSettings(): array
    {
        $settings = [];
        if (! empty($this->credential['insecure_skip_tls_verify'])) {
            $settings['ssl_verify_peer'] = false;
            $settings['ssl_allow_self_signed'] = true;
        } elseif (! empty($this->credential['ca_certificate'])) {
            $settings['ssl_cafile'] = $this->temporaryFile((string) $this->credential['ca_certificate']);
        }
        if (! empty($this->credential['client_certificate'])) {
            $settings['ssl_cert_file'] = $this->temporaryFile((string) $this->credential['client_certificate']);
            $settings['ssl_key_file'] = $this->temporaryFile((string) ($this->credential['client_key'] ?? ''));
        }
        return $settings;
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'galaxy-k8s-');
        if ($path === false || file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new AppException(500, '无法创建 Kubernetes 临时凭据文件');
        }
        chmod($path, 0600);
        $this->tempFiles[] = $path;
        return $path;
    }

    private function cleanup(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        $this->tempFiles = [];
    }
}
