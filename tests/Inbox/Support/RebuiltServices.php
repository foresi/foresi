<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Support;

/**
 * A service of the container built again with one of its constructor arguments swapped, such as
 * its logger or a collaborator the test controls; the container keeps its own.
 */
trait RebuiltServices
{
    /**
     * @template T of object
     *
     * @param T                    $service
     * @param array<string, mixed> $replace constructor arguments by name
     *
     * @return T
     */
    private function rebuilt(object $service, array $replace): object
    {
        $class = new \ReflectionClass($service);
        $arguments = [];
        foreach ($class->getConstructor()?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();
            $arguments[$name] = \array_key_exists($name, $replace) ? $replace[$name] : $class->getProperty($name)->getValue($service);
        }

        return $class->newInstanceArgs($arguments);
    }
}
