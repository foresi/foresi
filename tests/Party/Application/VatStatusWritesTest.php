<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Application;

use Foresi\Party\Contract\VatPeriodLength;
use Foresi\Party\Contract\VatRegistration;
use Foresi\Party\Contract\VatStretch;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Application\RefusalKind;
use Foresi\Tests\Party\Support\VatStatusTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The firm's VAT status over time: each stretch holds from its day until the
 * next, the firm keeps the status of today, and only the owner changes them.
 */
final class VatStatusWritesTest extends VatStatusTestCase
{
    private bool $withoutVatId = false;

    protected function setUp(): void
    {
        $this->withoutVatId = str_contains($this->name(), 'WithoutAVatId');
        parent::setUp();
    }

    protected function vatId(): ?VatId
    {
        return $this->withoutVatId ? null : parent::vatId();
    }

    public function testAFirmWithoutStoredStatusesHoldsItsKeptOneFromTheStart(): void
    {
        $stretches = $this->reads()->stretches();

        self::assertEquals([new VatStretch($this->firm->id(), VatRegistration::NotRegistered, null, null)], $stretches);
        self::assertSame($stretches[0]->id, $this->reads()->on(new \DateTimeImmutable('1990-01-01'))?->id);
        self::assertSame(0, $this->storedCount(), 'reading stores nothing');
    }

    public function testAStretchAppliesFromItsDayUntilTheNext(): void
    {
        $added = $this->add('registered', 'month', '2026-11-12');

        self::assertSame([VatRegistration::Registered, VatPeriodLength::Month, '2026-11-12'], [$added->status, $added->periodLength, $added->validFrom?->format('Y-m-d')]);
        self::assertSame([['not_registered', null, null], ['registered', 'month', '2026-11-12']], $this->stretches());
        $before = $this->reads()->on(new \DateTimeImmutable('2026-11-11'));
        self::assertSame([VatRegistration::NotRegistered, null], [$before?->status, $before?->periodLength]);
        self::assertNotSame($this->firm->id(), $before?->id, 'the kept status is stored with an id of its own');
        $from = $this->reads()->on(new \DateTimeImmutable('2026-11-12'));
        self::assertSame([$added->id, VatRegistration::Registered, VatPeriodLength::Month], [$from?->id, $from?->status, $from?->periodLength]);
        self::assertSame($added->id, $this->reads()->on(new \DateTimeImmutable('2030-01-01'))?->id);
        self::assertSame(VatStatus::Registered, $this->storedStatus(), 'the firm keeps the status of today');
        self::assertSame([['what' => 'added', 'status' => 'registered', 'period_length' => 'month', 'valid_from' => '2026-11-12']], $this->events('party.firm.vat_status_changed'));
    }

    public function testAStretchThatBeginsLaterLeavesTodaysStatus(): void
    {
        $this->add('registered', 'quarter', '2027-01-01');

        self::assertSame(VatStatus::NotRegistered, $this->storedStatus());
        self::assertSame([['not_registered', null, null], ['registered', 'quarter', '2027-01-01']], $this->stretches());
    }

    public function testStretchesAreReadOldestFirstWhateverTheOrderTheyWereAdded(): void
    {
        $this->add('identified_person', null, '2027-03-01');
        $this->add('registered', 'month', '2026-11-12');

        self::assertSame([['not_registered', null, null], ['registered', 'month', '2026-11-12'], ['identified_person', null, '2027-03-01']], $this->stretches());
        self::assertSame(VatStatus::Registered, $this->storedStatus());
    }

    public function testAPayerNeedsAPeriodLength(): void
    {
        $refused = $this->refused(fn (): VatStretch => $this->add('registered', null, '2026-11-12'));

        self::assertSame(['period_length_required', 'periodLength', RefusalKind::Invalid], [$refused->reason(), $refused->field(), $refused->kind()]);
        self::assertSame(0, $this->storedCount());
    }

    public function testANonPayerRefusesAPeriodLength(): void
    {
        foreach (['not_registered', 'identified_person'] as $status) {
            $refused = $this->refused(fn (): VatStretch => $this->add($status, 'month', '2026-11-12'));

            self::assertSame(['period_length_not_allowed', 'periodLength', RefusalKind::Invalid], [$refused->reason(), $refused->field(), $refused->kind()], $status);
        }
        self::assertSame(0, $this->storedCount());
    }

    /**
     * @return iterable<string, array{string, string|null, string, string, string}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'unknown status' => ['payer', null, '2026-11-12', 'status_invalid', 'status'];
        yield 'empty status' => ['', null, '2026-11-12', 'status_invalid', 'status'];
        yield 'unknown period length' => ['registered', 'year', '2026-11-12', 'period_length_invalid', 'periodLength'];
        yield 'a day that does not exist' => ['registered', 'month', '2026-02-30', 'date_invalid', 'validFrom'];
        yield 'a day in another format' => ['registered', 'month', '12.11.2026', 'date_invalid', 'validFrom'];
        yield 'no day' => ['not_registered', null, '', 'date_invalid', 'validFrom'];
        yield 'a time with the day' => ['not_registered', null, '2026-11-12T10:00:00', 'date_invalid', 'validFrom'];
    }

    #[DataProvider('invalidInputs')]
    public function testAnInvalidInputNamesItsField(string $status, ?string $length, string $from, string $reason, string $field): void
    {
        $refused = $this->refused(fn (): VatStretch => $this->add($status, $length, $from));

        self::assertSame([$reason, $field, RefusalKind::Invalid], [$refused->reason(), $refused->field(), $refused->kind()]);
        self::assertSame(0, $this->storedCount());
    }

    public function testInputIsTrimmed(): void
    {
        $added = $this->add(' registered ', ' quarter ', ' 2026-11-12 ');

        self::assertSame([VatRegistration::Registered, VatPeriodLength::Quarter, '2026-11-12'], [$added->status, $added->periodLength, $added->validFrom?->format('Y-m-d')]);
        self::assertSame($added->id, $this->remove(' '.$added->id.' '));
    }

    public function testTwoStretchesOnOneDayAreRefused(): void
    {
        $this->add('registered', 'month', '2026-11-12');

        $refused = $this->refused(fn (): VatStretch => $this->add('identified_person', null, '2026-11-12'));

        self::assertSame(['same_day', RefusalKind::Conflict, null], [$refused->reason(), $refused->kind(), $refused->field()]);
        self::assertSame([['not_registered', null, null], ['registered', 'month', '2026-11-12']], $this->stretches());
    }

    public function testTheLastStretchCannotBeRemoved(): void
    {
        $refused = $this->refused(fn (): string => $this->remove($this->firm->id()));

        self::assertSame(['last_stretch', RefusalKind::Conflict], [$refused->reason(), $refused->kind()]);
        self::assertSame([['not_registered', null, null]], $this->stretches());

        $added = $this->add('registered', 'month', '2026-11-12');
        $kept = $this->reads()->stretches()[0]->id;
        self::assertSame($added->id, $this->remove($added->id));
        $refused = $this->refused(fn (): string => $this->remove($kept));
        self::assertSame('last_stretch', $refused->reason());
    }

    public function testRemovingAStretchLetsTheOneBeforeItHoldAgain(): void
    {
        $added = $this->add('registered', 'month', '2026-11-12');
        $this->em->clear();

        self::assertSame($added->id, $this->remove($added->id));

        self::assertSame([['not_registered', null, null]], $this->stretches());
        self::assertSame(VatStatus::NotRegistered, $this->storedStatus());
        self::assertSame([
            ['what' => 'added', 'status' => 'registered', 'period_length' => 'month', 'valid_from' => '2026-11-12'],
            ['what' => 'removed', 'status' => 'registered', 'period_length' => 'month', 'valid_from' => '2026-11-12'],
        ], $this->events('party.firm.vat_status_changed'));
    }

    public function testTheUndatedStretchCanGoOnceADatedOneIsThere(): void
    {
        $added = $this->add('registered', 'month', '2026-11-12');
        $undated = $this->reads()->stretches()[0]->id;

        $this->remove($undated);

        self::assertSame([['registered', 'month', '2026-11-12']], $this->stretches());
        // Before its day, the first stretch holds nothing; the firm keeps it as today's.
        self::assertNull($this->reads()->on(new \DateTimeImmutable('2026-11-11')));
        self::assertSame($added->id, $this->reads()->on(new \DateTimeImmutable('2026-11-12'))?->id);
        self::assertSame(VatStatus::Registered, $this->storedStatus());
    }

    public function testAnUnknownStretchIsNotFound(): void
    {
        $this->add('registered', 'month', '2026-11-12');

        foreach (['01937f4e-0000-7000-8000-000000000000', $this->firm->id(), ''] as $id) {
            $refused = $this->refused(fn (): string => $this->remove($id));

            self::assertSame(['not_found', RefusalKind::NotFound], [$refused->reason(), $refused->kind()], $id);
        }
        self::assertCount(2, $this->stretches());
    }

    public function testAPayerWithoutAVatIdIsRefused(): void
    {
        $refused = $this->refused(fn (): VatStretch => $this->add('registered', 'month', '2026-11-12'));

        self::assertSame(['vat_id_missing', RefusalKind::Conflict], [$refused->reason(), $refused->kind()]);
        self::assertSame(0, $this->storedCount(), 'the refusal stores nothing, not even the kept status');
        self::assertSame(VatStatus::NotRegistered, $this->storedStatus());

        $refused = $this->refused(fn (): VatStretch => $this->add('identified_person', null, '2026-11-20'));
        self::assertSame('vat_id_missing', $refused->reason(), 'an identified person shows a VAT ID too');
    }

    public function testAPayerFromALaterDayWithoutAVatIdIsKeptUntilThen(): void
    {
        $this->add('registered', 'month', '2027-01-01');

        self::assertSame(VatStatus::NotRegistered, $this->storedStatus());
        self::assertSame(2, $this->storedCount());
    }

    public function testAMemberGetsOwnerOnly(): void
    {
        $added = $this->add('registered', 'month', '2026-11-12');
        $this->signIn($this->member);

        foreach ([fn (): VatStretch => $this->add('registered', 'month', '2026-12-01'), fn (): string => $this->remove($added->id)] as $work) {
            $refused = $this->refused($work);

            self::assertSame(['owner_only', RefusalKind::Forbidden], [$refused->reason(), $refused->kind()]);
        }
        self::assertCount(2, $this->stretches());
    }

    public function testTheRefreshKeepsTheStatusOfToday(): void
    {
        $this->add('registered', 'month', '2026-12-01');
        self::assertFalse($this->writes()->refresh(), 'nothing changes before the day');

        $this->clock->modify('2026-12-01 10:00:00');

        self::assertTrue($this->writes()->refresh());
        self::assertSame(VatStatus::Registered, $this->storedStatus());
        self::assertFalse($this->writes()->refresh(), 'the second run of the day changes nothing');
    }

    public function testTheRefreshOfAFirmWithoutStoredStatusesChangesNothing(): void
    {
        self::assertFalse($this->writes()->refresh());
        self::assertSame(0, $this->storedCount());
    }
}
