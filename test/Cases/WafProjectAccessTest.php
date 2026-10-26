<?php

namespace HyperfTest\Cases;

use App\Exception\AppException;
use App\Model\Cluster;
use App\Model\WafPolicy;
use App\Services\Gateway\WafManagementService;
use App\Services\Project\ProjectSwarmScope;
use App\Services\PermissionService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Context\Context;
use Hyperf\Database\Connection;
use Hyperf\Database\ConnectionResolver;
use Hyperf\Database\ConnectionResolverInterface;
use PDO;
use PHPUnit\Framework\TestCase;

final class WafProjectAccessTest extends TestCase
{
    public function testProjectMembersCanReadAndDirectorsCanConfigureUsingSharedEndpoints(): void
    {
        $factory = new \ReflectionProperty(\Hyperf\HttpServer\Router\Router::class, 'factory');
        $previous = $factory->getValue();
        try {
            new \Hyperf\HttpServer\Router\DispatcherFactory();
            $permission = new PermissionService(ApplicationContext::getContainer());
            $read = 'App\\Controller\\ClusterController@swarmWaf';
            $save = 'App\\Controller\\ClusterController@swarmWafSave';
            self::assertTrue($permission->needValid($read));
            self::assertSame(1, $permission->valid([1], $save));
            self::assertSame(1, $permission->valid([2, 9, 1], $save));
            self::assertSame(1, $permission->valid([2, 1, 9], $save));
            self::assertSame(1, $permission->valid([2, 1, 1], $read));
            self::assertSame(1, $permission->valid([2, 1, 1], 'App\\Controller\\ClusterController@swarmWafEvents'));
            $export = 'App\\Controller\\ClusterController@swarmWafEventsExport';
            self::assertTrue($permission->needValid($export));
            self::assertSame(1, $permission->valid([2, 1, 1], $export));
            self::assertSame(0, $permission->valid([2], $export));
            foreach (['swarmWafHistory', 'swarmWafRevision', 'swarmWafAlerts', 'swarmWafBans', 'swarmWafDataSources'] as $action) {
                self::assertSame(1, $permission->valid([2, 1, 1], 'App\\Controller\\ClusterController@' . $action));
                self::assertSame(0, $permission->valid([2], 'App\\Controller\\ClusterController@' . $action));
            }
            foreach (['swarmWafDataSourceSave','swarmWafDataSourceRefresh','swarmWafDataSourceDelete'] as $action) {
                $endpoint = 'App\\Controller\\ClusterController@' . $action;
                self::assertSame(0, $permission->valid([2, 1, 1], $endpoint));
                self::assertSame(0, $permission->valid([2, 9, 9], $endpoint));
                self::assertSame(1, $permission->valid([1], $endpoint));
            }
            foreach (['swarmWafRestore', 'swarmWafAlertAcknowledge', 'swarmWafBanRelease'] as $action) {
                $endpoint = 'App\\Controller\\ClusterController@' . $action;
                self::assertSame(0, $permission->valid([2, 1, 1], $endpoint));
                self::assertSame(1, $permission->valid([2, 1, 9], $endpoint));
                self::assertSame(1, $permission->valid([1], $endpoint));
            }
            self::assertSame(0, $permission->valid([2, 1, 1], $save));
            self::assertSame(0, $permission->valid([2, 1, false], $read));
            self::assertSame(0, $permission->valid([2], $read));
        } finally { $factory->setValue(null, $previous); }
    }

    public function testProjectScopeRequiresGrantAndIsolatesRoutesAndPolicies(): void
    {
        $container = ApplicationContext::getContainer();
        $oldResolver = $container->get(ConnectionResolverInterface::class);
        $oldProject = Context::get('project_id', 0); $oldGroup = Context::get('group_id', 0);
        $db = (new Connection(new PDO('sqlite::memory:'), ':memory:', 'galaxy_'))->setTablePrefix('galaxy_');
        $container->set(ConnectionResolverInterface::class, new ConnectionResolver(['default' => $db]));
        try {
            $db->statement('CREATE TABLE galaxy_project (id INTEGER, org_id INTEGER, group_id INTEGER)');
            $db->statement('CREATE TABLE galaxy_project_route (id INTEGER, org_id INTEGER, group_id INTEGER, project_id INTEGER, cluster_id INTEGER)');
            $db->statement('CREATE TABLE galaxy_group_resource_grant (org_id INTEGER, group_id INTEGER, resource_type TEXT, resource_id INTEGER)');
            $db->statement('CREATE TABLE galaxy_waf_policy (id INTEGER, org_id INTEGER, cluster_id INTEGER, route_source TEXT, route_id INTEGER)');
            $db->statement('INSERT INTO galaxy_project VALUES (10,1,2), (11,1,2), (12,1,3)');
            $db->statement('INSERT INTO galaxy_project_route VALUES (7,1,2,10,1), (8,1,2,11,1), (9,1,3,12,1), (10,1,2,10,2)');
            $db->statement("INSERT INTO galaxy_group_resource_grant VALUES (1,2,'cluster',1)");
            $db->statement("INSERT INTO galaxy_waf_policy VALUES (1,1,1,'project',7), (2,1,1,'project',8), (3,1,1,'gateway',7), (4,2,1,'project',7), (5,1,2,'project',7)");
            Context::set('project_id', 10); Context::set('group_id', 2);
            $scope = (new \ReflectionClass(ProjectSwarmScope::class))->newInstanceWithoutConstructor();
            $cluster = new Cluster(['org_id' => 1]); $cluster->id = 1;
            self::assertSame([7], $ids = $scope->wafRouteIds($cluster));
            self::assertSame([1], WafPolicy::forRoutes($cluster, $ids)->pluck('id')->all());
            self::assertSame([], WafPolicy::forRoutes($cluster, [])->pluck('id')->all());
            $management = (new \ReflectionClass(WafManagementService::class))->newInstanceWithoutConstructor();
            $management->assertRouteScope('project', 7, $ids);
            foreach ([['project', 8], ['gateway', 7], ['project', 9]] as [$source, $id]) {
                try { $management->assertRouteScope($source, $id, $ids); self::fail('Out-of-project route accepted'); }
                catch (AppException $e) { self::assertSame(404, $e->getCode()); }
            }
            Context::set('project_id', 12);
            try { $scope->wafRouteIds($cluster); self::fail('Project/group mismatch accepted'); }
            catch (AppException $e) { self::assertSame(404, $e->getCode()); }
            Context::set('project_id', 10);
            $cluster->id = 2;
            try { $scope->wafRouteIds($cluster); self::fail('Ungranted cluster accepted'); }
            catch (AppException $e) { self::assertSame(403, $e->getCode()); }
            Context::set('project_id', 0);
            try { $scope->wafRouteIds($cluster); self::fail('Group-only scope must not become cluster scope'); }
            catch (AppException $e) { self::assertSame(403, $e->getCode()); }
            Context::set('group_id', 0);
            self::assertNull($scope->wafRouteIds($cluster));
        } finally {
            $container->set(ConnectionResolverInterface::class, $oldResolver);
            Context::set('project_id', $oldProject); Context::set('group_id', $oldGroup);
        }
    }
}
