<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Domain;

use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Contract\ChildInput;
use Foresi\TaxesAndContributions\Contract\DeductionType;
use Foresi\TaxesAndContributions\Contract\ExpenseMethod;
use Foresi\TaxesAndContributions\Contract\ReturnRoute;
use Foresi\TaxesAndContributions\Contract\SpouseInput;
use Foresi\TaxesAndContributions\Contract\YearInputsView;

/**
 * The inputs of one year as the profile stores them: amounts in minor units
 * of CZK and days written as 2026-11-12. A child and the spouse are claimed
 * for 0 to 12 months, the chosen expense method is one of those compared, and
 * no amount is negative.
 */
final readonly class YearInputs
{
    /**
     * @param array<string, mixed> $data
     */
    private function __construct(private array $data)
    {
    }

    public static function of(YearInputsView $view): self
    {
        if ($view->chosenMethod instanceof ExpenseMethod && !\in_array($view->chosenMethod, $view->expenseMethods, true)) {
            throw new \InvalidArgumentException('The chosen expense method is one of those compared.');
        }
        foreach ($view->children as $child) {
            self::months($child->months);
            if ($child->order < 1) {
                throw new \InvalidArgumentException('The order of a child starts at 1.');
            }
        }
        if ($view->spouse instanceof SpouseInput) {
            self::months($view->spouse->months);
        }
        $deductions = [];
        foreach ($view->deductions as $type => $amount) {
            $deductions[DeductionType::from($type)->value] = self::minor($amount);
        }
        $amounts = [
            'employment_base' => $view->employmentBase,
            'employment_tax_withheld' => $view->employmentTaxWithheld,
            'rental_base' => $view->rentalBase,
            'other_base' => $view->otherBase,
            'last_known_tax' => $view->lastKnownTax,
            'previous_social_base' => $view->previousSocialBase,
            'previous_health_base' => $view->previousHealthBase,
            'average_wage_override' => $view->averageWageOverride,
        ];

        return new self([
            'year' => $view->year,
            'expense_methods' => array_values(array_unique(array_map(static fn (ExpenseMethod $method): string => $method->value, $view->expenseMethods))),
            'chosen_method' => $view->chosenMethod?->value,
            'children' => array_map(static fn (ChildInput $child): array => [
                'label' => $child->label,
                'birth_date' => $child->birthDate->format('Y-m-d'),
                'order' => $child->order,
                'ztp_p' => $child->ztpP,
                'months' => $child->months,
            ], $view->children),
            'spouse' => $view->spouse instanceof SpouseInput ? ['months' => $view->spouse->months, 'ztp_p' => $view->spouse->ztpP, 'low_income' => $view->spouse->lowIncome] : null,
            'deductions' => $deductions,
            'previous_overview_filed_on' => $view->previousOverviewFiledOn?->format('Y-m-d'),
            'return_route' => $view->returnRoute->value,
            'activity_start' => $view->activityStart?->format('Y-m-d'),
        ] + array_map(static fn (?Money $amount): ?int => $amount instanceof Money ? self::minor($amount) : null, $amounts));
    }

    /**
     * @param array<mixed> $data
     */
    public static function stored(array $data): self
    {
        $named = [];
        foreach ($data as $key => $value) {
            $named[(string) $key] = $value;
        }

        return new self($named);
    }

    public function year(): int
    {
        return Stored::int($this->data, 'year');
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    public function view(): YearInputsView
    {
        $data = $this->data;
        $methods = array_map(static fn (mixed $method): ExpenseMethod => ExpenseMethod::from(\is_string($method) ? $method : ''), \is_array($data['expense_methods'] ?? null) ? array_values($data['expense_methods']) : []);
        $chosen = $data['chosen_method'] ?? null;
        $spouse = \is_array($data['spouse'] ?? null) ? $data['spouse'] : null;
        $deductions = [];
        foreach (\is_array($data['deductions'] ?? null) ? $data['deductions'] : [] as $type => $minor) {
            if (\is_string($type) && \is_int($minor)) {
                $deductions[$type] = Money::ofMinor($minor, Currency::CZK);
            }
        }
        $route = $data['return_route'] ?? null;

        return new YearInputsView(
            $this->year(),
            [] === $methods ? [ExpenseMethod::Actual, ExpenseMethod::Lump60] : $methods,
            \is_string($chosen) ? ExpenseMethod::from($chosen) : null,
            array_map(static fn (array $child): ChildInput => new ChildInput(
                Stored::string($child, 'label'),
                Stored::day($child, 'birth_date') ?? throw new \UnexpectedValueException('A stored child has no birth date.'),
                Stored::int($child, 'order'),
                Stored::bool($child, 'ztp_p'),
                Stored::int($child, 'months'),
            ), Stored::list($data, 'children')),
            null === $spouse ? null : new SpouseInput(Stored::int($spouse, 'months'), Stored::bool($spouse, 'ztp_p'), Stored::bool($spouse, 'low_income')),
            $deductions,
            Stored::money($data, 'employment_base'),
            Stored::money($data, 'employment_tax_withheld'),
            Stored::money($data, 'rental_base'),
            Stored::money($data, 'other_base'),
            Stored::money($data, 'last_known_tax'),
            Stored::money($data, 'previous_social_base'),
            Stored::money($data, 'previous_health_base'),
            Stored::day($data, 'previous_overview_filed_on'),
            \is_string($route) ? ReturnRoute::from($route) : ReturnRoute::Electronic,
            Stored::day($data, 'activity_start'),
            Stored::money($data, 'average_wage_override'),
        );
    }

    private static function months(int $months): void
    {
        if ($months < 0 || $months > 12) {
            throw new \InvalidArgumentException('A child or the spouse is claimed for 0 to 12 months.');
        }
    }

    private static function minor(Money $amount): int
    {
        if (Currency::CZK !== $amount->currency() || $amount->isNegative()) {
            throw new \InvalidArgumentException('An amount of the profile is not negative and in CZK.');
        }

        return $amount->minor();
    }
}
