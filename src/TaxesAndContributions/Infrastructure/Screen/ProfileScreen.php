<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Infrastructure\Screen;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Application\ProfileReads;
use Foresi\TaxesAndContributions\Application\View\ProfileStatusLists;
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
use Foresi\TaxesAndContributions\Infrastructure\Column\StatusRow;
use Foresi\TaxesAndContributions\Infrastructure\Controller\ProfileController;
use Foresi\Ui\Contract\WorkingState;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the profile page shows: one line of the statuses that hold, the years
 * with their inputs and the statuses that change in them, and the account of
 * each authority; and the statuses dialog, a dated list per kind, each holding
 * until the next of its kind.
 *
 * @phpstan-type YearValues array{expenseMethods: list<string>, chosenMethod: string, children: list<array{label: string, birthDate: string, order: string, ztpP: bool, months: string, new?: bool}>, spouseMonths: string, spouseZtpP: bool, spouseLowIncome: bool, deductions: array<string, string>, employmentBase: string, employmentTaxWithheld: string, rentalBase: string, otherBase: string, lastKnownTax: string, previousSocialBase: string, previousHealthBase: string, previousOverviewFiledOn: string, previousActivityMonths: string, returnRoute: string, activityStart: string, averageWageOverride: string}
 */
final readonly class ProfileScreen
{
    public const string SECTION_EXPENSES = 'expenses';
    /** Where the firm's VAT statuses change: Party's firm settings. */
    public const string FIRM_VAT_ROUTE = 'app_firm_vat';
    public const string SECTION_CREDITS = 'credits';
    public const string SECTION_OTHER = 'other';

    /**
     * The three forms a year is entered in, each with the fields it sends: the
     * expenses to compare, the children with the other credits and the
     * deductions, and the rest. One long form split by topic, as a tax return is.
     */
    public const array SECTIONS = [
        self::SECTION_EXPENSES => ['expenseMethods', 'chosenMethod'],
        self::SECTION_CREDITS => ['children', 'spouseMonths', 'spouseZtpP', 'spouseLowIncome', 'deductions'],
        self::SECTION_OTHER => ['employmentBase', 'employmentTaxWithheld', 'rentalBase', 'otherBase', 'lastKnownTax', 'previousSocialBase', 'previousHealthBase', 'previousOverviewFiledOn', 'previousActivityMonths', 'returnRoute', 'activityStart', 'averageWageOverride'],
    ];

    public function __construct(
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
        private InstanceClock $clock,
        private RequestStack $requests,
    ) {
    }

    /**
     * The statuses of every kind that has one, in the order of StatusKind, oldest first, each
     * until the day before the next of its kind: the records of the list the dialog shows.
     *
     * @return list<StatusRow>
     */
    public function statusRows(ProfileView $profile): array
    {
        $rows = [];
        foreach (StatusKind::cases() as $kind) {
            $periods = array_values(array_filter($profile->statuses, static fn (StatusPeriod $status): bool => $kind === $status->kind));
            foreach ($periods as $index => $status) {
                $next = $periods[$index + 1] ?? null;
                $rows[] = new StatusRow($status->id, $kind, $this->statusValue($status), $this->from($status), $next instanceof StatusPeriod ? $this->day($next->validFrom->modify('-1 day')) : null);
            }
        }

        return $rows;
    }

    /**
     * A status as a row of Ui:DataTable. The VAT payer statuses are the firm's: read-only here.
     *
     * @return array<string, mixed>
     */
    public function statusRow(StatusRow $row, WorkingState $working): array
    {
        return [
            'id' => $row->id,
            'label' => $row->value,
            'secondary' => $row->from,
            'cells' => $working->display->cells($row),
            'actions' => StatusKind::VatPayer === $row->kind ? [] : [[
                'label' => $this->trans('taxes.profile.status.remove'),
                'href' => $this->urls->generate(ProfileController::ROUTE.'_status_remove', ['id' => $row->id]),
                'method' => 'POST',
                'icon' => 'trash-2',
                'confirm' => [
                    'title' => $this->trans('taxes.profile.status.remove_title'),
                    'text' => $this->trans('taxes.profile.status.remove_text'),
                    'accept' => $this->trans('taxes.profile.status.remove'),
                    'tone' => 'danger',
                    // The list is a dialog over the profile: its host posts the form itself.
                    'overlay' => true,
                ],
            ]],
        ];
    }

    /**
     * The value of a field of the list on a status, as RowConditions compares it.
     */
    public function statusField(StatusRow $row, string $field): mixed
    {
        return match ($field) {
            ProfileStatusLists::VALUE => $row->value,
            ProfileStatusLists::KIND => $row->kind->value,
            default => null,
        };
    }

    /**
     * The rows the list shows, a table per kind that has one in the order of StatusKind. The
     * VAT payer's has a link to where the firm's VAT statuses change. A kind none of whose
     * statuses the filter or search keeps is left out.
     *
     * @param list<StatusRow>            $records every status, as statusRows() gave them
     * @param list<array<string, mixed>> $rows    the rows of the list that ListTable kept, by their id
     *
     * @return list<array{kind: string, title: string, rows: list<array<string, mixed>>, firmVat: ?string}>
     */
    public function statusGroups(array $records, array $rows): array
    {
        $kinds = [];
        foreach ($records as $record) {
            $kinds[$record->id] = $record->kind;
        }
        $groups = [];
        foreach (StatusKind::cases() as $kind) {
            $own = array_values(array_filter($rows, static fn (array $row): bool => \is_string($row['id']) && ($kinds[$row['id']] ?? null) === $kind));
            if ([] === $own) {
                continue;
            }
            $groups[] = [
                'kind' => $kind->value,
                'title' => $this->trans('taxes.status.kind.'.$kind->value),
                'rows' => $own,
                'firmVat' => StatusKind::VatPayer === $kind ? $this->urls->generate(self::FIRM_VAT_ROUTE) : null,
            ];
        }

        return $groups;
    }

    /**
     * What holds today, one phrase per kind in the order of StatusKind, as one
     * line: "sole trader · main activity · VAT payer from Nov 12, 2026". A
     * status that replaced an earlier one of its kind carries its day; a change
     * still to come follows what holds, with its day.
     *
     * @return list<string>
     */
    public function statusSummary(ProfileView $profile): array
    {
        $today = $this->clock->today();
        $parts = [];
        foreach (StatusKind::cases() as $kind) {
            $periods = array_values(array_filter($profile->statuses, static fn (StatusPeriod $status): bool => $kind === $status->kind));
            $holding = null;
            foreach ($periods as $index => $status) {
                if ($status->validFrom <= $today) {
                    $holding = $index;
                }
            }
            if (null !== $holding) {
                $status = $periods[$holding];
                $parts[] = $holding > 0 ? $this->trans('taxes.profile.summary.since', ['status' => $this->statusPhrase($status), 'day' => $this->day($status->validFrom)]) : $this->statusPhrase($status);
            }
            foreach (\array_slice($periods, null === $holding ? 0 : $holding + 1) as $status) {
                $parts[] = $this->trans('taxes.profile.summary.since', ['status' => $this->statusPhrase($status), 'day' => $this->day($status->validFrom)]);
            }
        }

        return $parts;
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
        foreach (StatusKind::writable() as $kind) {
            foreach ($kind->values() as $value) {
                // The phrase names the kind too, so the closed select reads "Sickness insurance", not "On".
                $phrase = $this->trans('taxes.status.phrase.'.$kind->value.'.'.$value);
                $choices[$this->trans('taxes.status.kind.'.$kind->value)][$kind->value.':'.$value] = mb_strtoupper(mb_substr($phrase, 0, 1)).mb_substr($phrase, 1);
            }
        }

        return $choices;
    }

    /**
     * The years with inputs, and this year and the next when they have none
     * yet, newest first; each with the statuses that change in it ("from
     * Nov 12: VAT payer") and its three sections and what they hold.
     *
     * @return list<array{year: int, entered: bool, changes: list<string>, sections: list<array{section: string, title: string, href: string, summary: string}>}>
     */
    public function years(ProfileView $profile): array
    {
        $current = (int) $this->clock->today()->format('Y');
        $years = array_keys($profile->years);
        $all = array_unique([...$years, $current, $current + 1]);
        rsort($all);

        return array_map(function (int $year) use ($profile): array {
            $inputs = $profile->years[$year] ?? null;
            $sections = [];
            foreach (array_keys(self::SECTIONS) as $section) {
                $sections[] = [
                    'section' => $section,
                    'title' => $this->trans('taxes.profile.section.'.$section),
                    'href' => $this->urls->generate(ProfileController::ROUTE.'_year', ['year' => $year, 'section' => $section]),
                    'summary' => $inputs instanceof YearInputsView ? $this->sectionSummary($section, $inputs) : $this->trans('taxes.profile.year.not_entered'),
                ];
            }

            $changes = [];
            $statuses = $profile->statuses;
            usort($statuses, static fn (StatusPeriod $a, StatusPeriod $b): int => $a->validFrom <=> $b->validFrom);
            foreach ($statuses as $status) {
                if ((int) $status->validFrom->format('Y') === $year) {
                    $changes[] = $this->trans('taxes.profile.year.change', ['day' => $this->dayInYear($status->validFrom), 'status' => $this->statusPhrase($status)]);
                }
            }

            return ['year' => $year, 'entered' => $inputs instanceof YearInputsView, 'changes' => $changes, 'sections' => $sections];
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
     * The form values of a year, as the sheet shows them. A child's order is
     * empty while it follows the birth dates.
     *
     * @return YearValues
     */
    public function yearValues(YearInputsView $year): array
    {
        $children = array_map(fn (ChildInput $child): array => [
            'label' => $child->label,
            'birthDate' => $child->birthDate->format('Y-m-d'),
            'order' => $child->orderChosen ? (string) $child->order : '',
            'ztpP' => $child->ztpP,
            'months' => (string) $child->months,
        ], $year->children);
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
            'previousActivityMonths' => null === $year->previousActivityMonths ? '' : (string) $year->previousActivityMonths,
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

    /**
     * A status as a phrase that names its kind too ("main activity"), for the
     * summary line and the years.
     */
    public function statusPhrase(StatusPeriod $status): string
    {
        $phrase = $this->trans('taxes.status.phrase.'.$status->kind->value.'.'.$status->value);

        return $status->base instanceof Money ? $this->trans('taxes.profile.status.with_base', ['value' => $phrase, 'base' => $status->base->format($this->locale())]) : $phrase;
    }

    private function sectionSummary(string $section, YearInputsView $year): string
    {
        $parts = [];
        if (self::SECTION_EXPENSES === $section) {
            $parts[] = implode(', ', array_map(fn (ExpenseMethod $method): string => $this->trans('taxes.method.'.$method->value), $year->expenseMethods));
            if ($year->chosenMethod instanceof ExpenseMethod) {
                $parts[] = $this->trans('taxes.profile.summary.chosen', ['method' => $this->trans('taxes.method.'.$year->chosenMethod->value)]);
            }
        } elseif (self::SECTION_CREDITS === $section) {
            if ([] !== $year->children) {
                $parts[] = $this->trans('taxes.profile.year.children', ['count' => \count($year->children)]);
            }
            if ($year->spouse instanceof SpouseInput) {
                $parts[] = $this->trans('taxes.profile.summary.spouse');
            }
            if ([] !== $year->deductions) {
                $parts[] = $this->trans('taxes.profile.summary.deductions', ['count' => \count($year->deductions)]);
            }
        } else {
            $parts[] = $this->trans('taxes.route.'.$year->returnRoute->value);
            if (($year->employmentBase ?? $year->rentalBase ?? $year->otherBase ?? $year->lastKnownTax) instanceof Money) {
                $parts[] = $this->trans('taxes.profile.summary.income');
            }
            if (null !== ($year->previousSocialBase ?? $year->previousHealthBase ?? $year->previousOverviewFiledOn ?? $year->previousActivityMonths)) {
                $parts[] = $this->trans('taxes.profile.summary.previous');
            }
        }

        return [] === $parts ? $this->trans('taxes.profile.summary.none') : implode(' · ', $parts);
    }

    /**
     * The first day of a status, or "from the start" for the firm's VAT
     * status that held before its first known day.
     */
    private function from(StatusPeriod $status): string
    {
        return ProfileReads::FROM_THE_START === $status->validFrom->format('Y-m-d') ? $this->trans('taxes.profile.from_the_start') : $this->day($status->validFrom);
    }

    private function day(\DateTimeImmutable $day): string
    {
        $formatter = new \IntlDateFormatter($this->locale(), \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, $this->clock->timezone());

        return (string) $formatter->format($day);
    }

    /**
     * A day without its year, where the year is already said ("12. 11.").
     */
    private function dayInYear(\DateTimeImmutable $day): string
    {
        $pattern = new \IntlDatePatternGenerator($this->locale())->getBestPattern('dMMM');
        $formatter = new \IntlDateFormatter($this->locale(), \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $this->clock->timezone(), null, false === $pattern ? 'd. M.' : $pattern);

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
