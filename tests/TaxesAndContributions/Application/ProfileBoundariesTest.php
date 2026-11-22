<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\TaxesAndContributions\Application;

use Foresi\TaxesAndContributions\Application\AddStatusInput;
use Foresi\TaxesAndContributions\Application\RemoveStatusInput;
use Foresi\TaxesAndContributions\Application\SetAuthorityAccountInput;
use Foresi\TaxesAndContributions\Application\SetYearInputsInput;
use Foresi\TaxesAndContributions\Application\TaxesRefused;
use Foresi\TaxesAndContributions\Contract\ExpenseMethod;
use Foresi\TaxesAndContributions\Contract\ReturnRoute;
use Foresi\TaxesAndContributions\Contract\StatusKind;
use Foresi\TaxesAndContributions\Infrastructure\Events\TaxesEventAccess;
use Foresi\Tests\TaxesAndContributions\Support\TaxesTestCase;

/**
 * The edges of the profile's rules: values are trimmed, lengths and ranges
 * end where they say, every field of a year is kept, and only the owner reads
 * the events.
 */
final class ProfileBoundariesTest extends TaxesTestCase
{
    public function testEveryKindTakesItsOwnValuesOnly(): void
    {
        self::assertSame(['sole_trader'], StatusKind::LegalForm->values());
        self::assertSame(['none', 'secondary', 'main'], StatusKind::Activity->values());
        self::assertSame(['yes', 'no'], StatusKind::VatPayer->values());
        self::assertSame(['yes', 'no'], StatusKind::HealthMinimumExempt->values());
        self::assertSame(['none', '1', '2', '3'], StatusKind::FlatRateBand->values());
        self::assertSame(['off', 'on'], StatusKind::SicknessInsurance->values());
        self::assertTrue(StatusKind::SicknessInsurance->needsBase('on'));
        self::assertFalse(StatusKind::SicknessInsurance->needsBase('off'));
        self::assertFalse(StatusKind::Activity->needsBase('on'));
    }

    public function testAStatusIsTrimmedAndItsRemovalIsAnEvent(): void
    {
        $status = $this->writes()->addStatus(new AddStatusInput(' vat_payer ', ' yes ', ' 2026-11-12 ', ' '));

        self::assertSame([StatusKind::VatPayer, 'yes', '2026-11-12', null], [$status->kind, $status->value, $status->validFrom->format('Y-m-d'), $status->base]);
        $this->writes()->removeStatus(new RemoveStatusInput(' '.$status->id.' '));
        $this->em->clear();
        self::assertSame([], $this->reads()->profile()->statuses);
        self::assertCount(2, $this->eventData('taxes-and-contributions.profile.changed'));
        self::assertStringContainsString('"valid_from":"2026-11-12"', $this->eventData('taxes-and-contributions.profile.changed')[1]);
    }

    public function testAnAuthorityAccountEndsAt64AndItsSymbolAt35Characters(): void
    {
        $set = $this->writes()->setAuthority(new SetAuthorityAccountInput(' social ', ' '.str_repeat('1', 64).' ', ' '.str_repeat('9', 35).' '));
        self::assertSame(['social', str_repeat('1', 64), str_repeat('9', 35)], [$set->authority, $set->account, $set->variableSymbol]);

        $this->assertReason('account_invalid', fn (): mixed => $this->writes()->setAuthority(new SetAuthorityAccountInput('social', str_repeat('č', 65))));
        $this->assertReason('variable_symbol_invalid', fn (): mixed => $this->writes()->setAuthority(new SetAuthorityAccountInput('social', '1/0100', str_repeat('č', 36))));
        self::assertSame(str_repeat('č', 64), $this->writes()->setAuthority(new SetAuthorityAccountInput('social', str_repeat('č', 64), str_repeat('č', 35)))->account);
    }

    public function testAYearIs2000To2099(): void
    {
        self::assertSame(2000, $this->writes()->setYear(new SetYearInputsInput(' 2000 '))->year);
        self::assertSame(2099, $this->writes()->setYear(new SetYearInputsInput('2099'))->year);
        foreach (['1999', '2100', 'x2026', '2026x', '20.5', ''] as $year) {
            $this->assertReason('year_invalid', fn (): mixed => $this->writes()->setYear(new SetYearInputsInput($year)));
        }
        // Left out, the methods are the actual costs and the 60 % lump sum.
        self::assertSame([ExpenseMethod::Actual, ExpenseMethod::Lump60], $this->writes()->setYear(new SetYearInputsInput('2026'))->expenseMethods);
    }

    public function testEveryFieldOfAYearIsKept(): void
    {
        $this->writes()->setYear(new SetYearInputsInput(
            ' 2026 ',
            ['lump_40', 'actual', 'lump_40'],
            ' actual ',
            [['label' => ' Elder daughter ', 'birth_date' => ' 2019-05-14 ', 'order' => 3, 'ztp_p' => true], ['label' => 'Son', 'birth_date' => '2024-01-31', 'order' => 1, 'months' => 0]],
            0,
            true,
            true,
            [['type' => 'gifts', 'amount' => ' 1.50 '], ['type' => 'exam_fees', 'amount' => '0']],
            ' 1.00 ',
            ' 2.00 ',
            ' 3.00 ',
            ' 4.00 ',
            ' 5.00 ',
            ' 6.00 ',
            ' 7.00 ',
            ' 2026-04-30 ',
            'paper',
            ' 2026-02-01 ',
            ' 8.00 ',
            5,
        ));
        $this->em->clear();

        $year = $this->reads()->year(2026);
        self::assertSame([ExpenseMethod::Lump40, ExpenseMethod::Actual], $year->expenseMethods);
        self::assertSame(ExpenseMethod::Actual, $year->chosenMethod);
        self::assertSame(['Elder daughter', '2019-05-14', 3, true, 12], [$year->children[0]->label, $year->children[0]->birthDate->format('Y-m-d'), $year->children[0]->order, $year->children[0]->ztpP, $year->children[0]->months]);
        self::assertSame(['Son', 1, false, 0], [$year->children[1]->label, $year->children[1]->order, $year->children[1]->ztpP, $year->children[1]->months]);
        self::assertSame([0, true, true], [$year->spouse?->months, $year->spouse?->ztpP, $year->spouse?->lowIncome]);
        self::assertSame(['gifts' => 150, 'exam_fees' => 0], array_map(static fn (\Foresi\Shared\Domain\Money $amount): int => $amount->minor(), $year->deductions));
        self::assertSame([100, 200, 300, 400, 500, 600, 700, 800], [$year->employmentBase?->minor(), $year->employmentTaxWithheld?->minor(), $year->rentalBase?->minor(), $year->otherBase?->minor(), $year->lastKnownTax?->minor(), $year->previousSocialBase?->minor(), $year->previousHealthBase?->minor(), $year->averageWageOverride?->minor()]);
        self::assertSame(['2026-04-30', ReturnRoute::Paper, '2026-02-01'], [$year->previousOverviewFiledOn?->format('Y-m-d'), $year->returnRoute, $year->activityStart?->format('Y-m-d')]);
        self::assertSame(5, $year->previousActivityMonths);

        // The years stay in order, whatever order they were set in.
        $this->writes()->setYear(new SetYearInputsInput('2025'));
        $this->em->clear();
        self::assertSame([2025, 2026], array_keys($this->reads()->profile()->years));
    }

    public function testTheEdgesOfAYear(): void
    {
        $child = static fn (array $change): array => $change + ['label' => 'Child', 'birth_date' => '2020-01-01', 'order' => 1, 'months' => 12];
        $this->assertReason('child_label_invalid', fn (): mixed => $this->writes()->setYear(new SetYearInputsInput('2026', children: [$child(['label' => str_repeat('a', 101)])])));
        self::assertSame(str_repeat('č', 100), $this->writes()->setYear(new SetYearInputsInput('2026', children: [$child(['label' => str_repeat('č', 100)])]))->children[0]->label);
        $this->assertReason('child_label_invalid', fn (): mixed => $this->writes()->setYear(new SetYearInputsInput('2026', children: [$child(['label' => 5])])));
        self::assertSame('', $this->writes()->setYear(new SetYearInputsInput('2026', children: [$child(['label' => '  '])]))->children[0]->label);
        self::assertSame([1, false], [($c = $this->writes()->setYear(new SetYearInputsInput('2026', children: [$child(['order' => null])]))->children[0])->order, $c->orderChosen]);
        $this->assertReason('child_order_invalid', fn (): mixed => $this->writes()->setYear(new SetYearInputsInput('2026', children: [$child(['order' => 0])])));
        $this->assertReason('child_order_invalid', fn (): mixed => $this->writes()->setYear(new SetYearInputsInput('2026', children: [$child(['order' => '1'])])));
        $this->assertReason('months_invalid', fn (): mixed => $this->writes()->setYear(new SetYearInputsInput('2026', children: [$child(['months' => -1])])));
        $this->assertReason('months_invalid', fn (): mixed => $this->writes()->setYear(new SetYearInputsInput('2026', children: [$child(['months' => '12'])])));
        $this->assertReason('months_invalid', fn (): mixed => $this->writes()->setYear(new SetYearInputsInput('2026', spouseMonths: -1)));
        self::assertSame(12, $this->writes()->setYear(new SetYearInputsInput('2026', spouseMonths: 12))->spouse?->months);
        $this->assertReason('activity_months_invalid', fn (): mixed => $this->writes()->setYear(new SetYearInputsInput('2026', previousActivityMonths: 0)));
        $this->assertReason('activity_months_invalid', fn (): mixed => $this->writes()->setYear(new SetYearInputsInput('2026', previousActivityMonths: 13)));
        self::assertSame([1, 12, null], [
            $this->writes()->setYear(new SetYearInputsInput('2026', previousActivityMonths: 1))->previousActivityMonths,
            $this->writes()->setYear(new SetYearInputsInput('2026', previousActivityMonths: 12))->previousActivityMonths,
            $this->writes()->setYear(new SetYearInputsInput('2026'))->previousActivityMonths,
        ]);
        $this->assertReason('date_invalid', fn (): mixed => $this->writes()->setYear(new SetYearInputsInput('2026', children: [$child(['birth_date' => null])])));
        $this->assertReason('child_invalid', fn (): mixed => $this->writes()->setYear(new SetYearInputsInput('2026', children: ['Elder daughter'])));
        self::assertCount(20, $this->writes()->setYear(new SetYearInputsInput('2026', children: array_fill(0, 20, $child([]))))->children);
        $this->assertReason('children_too_many', fn (): mixed => $this->writes()->setYear(new SetYearInputsInput('2026', children: array_fill(0, 21, $child([])))));
        $this->assertReason('expense_method_invalid', fn (): mixed => $this->writes()->setYear(new SetYearInputsInput('2026', [1])));
        $this->assertReason('expense_method_invalid', fn (): mixed => $this->writes()->setYear(new SetYearInputsInput('2026', chosenMethod: 'lump_50')));
        self::assertNull($this->writes()->setYear(new SetYearInputsInput('2026', chosenMethod: ''))->chosenMethod);
        $this->assertReason('deduction_invalid', fn (): mixed => $this->writes()->setYear(new SetYearInputsInput('2026', deductions: ['gifts'])));
        $this->assertReason('deduction_invalid', fn (): mixed => $this->writes()->setYear(new SetYearInputsInput('2026', deductions: [['type' => 1, 'amount' => '1.00']])));
        $this->assertReason('amount_invalid', fn (): mixed => $this->writes()->setYear(new SetYearInputsInput('2026', deductions: [['type' => 'gifts']])));
        $this->assertReason('amount_invalid', fn (): mixed => $this->writes()->setYear(new SetYearInputsInput('2026', employmentBase: '1.005')));
        $this->assertReason('date_invalid', fn (): mixed => $this->writes()->setYear(new SetYearInputsInput('2026', activityStart: '2026-13-01')));
        self::assertSame(ReturnRoute::Electronic, $this->writes()->setYear(new SetYearInputsInput('2026', returnRoute: ''))->returnRoute);
        self::assertNull($this->writes()->setYear(new SetYearInputsInput('2026', employmentBase: ' ', activityStart: ' '))->employmentBase);
    }

    public function testOnlyTheOwnerReadsTheEvents(): void
    {
        $access = self::getContainer()->get(TaxesEventAccess::class);

        self::assertSame('taxes-and-contributions', $access->module());
        self::assertTrue($access->mayRead());
        self::assertTrue($access->mayUserRead($this->owner->user()->id()));
        self::assertFalse($access->mayUserRead($this->member->user()->id()));
        $this->signIn($this->member);
        self::assertFalse($access->mayRead());
    }

    private function assertReason(string $reason, \Closure $work): void
    {
        try {
            $work();
            self::fail('Expected the refusal '.$reason.'.');
        } catch (TaxesRefused $refused) {
            self::assertSame($reason, $refused->reason());
        }
    }
}
