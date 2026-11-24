<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Domain;

use Foresi\Banking\Domain\BankFeed;
use Foresi\Party\Domain\FirmBankAccount;
use Foresi\Party\Domain\Iban;
use Foresi\Shared\Domain\Currency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A bank feed belongs to one account and names the inbound address of Mail it receives on.
 */
final class BankFeedTest extends TestCase
{
    #[DataProvider('blankAddresses')]
    public function testAFeedNamesItsInboundAddress(string $addressId): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageIsOrContains('A bank feed names its inbound address.');

        BankFeed::open($this->account(), $addressId, new \DateTimeImmutable('2026-11-02 12:00:00'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankAddresses(): iterable
    {
        yield 'none' => [''];
        yield 'blanks only' => ["  \t "];
    }

    public function testAFeedKeepsItsAddress(): void
    {
        $feed = BankFeed::open($this->account(), '01937f4e-0000-7000-8000-000000000001', new \DateTimeImmutable('2026-11-02 12:00:00'));

        self::assertSame('01937f4e-0000-7000-8000-000000000001', $feed->addressId());
    }

    private function account(): FirmBankAccount
    {
        return FirmBankAccount::open(Iban::fromString('CZ6508000000192000145399'), Currency::CZK, '', 0);
    }
}
