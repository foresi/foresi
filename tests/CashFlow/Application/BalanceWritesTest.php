<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\CashFlow\Application;

use App\CashFlow\Application\BalanceWrites;
use App\CashFlow\Application\CashFlowRefused;
use App\CashFlow\Application\CashFlowStore;
use App\CashFlow\Application\ClearExpectedDateInput;
use App\CashFlow\Application\RecordCheckpointInput;
use App\CashFlow\Application\SetExpectedDateInput;
use App\CashFlow\Application\SetMinimumBalanceInput;
use App\CashFlow\Domain\Checkpoint;
use App\CashFlow\Domain\ExpectedDate;
use App\CashFlow\Domain\MinimumBalance;
use App\Shared\Application\RefusalKind;
use App\Shared\Domain\Currency;
use App\Tests\CashFlow\Support\CashFlowTestCase;

/**
 * The balance side of the cash flow (C6, C7, C14): a checkpoint per account
 * and day, corrected when the day is entered again and never in the future;
 * the expected day of a document; the minimum balance of a firm.
 */
final class BalanceWritesTest extends CashFlowTestCase
{
    public function testRecordsACheckpointInTheCurrencyOfTheAccountAndCorrectsTheSameDay(): void
    {
        $first = $this->writer()->checkpoint(new RecordCheckpointInput($this->account->id(), '2026-11-16', '125000.50'));
        $this->clock->modify('+1 hour');
        $again = $this->writer()->checkpoint(new RecordCheckpointInput($this->account->id(), '2026-11-16', '-300.00'));

        self::assertSame($first->id(), $again->id());
        $this->em->clear();
        $stored = $this->em->getRepository(Checkpoint::class)->findAll();
        self::assertCount(1, $stored);
        self::assertSame(-30000, $stored[0]->amount()->minor());
        self::assertSame(Currency::CZK, $stored[0]->amount()->currency());
        self::assertSame('2026-11-16', $stored[0]->on()->format('Y-m-d'));
        self::assertSame('2026-11-17T11:00:00+00:00', $stored[0]->recordedAt()->format(\DATE_ATOM));
        self::assertSame($this->account->id(), $stored[0]->account()->id());

        $euro = $this->euroAccount();
        self::assertSame(Currency::EUR, $this->writer()->checkpoint(new RecordCheckpointInput($euro->id(), '2026-11-17', '10.00'))->amount()->currency());
        self::assertSame(-30000, self::getContainer()->get(CashFlowStore::class)->latestCheckpoint($this->account, new \DateTimeImmutable('2026-11-17'))?->amount()->minor());
        self::assertNull(self::getContainer()->get(CashFlowStore::class)->latestCheckpoint($this->account, new \DateTimeImmutable('2026-11-15')));
    }

    public function testRefusesACheckpointInTheFutureOrOfAnUnknownAccount(): void
    {
        $this->assertRefused('date_in_future', 'on', fn (): Checkpoint => $this->writer()->checkpoint(new RecordCheckpointInput($this->account->id(), '2026-11-18', '1.00')));
        $this->assertRefused('amount_invalid', 'amount', fn (): Checkpoint => $this->writer()->checkpoint(new RecordCheckpointInput($this->account->id(), '2026-11-17', '1,00')));
        $this->assertRefused('date_invalid', 'on', fn (): Checkpoint => $this->writer()->checkpoint(new RecordCheckpointInput($this->account->id(), 'today', '1.00')));
        $this->assertRefused('not_found', null, fn (): Checkpoint => $this->writer()->checkpoint(new RecordCheckpointInput('0198a4f0-8b1e-7000-8000-000000000001', '2026-11-17', '1.00')));
        self::assertSame([], $this->em->getRepository(Checkpoint::class)->findAll());
    }

    public function testTodayIsThePragueDayInTheHourAroundMidnight(): void
    {
        // 23:30 in Prague on 30 November: 1 December is still tomorrow.
        $this->clock->modify('2026-11-30 22:30:00');
        $this->assertRefused('date_in_future', 'on', fn (): Checkpoint => $this->writer()->checkpoint(new RecordCheckpointInput($this->account->id(), '2026-12-01', '1.00')));

        // 00:30 in Prague on 1 December, while UTC is still on 30 November.
        $this->clock->modify('2026-11-30 23:30:00');
        self::assertSame('2026-12-01', $this->writer()->checkpoint(new RecordCheckpointInput($this->account->id(), '2026-12-01', '1.00'))->on()->format('Y-m-d'));
    }

    public function testSetsMovesAndClearsTheExpectedDayOfADocument(): void
    {
        $set = $this->writer()->expect(new SetExpectedDateInput(' invoice ', ' inv-1 ', '2026-12-15'));
        $moved = $this->writer()->expect(new SetExpectedDateInput('invoice', 'inv-1', '2026-12-20'));

        self::assertSame($set->id(), $moved->id());
        self::assertSame(['invoice:inv-1'], array_keys(self::getContainer()->get(CashFlowStore::class)->expectedDates()));
        $this->em->clear();
        $stored = $this->em->getRepository(ExpectedDate::class)->findAll();
        self::assertCount(1, $stored);
        self::assertSame(['invoice', 'inv-1', '2026-12-20'], [$stored[0]->subjectType(), $stored[0]->subjectId(), $stored[0]->expectedOn()->format('Y-m-d')]);

        self::assertSame(['invoice', 'inv-1'], $this->writer()->clearExpected(new ClearExpectedDateInput(' invoice', 'inv-1 ')));
        self::assertSame(['invoice', 'inv-1'], $this->writer()->clearExpected(new ClearExpectedDateInput('invoice', 'inv-1')));
        self::assertSame([], $this->em->getRepository(ExpectedDate::class)->findAll());
    }

    public function testRefusesASubjectThatIsNotOne(): void
    {
        $this->assertRefused('subject_type_invalid', 'subjectType', fn (): ExpectedDate => $this->writer()->expect(new SetExpectedDateInput('Invoice', 'inv-1', '2026-12-15')));
        $this->assertRefused('subject_type_invalid', 'subjectType', fn (): ExpectedDate => $this->writer()->expect(new SetExpectedDateInput('', 'inv-1', '2026-12-15')));
        $this->assertRefused('subject_type_invalid', 'subjectType', fn (): ExpectedDate => $this->writer()->expect(new SetExpectedDateInput(str_repeat('a', ExpectedDate::MAX_SUBJECT_TYPE + 1), 'inv-1', '2026-12-15')));
        $this->assertRefused('subject_id_invalid', 'subjectId', fn (): ExpectedDate => $this->writer()->expect(new SetExpectedDateInput('invoice', ' ', '2026-12-15')));
        $this->assertRefused('subject_id_invalid', 'subjectId', fn (): ExpectedDate => $this->writer()->expect(new SetExpectedDateInput('invoice', str_repeat('1', ExpectedDate::MAX_SUBJECT_ID + 1), '2026-12-15')));
        $this->assertRefused('date_invalid', 'expectedOn', fn (): ExpectedDate => $this->writer()->expect(new SetExpectedDateInput('invoice', 'inv-1', 'later')));
        $this->assertRefused('subject_id_invalid', 'subjectId', fn (): array => $this->writer()->clearExpected(new ClearExpectedDateInput('invoice', '')));
        self::assertSame('a'.str_repeat('1', ExpectedDate::MAX_SUBJECT_TYPE - 1), $this->writer()->expect(new SetExpectedDateInput('a'.str_repeat('1', ExpectedDate::MAX_SUBJECT_TYPE - 1), str_repeat('x', ExpectedDate::MAX_SUBJECT_ID), '2026-12-15'))->subjectType());
    }

    public function testSetsChangesAndRemovesTheMinimumBalance(): void
    {
        $set = $this->writer()->minimum(new SetMinimumBalanceInput($this->firm->id(), '50000.00'));
        $changed = $this->writer()->minimum(new SetMinimumBalanceInput($this->firm->id(), '60000.00'));

        self::assertSame($set?->id(), $changed?->id());
        self::assertSame(6000000, self::getContainer()->get(CashFlowStore::class)->minimumBalance($this->firm->id())?->amount()->minor());
        self::assertSame($this->firm->id(), $changed?->firm()->id());
        self::assertNull($this->writer()->minimum(new SetMinimumBalanceInput($this->firm->id(), null)));
        self::assertNull($this->writer()->minimum(new SetMinimumBalanceInput($this->firm->id(), null)));
        self::assertSame([], $this->em->getRepository(MinimumBalance::class)->findAll());
        self::assertNull(self::getContainer()->get(CashFlowStore::class)->minimumBalance('not-an-id'));
        $this->assertRefused('amount_invalid', 'amount', fn (): ?MinimumBalance => $this->writer()->minimum(new SetMinimumBalanceInput($this->firm->id(), 'lots')));
        $this->assertRefused('not_found', null, fn (): ?MinimumBalance => $this->writer()->minimum(new SetMinimumBalanceInput('0198a4f0-8b1e-7000-8000-000000000001', '1.00')));
    }

    public function testAGuestWritesNoBalance(): void
    {
        $this->signIn($this->guest);

        $this->assertRefused('forbidden', null, fn (): Checkpoint => $this->writer()->checkpoint(new RecordCheckpointInput($this->account->id(), '2026-11-17', '1.00')));
        $this->assertRefused('forbidden', null, fn (): ExpectedDate => $this->writer()->expect(new SetExpectedDateInput('invoice', 'inv-1', '2026-12-15')));
        $this->assertRefused('forbidden', null, fn (): array => $this->writer()->clearExpected(new ClearExpectedDateInput('invoice', 'inv-1')));
        $this->assertRefused('forbidden', null, fn (): ?MinimumBalance => $this->writer()->minimum(new SetMinimumBalanceInput($this->firm->id(), '1.00')));
    }

    private function writer(): BalanceWrites
    {
        return self::getContainer()->get(BalanceWrites::class);
    }

    private function assertRefused(string $reason, ?string $field, \Closure $work): void
    {
        try {
            $work();
            self::fail(\sprintf('Expected the refusal %s.', $reason));
        } catch (CashFlowRefused $refused) {
            self::assertSame($reason, $refused->reason(), $refused->getMessage());
            self::assertSame($field, $refused->field());
            self::assertSame(match ($reason) {
                'not_found' => RefusalKind::NotFound,
                'forbidden' => RefusalKind::Forbidden,
                default => RefusalKind::Invalid,
            }, $refused->kind());
        }
    }
}
