<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Timesheet\Domain;

use App\Invoicing\Domain\Quantity;
use App\Shared\Domain\Money;

/**
 * One line of an invoice of time: the entries it bills, all at one rate, and
 * what names it. Its quantity is the hours billed, to two decimals.
 */
final readonly class TimeLine
{
    public const string SEPARATOR = ' – ';

    /**
     * @param list<string>     $names   what the line is of, the project first
     * @param list<BilledTime> $entries
     * @param ?BillableTime    $entry   the entry a line of one entry is, named with its day and note
     */
    public function __construct(
        public string $key,
        public array $names,
        public Money $rate,
        public array $entries,
        public ?BillableTime $entry = null,
    ) {
    }

    /**
     * "Website – Design the homepage"; a line of one entry adds its day and its note.
     *
     * @param string $dateFormat the format of a day, as DateTimeInterface::format takes it
     */
    public function text(string $dateFormat): string
    {
        $parts = $this->names;
        if ($this->entry instanceof BillableTime) {
            $parts[] = $this->entry->workedOn->format($dateFormat);
            if ('' !== $this->entry->note) {
                $parts[] = $this->entry->note;
            }
        }

        return implode(self::SEPARATOR, $parts);
    }

    /**
     * The minutes billed: those of each entry, rounded first.
     */
    public function minutes(): int
    {
        return array_sum(array_map(static fn (BilledTime $billed): int => $billed->minutes, $this->entries));
    }

    /**
     * The hours, rounded to a hundredth. A minute is 5/3 of a hundredth, so the
     * hundredths of whole minutes end in a third or two and never in a half:
     * adding a third rounds two thirds up and one down.
     */
    public function hours(): Quantity
    {
        return Quantity::ofTenThousandths(intdiv($this->minutes() * 5 + 1, 3) * 100);
    }

    /**
     * What the line comes to before tax: the rate times its hours.
     */
    public function amount(): Money
    {
        return $this->rate->multipliedBy($this->hours()->toDecimal());
    }
}
