<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\TaxesAndContributions\Support;

use Foresi\TaxesAndContributions\Contract\ActualAmount;
use Foresi\TaxesAndContributions\Contract\ActualAmountSource;
use Foresi\TaxesAndContributions\Contract\OpenAmount;

/**
 * A source that pays the amounts of the days asked only, and has open what
 * the test gives it for each day, remembering the days asked.
 */
final class DatedAmountSource implements ActualAmountSource
{
    /** @var list<string> */
    public array $openAsked = [];

    /**
     * @param list<ActualAmount>              $amounts
     * @param array<string, list<OpenAmount>> $open    by the day, as Y-m-d
     */
    public function __construct(private readonly string $key, private readonly array $amounts = [], private readonly array $open = [], private readonly bool $openFails = false)
    {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function mayReadDetails(): bool
    {
        return true;
    }

    public function paidBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): iterable
    {
        return array_values(array_filter($this->amounts, static fn (ActualAmount $amount): bool => $amount->paidOn->format('Y-m-d') >= $from->format('Y-m-d') && $amount->paidOn->format('Y-m-d') <= $to->format('Y-m-d')));
    }

    public function sharesOf(array $subjectIds): array
    {
        return [];
    }

    public function openAt(\DateTimeImmutable $day): iterable
    {
        $this->openAsked[] = $day->format('Y-m-d');
        if ($this->openFails) {
            throw new \RuntimeException('The open documents cannot be read.');
        }

        return $this->open[$day->format('Y-m-d')] ?? [];
    }
}
