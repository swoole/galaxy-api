<?php
/**
 * Initialize a dependency injection container that implemented PSR-11 and return the container.
 */

declare(strict_types=1);
/**
 * This file is part of CodeGalaxy.
 *
 * @link     https://www.swoole.com
 */
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Hyperf\Context\ApplicationContext;

$container = new Container((new DefinitionSourceFactory())());

if (! $container instanceof \Psr\Container\ContainerInterface) {
    throw new RuntimeException('The dependency injection container is invalid.');
}
return ApplicationContext::setContainer($container);
