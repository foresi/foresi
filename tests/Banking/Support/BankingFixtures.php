<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Support;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Banking\Application\BankMovements;
use Foresi\Banking\Application\Matcher;
use Foresi\Banking\Application\MovementInput;
use Foresi\Banking\Application\PairMovement;
use Foresi\Banking\Application\RecordMovement;
use Foresi\Banking\Application\TrustVerdict;
use Foresi\Banking\Application\UnpairMovement;
use Foresi\Banking\Contract\MovementDirection;
use Foresi\Banking\Contract\MovementTrust;
use Foresi\Banking\Domain\BankMovement;
use Foresi\Party\Application\FirmBankAccounts;
use Foresi\Party\Domain\FirmBankAccount;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;

/**
 * The banking services, a clock on 20 November 2026 and the firm's primary
 * account, for a test case whose setUp() made the firm and its account.
 *
 * @property EntityManagerInterface $em
 */
trait BankingFixtures
{
    protected const string COUNTER_IBAN = 'CZ5508000000001234567899';

    protected RecordMovement $recordMovement;
    protected PairMovement $pairing;
    protected UnpairMovement $unpairing;
    protected Matcher $matcher;
    protected BankMovements $movements;
    protected FirmBankAccount $account;

    protected function setUpBanking(): void
    {
        Clock::set(new MockClock('2026-11-20 12:00:00', 'UTC'));
        $container = self::getContainer();
        $this->recordMovement = $container->get(RecordMovement::class);
        $this->pairing = $container->get(PairMovement::class);
        $this->unpairing = $container->get(UnpairMovement::class);
        $this->matcher = $container->get(Matcher::class);
        $this->movements = $container->get(BankMovements::class);
        $account = $container->get(FirmBankAccounts::class)->primary();
        self::assertInstanceOf(FirmBankAccount::class, $account);
        $this->account = $account;
    }

    protected function incoming(
        string $amount,
        ?string $vs = '20260001',
        ?Currency $currency = null,
        ?string $counterIban = self::COUNTER_IBAN,
        ?string $messageId = null,
        string $bookedOn = '2026-11-20',
        MovementDirection $direction = MovementDirection::Incoming,
        ?string $accountId = null,
    ): MovementInput {
        $currency ??= Currency::of('CZK');

        return new MovementInput(
            $accountId ?? $this->account->id(),
            $messageId,
            $direction,
            Money::of($amount, $currency),
            new \DateTimeImmutable($bookedOn),
            '123456789/0800',
            $counterIban,
            'Example Customer a.s.',
            $vs,
            '0308',
            null,
            'Payment for an invoice',
            null,
        );
    }

    protected static function verified(): TrustVerdict
    {
        return new TrustVerdict(MovementTrust::Verified, TrustVerdict::DMARC);
    }

    protected static function unverified(): TrustVerdict
    {
        return new TrustVerdict(MovementTrust::Unverified, TrustVerdict::NO_RESULTS);
    }

    protected function storedMovement(string $id): BankMovement
    {
        $this->em->clear();

        return $this->movements->get($id);
    }

    protected static function int(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }

    protected function czk(string $amount): Money
    {
        return Money::of($amount, Currency::of('CZK'));
    }
}
