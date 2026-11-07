<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Application;

use App\Banking\Application\Candidate;
use App\Banking\Application\Candidates;
use App\Banking\Application\FirmPairingRules;
use App\Banking\Application\Matcher;
use App\Banking\Application\PairingChoice;
use App\Banking\Application\PairingChoices;
use App\Banking\Application\PairingOptions;
use App\Banking\Application\PairingRules;
use App\Banking\Domain\BankMovement;
use App\Banking\Domain\MessageTrust;
use App\Banking\Domain\MovementDirection;
use App\Banking\Domain\MovementState;
use App\Banking\Domain\UnmatchedReason;
use App\Invoicing\Application\InvoicingCountries;
use App\Party\Domain\LegalEntity;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tests\Banking\Support\BankingTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What the pair dialog offers, on candidates made up here: the invoices read are
 * the ones a test gives, so each rule of the offer shows on its own.
 */
final class PairingChoicesTest extends BankingTestCase
{
    /** @var list<Candidate> what the symbol finds */
    private array $bySymbol = [];
    /** @var list<Candidate> the open invoices of the firm, oldest due first */
    private array $open = [];
    /** @var list<Candidate> what a search finds */
    private array $found = [];
    /** @var list<array{string, list<string>, int}> every read of open invoices: the text, the ids and the limit */
    private array $reads = [];
    /** @var list<Candidate> the invoices of the firm with something to refund */
    private array $refunds = [];
    /** @var list<string> the invoices an earlier payment from the counter account paid */
    private array $paidFrom = [];

    public function testSuggestionsComeBySymbolThenAmountThenCustomerAndTheRestAreOthers(): void
    {
        $symbol = $this->candidate('S', '300.00');
        $this->bySymbol = [$symbol];
        $this->open = [$symbol, $this->candidate('A', '1210.00'), $this->candidate('C', '500.00', 'Example Customer a.s.'), $this->candidate('O1', '700.00'), $this->candidate('O2', '800.00')];

        $options = $this->choices($this->movement('1210.00', 'Example Customer'));

        self::assertSame([['S', PairingChoices::BY_SYMBOL], ['A', PairingChoices::BY_AMOUNT], ['C', PairingChoices::BY_CUSTOMER]], $this->offered($options->suggested));
        self::assertSame([['O1', null], ['O2', null]], $this->offered($options->others));
        self::assertSame(['S' => 121000], $this->filled($options), 'the one invoice with the symbol takes the whole movement');
    }

    public function testOneInvoiceWithTheAmountIsFilledInWhole(): void
    {
        $this->open = [$this->candidate('C', '500.00', 'Example Customer a.s.'), $this->candidate('A', '1210.00')];

        $options = $this->choices($this->movement('1210.00', 'Example Customer', vs: null));

        self::assertSame([['A', PairingChoices::BY_AMOUNT], ['C', PairingChoices::BY_CUSTOMER]], $this->offered($options->suggested));
        self::assertSame(['A' => 121000], $this->filled($options), 'the customer\'s invoice is only suggested');
    }

    public function testTwoInvoicesWithTheAmountFillNothingIn(): void
    {
        $this->open = [$this->candidate('A', '1210.00'), $this->candidate('B', '1210.00')];

        $options = $this->choices($this->movement('1210.00', vs: null));

        self::assertSame([['A', PairingChoices::BY_AMOUNT], ['B', PairingChoices::BY_AMOUNT]], $this->offered($options->suggested));
        self::assertSame([], $this->filled($options));
    }

    public function testOnlyACustomersInvoiceFillsNothingIn(): void
    {
        $this->open = [$this->candidate('C', '500.00', 'Example Customer a.s.')];

        $options = $this->choices($this->movement('1210.00', 'Example Customer', vs: null));

        self::assertSame([['C', PairingChoices::BY_CUSTOMER]], $this->offered($options->suggested));
        self::assertSame([], $this->filled($options));
    }

    public function testSeveralWithTheSymbolTakeWhatEachHasLeftAndTheLastWhatRemains(): void
    {
        $this->bySymbol = [$this->candidate('A', '500.00', due: '2026-11-01'), $this->candidate('B', '300.00', due: '2026-11-10'), $this->candidate('C', '200.00', due: '2026-11-20')];

        self::assertSame(['A' => 50000, 'B' => 30000, 'C' => 10000], $this->filled($this->choices($this->movement('900.00'))));
        self::assertSame(['A' => 50000, 'B' => 30000, 'C' => 50000], $this->filled($this->choices($this->movement('1300.00'))), 'the last one takes the overpayment');
        self::assertSame(['A' => 50000], $this->filled($this->choices($this->movement('500.00'))), 'nothing is filled in once the movement is spent');
        self::assertSame(['A' => 40000], $this->filled($this->choices($this->movement('400.00'))));
    }

    public function testASymbolInvoiceInAnotherCurrencyIsNotSuggested(): void
    {
        $this->bySymbol = [new Candidate('E', '2026-E', new \DateTimeImmutable('2026-11-15'), Money::of('1210.00', Currency::EUR), 'Other Buyer s.r.o.', new \DateTimeImmutable('2026-11-01'))];

        self::assertSame([], $this->choices($this->movement('1210.00'))->suggested);
    }

    public function testOthersLeaveOutTheSuggestedAndStopAtTen(): void
    {
        $this->open = [$this->candidate('A', '1210.00'), ...array_map(fn (int $index): Candidate => $this->candidate('O'.$index, '100.00'), range(1, 11))];

        $options = $this->choices($this->movement('1210.00', vs: null));

        self::assertSame([['A', PairingChoices::BY_AMOUNT]], $this->offered($options->suggested));
        self::assertSame(array_map(static fn (int $index): array => ['O'.$index, null], range(1, PairingChoices::OTHERS)), $this->offered($options->others));
    }

    public function testTheOpenInvoicesAreReadUpToTwoHundred(): void
    {
        $this->open = array_map(fn (int $index): Candidate => $this->candidate('O'.$index, \in_array($index, [200, 201], true) ? '1210.00' : '100.00'), range(1, 201));

        $options = $this->choices($this->movement('1210.00', vs: null));

        self::assertSame([['O200', PairingChoices::BY_AMOUNT]], $this->offered($options->suggested), 'the 201st is not read');
        self::assertSame([['', [], 200]], $this->reads);
    }

    public function testABlankSearchIsNoSearchAndASearchIsTrimmed(): void
    {
        $this->open = [$this->candidate('O1', '100.00')];
        $this->found = [$this->candidate('F', '100.00')];

        self::assertSame([['O1', null]], $this->offered($this->choices($this->movement('1210.00', vs: null), '   ')->others));
        self::assertSame([['', [], 200]], $this->reads);

        $this->reads = [];
        self::assertSame([['F', null]], $this->offered($this->choices($this->movement('1210.00', vs: null), ' 2026-F ')->others));
        self::assertSame([['', [], 200], ['2026-F', [], PairingChoices::FOUND]], $this->reads);
    }

    public function testTickedInvoicesStayOfferedWhateverTheSearchFinds(): void
    {
        $found = $this->candidate('F', '100.00');
        $this->open = [$this->candidate('K', '100.00'), $found];
        $this->found = [$found];

        $options = $this->choices($this->movement('1210.00', vs: null), 'F', ['F', 'K']);

        self::assertSame([['F', null], ['K', null]], $this->offered($options->others));
        self::assertSame($found, $options->others[0]->candidate, 'an invoice offered twice is offered as it was found first');
        self::assertSame([['', [], 200], ['F', [], PairingChoices::FOUND], ['', ['F', 'K'], 2]], $this->reads);
    }

    public function testNothingTickedReadsNoTickedInvoices(): void
    {
        $this->open = [$this->candidate('O1', '100.00')];

        $this->choices($this->movement('1210.00', vs: null));

        self::assertSame([['', [], 200]], $this->reads);
    }

    /**
     * @return iterable<string, array{?string, string, bool}>
     */
    public static function customers(): iterable
    {
        yield 'a part of the name' => ['Example', 'Example Customer a.s.', true];
        yield 'the name in a longer one' => ['Example Customer a.s., Example City', 'Example Customer a.s.', true];
        yield 'spaces around the payer' => ['  Example  ', 'Example Customer a.s.', true];
        yield 'spaces around the customer' => ['Example Customer a.s. Example City', ' Example Customer a.s. ', true];
        yield 'the payer in capitals' => ['ŽLUTÝ KŮŇ', 'Žlutý kůň s.r.o.', true];
        yield 'the customer in capitals' => ['žlutý kůň', 'ŽLUTÝ KŮŇ S.R.O.', true];
        yield 'three letters are enough' => ['Abc', 'Abc Trading s.r.o.', true];
        yield 'two letters are not' => ['Ab', 'Ab Trading s.r.o.', false];
        yield 'two letters of four bytes are not' => ['Žů', 'Žůstek s.r.o.', false];
        yield 'a customer without a name' => ['Example', '', false];
        yield 'no payer' => [null, 'Example Customer a.s.', false];
        yield 'another name' => ['Example Customer', 'Other Buyer s.r.o.', false];
    }

    #[DataProvider('customers')]
    public function testTheCustomerWhoPaidIsSuggested(?string $counterName, string $customerName, bool $suggested): void
    {
        $this->open = [$this->candidate('C', '500.00', $customerName)];

        $options = $this->choices($this->movement('1210.00', $counterName, vs: null));

        self::assertSame($suggested ? [['C', PairingChoices::BY_CUSTOMER]] : [['C', null]], $this->offered([...$options->suggested, ...$options->others]));
    }

    public function testTheLimitIsOfferedOnlyToAFirmThatSettlesSmallDifferences(): void
    {
        self::assertNull($this->choices($this->movement('1210.00'))->settlementLimit);
        self::assertSame(99, $this->choices($this->movement('1210.00'), smallDifference: true)->settlementLimit);
    }

    public function testAnOutgoingMovementIsOfferedTheRefundsTheAccountItGoesToPaidBeforeTheCustomersAndFillsNothingIn(): void
    {
        $this->refunds = [
            $this->candidate('R1', '100.00', 'Example Customer a.s.'),
            $this->candidate('R2', '200.00'),
            $this->candidate('R3', '300.00'),
        ];
        $this->paidFrom = ['R2', 'R1', 'X'];
        $outgoing = $this->movement('1000.00', 'Example Customer a.s.', vs: null, direction: MovementDirection::Outgoing);

        $options = $this->choices($outgoing);

        self::assertSame([['R1', PairingChoices::BY_ACCOUNT], ['R2', PairingChoices::BY_ACCOUNT]], $this->offered($options->suggested), 'the account outranks the customer; an invoice with nothing to refund is left out');
        self::assertSame([['R3', null]], $this->offered($options->others));
        self::assertSame([], $options->prefill, 'a suggestion by the account is never filled in');
    }

    public function testAnIncomingMovementIsNeverSuggestedByTheAccount(): void
    {
        $this->open = [$this->candidate('A', '100.00')];
        $this->paidFrom = ['A'];

        self::assertSame([], $this->offered($this->choices($this->movement('1000.00', vs: null))->suggested));
    }

    public function testAnOutgoingMovementIsOfferedTheRefundsInItsCurrencyAsTheSearchFindsThem(): void
    {
        $this->refunds = [
            new Candidate('E', '2026-E', new \DateTimeImmutable('2026-11-15'), Money::of('100.00', Currency::EUR), 'Other Buyer s.r.o.', new \DateTimeImmutable('2026-11-01')),
            $this->candidate('R1', '100.00'),
            $this->candidate('R2', '200.00', 'Žluťoučký kůň s.r.o.'),
            $this->candidate('R3', '300.00', 'ŽLUTÝ PES a.s.'),
        ];
        $outgoing = $this->movement('1000.00', vs: null, direction: MovementDirection::Outgoing);

        self::assertSame([['R1', null], ['R2', null], ['R3', null]], $this->offered($this->choices($outgoing)->others), 'the refund in euros is left out');
        self::assertSame([['R1', null]], $this->offered($this->choices($outgoing, ' 2026-r1 OTHER ')->others), 'the number and the name read as one text');
        self::assertSame([['R2', null]], $this->offered($this->choices($outgoing, 'ŽLUŤ')->others), 'the search in capitals');
        self::assertSame([['R3', null]], $this->offered($this->choices($outgoing, 'žlutý')->others), 'the name in capitals');
        self::assertSame([['R1', null], ['R3', null]], $this->offered($this->choices($outgoing, 'R1', ['R3'])->others), 'what was ticked stays offered after what the search finds');
        self::assertSame([], $this->reads, 'no unpaid invoices are read');
    }

    /**
     * @param list<string> $kept
     */
    private function choices(BankMovement $movement, string $text = '', array $kept = [], bool $smallDifference = false): PairingOptions
    {
        $candidates = new readonly class($this) implements Candidates {
            public function __construct(private PairingChoicesTest $test)
            {
            }

            public function withSymbol(string $firmId, string $variableSymbol): array
            {
                return $this->test->symbolRead();
            }

            public function open(string $firmId, Currency $currency, string $text, array $ids, int $limit): array
            {
                return $this->test->openRead($text, $ids, $limit);
            }

            public function refunds(string $firmId, ?string $variableSymbol): array
            {
                return null === $variableSymbol ? $this->test->refundsRead() : $this->test->symbolRead();
            }

            public function paidFrom(string $firmId, ?string $counterIban, ?string $counterAccount): array
            {
                return $this->test->paidFromRead();
            }
        };
        $rules = new readonly class($smallDifference) implements FirmPairingRules {
            public function __construct(private bool $smallDifference)
            {
            }

            public function for(LegalEntity $firm): PairingRules
            {
                return new PairingRules(true, $this->smallDifference);
            }
        };

        return new PairingChoices(new Matcher($candidates, self::getContainer()->get(InvoicingCountries::class)), $candidates, $rules)->of($movement, $text, $kept);
    }

    /**
     * @return list<Candidate>
     */
    public function symbolRead(): array
    {
        return $this->bySymbol;
    }

    /**
     * @return list<Candidate>
     */
    public function refundsRead(): array
    {
        return $this->refunds;
    }

    /**
     * @return list<string>
     */
    public function paidFromRead(): array
    {
        return $this->paidFrom;
    }

    /**
     * The ticked ones are read as new objects, as another query would give them.
     *
     * @param list<string> $ids
     *
     * @return list<Candidate>
     */
    public function openRead(string $text, array $ids, int $limit): array
    {
        $this->reads[] = [$text, $ids, $limit];
        if ([] !== $ids) {
            return array_values(array_map(static fn (Candidate $candidate): Candidate => clone $candidate, array_filter($this->open, static fn (Candidate $candidate): bool => \in_array($candidate->invoiceId, $ids, true))));
        }

        return '' === $text ? \array_slice($this->open, 0, $limit) : $this->found;
    }

    private function candidate(string $id, string $remaining, string $customerName = 'Other Buyer s.r.o.', string $due = '2026-11-15'): Candidate
    {
        return new Candidate($id, '2026-'.$id, new \DateTimeImmutable($due), $this->czk($remaining), $customerName, new \DateTimeImmutable('2026-11-01'));
    }

    private function movement(string $amount, ?string $counterName = null, ?string $vs = '20260001', MovementDirection $direction = MovementDirection::Incoming): BankMovement
    {
        $incoming = MovementDirection::Incoming === $direction;

        return new BankMovement($this->account, null, $direction, $this->czk($amount), new \DateTimeImmutable('2026-11-20'), null, null, $counterName, $vs, null, null, null, null, str_repeat('a', 64), MessageTrust::Verified, 'dmarc', $incoming ? MovementState::Unmatched : MovementState::Recorded, $incoming ? UnmatchedReason::NoInvoice : null);
    }

    /**
     * @param array<array-key, PairingChoice> $choices
     *
     * @return array<array-key, array{string, ?string}>
     */
    private function offered(array $choices): array
    {
        return array_map(static fn (PairingChoice $choice): array => [$choice->candidate->invoiceId, $choice->reason], $choices);
    }

    /**
     * @return array<string, int>
     */
    private function filled(PairingOptions $options): array
    {
        return array_map(static fn (Money $money): int => $money->minor(), $options->prefill);
    }
}
