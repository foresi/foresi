<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Country\Integration\TaxesAndContributions\CzechRepublic;

use Foresi\TaxesAndContributions\Contract\CreditLine;
use Foresi\TaxesAndContributions\Contract\ExpenseMethod;
use Foresi\TaxesAndContributions\Contract\RegimeInput;
use Foresi\TaxesAndContributions\Contract\RuleRef;
use Foresi\TaxesAndContributions\Contract\YearInputsView;

/**
 * One expense method of one year on its way through the pipeline: what the
 * steps read and what they leave for the next, in minor units of CZK, and the
 * rule values they used.
 */
final class Calculation
{
    public const string UNVERIFIED_MIXED_YEAR = 'mixed_year';
    public const string UNVERIFIED_STARTING_MINIMUM = 'starting_minimum';
    public const string WARNING_DEDUCTION_NOT_COMPUTED = 'deduction_not_computed';

    public int $income = 0;
    public int $costs = 0;
    public int $expenses = 0;
    public int $profit = 0;
    public int $socialBase = 0;
    public int $social = 0;
    public int $health = 0;
    public int $sickness = 0;
    public int $taxBase = 0;
    public int $tax = 0;
    public int $taxAfterCredits = 0;
    public int $incomeTax = 0;
    public int $childBonus = 0;

    /** @var list<CreditLine> */
    public array $credits = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var list<string> */
    public array $unverified = [];

    /** @var array<string, RuleValue> */
    private array $used = [];

    /**
     * @param list<Month> $months January to the month of the cut-off
     */
    public function __construct(
        public readonly RegimeInput $input,
        public readonly YearInputsView $inputs,
        public readonly ExpenseMethod $method,
        public readonly array $months,
        private readonly CzechRuleBook $rules,
    ) {
    }

    /**
     * The value of a rule on a day, the first day of the year when none is
     * given, remembered as used.
     */
    public function rule(string $key, ?\DateTimeImmutable $day = null): RuleValue
    {
        $value = $this->rules->valueOn($key, $day ?? $this->yearStart());
        $this->used[$value->key.'@'.$value->validFrom->format('Y-m-d')] = $value;

        return $value;
    }

    public function has(string $key): bool
    {
        return $this->rules->has($key, $this->yearStart());
    }

    public function yearStart(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(\sprintf('%04d-01-01', $this->input->year));
    }

    /**
     * @return list<Month>
     */
    public function monthsOf(string $activity): array
    {
        return array_values(array_filter($this->months, static fn (Month $month): bool => $activity === $month->activity));
    }

    /**
     * Whether the starting minimum holds: the activity started this year or
     * in the two years before.
     */
    public function isStarting(): bool
    {
        $start = $this->inputs->activityStart;

        return $start instanceof \DateTimeImmutable && (int) $start->format('Y') >= $this->input->year - 2 && (int) $start->format('Y') <= $this->input->year;
    }

    public function flag(string $unverified): void
    {
        if (!\in_array($unverified, $this->unverified, true)) {
            $this->unverified[] = $unverified;
        }
    }

    public function warn(string $warning): void
    {
        if (!\in_array($warning, $this->warnings, true)) {
            $this->warnings[] = $warning;
        }
    }

    /**
     * @return array<string, RuleValue>
     */
    public function used(): array
    {
        return $this->used;
    }

    /**
     * @return list<RuleRef>
     */
    public function usedRefs(): array
    {
        return array_values(array_map(static fn (RuleValue $value): RuleRef => $value->ref(), $this->used));
    }
}
