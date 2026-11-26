<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Application;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Party\Application\TransactionalWork;
use Foresi\Shared\Contract\Event\EventRecorder;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Contract\Authority;
use Foresi\TaxesAndContributions\Contract\AuthorityAccountView;
use Foresi\TaxesAndContributions\Contract\ChildInput;
use Foresi\TaxesAndContributions\Contract\DeductionType;
use Foresi\TaxesAndContributions\Contract\Event\ProfileChanged;
use Foresi\TaxesAndContributions\Contract\ExpenseMethod;
use Foresi\TaxesAndContributions\Contract\ReturnRoute;
use Foresi\TaxesAndContributions\Contract\SpouseInput;
use Foresi\TaxesAndContributions\Contract\StatusKind;
use Foresi\TaxesAndContributions\Contract\StatusPeriod as StatusPeriodView;
use Foresi\TaxesAndContributions\Contract\YearInputsView;
use Foresi\TaxesAndContributions\Domain\AuthorityAccount;
use Foresi\TaxesAndContributions\Domain\ChildOrder;
use Foresi\TaxesAndContributions\Domain\OwnerProfile;
use Foresi\TaxesAndContributions\Domain\StatusPeriod;
use Foresi\TaxesAndContributions\Domain\YearInputs;

/**
 * Changes to the owner profile over the screens, the API and MCP (ADR 0032):
 * a dated status added or removed, the inputs of a year and the account of an
 * authority set. Only the owner writes. Each change is stored in one unit of
 * work with its taxes-and-contributions.profile.changed event, which says what
 * changed and never a value.
 */
final readonly class ProfileWrites
{
    public const int FIRST_YEAR = 2000;
    public const int LAST_YEAR = 2099;
    public const int MAX_CHILDREN = 20;
    public const int MAX_LABEL = 100;

    public function __construct(
        private TaxesAccess $access,
        private TaxesStore $store,
        private TransactionalWork $transaction,
        private EventRecorder $events,
        private InstanceClock $clock,
    ) {
    }

    /**
     * @throws TaxesRefused
     */
    public function addStatus(AddStatusInput $input): StatusPeriodView
    {
        $this->access->denyUnlessOwner();
        $kind = StatusKind::tryFrom(trim($input->kind)) ?? throw TaxesRefused::invalid('kind_invalid', 'kind', 'A kind is legal_form, activity, flat_rate_band, sickness_insurance or health_minimum_exempt.');
        if (!\in_array($kind, StatusKind::writable(), true)) {
            throw $this->vatPayerOnFirm('kind');
        }
        $value = trim($input->value);
        if (!\in_array($value, $kind->values(), true)) {
            throw TaxesRefused::invalid('value_invalid', 'value', \sprintf('A status of the kind %s is %s.', $kind->value, implode(', ', $kind->values())));
        }
        $from = $this->day($input->validFrom, 'validFrom');
        $base = null === $input->base || '' === trim($input->base) ? null : $this->money($input->base, 'base');
        if ($kind->needsBase($value) && !$base instanceof Money) {
            throw TaxesRefused::invalid('base_required', 'base', 'Sickness insurance that is on needs its monthly base in CZK.');
        }
        if (!$kind->needsBase($value) && $base instanceof Money) {
            throw TaxesRefused::invalid('base_not_allowed', 'base', 'Only sickness insurance that is on has a base.');
        }
        if ($base instanceof Money && $base->isZero()) {
            throw TaxesRefused::invalid('amount_invalid', 'base', 'A base is more than zero.');
        }

        return $this->transaction->run(function () use ($kind, $value, $from, $base): StatusPeriodView {
            if ($this->store->statusStarting($kind, $from) instanceof StatusPeriod) {
                throw TaxesRefused::conflict('status_day_taken', 'A status of this kind already starts on this day. Remove it first with foresi_remove_tax_status, then add the new one.');
            }
            $status = new StatusPeriod($this->profile(), $kind, $value, $from, $base);
            $this->store->add($status);
            $this->events->record(new ProfileChanged(ProfileChanged::STATUS, $kind->value, null, $from->format('Y-m-d')));

            return $status->view();
        });
    }

    /**
     * @throws TaxesRefused
     */
    public function removeStatus(RemoveStatusInput $input): string
    {
        $this->access->denyUnlessOwner();

        if (str_starts_with(trim($input->statusId), ProfileReads::FIRM_VAT)) {
            throw $this->vatPayerOnFirm('statusId');
        }

        return $this->transaction->run(function () use ($input): string {
            $status = $this->store->status(trim($input->statusId)) ?? throw TaxesRefused::notFound('The owner profile has no status with this id. Find it in statuses of foresi_get_tax_profile.');
            $id = $status->id();
            $this->store->remove($status);
            $this->events->record(new ProfileChanged(ProfileChanged::STATUS, $status->kind()->value, null, $status->validFrom()->format('Y-m-d')));

            return $id;
        });
    }

    /**
     * @throws TaxesRefused
     */
    public function setYear(SetYearInputsInput $input): YearInputsView
    {
        $this->access->denyUnlessOwner();
        $view = $this->yearView($input);

        return $this->transaction->run(function () use ($view): YearInputsView {
            $inputs = YearInputs::of($view);
            $this->profile()->setYear($inputs);
            $this->events->record(new ProfileChanged(ProfileChanged::YEAR, null, $view->year));

            return $inputs->view();
        });
    }

    /**
     * @throws TaxesRefused
     */
    public function setAuthority(SetAuthorityAccountInput $input): AuthorityAccountView
    {
        $this->access->denyUnlessOwner();
        $authority = Authority::tryFrom(trim($input->authority)) ?? throw TaxesRefused::invalid('authority_invalid', 'authority', 'An authority is social, health, sickness, income_tax or flat_rate.');
        $account = trim($input->account);
        if ('' === $account || mb_strlen($account) > AuthorityAccount::MAX_ACCOUNT) {
            throw TaxesRefused::invalid('account_invalid', 'account', \sprintf('An account has 1 to %d characters.', AuthorityAccount::MAX_ACCOUNT));
        }
        $reference = trim($input->variableSymbol);
        if (mb_strlen($reference) > AuthorityAccount::MAX_REFERENCE) {
            throw TaxesRefused::invalid('variable_symbol_invalid', 'variableSymbol', \sprintf('A variable symbol has at most %d characters.', AuthorityAccount::MAX_REFERENCE));
        }

        return $this->transaction->run(function () use ($authority, $account, $reference): AuthorityAccountView {
            $set = new AuthorityAccount($authority, $account, $reference);
            $this->profile()->setAuthority($set);
            $this->events->record(new ProfileChanged(ProfileChanged::AUTHORITY, $authority->value));

            return $set->view();
        });
    }

    /**
     * The VAT payer days are the firm's VAT statuses, changed in Party.
     */
    private function vatPayerOnFirm(string $field): TaxesRefused
    {
        return TaxesRefused::invalid('vat_payer_on_firm', $field, 'Whether the owner is a VAT payer comes from the VAT statuses of the firm: change them with POST /api/v1/firm/vat-statuses or DELETE /api/v1/firm/vat-statuses/{id} (foresi_add_firm_vat_status, foresi_remove_firm_vat_status).');
    }

    /**
     * The one profile, created with the first change.
     */
    private function profile(): OwnerProfile
    {
        $profile = $this->store->profile();
        if (!$profile instanceof OwnerProfile) {
            $profile = new OwnerProfile();
            $this->store->add($profile);
        }

        return $profile;
    }

    /**
     * @throws TaxesRefused
     */
    private function yearView(SetYearInputsInput $input): YearInputsView
    {
        $year = filter_var(trim($input->year), \FILTER_VALIDATE_INT, ['options' => ['min_range' => self::FIRST_YEAR, 'max_range' => self::LAST_YEAR]]);
        if (false === $year) {
            throw TaxesRefused::invalid('year_invalid', 'year', \sprintf('A year is %d to %d.', self::FIRST_YEAR, self::LAST_YEAR));
        }
        $methods = [];
        foreach ($input->expenseMethods as $method) {
            $methods[] = (\is_string($method) ? ExpenseMethod::tryFrom($method) : null) ?? throw TaxesRefused::invalid('expense_method_invalid', 'expenseMethods', 'An expense method is actual, lump_80, lump_60, lump_40 or lump_30.');
        }
        $methods = array_values(array_unique($methods, \SORT_REGULAR));
        if ([] === $methods) {
            throw TaxesRefused::invalid('expense_methods_empty', 'expenseMethods', 'Compare at least one expense method.');
        }
        $chosen = null;
        $chosenValue = trim($input->chosenMethod ?? '');
        if ('' !== $chosenValue) {
            $chosen = ExpenseMethod::tryFrom($chosenValue) ?? throw TaxesRefused::invalid('expense_method_invalid', 'chosenMethod', 'An expense method is actual, lump_80, lump_60, lump_40 or lump_30.');
            if (!\in_array($chosen, $methods, true)) {
                throw TaxesRefused::invalid('chosen_method_not_compared', 'chosenMethod', 'The chosen method is one of expense_methods.');
            }
        }
        if (\count($input->children) > self::MAX_CHILDREN) {
            throw TaxesRefused::invalid('children_too_many', 'children', \sprintf('A year claims at most %d children.', self::MAX_CHILDREN));
        }
        $children = $this->children(array_map($this->child(...), $input->children));
        $spouse = null;
        if (null !== $input->spouseMonths) {
            $spouse = new SpouseInput($this->months($input->spouseMonths, 'spouseMonths'), $input->spouseZtpP, $input->spouseLowIncome);
        }
        $deductions = [];
        foreach ($input->deductions as $deduction) {
            $type = \is_array($deduction) && \is_string($deduction['type'] ?? null) ? DeductionType::tryFrom($deduction['type']) : null;
            if (!$type instanceof DeductionType) {
                throw TaxesRefused::invalid('deduction_invalid', 'deductions', 'A deduction is {type, amount}, where type is gifts, housing_loan_interest, pension_products, life_insurance, long_term_investment, union_fees or exam_fees.');
            }
            if (isset($deductions[$type->value])) {
                throw TaxesRefused::invalid('deduction_repeated', 'deductions', 'Each type of deduction comes once.');
            }
            $amount = $deduction['amount'] ?? null;
            $deductions[$type->value] = $this->money(\is_string($amount) ? $amount : '', 'deductions');
        }
        $route = null === $input->returnRoute || '' === $input->returnRoute ? ReturnRoute::Electronic : (ReturnRoute::tryFrom($input->returnRoute) ?? throw TaxesRefused::invalid('return_route_invalid', 'returnRoute', 'A return route is paper, electronic or advisor.'));

        return new YearInputsView(
            $year,
            $methods,
            $chosen,
            $children,
            $spouse,
            $deductions,
            $this->optionalMoney($input->employmentBase, 'employmentBase'),
            $this->optionalMoney($input->employmentTaxWithheld, 'employmentTaxWithheld'),
            $this->optionalMoney($input->rentalBase, 'rentalBase'),
            $this->optionalMoney($input->otherBase, 'otherBase'),
            $this->optionalMoney($input->lastKnownTax, 'lastKnownTax'),
            $this->optionalMoney($input->previousSocialBase, 'previousSocialBase'),
            $this->optionalMoney($input->previousHealthBase, 'previousHealthBase'),
            $this->optionalDay($input->previousOverviewFiledOn, 'previousOverviewFiledOn'),
            $route,
            $this->optionalDay($input->activityStart, 'activityStart'),
            $this->optionalMoney($input->averageWageOverride, 'averageWageOverride'),
            $this->activityMonths($input->previousActivityMonths),
        );
    }

    /**
     * A child as sent, its order null when it follows the birth dates.
     *
     * @return array{label: string, birth: \DateTimeImmutable, order: ?int, ztpP: bool, months: int}
     *
     * @throws TaxesRefused
     */
    private function child(mixed $child): array
    {
        if (!\is_array($child)) {
            throw TaxesRefused::invalid('child_invalid', 'children', 'A child is {label, birth_date, order, ztp_p, months}.');
        }
        $label = $child['label'] ?? '';
        $label = \is_string($label) ? trim($label) : null;
        if (null === $label || mb_strlen($label) > self::MAX_LABEL) {
            throw TaxesRefused::invalid('child_label_invalid', 'children', \sprintf('The name of a child is text of at most %d characters, or empty.', self::MAX_LABEL));
        }
        $birth = $child['birth_date'] ?? null;
        $order = $child['order'] ?? null;
        if (null !== $order && (!\is_int($order) || $order < 1 || $order > 3)) {
            throw TaxesRefused::invalid('child_order_invalid', 'children', 'The order of a child is 1, 2 or 3 for the third and further, or null to follow the birth dates.');
        }
        $months = $child['months'] ?? 12;

        return [
            'label' => $label,
            'birth' => $this->day(\is_string($birth) ? $birth : '', 'children'),
            'order' => $order,
            'ztpP' => true === ($child['ztp_p'] ?? false),
            'months' => $this->months(\is_int($months) ? $months : -1, 'children'),
        ];
    }

    /**
     * The children by birth date, each with the order it takes (ChildOrder).
     *
     * @param list<array{label: string, birth: \DateTimeImmutable, order: ?int, ztpP: bool, months: int}> $children
     *
     * @return list<ChildInput>
     */
    private function children(array $children): array
    {
        return array_map(static fn (array $place): ChildInput => new ChildInput(
            $children[$place['key']]['label'],
            $children[$place['key']]['birth'],
            $place['order'],
            $children[$place['key']]['ztpP'],
            $children[$place['key']]['months'],
            $place['chosen'],
        ), ChildOrder::of(array_column($children, 'birth'), array_column($children, 'order')));
    }

    /**
     * The months of the previous year with an activity, 1 to 12, or null.
     *
     * @throws TaxesRefused
     */
    private function activityMonths(?int $months): ?int
    {
        if (null !== $months && ($months < 1 || $months > 12)) {
            throw TaxesRefused::invalid('activity_months_invalid', 'previousActivityMonths', 'The months of the previous year with an activity are 1 to 12, or null.');
        }

        return $months;
    }

    /**
     * @throws TaxesRefused
     */
    private function months(int $months, string $field): int
    {
        if ($months < 0 || $months > 12) {
            throw TaxesRefused::invalid('months_invalid', $field, 'A child or the spouse is claimed for 0 to 12 months.');
        }

        return $months;
    }

    /**
     * @throws TaxesRefused
     */
    private function day(string $value, string $field): \DateTimeImmutable
    {
        $value = trim($value);
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $this->clock->timezone());
        if (false === $day || $day->format('Y-m-d') !== $value) {
            throw TaxesRefused::invalid('date_invalid', $field, 'A day is written in ISO 8601, such as 2026-11-12.');
        }

        return $day;
    }

    /**
     * @throws TaxesRefused
     */
    private function optionalDay(?string $value, string $field): ?\DateTimeImmutable
    {
        return null === $value || '' === trim($value) ? null : $this->day($value, $field);
    }

    /**
     * @throws TaxesRefused
     */
    private function money(string $value, string $field): Money
    {
        $value = trim($value);
        if (1 !== preg_match('/^[0-9]{1,13}(\.[0-9]{1,2})?\z/', $value)) {
            throw TaxesRefused::invalid('amount_invalid', $field, 'An amount is a decimal number in CZK, not negative, with at most two decimals, such as "12500.00".');
        }

        return Money::of($value, Currency::CZK);
    }

    /**
     * @throws TaxesRefused
     */
    private function optionalMoney(?string $value, string $field): ?Money
    {
        return null === $value || '' === trim($value) ? null : $this->money($value, $field);
    }
}
