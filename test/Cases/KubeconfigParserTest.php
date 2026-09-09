<?php

declare(strict_types=1);

namespace HyperfTest\Cases;

use App\Exception\AppException;
use App\Services\Kubernetes\KubeconfigParser;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class KubeconfigParserTest extends TestCase
{
    public function testParsesPortableEmbeddedCertificateKubeconfig(): void
    {
        $config = $this->parser()->parse($this->kubeconfig());

        self::assertSame('https://127.0.0.1:6550', $config['server']);
        self::assertSame('k3d-dev-cluster', $config['context_name']);
        self::assertSame('default', $config['namespace']);
        self::assertSame('CA', $config['ca_certificate']);
        self::assertSame('CERT', $config['client_certificate']);
        self::assertSame('KEY', $config['client_key']);
    }

    public function testRejectsMachineLocalCredentialPaths(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('本机路径');
        $this->parser()->parse(str_replace(
            'client-certificate-data: Q0VSVA==',
            'client-certificate: /root/.kube/client.crt',
            $this->kubeconfig()
        ));
    }

    public function testNormalizesK3dWildcardListenerToLoopback(): void
    {
        $config = $this->parser()->parse(str_replace(
            'https://127.0.0.1:6550',
            'https://0.0.0.0:35773',
            $this->kubeconfig()
        ));

        self::assertSame('https://127.0.0.1:35773', $config['server']);
    }

    private function parser(): KubeconfigParser
    {
        return new KubeconfigParser();
    }

    private function kubeconfig(): string
    {
        return <<<'YAML'
apiVersion: v1
kind: Config
current-context: k3d-dev-cluster
clusters:
  - name: k3d-dev-cluster
    cluster:
      server: https://127.0.0.1:6550
      certificate-authority-data: Q0E=
contexts:
  - name: k3d-dev-cluster
    context:
      cluster: k3d-dev-cluster
      user: admin@k3d-dev-cluster
users:
  - name: admin@k3d-dev-cluster
    user:
      client-certificate-data: Q0VSVA==
      client-key-data: S0VZ
YAML;
    }
}
