<?php

declare(strict_types=1);

namespace HyperfTest\Cases;

use App\Exception\AppException;
use App\Services\Project\ManagedSwarmResourceGuard;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ManagedSwarmResourceGuardTest extends TestCase
{
    public function testServiceOwnershipDoesNotDependOnLegacyManagedLabel(): void
    {
        $guard = new ManagedSwarmResourceGuard();

        $guard->assertService([
            'Spec' => [
                'Name' => 'cg-1-9-1-web',
                'Labels' => [
                    'com.codegalaxy.org.id' => '1',
                    'com.codegalaxy.project.id' => '9',
                    'com.codegalaxy.env.id' => '1',
                ],
            ],
        ], 1, 9, 1, 'cg-1-9-1-web');

        self::assertTrue(true);
    }

    public function testServiceOwnershipStillRejectsAnotherProject(): void
    {
        $guard = new ManagedSwarmResourceGuard();
        $this->expectException(AppException::class);

        $guard->assertService([
            'Spec' => [
                'Name' => 'cg-1-9-1-web',
                'Labels' => [
                    'com.codegalaxy.org.id' => '1',
                    'com.codegalaxy.project.id' => '10',
                    'com.codegalaxy.env.id' => '1',
                ],
            ],
        ], 1, 9, 1, 'cg-1-9-1-web');
    }
}
