<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mcp;

use App\Shared\Infrastructure\Api\Cursor;
use App\Shared\Infrastructure\Api\InvalidParameter;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * What the read tools share: reading their arguments, the schema of the common
 * ones and the answer of a search. The SDK checks the arguments against the
 * schema of a tool before the call; the readers check the type once more, for a
 * call that does not come through it. A search builds the query object of its
 * REST list from the arguments and has it validated here, so a filter means the
 * same on both surfaces. Whatever does not fit is refused as an invalid
 * argument, named as the model sends it.
 */
final readonly class ToolArguments
{
    public const int DEFAULT_LIMIT = 25;
    public const int MAX_LIMIT = 100;

    public function __construct(private ValidatorInterface $validator)
    {
    }

    /**
     * @throws ToolRefusal for the first argument that breaks a constraint of the query object
     */
    public function validate(object $query): void
    {
        $violations = $this->validator->validate($query);
        if (0 === \count($violations)) {
            return;
        }
        $violation = $violations->get(0);
        // The message of the violation is in the locale of the application; the
        // template is the English one, which the model reads best.
        $message = strtr($violation->getMessageTemplate(), array_map(static fn (mixed $value): string => \is_scalar($value) ? (string) $value : '', $violation->getParameters()));

        throw ToolRefusal::invalidArgument(\sprintf('The argument "%s" cannot be used: %s', $this->name($violation->getPropertyPath()), $message));
    }

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
     * @return string|null the id to list after; null for the first page
     *
     * @throws ToolRefusal for a cursor no search gave
     */
    public static function afterId(?string $cursor): ?string
    {
        try {
            return Cursor::decode($cursor);
        } catch (InvalidParameter) {
            throw ToolRefusal::invalidArgument('The argument "cursor" is not one a search gave. Pass next_cursor of the previous answer unchanged, or leave cursor out for the first page.');
        }
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

    /**
     * The answer of a search, {items, next_cursor}, with a summary that says how
     * many it holds and whether more match.
     *
     * @param list<array<string, mixed>> $items
     * @param string|null                $afterId the id the next page starts after; null when nothing follows
     * @param string                     $one     the noun of one item, such as "invoice"
     * @param string                     $many    the noun of more, such as "invoices"
     */
    public static function page(array $items, ?string $afterId, string $one, string $many): ToolOutput
    {
        $next = Cursor::next($afterId);
        $count = \count($items);
        if (0 === $count) {
            $summary = \sprintf('No %s match.', $many);
        } else {
            $summary = \sprintf('%d %s, newest first; ', $count, 1 === $count ? $one : $many)
                .(null === $next ? 'no more match.' : 'more match: call again with the same arguments and next_cursor as cursor.');
        }

        return new ToolOutput(['items' => $items, 'next_cursor' => $next], $summary);
    }

    /**
     * The name of an argument as the model sends it: firmId is firm_id.
     */
    private function name(string $propertyPath): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $propertyPath));
    }
}
