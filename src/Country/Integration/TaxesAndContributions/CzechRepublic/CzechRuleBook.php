<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Country\Integration\TaxesAndContributions\CzechRepublic;

use Foresi\TaxesAndContributions\Contract\RulesNotAvailable;
use Foresi\TaxesAndContributions\Contract\RulesStatus;

/**
 * The Czech rule values by day (Goal 7): a value holds from its day until the
 * next value of its key, so a rule that changes in the middle of a year, such
 * as the minimum social base on 1 July 2026, is two rows. A year after the
 * last one with rules uses the latest values and says so.
 */
final class CzechRuleBook
{
    public const int FIRST_YEAR = Rules2026::YEAR;
    public const int LAST_YEAR = Rules2027::YEAR;

    /** @var list<RuleValue>|null oldest first */
    private ?array $values = null;

    /**
     * @throws RulesNotAvailable     for a day before the first year
     * @throws \OutOfBoundsException for a key that has no value on the day
     */
    public function valueOn(string $key, \DateTimeImmutable $day): RuleValue
    {
        $year = (int) $day->format('Y');
        if ($year < self::FIRST_YEAR) {
            throw RulesNotAvailable::forYear($year, self::FIRST_YEAR);
        }
        $on = $day->format('Y-m-d');
        $found = null;
        foreach ($this->values() as $value) {
            if ($key === $value->key && $value->validFrom->format('Y-m-d') <= $on) {
                $found = $value;
            }
        }

        return $found ?? throw new \OutOfBoundsException(\sprintf('No Czech rule %s on %s.', $key, $on));
    }

    /**
     * Whether a key has a value on the day, such as a credit that starts in 2027.
     */
    public function has(string $key, \DateTimeImmutable $day): bool
    {
        try {
            $this->valueOn($key, $day);
        } catch (\OutOfBoundsException) {
            return false;
        }

        return true;
    }

    /**
     * The year whose rules a year uses: itself, or the latest year with rules.
     *
     * @throws RulesNotAvailable for a year before the first one
     */
    public function ruleYear(int $year): int
    {
        if ($year < self::FIRST_YEAR) {
            throw RulesNotAvailable::forYear($year, self::FIRST_YEAR);
        }

        return min($year, self::LAST_YEAR);
    }

    public function statusOf(int $year): RulesStatus
    {
        return match (true) {
            $year > self::LAST_YEAR => RulesStatus::LatestUsed,
            Rules2027::YEAR === $year => RulesStatus::Derived,
            default => RulesStatus::Published,
        };
    }

    /**
     * @return list<RuleValue>
     */
    private function values(): array
    {
        if (null === $this->values) {
            $values = [...Rules2026::values(), ...Rules2027::values()];
            usort($values, static fn (RuleValue $a, RuleValue $b): int => $a->validFrom <=> $b->validFrom);
            $this->values = $values;
        }

        return $this->values;
    }
}
