<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Contract\Mcp;

/**
 * What the tools of every module share to describe and read their arguments:
 * the JSON Schema of the common ones and the readers of plain values. The SDK
 * checks the arguments against the schema of a tool before the call; the
 * readers check the type once more, for a call that does not come through it.
 * Whatever does not fit is refused as an invalid argument, named as the model
 * sends it.
 */
final class ToolSchema
{
    public const int DEFAULT_LIMIT = 25;
    public const int MAX_LIMIT = 100;

    /**
     * @param array<string, mixed> $arguments
     *
     * @throws ToolRefusal for a value that is no string
     */
    public static function text(array $arguments, string $name): ?string
    {
        $value = $arguments[$name] ?? null;
        if (null !== $value && !\is_string($value)) {
            throw ToolRefusal::invalidArgument(\sprintf('The argument "%s" must be a string.', $name));
        }

        return $value;
    }

    /**
     * False when left out.
     *
     * @param array<string, mixed> $arguments
     *
     * @throws ToolRefusal for a value that is no boolean
     */
    public static function flag(array $arguments, string $name): bool
    {
        $value = $arguments[$name] ?? false;
        if (!\is_bool($value)) {
            throw ToolRefusal::invalidArgument(\sprintf('The argument "%s" must be true or false.', $name));
        }

        return $value;
    }

    /**
     * DEFAULT_LIMIT when left out. The query object checks the range.
     *
     * @param array<string, mixed> $arguments
     *
     * @throws ToolRefusal for a value that is no integer
     */
    public static function limit(array $arguments): int
    {
        $value = $arguments['limit'] ?? self::DEFAULT_LIMIT;
        if (!\is_int($value)) {
            throw ToolRefusal::invalidArgument('The argument "limit" must be an integer.');
        }

        return $value;
    }

    /**
     * The JSON Schema of the limit and the cursor, for the properties of a search.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function pagingSchema(): array
    {
        return [
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'default' => self::DEFAULT_LIMIT, 'description' => 'How many to return at most.'],
            'cursor' => ['type' => 'string', 'description' => 'The next_cursor of the previous answer, to get the page after it. Leave it out for the first page.'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function idSchema(string $description): array
    {
        return ['type' => 'string', 'format' => 'uuid', 'description' => $description];
    }

    /**
     * @return array<string, string>
     */
    public static function dateSchema(string $description): array
    {
        return ['type' => 'string', 'format' => 'date', 'description' => $description.' As YYYY-MM-DD, the day included.'];
    }

    /**
     * @param list<string> $values
     *
     * @return array<string, mixed>
     */
    public static function choiceSchema(array $values, string $description): array
    {
        return ['type' => 'string', 'enum' => $values, 'description' => $description];
    }
}
