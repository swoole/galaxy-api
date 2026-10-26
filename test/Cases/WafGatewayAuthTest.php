<?php

namespace HyperfTest\Cases;

use App\Exception\AppException;
use App\Model\Cluster;
use App\Model\WafGatewayCredential;
use App\Model\WafPolicy;
use App\Services\Gateway\WafGatewayAuth;
use App\Services\Gateway\WafSchemaState;
use Hyperf\Context\ApplicationContext;
use Hyperf\Database\ConnectionResolver;
use Hyperf\Database\ConnectionResolverInterface;
use HyperfTest\Stub\WafDynamicDatabase;
use PHPUnit\Framework\TestCase;

final class WafGatewayAuthTest extends TestCase
{
    private mixed $old;
    protected function setUp(): void
    {
        $container = ApplicationContext::getContainer(); $this->old = $container->get(ConnectionResolverInterface::class);
        $container->set(ConnectionResolverInterface::class, new ConnectionResolver(['default' => WafDynamicDatabase::connect()]));
    }
    protected function tearDown(): void { ApplicationContext::getContainer()->set(ConnectionResolverInterface::class, $this->old); }

    public function testCredentialsAreStableAcrossInstancesScopedAndNeverSerialized(): void
    {
        $one = WafPolicy::create(['org_id' => 1, 'cluster_id' => 1]); $two = WafPolicy::create(['org_id' => 2, 'cluster_id' => 2]);
        $auth = new WafGatewayAuth(new WafSchemaState()); $token = $auth->credential($one);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $token);
        self::assertSame($token, (new WafGatewayAuth(new WafSchemaState()))->credential($one));
        self::assertNotSame($token, $auth->credential($two)); self::assertSame(2, WafGatewayCredential::count());
        self::assertStringNotContainsString($token, WafGatewayCredential::where('policy_id', $one->id)->first()->toJson());
        $forged = clone $one; $forged->org_id = 2;
        $this->expectExceptionCode(409); $auth->credential($forged);
    }

    public function testOrphanCleanupNeverRemovesAnotherOrganizationCredential(): void
    {
        foreach ([1,2] as $org) WafGatewayCredential::create(['org_id' => $org, 'cluster_id' => $org, 'policy_id' => 900 + $org,
            'token' => str_repeat((string) $org,64), 'created_at' => time()]);
        $cluster = new Cluster(['org_id' => 1]); $cluster->id = 1;
        (new WafGatewayAuth(new WafSchemaState()))->prune($cluster);
        self::assertSame([2], WafGatewayCredential::pluck('org_id')->all());
    }

    public function testGatewayRequestsRequireMigrationBeforeDeployment(): void
    {
        (new WafPolicy())->getConnection()->statement('DROP TABLE galaxy_waf_gateway_credential');
        $policy = WafPolicy::create(['org_id' => 1, 'cluster_id' => 1]);
        $this->expectExceptionCode(409); (new WafGatewayAuth(new WafSchemaState()))->credential($policy);
    }
}
