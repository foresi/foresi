<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Integration\Country;

use Foresi\Banking\Application\Candidate;
use Foresi\Banking\Application\Candidates;
use Foresi\Banking\Application\FirmPairingRules;
use Foresi\Banking\Application\Matcher;
use Foresi\Banking\Application\PairingChoices;
use Foresi\Banking\Application\PairingOptions;
use Foresi\Banking\Application\PairingRules;
use Foresi\Banking\Application\PaymentPairing;
use Foresi\Banking\Contract\MovementDirection;
use Foresi\Banking\Contract\MovementTrust;
use Foresi\Banking\Domain\BankMovement;
use Foresi\Banking\Domain\MovementState;
use Foresi\Banking\Domain\UnmatchedReason;
use Foresi\Shared\Domain\Currency;
use Foresi\Tests\Banking\Integration\Invoicing\Support\InvoiceBankingTestCase;

/**
 * What the pair dialog offers, on candidates made up here: the invoices read are
 * the ones a test gives, so each rule of the offer shows on its own.
 */
final class PairingChoicesTest extends InvoiceBankingTestCase
{
    /** @var list<Candidate> what the symbol finds */
    private array $bySymbol = [];
    /** @var list<Candidate> the open invoices, oldest due first */
    private array $open = [];
    /** @var list<Candidate> what a search finds */
    private array $found = [];
    /** @var list<Candidate> the invoices with something to refund */
    private array $refunds = [];
    /** @var list<string> the invoices an earlier payment from the counter account paid */
    private array $paidFrom = [];

    public function testTheLimitIsOfferedOnlyToAFirmThatSettlesSmallDifferences(): void
    {
        self::assertNull($this->choices($this->movement('1210.00'))->settlementLimit);
        self::assertSame(99, $this->choices($this->movement('1210.00'), smallDifference: true)->settlementLimit);
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

            public function withSymbol(string $variableSymbol): array
            {
                return $this->test->symbolRead();
            }

            public function open(Currency $currency, string $text, array $ids, int $limit): array
            {
                return $this->test->openRead($text, $ids, $limit);
            }

            public function refunds(?string $variableSymbol): array
            {
                return null === $variableSymbol ? $this->test->refundsRead() : $this->test->symbolRead();
            }

            public function paidFrom(?string $counterIban, ?string $counterAccount): array
            {
                return $this->test->paidFromRead();
            }
        };
        $rules = new readonly class($smallDifference) implements FirmPairingRules {
            public function __construct(private bool $smallDifference)
            {
            }

            public function get(): PairingRules
            {
                return new PairingRules(true, $this->smallDifference);
            }
        };

        return new PairingChoices(new Matcher($candidates, self::getContainer()->get(PaymentPairing::class)), $candidates, $rules)->of($movement, $text, $kept);
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
        if ([] !== $ids) {
            return array_values(array_map(static fn (Candidate $candidate): Candidate => clone $candidate, array_filter($this->open, static fn (Candidate $candidate): bool => \in_array($candidate->invoiceId, $ids, true))));
        }

        return '' === $text ? \array_slice($this->open, 0, $limit) : $this->found;
    }

    private function movement(string $amount, ?string $counterName = null, ?string $vs = '20260001', MovementDirection $direction = MovementDirection::Incoming): BankMovement
    {
        $incoming = MovementDirection::Incoming === $direction;

        return new BankMovement($this->account, null, $direction, $this->czk($amount), new \DateTimeImmutable('2026-11-20'), null, null, $counterName, $vs, null, null, null, null, str_repeat('a', 64), MovementTrust::Verified, 'dmarc', $incoming ? MovementState::Unmatched : MovementState::Recorded, $incoming ? UnmatchedReason::NoInvoice : null);
    }
}
