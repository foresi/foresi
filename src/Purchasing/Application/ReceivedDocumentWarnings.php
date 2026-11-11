<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application;

/**
 * The warnings of a saved document, in a fixed order.
 *
 * @implements \IteratorAggregate<int, ReceivedDocumentWarning>
 */
final readonly class ReceivedDocumentWarnings implements \IteratorAggregate, \Countable
{
    /**
     * @param list<ReceivedDocumentWarning> $warnings
     */
    public function __construct(private array $warnings = [])
    {
    }

    /**
     * @return list<ReceivedDocumentWarning>
     */
    public function all(): array
    {
        return $this->warnings;
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_map(static fn (ReceivedDocumentWarning $warning): string => $warning->code, $this->warnings);
    }

    public function has(string $code): bool
    {
        return \in_array($code, $this->codes(), true);
    }

    public function get(string $code): ?ReceivedDocumentWarning
    {
        return array_find($this->warnings, static fn (ReceivedDocumentWarning $warning): bool => $warning->code === $code);
    }

    public function isEmpty(): bool
    {
        return [] === $this->warnings;
    }

    public function count(): int
    {
        return \count($this->warnings);
    }

    /**
     * @return \ArrayIterator<int, ReceivedDocumentWarning>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->warnings);
    }
}
