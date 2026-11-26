<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Mcp;

use Foresi\Shared\Contract\Mcp\ToolOutput;
use Foresi\Shared\Contract\Mcp\ToolRefusal;
use Foresi\Shared\Infrastructure\Api\Cursor;
use Foresi\Shared\Infrastructure\Api\InvalidParameter;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * What the read tools share beyond ToolSchema: the cursor and the answer of a
 * search, and the check of a query object. A search builds the query object of its
 * REST list from the arguments and has it validated here, so a filter means the
 * same on both surfaces. Whatever does not fit is refused as an invalid
 * argument, named as the model sends it.
 */
final readonly class ToolArguments
{
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
     * The name of an argument as the model sends it: customerId is customer_id.
     */
    private function name(string $propertyPath): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $propertyPath));
    }
}
