<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Infrastructure\Api;

use Foresi\Shared\Domain\Money;
use Foresi\Shared\Infrastructure\Api\JsonValues;
use Foresi\TaxesAndContributions\Contract\AuthorityAccountView;
use Foresi\TaxesAndContributions\Contract\ChildInput;
use Foresi\TaxesAndContributions\Contract\ExpenseMethod;
use Foresi\TaxesAndContributions\Contract\ProfileView;
use Foresi\TaxesAndContributions\Contract\SpouseInput;
use Foresi\TaxesAndContributions\Contract\StatusPeriod;
use Foresi\TaxesAndContributions\Contract\YearInputsView;

/**
 * The owner profile as the API and MCP write it, in snake case, with amounts
 * as {amount_minor, currency} and days as 2026-11-12.
 */
final class TaxesJson
{
    /**
     * @return array<string, mixed>
     */
    public static function profile(ProfileView $profile): array
    {
        return [
            'statuses' => array_map(self::status(...), $profile->statuses),
            'years' => array_values(array_map(self::year(...), $profile->years)),
            'authorities' => array_map(self::authority(...), $profile->authorities),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function status(StatusPeriod $status): array
    {
        return [
            'id' => $status->id,
            'kind' => $status->kind->value,
            'value' => $status->value,
            'valid_from' => $status->validFrom->format('Y-m-d'),
            'base' => self::money($status->base),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function year(YearInputsView $year): array
    {
        $deductions = [];
        foreach ($year->deductions as $type => $amount) {
            $deductions[] = ['type' => $type, 'amount' => JsonValues::money($amount)];
        }

        return [
            'year' => $year->year,
            'expense_methods' => array_map(static fn (ExpenseMethod $method): string => $method->value, $year->expenseMethods),
            'chosen_method' => $year->chosenMethod?->value,
            'children' => array_map(static fn (ChildInput $child): array => [
                'label' => $child->label,
                'birth_date' => $child->birthDate->format('Y-m-d'),
                'order' => $child->order,
                'ztp_p' => $child->ztpP,
                'months' => $child->months,
            ], $year->children),
            'spouse' => $year->spouse instanceof SpouseInput ? ['months' => $year->spouse->months, 'ztp_p' => $year->spouse->ztpP, 'low_income' => $year->spouse->lowIncome] : null,
            'deductions' => $deductions,
            'other_bases' => [
                'employment' => self::money($year->employmentBase),
                'employment_tax_withheld' => self::money($year->employmentTaxWithheld),
                'rental' => self::money($year->rentalBase),
                'other' => self::money($year->otherBase),
            ],
            'last_known_tax' => self::money($year->lastKnownTax),
            'previous_year' => [
                'social_base' => self::money($year->previousSocialBase),
                'health_base' => self::money($year->previousHealthBase),
                'overview_filed_on' => $year->previousOverviewFiledOn?->format('Y-m-d'),
            ],
            'return_route' => $year->returnRoute->value,
            'activity_start' => $year->activityStart?->format('Y-m-d'),
            'average_wage_override' => self::money($year->averageWageOverride),
        ];
    }

    /**
     * @return array{authority: string, account: string, variable_symbol: string}
     */
    public static function authority(AuthorityAccountView $account): array
    {
        return ['authority' => $account->authority, 'account' => $account->account, 'variable_symbol' => $account->variableSymbol];
    }

    /**
     * @return array{amount_minor: int, currency: string}|null
     */
    private static function money(?Money $amount): ?array
    {
        return $amount instanceof Money ? JsonValues::money($amount) : null;
    }
}
