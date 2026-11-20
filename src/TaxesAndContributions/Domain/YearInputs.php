<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Domain;

use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Contract\ChildInput;
use Foresi\TaxesAndContributions\Contract\DeductionType;
use Foresi\TaxesAndContributions\Contract\ExpenseMethod;
use Foresi\TaxesAndContributions\Contract\ReturnRoute;
use Foresi\TaxesAndContributions\Contract\SpouseInput;
use Foresi\TaxesAndContributions\Contract\YearInputsView;

/**
 * The inputs of one year as the profile stores them: amounts in minor units
 * of CZK and days written as 2026-11-12. ProfileWrites checks them before
 * they get here.
 */
final readonly class YearInputs
{
    /** The amounts of a year, by the key they are stored under. */
    private const array AMOUNTS = ['employment_base', 'employment_tax_withheld', 'rental_base', 'other_base', 'last_known_tax', 'previous_social_base', 'previous_health_base', 'average_wage_override'];

    /**
     * @param array<mixed> $data
     */
    private function __construct(private array $data)
    {
    }

    public static function of(YearInputsView $view): self
    {
        $deductions = [];
        foreach ($view->deductions as $type => $amount) {
            $deductions[$type] = $amount->minor();
        }
        $amounts = array_combine(self::AMOUNTS, array_map(static fn (?Money $amount): ?int => $amount?->minor(), [
            $view->employmentBase,
            $view->employmentTaxWithheld,
            $view->rentalBase,
            $view->otherBase,
            $view->lastKnownTax,
            $view->previousSocialBase,
            $view->previousHealthBase,
            $view->averageWageOverride,
        ]));

        return new self([
            'year' => $view->year,
            'expense_methods' => array_map(static fn (ExpenseMethod $method): string => $method->value, $view->expenseMethods),
            'chosen_method' => $view->chosenMethod?->value,
            'children' => array_map(static fn (ChildInput $child): array => [
                'label' => $child->label,
                'birth_date' => $child->birthDate->format('Y-m-d'),
                'order' => $child->orderChosen ? $child->order : null,
                'ztp_p' => $child->ztpP,
                'months' => $child->months,
            ], $view->children),
            'spouse' => $view->spouse instanceof SpouseInput ? ['months' => $view->spouse->months, 'ztp_p' => $view->spouse->ztpP, 'low_income' => $view->spouse->lowIncome] : null,
            'deductions' => $deductions,
            'previous_overview_filed_on' => $view->previousOverviewFiledOn?->format('Y-m-d'),
            'return_route' => $view->returnRoute->value,
            'activity_start' => $view->activityStart?->format('Y-m-d'),
        ] + $amounts);
    }

    /**
     * @param array<mixed> $data
     */
    public static function stored(array $data): self
    {
        return new self($data);
    }

    public function year(): int
    {
        return Stored::int($this->data, 'year');
    }

    /**
     * @return array<mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    public function view(): YearInputsView
    {
        $data = $this->data;
        $spouse = $data['spouse'] ?? null;
        $stored = \is_array($data['deductions'] ?? null) ? $data['deductions'] : [];
        $deductions = [];
        foreach (DeductionType::cases() as $type) {
            $amount = Stored::money($stored, $type->value);
            if ($amount instanceof Money) {
                $deductions[$type->value] = $amount;
            }
        }
        $methods = array_map(ExpenseMethod::from(...), Stored::strings($data, 'expense_methods'));
        $chosen = $data['chosen_method'] ?? null;

        return new YearInputsView(
            $this->year(),
            [] === $methods ? throw new \UnexpectedValueException('A stored year compares no expense method.') : $methods,
            \is_string($chosen) ? ExpenseMethod::from($chosen) : null,
            $this->children(Stored::list($data, 'children')),
            \is_array($spouse) ? new SpouseInput(Stored::int($spouse, 'months'), Stored::bool($spouse, 'ztp_p'), Stored::bool($spouse, 'low_income')) : null,
            $deductions,
            Stored::money($data, 'employment_base'),
            Stored::money($data, 'employment_tax_withheld'),
            Stored::money($data, 'rental_base'),
            Stored::money($data, 'other_base'),
            Stored::money($data, 'last_known_tax'),
            Stored::money($data, 'previous_social_base'),
            Stored::money($data, 'previous_health_base'),
            Stored::day($data, 'previous_overview_filed_on'),
            ReturnRoute::from(Stored::string($data, 'return_route')),
            Stored::day($data, 'activity_start'),
            Stored::money($data, 'average_wage_override'),
        );
    }

    /**
     * The stored children by birth date, each with the order it takes. A child
     * stored before the order followed the birth dates has an order of its
     * own; when it is the one the birth date gives, it follows them again.
     *
     * @param list<array<mixed>> $stored
     *
     * @return list<ChildInput>
     */
    private function children(array $stored): array
    {
        $births = array_map(static fn (array $child): \DateTimeImmutable => Stored::day($child, 'birth_date') ?? throw new \UnexpectedValueException('A stored child has no birth date.'), $stored);
        $fixed = array_map(static fn (array $child): ?int => \is_int($child['order'] ?? null) ? $child['order'] : null, $stored);

        return array_map(static fn (array $place): ChildInput => new ChildInput(
            Stored::string($stored[$place['key']], 'label'),
            $births[$place['key']],
            $place['order'],
            Stored::bool($stored[$place['key']], 'ztp_p'),
            Stored::int($stored[$place['key']], 'months'),
            $place['chosen'],
        ), ChildOrder::of($births, $fixed));
    }
}
