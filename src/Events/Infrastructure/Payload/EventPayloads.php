<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Events\Infrastructure\Payload;

use App\Events\Infrastructure\Catalogue\EventType;
use App\Shared\Domain\Event\DomainEventPayload;

/**
 * Turns the facts of a contract event into the JSON that is stored and
 * published, and back. The keys are the snake case names of the constructor
 * properties; a backed enum is stored as its value, a list as a list.
 */
final class EventPayloads
{
    /**
     * @return array<string, scalar|list<string>|null>
     */
    public function normalise(DomainEventPayload $event): array
    {
        $facts = [];
        foreach ($this->parameters($event::class) as $parameter) {
            $value = (new \ReflectionProperty($event, $parameter->getName()))->getValue($event);
            $facts[self::snake($parameter->getName())] = match (true) {
                $value instanceof \BackedEnum => $value->value,
                \is_array($value) => array_values(array_map(static fn (mixed $item): string => \is_string($item) ? $item : throw new \LogicException('A list fact holds strings.'), $value)),
                \is_scalar($value), null === $value => $value,
                default => throw new \LogicException(\sprintf('The fact "%s" of %s is not a scalar, a list of strings or an enum.', $parameter->getName(), $event::class)),
            };
        }

        return $facts;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function denormalise(EventType $type, array $data): DomainEventPayload
    {
        $arguments = [];
        foreach ($this->parameters($type->class) as $parameter) {
            $key = self::snake($parameter->getName());
            if (!\array_key_exists($key, $data)) {
                // A fact added in a later release is missing from older rows: its default, or null.
                $arguments[$parameter->getName()] = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;

                continue;
            }
            $value = $data[$key];
            $enum = $this->enumOf($parameter);
            $arguments[$parameter->getName()] = null !== $enum && (\is_int($value) || \is_string($value)) ? $enum::from($value) : $value;
        }

        return new ($type->class)(...$arguments);
    }

    /**
     * @param class-string $class
     *
     * @return list<\ReflectionParameter>
     */
    private function parameters(string $class): array
    {
        $constructor = (new \ReflectionClass($class))->getConstructor();

        return null === $constructor ? [] : array_values(array_filter($constructor->getParameters(), static fn (\ReflectionParameter $p): bool => $p->isPromoted()));
    }

    /**
     * @return class-string<\BackedEnum>|null
     */
    private function enumOf(\ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();
        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin() && is_subclass_of($type->getName(), \BackedEnum::class)) {
            return $type->getName();
        }

        return null;
    }

    private static function snake(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }
}
