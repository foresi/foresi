<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Domain;

use Foresi\Banking\Domain\BankFeed;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\FirmBankAccount;
use Foresi\Party\Domain\Iban;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BankFeedTest extends TestCase
{
    public function testItKeepsTheHashOfItsToken(): void
    {
        $hash = hash('sha256', 'token');

        self::assertSame($hash, $this->open($hash)->tokenHash());
    }

    #[DataProvider('badHashes')]
    public function testATokenHashIsSixtyFourLowercaseHexDigits(string $hash): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageIsOrContains('A token hash is 64 lowercase hex digits.');

        $this->open($hash);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badHashes(): iterable
    {
        $hash = hash('sha256', 'token');

        yield 'something before' => ['x'.$hash];
        yield 'something after' => [$hash.'x'];
        yield 'a line end after' => [$hash."\n"];
        yield 'upper case' => [strtoupper($hash)];
    }

    private function open(string $hash): BankFeed
    {
        $firm = new LegalEntity('Example Firm', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');
        $account = FirmBankAccount::open($firm, Iban::fromString('CZ6508000000192000145399'), Currency::CZK, '', 0);

        return BankFeed::open($account, $hash, 'sealed', new \DateTimeImmutable('2026-11-02T09:00:00+01:00'));
    }
}
