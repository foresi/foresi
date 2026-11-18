<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Operation;

use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * A route for every declared operation, to OperationController (imported in
 * config/routes.yaml with the type "operations"). The route name is
 * api_v1_operation_ and the operation's name.
 */
#[AutoconfigureTag('routing.loader')]
final class OperationRoutes extends Loader
{
    public const string TYPE = 'operations';

    public function __construct(private readonly Operations $operations)
    {
        parent::__construct();
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $routes = new RouteCollection();
        foreach ($this->operations->all() as $definition) {
            $operation = $definition->operation;
            $routes->add($definition->route(), new Route(
                $operation->path,
                ['_controller' => OperationController::class, OperationController::ATTRIBUTE => $operation->name],
                methods: [$operation->method],
            ));
        }

        return $routes;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return self::TYPE === $type;
    }
}
