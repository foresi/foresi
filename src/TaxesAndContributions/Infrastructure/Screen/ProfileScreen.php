<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Infrastructure\Screen;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Contract\Authority;
use Foresi\TaxesAndContributions\Contract\AuthorityAccountView;
use Foresi\TaxesAndContributions\Contract\ChildInput;
use Foresi\TaxesAndContributions\Contract\DeductionType;
use Foresi\TaxesAndContributions\Contract\ExpenseMethod;
use Foresi\TaxesAndContributions\Contract\ProfileView;
use Foresi\TaxesAndContributions\Contract\SpouseInput;
use Foresi\TaxesAndContributions\Contract\StatusKind;
use Foresi\TaxesAndContributions\Contract\StatusPeriod;
use Foresi\TaxesAndContributions\Contract\YearInputsView;
use Foresi\TaxesAndContributions\Infrastructure\Controller\ProfileController;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the profile page shows: the statuses as a dated list per kind, each
 * holding until the next of its kind, the years with their inputs, and the
 * account of each authority.
 */
final readonly class ProfileScreen
{
    public function __construct(
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
        private InstanceClock $clock,
        private RequestStack $requests,
    ) {
    }

    /**
     * @return list<array<string, mixed>> the columns of Ui:DataTable
     */
    public function statusColumns(): array
    {
        return [
            ['key' => 'value', 'label' => $this->trans('taxes.profile.column.value'), 'truncate' => true],
            ['key' => 'from', 'label' => $this->trans('taxes.profile.column.from')],
            ['key' => 'until', 'label' => $this->trans('taxes.profile.column.until'), 'phone' => false],
        ];
    }

    /**
     * The statuses of each kind that has one, in the order of StatusKind,
     * oldest first, each until the day before the next of its kind.
     *
     * @return list<array{kind: string, title: string, rows: list<array<string, mixed>>}>
     */
    public function statusGroups(ProfileView $profile): array
    {
        $groups = [];
        foreach (StatusKind::cases() as $kind) {
            $periods = array_values(array_filter($profile->statuses, static fn (StatusPeriod $status): bool => $kind === $status->kind));
            if ([] === $periods) {
                continue;
            }
            $rows = [];
            foreach ($periods as $index => $status) {
                $next = $periods[$index + 1] ?? null;
                $value = $this->statusValue($status);
                $rows[] = [
                    'id' => $status->id,
                    'label' => $value,
                    'secondary' => $this->day($status->validFrom),
                    'cells' => [
                        'value' => $value,
                        'from' => $this->day($status->validFrom),
                        'until' => $next instanceof StatusPeriod ? $this->day($next->validFrom->modify('-1 day')) : ['empty' => $this->trans('taxes.profile.until_next')],
                    ],
                    'actions' => [[
                        'label' => $this->trans('taxes.profile.status.remove'),
                        'href' => $this->urls->generate(ProfileController::ROUTE.'_status_remove', ['id' => $status->id]),
                        'method' => 'POST',
                        'icon' => 'trash-2',
                        'confirm' => [
                            'title' => $this->trans('taxes.profile.status.remove_title'),
                            'text' => $this->trans('taxes.profile.status.remove_text'),
                            'accept' => $this->trans('taxes.profile.status.remove'),
                            'tone' => 'danger',
                        ],
                    ]],
                ];
            }
            $groups[] = ['kind' => $kind->value, 'title' => $this->trans('taxes.status.kind.'.$kind->value), 'rows' => $rows];
        }

        return $groups;
    }

    /**
     * The choices of the "Add a change" dialog: each kind is a group of its
     * values, sent as kind:value.
     *
     * @return array<string, array<string, string>>
     */
    public function statusChoices(): array
    {
        $choices = [];
        foreach (StatusKind::cases() as $kind) {
            foreach ($kind->values() as $value) {
                $choices[$this->trans('taxes.status.kind.'.$kind->value)][$kind->value.':'.$value] = $this->trans('taxes.status.value.'.$kind->value.'.'.$value);
            }
        }

        return $choices;
    }

    /**
     * The years with inputs, and this year and the next when they have none
     * yet, newest first.
     *
     * @return list<array{year: int, href: string, entered: bool, summary: string}>
     */
    public function years(ProfileView $profile): array
    {
        $current = (int) $this->clock->today()->format('Y');
        $years = array_keys($profile->years);
        $all = array_unique([...$years, $current, $current + 1]);
        rsort($all);

        return array_map(function (int $year) use ($profile): array {
            $inputs = $profile->years[$year] ?? null;

            return [
                'year' => $year,
                'href' => $this->urls->generate(ProfileController::ROUTE.'_year', ['year' => $year]),
                'entered' => $inputs instanceof YearInputsView,
                'summary' => $inputs instanceof YearInputsView ? $this->yearSummary($inputs) : $this->trans('taxes.profile.year.not_entered'),
            ];
        }, $all);
    }

    /**
     * Each authority with its account, or none yet.
     *
     * @return list<array{authority: string, title: string, href: string, account: ?string, variableSymbol: ?string}>
     */
    public function authorities(ProfileView $profile): array
    {
        return array_map(function (Authority $authority) use ($profile): array {
            $account = array_find($profile->authorities, static fn (AuthorityAccountView $set): bool => $set->authority === $authority->value);

            return [
                'authority' => $authority->value,
                'title' => $this->trans('taxes.authority.'.$authority->value),
                'href' => $this->urls->generate(ProfileController::ROUTE.'_authority', ['authority' => $authority->value]),
                'account' => $account?->account,
                'variableSymbol' => $account instanceof AuthorityAccountView && '' !== $account->variableSymbol ? $account->variableSymbol : null,
            ];
        }, Authority::cases());
    }

    /**
     * The form values of a year, as the sheet shows them: one blank child
     * after those claimed, so another can be added.
     *
     * @return array<string, mixed>
     */
    public function yearValues(YearInputsView $year): array
    {
        $children = array_map(fn (ChildInput $child): array => [
            'label' => $child->label,
            'birthDate' => $child->birthDate->format('Y-m-d'),
            'order' => (string) $child->order,
            'ztpP' => $child->ztpP,
            'months' => (string) $child->months,
        ], $year->children);
        $children[] = ['label' => '', 'birthDate' => '', 'order' => (string) min(3, \count($children) + 1), 'ztpP' => false, 'months' => '12'];
        $deductions = [];
        foreach (DeductionType::cases() as $type) {
            $deductions[$type->value] = $this->plain($year->deductions[$type->value] ?? null);
        }

        return [
            'expenseMethods' => array_map(static fn (ExpenseMethod $method): string => $method->value, $year->expenseMethods),
            'chosenMethod' => $year->chosenMethod instanceof ExpenseMethod ? $year->chosenMethod->value : '',
            'children' => $children,
            'spouseMonths' => $year->spouse instanceof SpouseInput ? (string) $year->spouse->months : '',
            'spouseZtpP' => $year->spouse instanceof SpouseInput && $year->spouse->ztpP,
            'spouseLowIncome' => $year->spouse instanceof SpouseInput && $year->spouse->lowIncome,
            'deductions' => $deductions,
            'employmentBase' => $this->plain($year->employmentBase),
            'employmentTaxWithheld' => $this->plain($year->employmentTaxWithheld),
            'rentalBase' => $this->plain($year->rentalBase),
            'otherBase' => $this->plain($year->otherBase),
            'lastKnownTax' => $this->plain($year->lastKnownTax),
            'previousSocialBase' => $this->plain($year->previousSocialBase),
            'previousHealthBase' => $this->plain($year->previousHealthBase),
            'previousOverviewFiledOn' => $year->previousOverviewFiledOn?->format('Y-m-d') ?? '',
            'returnRoute' => $year->returnRoute->value,
            'activityStart' => $year->activityStart?->format('Y-m-d') ?? '',
            'averageWageOverride' => $this->plain($year->averageWageOverride),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function methodChoices(): array
    {
        $choices = [];
        foreach (ExpenseMethod::cases() as $method) {
            $choices[$method->value] = $this->trans('taxes.method.'.$method->value);
        }

        return $choices;
    }

    public function statusValue(StatusPeriod $status): string
    {
        $value = $this->trans('taxes.status.value.'.$status->kind->value.'.'.$status->value);

        return $status->base instanceof Money ? $this->trans('taxes.profile.status.with_base', ['value' => $value, 'base' => $status->base->format($this->locale())]) : $value;
    }

    private function yearSummary(YearInputsView $year): string
    {
        $parts = [implode(', ', array_map(fn (ExpenseMethod $method): string => $this->trans('taxes.method.'.$method->value), $year->expenseMethods))];
        if ([] !== $year->children) {
            $parts[] = $this->trans('taxes.profile.year.children', ['count' => \count($year->children)]);
        }

        return implode(' · ', $parts);
    }

    private function day(\DateTimeImmutable $day): string
    {
        $formatter = new \IntlDateFormatter($this->locale(), \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, $this->clock->timezone());

        return (string) $formatter->format($day);
    }

    private function plain(?Money $amount): string
    {
        if (!$amount instanceof Money) {
            return '';
        }
        $symbol = new \NumberFormatter($this->locale(), \NumberFormatter::DECIMAL)->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);

        return \sprintf('%d%s%02d', intdiv($amount->minor(), 100), $symbol, $amount->minor() % 100);
    }

    private function locale(): string
    {
        return $this->requests->getCurrentRequest()?->getLocale() ?? 'cs';
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters);
    }
}
