<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Integration\Invoicing;

use Foresi\Banking\Application\Allocation;
use Foresi\Banking\Application\Candidate;
use Foresi\Banking\Application\MatchDecision;
use Foresi\Banking\Application\Matcher;
use Foresi\Banking\Application\PairingRules;
use Foresi\Banking\Contract\MovementDirection;
use Foresi\Banking\Contract\MovementTrust;
use Foresi\Banking\Domain\BankMovement;
use Foresi\Banking\Domain\MovementState;
use Foresi\Banking\Domain\UnmatchedReason;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\FirmBankAccount;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Banking\Integration\Invoicing\Support\InvoiceBankingTestCase;
use Foresi\Tests\Party\Support\FirmAccounts;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * decide() on candidates made up here, and candidates() on stored invoices.
 * The tests that need the Czech rules are in Integration/Country.
 */
final class MatcherTest extends InvoiceBankingTestCase
{
    private ?FirmBankAccount $foreign = null;

    public function testSeveralSameVsOtherwiseAmbiguousOldestFirst(): void
    {
        $decision = $this->decide($this->movement('100.00'), $this->candidates([['B', '300.00', '2026-11-10'], ['C', '200.00', '2026-11-20'], ['A', '500.00', '2026-11-01']]), new PairingRules(true, false));

        self::assertSame(UnmatchedReason::Ambiguous, $decision->reason);
        self::assertSame(['A', 'B', 'C'], array_map(static fn (Candidate $candidate): string => $candidate->invoiceId, $decision->suggestions));
    }

    /**
     * @return iterable<string, array{string, list<array{string, string, string}>, list<bool>, list<array{string, string, ?string}>|string}>
     */
    public static function refundDecisions(): iterable
    {
        yield 'exactly what is to refund' => ['484.00', [['A', '484.00', '2026-11-15']], [true, true], [['A', '484.00', null]]];
        yield 'less than what is to refund' => ['400.00', [['A', '484.00', '2026-11-15']], [true, true], 'refund_amount_differs'];
        yield 'more than what is to refund' => ['500.00', [['A', '484.00', '2026-11-15']], [true, true], 'refund_amount_differs'];
        yield 'a small difference is never settled on a refund' => ['484.50', [['A', '484.00', '2026-11-15']], [true, true], 'refund_amount_differs'];
        yield 'two invoices, even one with the amount' => ['484.00', [['A', '484.00', '2026-11-15'], ['B', '100.00', '2026-11-10']], [true, true], 'ambiguous'];
        yield 'two invoices whose sum is the amount' => ['584.00', [['A', '484.00', '2026-11-15'], ['B', '100.00', '2026-11-10']], [true, true], 'ambiguous'];
        yield 'a firm that pairs by hand' => ['484.00', [['A', '484.00', '2026-11-15']], [false, false], 'auto_pairing_off'];
        yield 'nothing to refund' => ['484.00', [], [true, true], 'no_invoice'];
    }

    /**
     * @param list<array{string, string, string}>         $candidates
     * @param list<bool>                                  $rules
     * @param list<array{string, string, ?string}>|string $expected
     */
    #[DataProvider('refundDecisions')]
    public function testRefundDecisions(string $amount, array $candidates, array $rules, array|string $expected): void
    {
        $decision = $this->decide($this->movement($amount, direction: MovementDirection::Outgoing), $this->candidates($candidates), new PairingRules(...$rules));

        self::assertSame($expected, $this->shape($decision));
    }

    public function testAnUnverifiedRefundKeepsItsInvoiceAsASuggestion(): void
    {
        $decision = $this->decide($this->movement('484.00', trust: MovementTrust::Unverified, direction: MovementDirection::Outgoing), $this->candidates([['A', '484.00', '2026-11-15']]), new PairingRules(true, false));

        self::assertSame(UnmatchedReason::Unverified, $decision->reason);
        self::assertSame(['A'], array_map(static fn (Candidate $candidate): string => $candidate->invoiceId, $decision->suggestions));
    }

    public function testSmallDifferenceSettlesOnlyInCzk(): void
    {
        $eur = new Candidate('A', '2026-0001', new \DateTimeImmutable('2026-11-15'), Money::of('1000.00', Currency::of('EUR')), 'Example Customer a.s.', new \DateTimeImmutable('2026-11-01'));

        $decision = $this->decide($this->movement('999.01', Currency::of('EUR')), [$eur], new PairingRules(true, true));

        self::assertSame([['A', '999.01', null]], $this->shape($decision));
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
        $eur = new Candidate('A', '2026-0001', new \DateTimeImmutable('2026-11-15'), Money::of('50.00', Currency::of('EUR')), 'Example Customer a.s.', new \DateTimeImmutable('2026-11-01'));

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

        $unverified = $this->decide($this->movement('1210.00', trust: MovementTrust::Unverified), $candidates, new PairingRules(true, false));
        $failed = $this->decide($this->movement('1210.00', trust: MovementTrust::Failed), $candidates, new PairingRules(true, false));

        self::assertSame([UnmatchedReason::Unverified, ['A']], [$unverified->reason, array_map(static fn (Candidate $candidate): string => $candidate->invoiceId, $unverified->suggestions)]);
        self::assertSame(UnmatchedReason::Rejected, $failed->reason);
        self::assertFalse($unverified->pairs());
    }

    public function testAMovementInAnotherCurrencyThanItsAccountIsNeverPairedByItself(): void
    {
        $eur = new Candidate('A', '2026-0001', new \DateTimeImmutable('2026-11-15'), Money::of('1210.00', Currency::of('EUR')), 'Example Customer a.s.', new \DateTimeImmutable('2026-11-01'));

        $decision = $this->decide($this->movement('1210.00', Currency::of('EUR'), account: $this->account), [$eur], new PairingRules(true, false));
        $failed = $this->decide($this->movement('1210.00', Currency::of('EUR'), trust: MovementTrust::Failed, account: $this->account), [$eur], new PairingRules(true, false));

        self::assertSame([UnmatchedReason::AccountCurrency, ['A']], [$decision->reason, array_map(static fn (Candidate $candidate): string => $candidate->invoiceId, $decision->suggestions)], 'the CZK account says the bank booked something else');
        self::assertSame(UnmatchedReason::Rejected, $failed->reason, 'a rejected message stays rejected');
    }

    public function testAnInvoiceIssuedAfterThePaymentIsOnlySuggested(): void
    {
        $later = new Candidate('A', '2026-0001', new \DateTimeImmutable('2026-12-05'), $this->czk('1210.00'), 'Example Customer a.s.', new \DateTimeImmutable('2026-11-21'));

        $decision = $this->decide($this->movement('1210.00'), [$later], new PairingRules(true, false));

        self::assertSame(UnmatchedReason::NoInvoice, $decision->reason);
        self::assertSame([$later], $decision->suggestions);
    }

    public function testAnInvoiceIssuedOnTheDayOfThePaymentPairs(): void
    {
        $sameDay = new Candidate('A', '2026-0001', new \DateTimeImmutable('2026-12-05'), $this->czk('1210.00'), 'Example Customer a.s.', new \DateTimeImmutable('2026-11-20'));

        self::assertSame([['A', '1210.00', null]], $this->shape($this->decide($this->movement('1210.00'), [$sameDay], new PairingRules(true, false))));
    }

    public function testAnInvoiceIssuedAfterThePaymentLeavesTheOtherToPair(): void
    {
        $later = new Candidate('A', '2026-0001', new \DateTimeImmutable('2026-11-01'), $this->czk('500.00'), 'Example Customer a.s.', new \DateTimeImmutable('2026-11-21'));
        $earlier = new Candidate('B', '2026-0002', new \DateTimeImmutable('2026-11-10'), $this->czk('300.00'), 'Example Customer a.s.', new \DateTimeImmutable('2026-11-01'));

        self::assertSame([['B', '100.00', null]], $this->shape($this->decide($this->movement('100.00'), [$later, $earlier], new PairingRules(true, false))));
    }

    public function testASymbolIsItsDigitsWithoutLeadingZeros(): void
    {
        self::assertSame('1234', Matcher::normalised('VS 0012-34'));
        self::assertNull(Matcher::normalised('VS 000'));
        self::assertNull(Matcher::normalised(null));
    }

    /**
     * @param list<Candidate> $candidates
     */
    private function decide(BankMovement $movement, array $candidates, PairingRules $rules): MatchDecision
    {
        return $this->matcher->decide($movement, $candidates, $rules, $this->matcher->settlementLimit($movement));
    }

    /**
     * On an account in the currency of the movement, unless one is given.
     */
    private function movement(string $amount, ?Currency $currency = null, ?string $vs = '20260001', MovementTrust $trust = MovementTrust::Verified, ?FirmBankAccount $account = null, MovementDirection $direction = MovementDirection::Incoming): BankMovement
    {
        $currency ??= Currency::of('CZK');
        if (!$account instanceof FirmBankAccount) {
            $account = $currency === $this->account->currency() ? $this->account : ($this->foreign ??= FirmAccounts::add($this->em, 'DE89370400440532013000', $currency));
        }

        return new BankMovement($account, null, $direction, Money::of($amount, $currency), new \DateTimeImmutable('2026-11-20'), null, null, null, $vs, null, null, null, null, str_repeat('a', 64), $trust, 'dmarc', MovementState::Unmatched, UnmatchedReason::NoInvoice);
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

        return array_map(static fn (Allocation $allocation): array => [$allocation->documentId, self::decimal($allocation->amount), $allocation->settlement instanceof Money ? self::decimal($allocation->settlement) : null], $decision->allocations);
    }

    private static function decimal(Money $money): string
    {
        $minor = $money->minor();

        return ($minor < 0 ? '-' : '').intdiv(abs($minor), 100).'.'.str_pad((string) (abs($minor) % 100), 2, '0', \STR_PAD_LEFT);
    }
}
