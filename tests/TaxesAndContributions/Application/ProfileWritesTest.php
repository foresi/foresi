<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\TaxesAndContributions\Application;

use Foresi\Shared\Application\RefusalKind;
use Foresi\TaxesAndContributions\Application\AddStatusInput;
use Foresi\TaxesAndContributions\Application\RemoveStatusInput;
use Foresi\TaxesAndContributions\Application\SetAuthorityAccountInput;
use Foresi\TaxesAndContributions\Application\SetYearInputsInput;
use Foresi\TaxesAndContributions\Application\TaxesRefused;
use Foresi\TaxesAndContributions\Contract\ChildInput;
use Foresi\TaxesAndContributions\Contract\ExpenseMethod;
use Foresi\TaxesAndContributions\Contract\ReturnRoute;
use Foresi\TaxesAndContributions\Contract\StatusKind;
use Foresi\TaxesAndContributions\Domain\OwnerProfile;
use Foresi\TaxesAndContributions\Domain\StatusPeriod;
use Foresi\Tests\TaxesAndContributions\Support\TaxesTestCase;

/**
 * The owner profile (B7, Goal 6, old T3): dated statuses that hold until the
 * next of their kind, the inputs of a year, the authority accounts, only for
 * the owner, and events that carry no personal data.
 */
final class ProfileWritesTest extends TaxesTestCase
{
    public function testAStatusAppliesFromItsDayUntilTheNextOfItsKind(): void
    {
        $this->writes()->addStatus(new AddStatusInput('vat_payer', 'yes', '2026-11-12'));
        $this->writes()->addStatus(new AddStatusInput('vat_payer', 'no', '2026-01-01'));
        $this->writes()->addStatus(new AddStatusInput('activity', 'main', '2026-01-01'));
        $this->em->clear();

        $profile = $this->reads()->profile();

        self::assertSame('no', $profile->valueOn(StatusKind::VatPayer, new \DateTimeImmutable('2026-11-11'))?->value);
        self::assertSame('yes', $profile->valueOn(StatusKind::VatPayer, new \DateTimeImmutable('2026-11-12'))?->value);
        self::assertSame('yes', $profile->valueOn(StatusKind::VatPayer, new \DateTimeImmutable('2027-03-01'))?->value);
        self::assertNull($profile->valueOn(StatusKind::VatPayer, new \DateTimeImmutable('2025-12-31')));
        self::assertSame('main', $profile->valueOn(StatusKind::Activity, new \DateTimeImmutable('2026-11-12'))?->value);
        self::assertNull($profile->valueOn(StatusKind::FlatRateBand, new \DateTimeImmutable('2026-11-12')));
        // By kind in the order of StatusKind, then oldest first.
        self::assertSame([['activity', '2026-01-01'], ['vat_payer', '2026-01-01'], ['vat_payer', '2026-11-12']], array_map(static fn (\Foresi\TaxesAndContributions\Contract\StatusPeriod $status): array => [$status->kind->value, $status->validFrom->format('Y-m-d')], $profile->statuses));
        self::assertCount(1, $this->em->getRepository(OwnerProfile::class)->findAll());
    }

    public function testTwoPeriodsOfOneKindOnOneDayAreRefused(): void
    {
        $this->writes()->addStatus(new AddStatusInput('vat_payer', 'no', '2026-01-01'));

        $this->assertRefused('status_day_taken', null, RefusalKind::Conflict, fn (): mixed => $this->writes()->addStatus(new AddStatusInput('vat_payer', 'yes', '2026-01-01')));
        // Another kind may start on the same day.
        self::assertSame('main', $this->writes()->addStatus(new AddStatusInput('activity', 'main', '2026-01-01'))->value);
        self::assertCount(2, $this->em->getRepository(StatusPeriod::class)->findAll());
    }

    public function testSicknessOnNeedsABase(): void
    {
        $this->assertRefused('base_required', 'base', RefusalKind::Invalid, fn (): mixed => $this->writes()->addStatus(new AddStatusInput('sickness_insurance', 'on', '2026-01-01')));
        $this->assertRefused('base_not_allowed', 'base', RefusalKind::Invalid, fn (): mixed => $this->writes()->addStatus(new AddStatusInput('sickness_insurance', 'off', '2026-01-01', '10000.00')));
        $this->assertRefused('amount_invalid', 'base', RefusalKind::Invalid, fn (): mixed => $this->writes()->addStatus(new AddStatusInput('sickness_insurance', 'on', '2026-01-01', '0')));

        $status = $this->writes()->addStatus(new AddStatusInput('sickness_insurance', 'on', '2026-01-01', '10000.50'));

        self::assertSame(1000050, $status->base?->minor());
    }

    public function testAStatusHasAKnownKindValueAndDay(): void
    {
        $this->assertRefused('kind_invalid', 'kind', RefusalKind::Invalid, fn (): mixed => $this->writes()->addStatus(new AddStatusInput('vat', 'yes', '2026-01-01')));
        $this->assertRefused('value_invalid', 'value', RefusalKind::Invalid, fn (): mixed => $this->writes()->addStatus(new AddStatusInput('flat_rate_band', '4', '2026-01-01')));
        $this->assertRefused('date_invalid', 'validFrom', RefusalKind::Invalid, fn (): mixed => $this->writes()->addStatus(new AddStatusInput('activity', 'main', '2026-02-30')));
        self::assertSame([], $this->em->getRepository(StatusPeriod::class)->findAll());
    }

    public function testRemovingAStatusLetsTheOneBeforeItHoldAgain(): void
    {
        $this->writes()->addStatus(new AddStatusInput('vat_payer', 'no', '2026-01-01'));
        $yes = $this->writes()->addStatus(new AddStatusInput('vat_payer', 'yes', '2026-11-12'));

        self::assertSame($yes->id, $this->writes()->removeStatus(new RemoveStatusInput($yes->id)));
        $this->em->clear();

        self::assertSame('no', $this->reads()->profile()->valueOn(StatusKind::VatPayer, new \DateTimeImmutable('2026-12-01'))?->value);
        $this->assertRefused('not_found', null, RefusalKind::NotFound, fn (): mixed => $this->writes()->removeStatus(new RemoveStatusInput($yes->id)));
    }

    public function testClaimedMonthsAbove12AreRefused(): void
    {
        $this->assertRefused('months_invalid', 'children', RefusalKind::Invalid, fn (): mixed => $this->writes()->setYear($this->year(children: [$this->child(months: 13)])));
        $this->assertRefused('months_invalid', 'spouseMonths', RefusalKind::Invalid, fn (): mixed => $this->writes()->setYear($this->year(spouseMonths: 13)));
        self::assertNull($this->em->find(OwnerProfile::class, OwnerProfile::ID));
    }

    public function testTheInputsOfAYearAreReplacedAndReadBack(): void
    {
        $this->writes()->setYear($this->year(['actual', 'lump_60'], 'lump_60', [$this->child(), $this->child('Younger son', '2022-09-01', 2, 4)], 12, deductions: [['type' => 'pension_products', 'amount' => '24000.00']], employmentBase: '120000.00', previousSocialBase: '210000.00', previousOverviewFiledOn: '2026-04-30', returnRoute: 'advisor'));
        $this->writes()->setYear($this->year(year: '2027'));
        $this->em->clear();

        $year = $this->reads()->year(2026);
        self::assertSame([ExpenseMethod::Actual, ExpenseMethod::Lump60], $year->expenseMethods);
        self::assertSame(ExpenseMethod::Lump60, $year->chosenMethod);
        self::assertSame(['Elder daughter', '2019-05-14', 1, false, 12], [$year->children[0]->label, $year->children[0]->birthDate->format('Y-m-d'), $year->children[0]->order, $year->children[0]->ztpP, $year->children[0]->months]);
        self::assertSame([2, 4], [$year->children[1]->order, $year->children[1]->months]);
        self::assertSame(12, $year->spouse?->months);
        self::assertSame(2400000, $year->deductions['pension_products']->minor());
        self::assertSame([12000000, 21000000, '2026-04-30', ReturnRoute::Advisor], [$year->employmentBase?->minor(), $year->previousSocialBase?->minor(), $year->previousOverviewFiledOn?->format('Y-m-d'), $year->returnRoute]);
        self::assertSame([2026, 2027], array_keys($this->reads()->profile()->years));

        // A year is replaced as a whole: what is left out is cleared.
        $this->writes()->setYear($this->year());
        $this->em->clear();
        $year = $this->reads()->year(2026);
        self::assertSame([[], null, null, ReturnRoute::Electronic], [$year->children, $year->spouse, $year->employmentBase, $year->returnRoute]);
        // A year never entered reads as its defaults.
        self::assertSame([ExpenseMethod::Actual, ExpenseMethod::Lump60], $this->reads()->year(2030)->expenseMethods);
    }

    public function testTheChildrenGoByBirthDateAndTakeTheirOrderFromIt(): void
    {
        // Sent youngest first, with no name and no order: the eldest is first.
        $this->writes()->setYear($this->year(children: [$this->child('', '2024-02-01', null), $this->child('', '2017-03-01', null), $this->child('Middle', '2020-06-01', null), $this->child('', '2025-01-01', null)]));
        $this->em->clear();
        $children = $this->reads()->year(2026)->children;
        self::assertSame(['2017-03-01', '2020-06-01', '2024-02-01', '2025-01-01'], array_map(static fn (ChildInput $child): string => $child->birthDate->format('Y-m-d'), $children));
        self::assertSame([1, 2, 3, 3], array_map(static fn (ChildInput $child): int => $child->order, $children));
        self::assertSame(['', 'Middle', '', ''], array_map(static fn (ChildInput $child): string => $child->label, $children));
        self::assertSame([false, false, false, false], array_map(static fn (ChildInput $child): bool => $child->orderChosen, $children));

        // An order the parents agreed on stays; one the birth date gives anyway follows the birth dates.
        $this->writes()->setYear($this->year(children: [$this->child('Elder', '2017-03-01', 1), $this->child('Younger', '2020-06-01', 1)]));
        $this->em->clear();
        $children = $this->reads()->year(2026)->children;
        self::assertSame([[1, false], [1, true]], array_map(static fn (ChildInput $child): array => [$child->order, $child->orderChosen], $children));

        // An elder child added later moves the others along unless their order is fixed.
        $this->writes()->setYear($this->year(children: [$this->child('Younger', '2020-06-01', null), $this->child('Elder', '2015-01-01', null)]));
        $this->em->clear();
        self::assertSame(['Elder', 'Younger'], array_map(static fn (ChildInput $child): string => $child->label, $this->reads()->year(2026)->children));
        self::assertSame([1, 2], array_map(static fn (ChildInput $child): int => $child->order, $this->reads()->year(2026)->children));
    }

    public function testAYearRefusesWhatItCannotUse(): void
    {
        $this->assertRefused('year_invalid', 'year', RefusalKind::Invalid, fn (): mixed => $this->writes()->setYear($this->year(year: '1999')));
        $this->assertRefused('expense_methods_empty', 'expenseMethods', RefusalKind::Invalid, fn (): mixed => $this->writes()->setYear($this->year([])));
        $this->assertRefused('expense_method_invalid', 'expenseMethods', RefusalKind::Invalid, fn (): mixed => $this->writes()->setYear($this->year(['lump_50'])));
        $this->assertRefused('chosen_method_not_compared', 'chosenMethod', RefusalKind::Invalid, fn (): mixed => $this->writes()->setYear($this->year(['actual'], 'lump_60')));
        $this->assertRefused('child_label_invalid', 'children', RefusalKind::Invalid, fn (): mixed => $this->writes()->setYear($this->year(children: [$this->child(str_repeat('a', 101))])));
        $this->assertRefused('child_order_invalid', 'children', RefusalKind::Invalid, fn (): mixed => $this->writes()->setYear($this->year(children: [$this->child(order: 4)])));
        $this->assertRefused('date_invalid', 'children', RefusalKind::Invalid, fn (): mixed => $this->writes()->setYear($this->year(children: [$this->child(birth: 'May 2019')])));
        $this->assertRefused('deduction_invalid', 'deductions', RefusalKind::Invalid, fn (): mixed => $this->writes()->setYear($this->year(deductions: [['type' => 'mortgage', 'amount' => '1.00']])));
        $this->assertRefused('deduction_repeated', 'deductions', RefusalKind::Invalid, fn (): mixed => $this->writes()->setYear($this->year(deductions: [['type' => 'gifts', 'amount' => '1.00'], ['type' => 'gifts', 'amount' => '2.00']])));
        $this->assertRefused('amount_invalid', 'employmentBase', RefusalKind::Invalid, fn (): mixed => $this->writes()->setYear($this->year(employmentBase: '-1.00')));
        $this->assertRefused('return_route_invalid', 'returnRoute', RefusalKind::Invalid, fn (): mixed => $this->writes()->setYear($this->year(returnRoute: 'fax')));
    }

    public function testAnAuthorityAccountIsSetAndReplaced(): void
    {
        $this->writes()->setAuthority(new SetAuthorityAccountInput('health', '1234567890/0300', '8501011234'));
        $this->writes()->setAuthority(new SetAuthorityAccountInput('social', '000000-1234567890/0710', '9876543210'));
        $this->writes()->setAuthority(new SetAuthorityAccountInput('health', '1111111111/0300'));
        $this->em->clear();

        $authorities = $this->reads()->profile()->authorities;
        self::assertSame([['social', '000000-1234567890/0710', '9876543210'], ['health', '1111111111/0300', '']], array_map(static fn (\Foresi\TaxesAndContributions\Contract\AuthorityAccountView $account): array => [$account->authority, $account->account, $account->variableSymbol], $authorities));
        $this->assertRefused('authority_invalid', 'authority', RefusalKind::Invalid, fn (): mixed => $this->writes()->setAuthority(new SetAuthorityAccountInput('customs', '1/0100')));
        $this->assertRefused('account_invalid', 'account', RefusalKind::Invalid, fn (): mixed => $this->writes()->setAuthority(new SetAuthorityAccountInput('health', ' ')));
    }

    public function testAMemberIsRefusedWithOwnerOnly(): void
    {
        $this->writes()->addStatus(new AddStatusInput('vat_payer', 'no', '2026-01-01'));
        $this->signIn($this->member);

        $this->assertRefused('owner_only', null, RefusalKind::Forbidden, fn (): mixed => $this->reads()->profile());
        $this->assertRefused('owner_only', null, RefusalKind::Forbidden, fn (): mixed => $this->reads()->year(2026));
        $this->assertRefused('owner_only', null, RefusalKind::Forbidden, fn (): mixed => $this->writes()->addStatus(new AddStatusInput('vat_payer', 'yes', '2026-11-12')));
        $this->assertRefused('owner_only', null, RefusalKind::Forbidden, fn (): mixed => $this->writes()->setYear($this->year()));
        $this->assertRefused('owner_only', null, RefusalKind::Forbidden, fn (): mixed => $this->writes()->setAuthority(new SetAuthorityAccountInput('health', '1/0300')));
        self::assertCount(1, $this->em->getRepository(StatusPeriod::class)->findAll());
    }

    public function testProfileChangedCarriesNoPersonalData(): void
    {
        $this->writes()->setYear($this->year(children: [$this->child('Elder daughter', '2019-05-14')], deductions: [['type' => 'gifts', 'amount' => '5000.00']], employmentBase: '120000.00'));
        $this->writes()->addStatus(new AddStatusInput('sickness_insurance', 'on', '2026-03-01', '10000.00'));
        $this->writes()->setAuthority(new SetAuthorityAccountInput('health', '1234567890/0300', '8501011234'));

        $events = $this->eventData('taxes-and-contributions.profile.changed');

        self::assertCount(3, $events);
        $all = implode("\n", $events);
        foreach (['Elder daughter', '2019-05-14', '500000', '5000', '120000', '1000000', '10000', '1234567890', '8501011234', '"on"'] as $personal) {
            self::assertStringNotContainsString($personal, $all);
        }
        self::assertSame(['what' => 'year', 'kind' => null, 'year' => 2026, 'valid_from' => null], json_decode($events[0], true, flags: \JSON_THROW_ON_ERROR));
        self::assertSame(['what' => 'status', 'kind' => 'sickness_insurance', 'year' => null, 'valid_from' => '2026-03-01'], json_decode($events[1], true, flags: \JSON_THROW_ON_ERROR));
        self::assertSame(['what' => 'authority', 'kind' => 'health', 'year' => null, 'valid_from' => null], json_decode($events[2], true, flags: \JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<mixed> $methods
     * @param list<mixed> $children
     * @param list<mixed> $deductions
     */
    private function year(
        array $methods = ['actual', 'lump_60'],
        ?string $chosen = null,
        array $children = [],
        ?int $spouseMonths = null,
        string $year = '2026',
        array $deductions = [],
        ?string $employmentBase = null,
        ?string $previousSocialBase = null,
        ?string $previousOverviewFiledOn = null,
        ?string $returnRoute = null,
    ): SetYearInputsInput {
        return new SetYearInputsInput($year, $methods, $chosen, $children, $spouseMonths, deductions: $deductions, employmentBase: $employmentBase, previousSocialBase: $previousSocialBase, previousOverviewFiledOn: $previousOverviewFiledOn, returnRoute: $returnRoute);
    }

    /**
     * @return array<string, mixed>
     */
    private function child(string $label = 'Elder daughter', string $birth = '2019-05-14', ?int $order = 1, int $months = 12): array
    {
        return ['label' => $label, 'birth_date' => $birth, 'order' => $order, 'ztp_p' => false, 'months' => $months];
    }

    private function assertRefused(string $reason, ?string $field, RefusalKind $kind, \Closure $work): void
    {
        try {
            $work();
            self::fail('Expected the refusal '.$reason.'.');
        } catch (TaxesRefused $refused) {
            self::assertSame([$reason, $field, $kind], [$refused->reason(), $refused->field(), $refused->kind()]);
        }
    }
}
