<?php

declare(strict_types=1);
/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace HyperfTest\Cases;

use App\Support\Git;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class GitTest extends TestCase
{
    public function testGetRemoteBranches()
    {
        $branches = Git::getRemoteBranches(BASE_PATH);
        $this->assertIsArray($branches);
        $this->assertNotEmpty($branches);
        $this->assertTrue(in_array('master', $branches));
    }

    public function testGetTags()
    {
        $tags = Git::getTags(BASE_PATH);
        $this->assertIsArray($tags);
    }

    public function testGetCommitId()
    {
        $commitIds = Git::getCommitId(BASE_PATH, 5);
        $this->assertIsArray($commitIds);
        $this->assertNotEmpty($commitIds);
        $this->assertCount(5, $commitIds);
    }
}
