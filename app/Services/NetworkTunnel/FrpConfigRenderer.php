<?php

namespace App\Services\NetworkTunnel;

use App\Model\FrpClient;
use App\Model\FrpServer;
use App\Services\Encrypt\EncryptService;

final class FrpConfigRenderer
{
    public function __construct(private EncryptService $cipher) {}

    public function server(FrpServer $server, string $token): string
    {
        $lines = [
            'bindAddr = "0.0.0.0"',
            'bindPort = ' . (int) $server->bind_port,
        ];
        if ((int) $server->vhost_http_port > 0) {
            $lines[] = 'vhostHTTPPort = ' . (int) $server->vhost_http_port;
        }
        if ((int) $server->vhost_https_port > 0) {
            $lines[] = 'vhostHTTPSPort = ' . (int) $server->vhost_https_port;
        }
        if ((int) $server->dashboard_port > 0) {
            $lines[] = 'webServer.addr = "0.0.0.0"';
            $lines[] = 'webServer.port = ' . (int) $server->dashboard_port;
            if (trim((string) $server->dashboard_user) !== '') {
                $lines[] = 'webServer.user = ' . $this->quote((string) $server->dashboard_user);
            }
            if (trim((string) $server->dashboard_password_ciphertext) !== '') {
                $lines[] = 'webServer.password = ' . $this->quote(
                    $this->cipher->decryptFast((string) $server->dashboard_password_ciphertext)
                );
            }
        }
        $allowedPorts = [];
        foreach ($server->clients as $client) {
            if ((string) $client->deployment_mode !== 'managed') {
                continue;
            }
            foreach ($client->tunnels->where('enabled', true) as $tunnel) {
                if (in_array((string) $tunnel->type, ['tcp', 'udp'], true)
                    && (int) $tunnel->remote_port > 0) {
                    $allowedPorts[(int) $tunnel->remote_port] = true;
                }
            }
        }
        if ($allowedPorts !== []) {
            $ports = array_keys($allowedPorts);
            sort($ports, SORT_NUMERIC);
            $lines[] = 'allowPorts = ['
                . implode(', ', array_map(
                    static fn (int $port): string => '{ single = ' . $port . ' }',
                    $ports
                ))
                . ']';
        }
        $lines[] = 'auth.method = "token"';
        $lines[] = 'auth.token = ' . $this->quote($token);
        $lines[] = 'transport.tls.force = true';
        $lines[] = 'log.to = "console"';
        $lines[] = 'log.level = "info"';
        return implode("\n", $lines) . "\n";
    }

    public function client(FrpClient $client, FrpServer $server, string $token): string
    {
        $lines = [
            'serverAddr = ' . $this->quote((string) $server->advertise_host),
            'serverPort = ' . (int) $server->bind_port,
            'auth.method = "token"',
            'auth.token = ' . $this->quote($token),
            'transport.tls.enable = true',
            'transport.poolCount = ' . max(1, (int) ($client->transport_pool_count ?: 5)),
            'loginFailExit = false',
            'log.to = "console"',
            'log.level = "info"',
        ];
        if (trim((string) $client->frp_user) !== '') {
            $lines[] = 'user = ' . $this->quote((string) $client->frp_user);
        }
        foreach ($client->tunnels->where('enabled', true) as $tunnel) {
            $lines[] = '';
            $lines[] = '[[proxies]]';
            $lines[] = 'name = ' . $this->quote((string) $tunnel->proxy_name);
            $lines[] = 'type = ' . $this->quote((string) $tunnel->type);
            $lines[] = 'localIP = ' . $this->quote((string) $tunnel->local_host);
            $lines[] = 'localPort = ' . (int) $tunnel->local_port;
            if (in_array((string) $tunnel->type, ['tcp', 'udp'], true)) {
                $lines[] = 'remotePort = ' . (int) $tunnel->remote_port;
            } else {
                $domains = array_values(array_filter(array_map('trim', (array) $tunnel->custom_domains)));
                $lines[] = 'customDomains = [' . implode(', ', array_map($this->quote(...), $domains)) . ']';
                $locations = array_values(array_filter(array_map('trim', (array) $tunnel->locations)));
                if ($locations !== []) {
                    $lines[] = 'locations = [' . implode(', ', array_map($this->quote(...), $locations)) . ']';
                }
                if (trim((string) $tunnel->host_header_rewrite) !== '') {
                    $lines[] = 'hostHeaderRewrite = ' . $this->quote((string) $tunnel->host_header_rewrite);
                }
            }
            $lines[] = 'transport.useEncryption = ' . ($tunnel->transport_encryption ? 'true' : 'false');
            $lines[] = 'transport.useCompression = ' . ($tunnel->transport_compression ? 'true' : 'false');
            if (trim((string) $tunnel->bandwidth_limit) !== '') {
                $lines[] = 'transport.bandwidthLimit = ' . $this->quote((string) $tunnel->bandwidth_limit);
            }
        }
        return implode("\n", $lines) . "\n";
    }

    private function quote(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
