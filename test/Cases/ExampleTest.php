<?php

declare(strict_types=1);
/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
namespace HyperfTest\Cases;

use HyperfTest\HttpTestCase;

/**
 * @internal
 * @coversNothing
 */
class ExampleTest extends HttpTestCase
{
    public function testExample()
    {
        $this->assertTrue(true);
        $this->assertTrue(is_array($this->get('/')));
    }
}
