<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Application;

use App\Banking\Application\Candidate;
use App\Banking\Application\MatchDecision;
use App\Banking\Application\PairingRules;
use App\Banking\Domain\BankMovement;
use App\Banking\Domain\MessageTrust;
use App\Banking\Domain\MovementDirection;
use App\Banking\Domain\MovementState;
use App\Banking\Domain\UnmatchedReason;
use App\Invoicing\Application\AllocationRequest;
use App\Invoicing\Application\PaymentRequest;
use App\Invoicing\Application\RecordPayment;
use App\Invoicing\Domain\PaymentSource;
use App\Party\Domain\Address;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tests\Banking\Support\BankingTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * decide() on candidates made up here, and candidates() on stored invoices.
 */
final class MatcherTest extends BankingTestCase
{
    private const array ON = [true, false];
    private const array SMALL = [true, true];

    /**
     * @return iterable<string, array{string, list<array{string, string, string}>, array{bool, bool}, list<array{string, string, ?string}>|string}>
     */
    public static function decisions(): iterable
    {
        // [invoice, remaining, due date] → [invoice, amount, settlement] or an unmatched reason.
        yield 'exact VS and amount pays' => ['1210.00', [['A', '1210.00', '2026-11-15']], self::ON, [['A', '1210.00', null]]];
        yield 'lower amount is partial' => ['500.00', [['A', '1210.00', '2026-11-15']], self::ON, [['A', '500.00', null]]];
        yield 'higher amount is overpaid' => ['1300.00', [['A', '1210.00', '2026-11-15']], self::ON, [['A', '1300.00', null]]];
        yield 'small difference below settles' => ['999.01', [['A', '1000.00', '2026-11-15']], self::SMALL, [['A', '999.01', '0.99']]];
        yield 'small difference above settles' => ['1000.50', [['A', '1000.00', '2026-11-15']], self::SMALL, [['A', '1000.50', '-0.50']]];
        yield 'a difference past 99 minor is partial' => ['998.99', [['A', '1000.00', '2026-11-15']], self::SMALL, [['A', '998.99', null]]];
        yield 'small difference off is partial' => ['999.01', [['A', '1000.00', '2026-11-15']], self::ON, [['A', '999.01', null]]];
        yield 'several: the one with the equal remaining' => ['300.00', [['A', '500.00', '2026-11-01'], ['B', '300.00', '2026-11-10']], self::ON, [['B', '300.00', null]]];
        yield 'several: within the small difference' => ['299.50', [['A', '500.00', '2026-11-01'], ['B', '300.00', '2026-11-10']], self::SMALL, [['B', '299.50', '0.50']]];
        yield 'several: paid together when the sum matches' => ['800.00', [['B', '300.00', '2026-11-10'], ['A', '500.00', '2026-11-01']], self::ON, [['A', '500.00', null], ['B', '300.00', null]]];
        yield 'several: otherwise ambiguous' => ['100.00', [['B', '300.00', '2026-11-10'], ['A', '500.00', '2026-11-01']], self::ON, 'ambiguous'];
        yield 'several: two equal are ambiguous' => ['300.00', [['B', '300.00', '2026-11-10'], ['A', '300.00', '2026-11-01']], self::ON, 'ambiguous'];
        yield 'no candidate' => ['100.00', [], self::ON, 'no_invoice'];
        yield 'automatic pairing off' => ['1210.00', [['A', '1210.00', '2026-11-15']], [false, false], 'auto_pairing_off'];
    }

    /**
     * @param list<array{string, string, string}>         $candidates
     * @param array{bool, bool}                           $rules
     * @param list<array{string, string, ?string}>|string $expected
     */
    #[DataProvider('decisions')]
    public function testDecisions(string $amount, array $candidates, array $rules, array|string $expected): void
    {
        $decision = $this->decide($this->movement($amount), $this->candidates($candidates), new PairingRules(...$rules));

        self::assertSame($expected, $this->shape($decision));
    }

    public function testSeveralSameVsOtherwiseAmbiguousOldestFirst(): void
    {
        $decision = $this->decide($this->movement('100.00'), $this->candidates([['B', '300.00', '2026-11-10'], ['C', '200.00', '2026-11-20'], ['A', '500.00', '2026-11-01']]), new PairingRules(true, false));

        self::assertSame(UnmatchedReason::Ambiguous, $decision->reason);
        self::assertSame(['A', 'B', 'C'], array_map(static fn (Candidate $candidate): string => $candidate->invoiceId, $decision->suggestions));
    }

    public function testSmallDifferenceSettlesOnlyInCzk(): void
    {
        $eur = new Candidate('A', '2026-0001', new \DateTimeImmutable('2026-11-15'), Money::of('1000.00', Currency::EUR), 'Example Customer a.s.', new \DateTimeImmutable('2026-11-01'));

        $decision = $this->decide($this->movement('999.01', Currency::EUR), [$eur], new PairingRules(true, true));

        self::assertSame([['A', '999.01', null]], $this->shape($decision));
    }

    public function testTheLimitComesFromTheFirmsCountry(): void
    {
        self::assertSame(99, $this->matcher->settlementLimit($this->movement('999.01')));
        self::assertNull($this->matcher->settlementLimit($this->movement('999.01', Currency::EUR)));
    }

    public function testAFirmWhoseCountryGivesNoLimitSettlesNothing(): void
    {
        $this->firm->update($this->firm->name(), $this->firm->registrationNumber(), $this->firm->vatId(), new Address('Example Street 1', 'Example City', '100 00', 'SK'), $this->firm->vatStatus(), $this->firm->registerEntry());
        $movement = $this->movement('999.01');

        $decision = $this->decide($movement, $this->candidates([['A', '1000.00', '2026-11-15']]), new PairingRules(true, true));

        self::assertNull($this->matcher->settlementLimit($movement));
        self::assertSame([['A', '999.01', null]], $this->shape($decision));
    }

    public function testAnotherCurrencyIsCurrencyMismatch(): void
    {
        $eur = new Candidate('A', '2026-0001', new \DateTimeImmutable('2026-11-15'), Money::of('50.00', Currency::EUR), 'Example Customer a.s.', new \DateTimeImmutable('2026-11-01'));

        $decision = $this->decide($this->movement('50.00'), [$eur], new PairingRules(true, false));

        self::assertSame(UnmatchedReason::CurrencyMismatch, $decision->reason);
        self::assertSame([], $decision->suggestions);
    }

    public function testNoSymbolOrOnlyZerosIsNoSymbol(): void
    {
        foreach ([null, '', '0', '000'] as $vs) {
            $movement = $this->movement('1210.00', vs: $vs);
            self::assertSame([], $this->matcher->candidates($movement));
            self::assertSame(UnmatchedReason::NoSymbol, $this->decide($movement, [], new PairingRules(true, false))->reason);
        }
    }

    public function testTheGateKeepsCandidatesAsSuggestions(): void
    {
        $candidates = $this->candidates([['A', '1210.00', '2026-11-15']]);

        $unverified = $this->decide($this->movement('1210.00', trust: MessageTrust::Unverified), $candidates, new PairingRules(true, false));
        $failed = $this->decide($this->movement('1210.00', trust: MessageTrust::Failed), $candidates, new PairingRules(true, false));

        self::assertSame([UnmatchedReason::Unverified, ['A']], [$unverified->reason, array_map(static fn (Candidate $candidate): string => $candidate->invoiceId, $unverified->suggestions)]);
        self::assertSame(UnmatchedReason::Rejected, $failed->reason);
        self::assertFalse($unverified->pairs());
    }

    public function testAnInvoiceIssuedAfterThePaymentIsOnlySuggested(): void
    {
        $later = new Candidate('A', '2026-0001', new \DateTimeImmutable('2026-12-05'), $this->czk('1210.00'), 'Example Customer a.s.', new \DateTimeImmutable('2026-11-21'));

        $decision = $this->decide($this->movement('1210.00'), [$later], new PairingRules(true, false));

        self::assertSame(UnmatchedReason::NoInvoice, $decision->reason);
        self::assertSame([$later], $decision->suggestions);
    }

    public function testLeadingZerosInVsStillMatch(): void
    {
        $invoice = $this->issue->issue($this->input(overrides: ['variableSymbol' => '2026001']));

        $found = $this->matcher->candidates($this->movement('1210.00', vs: '0002026001'));

        self::assertSame([[$invoice->id(), $invoice->number(), 121000, 'CZK', 'Example Customer a.s.', '2026-11-15']], array_map(static fn (Candidate $candidate): array => [$candidate->invoiceId, $candidate->number, $candidate->remaining->minor(), $candidate->remaining->currency()->value, $candidate->customerName, $candidate->dueDate->format('Y-m-d')], $found));
    }

    public function testATrashedOrPaidInvoiceIsNoCandidate(): void
    {
        $trashed = $this->issue->issue($this->input());
        $this->trash->trash($trashed->id(), 'Issued by mistake');
        $paid = $this->issue->issue($this->input());
        $this->pay($paid->id(), '1210.00');
        $overpaid = $this->issue->issue($this->input());
        $this->pay($overpaid->id(), '1300.00');
        $partly = $this->issue->issue($this->input());
        $this->pay($partly->id(), '210.00');

        $found = $this->matcher->candidates($this->movement('1000.00'));

        self::assertSame([[$partly->id(), 100000]], array_map(static fn (Candidate $candidate): array => [$candidate->invoiceId, $candidate->remaining->minor()], $found));
    }

    public function testAnotherCurrencyInvoiceIsACandidateTheDecisionTellsApart(): void
    {
        // Invoices are in the home currency only, so the movement is the one in another.
        $this->issue->issue($this->input());
        $movement = $this->movement('1210.00', Currency::EUR);

        $decision = $this->decide($movement, $this->matcher->candidates($movement), new PairingRules(true, false));

        self::assertSame(UnmatchedReason::CurrencyMismatch, $decision->reason);
    }

    public function testAnotherFirmsInvoiceIsNoCandidate(): void
    {
        $other = $this->firm(vatId: 'CZ12345687', registrationNumber: '12345687');
        $otherSeries = $this->seriesOf($other);
        $this->em->flush();
        $this->issue->issue($this->input(overrides: ['firmId' => $other->id(), 'seriesId' => $otherSeries->id()]));

        self::assertSame([], $this->matcher->candidates($this->movement('1210.00')));
    }

    private function pay(string $invoiceId, string $amount): void
    {
        self::getContainer()->get(RecordPayment::class)->record(new PaymentRequest(
            $this->firm->id(),
            new \DateTimeImmutable('2026-11-20'),
            $this->czk($amount),
            PaymentMethod::BankTransfer,
            PaymentSource::Manual,
            null,
            '',
            [new AllocationRequest($invoiceId, $this->czk($amount))],
        ));
    }

    /**
     * @param list<Candidate> $candidates
     */
    private function decide(BankMovement $movement, array $candidates, PairingRules $rules): MatchDecision
    {
        return $this->matcher->decide($movement, $candidates, $rules, $this->matcher->settlementLimit($movement));
    }

    private function movement(string $amount, Currency $currency = Currency::CZK, ?string $vs = '20260001', MessageTrust $trust = MessageTrust::Verified): BankMovement
    {
        return new BankMovement($this->account, null, MovementDirection::Incoming, Money::of($amount, $currency), new \DateTimeImmutable('2026-11-20'), null, null, null, $vs, null, null, null, null, str_repeat('a', 64), $trust, 'dmarc', MovementState::Unmatched, UnmatchedReason::NoInvoice);
    }

    /**
     * @param list<array{string, string, string}> $rows
     *
     * @return list<Candidate>
     */
    private function candidates(array $rows): array
    {
        return array_map(fn (array $row): Candidate => new Candidate($row[0], '2026-'.$row[0], new \DateTimeImmutable($row[2]), $this->czk($row[1]), 'Example Customer a.s.', new \DateTimeImmutable('2026-11-01')), $rows);
    }

    /**
     * @return list<array{string, string, ?string}>|string
     */
    private function shape(MatchDecision $decision): array|string
    {
        if (!$decision->pairs()) {
            return $decision->reason instanceof UnmatchedReason ? $decision->reason->value : 'none';
        }

        return array_map(static fn (AllocationRequest $allocation): array => [$allocation->invoiceId, self::decimal($allocation->amount), $allocation->settlement instanceof Money ? self::decimal($allocation->settlement) : null], $decision->allocations);
    }

    private static function decimal(Money $money): string
    {
        $minor = $money->minor();

        return ($minor < 0 ? '-' : '').intdiv(abs($minor), 100).'.'.str_pad((string) (abs($minor) % 100), 2, '0', \STR_PAD_LEFT);
    }
}
