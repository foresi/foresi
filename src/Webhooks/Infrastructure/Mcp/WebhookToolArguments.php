<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Infrastructure\Mcp;

use App\Shared\Infrastructure\Mcp\ToolArguments;
use App\Shared\Infrastructure\Mcp\ToolRefusal;

/**
 * Reading the arguments the webhook tools share. The SDK has checked them
 * against the schema; these check the type once more, for a call that does not
 * come through it.
 */
final class WebhookToolArguments
{
    /**
     * @param array<string, mixed> $arguments
     *
     * @throws ToolRefusal when the id is missing or no string
     */
    public static function required(array $arguments, string $name): string
    {
        return ToolArguments::text($arguments, $name) ?? throw ToolRefusal::invalidArgument(\sprintf('The argument "%s" is required.', $name));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return list<string>|null null when left out
     *
     * @throws ToolRefusal for a value that is no list of strings
     */
    public static function eventTypes(array $arguments): ?array
    {
        if (!\array_key_exists('event_types', $arguments) || null === $arguments['event_types']) {
            return null;
        }
        $asked = $arguments['event_types'];
        if (!\is_array($asked) || !array_is_list($asked)) {
            throw ToolRefusal::invalidArgument('The argument "event_types" must be a list of strings.');
        }
        $types = [];
        foreach ($asked as $type) {
            $types[] = \is_string($type) ? $type : throw ToolRefusal::invalidArgument('The argument "event_types" must be a list of strings.');
        }

        return $types;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return int|null null when left out
     *
     * @throws ToolRefusal for a value that is no integer
     */
    public static function integer(array $arguments, string $name): ?int
    {
        $value = $arguments[$name] ?? null;
        if (null !== $value && !\is_int($value)) {
            throw ToolRefusal::invalidArgument(\sprintf('The argument "%s" must be an integer.', $name));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return bool|null null when left out
     *
     * @throws ToolRefusal for a value that is no boolean
     */
    public static function boolean(array $arguments, string $name): ?bool
    {
        $value = $arguments[$name] ?? null;
        if (null !== $value && !\is_bool($value)) {
            throw ToolRefusal::invalidArgument(\sprintf('The argument "%s" must be true or false.', $name));
        }

        return $value;
    }
}
