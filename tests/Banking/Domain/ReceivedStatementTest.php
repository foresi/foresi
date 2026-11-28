<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Domain;

use Foresi\Banking\Contract\BankStatement;
use Foresi\Banking\Contract\MovementChannel;
use Foresi\Banking\Contract\MovementProvenance;
use Foresi\Banking\Contract\StatementSequence;
use Foresi\Banking\Domain\ReceivedStatement;
use Foresi\Party\Domain\FirmBankAccount;
use Foresi\Party\Domain\Iban;
use Foresi\Shared\Domain\Currency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a stored statement accepts of the file it came in, and the number it keeps.
 */
final class ReceivedStatementTest extends TestCase
{
    public function testAFileHashAndANameOfTheirLimitsAreKept(): void
    {
        $hash = str_repeat('0a', 32);
        $name = str_repeat('ř', 255);

        $statement = $this->received($hash, $name);

        self::assertSame([$hash, $name], [$statement->fileSha256(), $statement->fileName()]);
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function wrongFiles(): iterable
    {
        yield 'a hash too long' => [str_repeat('a', 65), null];
        yield 'a hash after other text' => ['x'.str_repeat('a', 64), null];
        yield 'a hash with a new line' => [str_repeat('a', 64)."\n", null];
        yield 'a hash in upper case' => [str_repeat('A', 64), null];
        yield 'a name too long' => [null, str_repeat('n', 256)];
    }

    #[DataProvider('wrongFiles')]
    public function testAWrongFileIsRefused(?string $hash, ?string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->received($hash, $name);
    }

    public function testTheSequenceIsKept(): void
    {
        self::assertEquals(StatementSequence::withinYear(42, 2026), $this->received(null, null, StatementSequence::withinYear(42, 2026))->sequence());
        self::assertNull($this->received(null, null)->sequence());
    }

    private function received(?string $hash, ?string $name, ?StatementSequence $sequence = null): ReceivedStatement
    {
        $account = FirmBankAccount::open(Iban::fromString('CZ6508000000192000145399'), Currency::of('CZK'), 'Main', 1);

        return new ReceivedStatement(
            $account,
            new BankStatement($account->iban(), Currency::of('CZK'), [], sequence: $sequence),
            MovementProvenance::unverified(MovementChannel::Statement, 'test.json'),
            $hash,
            $name,
            new \DateTimeImmutable('2026-12-01 10:00:00'),
        );
    }
}
