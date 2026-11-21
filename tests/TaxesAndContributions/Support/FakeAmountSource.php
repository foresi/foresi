<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\TaxesAndContributions\Support;

use Foresi\TaxesAndContributions\Contract\ActualAmount;
use Foresi\TaxesAndContributions\Contract\ActualAmountSource;
use Foresi\TaxesAndContributions\Contract\VatShare;

/**
 * A source that pays what the test gives it, and remembers what it was asked.
 */
final class FakeAmountSource implements ActualAmountSource
{
    /** @var list<array{\DateTimeImmutable, \DateTimeImmutable}> */
    public array $asked = [];

    /** @var list<list<string>> */
    public array $sharesAsked = [];

    /**
     * @param list<ActualAmount>      $amounts
     * @param array<string, VatShare> $shares  the ratio of each open document it knows
     */
    public function __construct(private readonly string $key, private readonly array $amounts, private readonly bool $details = true, private readonly bool $fails = false, private readonly array $shares = [])
    {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function mayReadDetails(): bool
    {
        return $this->details;
    }

    public function paidBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): iterable
    {
        $this->asked[] = [$from, $to];
        if ($this->fails) {
            throw new \RuntimeException('The source is down.');
        }

        return $this->amounts;
    }

    public function sharesOf(array $subjectIds): array
    {
        $this->sharesAsked[] = $subjectIds;

        return array_intersect_key($this->shares, array_flip($subjectIds));
    }
}
