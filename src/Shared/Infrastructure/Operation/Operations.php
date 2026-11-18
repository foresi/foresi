<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Operation;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Every declared operation of the installed modules, read from their handlers
 * (ADR 0032). Names, tools and method-and-path pairs are unique.
 */
final class Operations
{
    /** @var array<string, OperationDefinition>|null by name */
    private ?array $definitions = null;

    /**
     * @param iterable<OperationHandler> $handlers
     */
    public function __construct(
        #[AutowireIterator(OperationHandler::TAG)]
        private readonly iterable $handlers,
    ) {
    }

    /**
     * @return list<OperationDefinition> by name
     */
    public function all(): array
    {
        return array_values($this->definitions());
    }

    /**
     * @throws \LogicException for a name no operation has
     */
    public function get(string $name): OperationDefinition
    {
        return $this->definitions()[$name] ?? throw new \LogicException(\sprintf('No operation is named %s.', $name));
    }

    /**
     * @return array<string, OperationDefinition>
     */
    private function definitions(): array
    {
        if (null !== $this->definitions) {
            return $this->definitions;
        }
        $definitions = [];
        $tools = [];
        $routes = [];
        foreach ($this->handlers as $handler) {
            $definition = OperationDefinition::of($handler);
            $operation = $definition->operation;
            $route = $operation->method.' '.$operation->path;
            foreach ([[$definitions, $operation->name], [$tools, $operation->tool], [$routes, $route]] as [$seen, $id]) {
                if (isset($seen[$id])) {
                    throw new \LogicException(\sprintf('Two operations are declared as %s.', $id));
                }
            }
            $definitions[$operation->name] = $definition;
            $tools[$operation->tool] = true;
            $routes[$route] = true;
        }
        ksort($definitions, \SORT_STRING);

        return $this->definitions = $definitions;
    }
}
