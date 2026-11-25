<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Contract;

use Foresi\Banking\Contract\BankStatement;
use Foresi\Banking\Contract\IntakeResult;
use Foresi\Banking\Contract\MovementChannel;
use Foresi\Banking\Contract\MovementDirection;
use Foresi\Banking\Contract\MovementProvenance;
use Foresi\Banking\Contract\MovementTrust;
use Foresi\Banking\Contract\ParsedMovement;
use Foresi\Banking\Contract\StatementFile;
use Foresi\Banking\Contract\StatementRefused;
use Foresi\Banking\Contract\StatementSequence;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatementContractTest extends TestCase
{
    private const string IBAN = 'CZ6508000000192000145399';

    /**
     * @return iterable<string, array{StatementSequence, StatementSequence, bool|null}>
     */
    public static function sequences(): iterable
    {
        yield 'the next number' => [StatementSequence::withinYear(42, 2026), StatementSequence::withinYear(43, 2026), true];
        yield 'a number missing' => [StatementSequence::withinYear(42, 2026), StatementSequence::withinYear(44, 2026), false];
        yield 'the same number' => [StatementSequence::withinYear(42, 2026), StatementSequence::withinYear(42, 2026), null];
        yield 'an earlier number' => [StatementSequence::withinYear(42, 2026), StatementSequence::withinYear(41, 2026), null];
        yield 'a new year starts at 1' => [StatementSequence::withinYear(12, 2026), StatementSequence::withinYear(1, 2027), true];
        yield 'a new year not at 1' => [StatementSequence::withinYear(12, 2026), StatementSequence::withinYear(2, 2027), false];
        yield 'a year missing' => [StatementSequence::withinYear(12, 2025), StatementSequence::withinYear(1, 2027), false];
        yield 'an earlier year' => [StatementSequence::withinYear(1, 2027), StatementSequence::withinYear(12, 2026), null];
        yield 'electronic, next' => [StatementSequence::electronic(7), StatementSequence::electronic(8), true];
        yield 'electronic, missing' => [StatementSequence::electronic(7), StatementSequence::electronic(9), false];
        yield 'another kind' => [StatementSequence::electronic(7), StatementSequence::withinYear(8, 2026), null];
    }

    #[DataProvider('sequences')]
    public function testASequenceFollowsOnTheNextNumberOnly(StatementSequence $earlier, StatementSequence $later, ?bool $follows): void
    {
        self::assertSame($follows, $later->followsOn($earlier));
    }

    public function testASequenceIsWrittenAsPeopleWriteIt(): void
    {
        self::assertSame('42/2026', StatementSequence::withinYear(42, 2026)->label());
        self::assertSame('7', StatementSequence::electronic(7)->label());
        self::assertEquals(StatementSequence::withinYear(42, 2026), StatementSequence::of(StatementSequence::WITHIN_YEAR, 42, 2026));
        self::assertEquals(StatementSequence::electronic(7), StatementSequence::of(StatementSequence::ELECTRONIC, 7, null));
    }

    /**
     * @return iterable<string, array{\Closure(): mixed}>
     */
    public static function wrongSequences(): iterable
    {
        yield 'number 0' => [static fn (): StatementSequence => StatementSequence::electronic(0)];
        yield 'a year of two digits' => [static fn (): StatementSequence => StatementSequence::withinYear(1, 26)];
        yield 'a year of five digits' => [static fn (): StatementSequence => StatementSequence::withinYear(1, 10000)];
        yield 'within a year without one' => [static fn (): StatementSequence => StatementSequence::of(StatementSequence::WITHIN_YEAR, 1, null)];
        yield 'electronic with a year' => [static fn (): StatementSequence => StatementSequence::of(StatementSequence::ELECTRONIC, 1, 2026)];
        yield 'an unknown kind' => [static fn (): StatementSequence => StatementSequence::of('daily', 1, null)];
    }

    #[DataProvider('wrongSequences')]
    public function testAWrongSequenceIsRefused(\Closure $make): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $make();
    }

    public function testTheYearBoundsAreFourDigits(): void
    {
        self::assertSame(1900, StatementSequence::withinYear(1, 1900)->year);
        self::assertSame(9999, StatementSequence::withinYear(1, 9999)->year);
        self::assertSame(1, StatementSequence::electronic(1)->number);
    }

    public function testAStatementNetsItsEntriesAndIsChainedByANumberOrABalance(): void
    {
        $statement = new BankStatement(self::IBAN, Currency::CZK, [self::entry('210.00'), self::entry('10.00', MovementDirection::Outgoing)]);

        self::assertTrue(Money::of('200.00', Currency::CZK)->equals($statement->net()));
        self::assertFalse($statement->isChained());
        self::assertTrue(new BankStatement(self::IBAN, Currency::CZK, [], sequence: StatementSequence::electronic(1))->isChained());
        self::assertTrue(new BankStatement(self::IBAN, Currency::CZK, [], openingBalance: Money::of('1.00', Currency::CZK))->isChained());
        self::assertTrue(new BankStatement(self::IBAN, Currency::CZK, [], closingBalance: Money::of('1.00', Currency::CZK))->isChained());
    }

    public function testAStatementWithoutBalancesLeavesEntriesOfAnotherCurrencyOutOfItsNet(): void
    {
        $statement = new BankStatement(self::IBAN, Currency::CZK, [self::entry('210.00'), self::entry('5.00', currency: Currency::EUR)]);

        self::assertTrue(Money::of('210.00', Currency::CZK)->equals($statement->net()));
    }

    public function testAnEntryWrittenWithSpacesIsOfTheAccount(): void
    {
        $statement = new BankStatement('cz65 0800 0000 1920 0014 5399', Currency::CZK, [self::entry('1.00')]);

        self::assertCount(1, $statement->entries);
    }

    /**
     * @return iterable<string, array{\Closure(): mixed}>
     */
    public static function wrongStatements(): iterable
    {
        $czk = static fn (string $amount): Money => Money::of($amount, Currency::CZK);
        yield 'no account' => [static fn (): BankStatement => new BankStatement(' ', Currency::CZK, [])];
        yield 'an entry of another account' => [static fn (): BankStatement => new BankStatement('CZ5508000000001234567899', Currency::CZK, [self::entry('1.00')])];
        yield 'an empty id' => [static fn (): BankStatement => new BankStatement(self::IBAN, Currency::CZK, [], ' ')];
        yield 'a long id' => [static fn (): BankStatement => new BankStatement(self::IBAN, Currency::CZK, [], str_repeat('x', 65))];
        yield 'a period backwards' => [static fn (): BankStatement => new BankStatement(self::IBAN, Currency::CZK, [], from: new \DateTimeImmutable('2026-11-30'), to: new \DateTimeImmutable('2026-11-01'))];
        yield 'an opening balance in another currency' => [static fn (): BankStatement => new BankStatement(self::IBAN, Currency::CZK, [], openingBalance: Money::of('1.00', Currency::EUR))];
        yield 'a closing balance in another currency' => [static fn (): BankStatement => new BankStatement(self::IBAN, Currency::CZK, [], closingBalance: Money::of('1.00', Currency::EUR))];
        yield 'an entry in another currency with balances' => [static fn (): BankStatement => new BankStatement(self::IBAN, Currency::CZK, [self::entry('1.00', currency: Currency::EUR)], openingBalance: $czk('1.00'))];
    }

    #[DataProvider('wrongStatements')]
    public function testAWrongStatementIsRefused(\Closure $make): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $make();
    }

    public function testAStatementIdOfSixtyFourIsKept(): void
    {
        $id = str_repeat('x', 64);

        self::assertSame($id, new BankStatement(self::IBAN, Currency::CZK, [], $id)->statementId);
        $day = new \DateTimeImmutable('2026-11-01');
        self::assertSame($day, new BankStatement(self::IBAN, Currency::CZK, [], from: $day, to: $day)->to);
    }

    public function testEachChannelVouchesAsItCan(): void
    {
        $api = MovementProvenance::bankApi('cz.fio');
        self::assertSame([MovementChannel::Api, 'cz.fio', MovementTrust::Verified, MovementProvenance::BANK_API, null, null], [$api->channel, $api->sourceId, $api->trust, $api->basis, $api->inboundMessageId, $api->actorId]);

        $uploaded = MovementProvenance::uploaded('iso.camt053', '01963c5e-0000-7000-8000-0000000000aa');
        self::assertSame([MovementChannel::Statement, 'iso.camt053', MovementTrust::Verified, MovementProvenance::UPLOADED, '01963c5e-0000-7000-8000-0000000000aa'], [$uploaded->channel, $uploaded->sourceId, $uploaded->trust, $uploaded->basis, $uploaded->actorId]);

        $unverified = MovementProvenance::unverified(MovementChannel::Api, 'example_aggregator-2');
        self::assertSame([MovementTrust::Unverified, MovementProvenance::UNVERIFIED_SOURCE], [$unverified->trust, $unverified->basis]);

        $mail = MovementProvenance::mail('cz.kb', MovementTrust::Unverified, 'no_results', '01963c5e-0000-7000-8000-000000000001');
        self::assertSame([MovementChannel::Mail, 'cz.kb', MovementTrust::Unverified, 'no_results', '01963c5e-0000-7000-8000-000000000001'], [$mail->channel, $mail->sourceId, $mail->trust, $mail->basis, $mail->inboundMessageId]);

        self::assertSame(str_repeat('a', 64), MovementProvenance::bankApi(str_repeat('a', 64))->sourceId);
        self::assertSame(str_repeat('b', 32), MovementProvenance::mail('cz.kb', MovementTrust::Verified, str_repeat('b', 32), 'm')->basis);
    }

    /**
     * @return iterable<string, array{\Closure(): mixed}>
     */
    public static function wrongProvenances(): iterable
    {
        yield 'an upper-case source' => [static fn (): MovementProvenance => MovementProvenance::bankApi('CZ.fio')];
        yield 'a source starting with a dot' => [static fn (): MovementProvenance => MovementProvenance::bankApi('.fio')];
        yield 'a source with a space' => [static fn (): MovementProvenance => MovementProvenance::bankApi('cz fio')];
        yield 'a source with a new line' => [static fn (): MovementProvenance => MovementProvenance::bankApi("fio\n")];
        yield 'a long source' => [static fn (): MovementProvenance => MovementProvenance::bankApi(str_repeat('a', 65))];
        yield 'an empty basis' => [static fn (): MovementProvenance => MovementProvenance::mail('cz.kb', MovementTrust::Verified, '', 'm')];
        yield 'a long basis' => [static fn (): MovementProvenance => MovementProvenance::mail('cz.kb', MovementTrust::Verified, str_repeat('b', 33), 'm')];
        yield 'a failed mail' => [static fn (): MovementProvenance => MovementProvenance::mail('cz.kb', MovementTrust::Failed, 'dmarc', 'm')];
        yield 'an unverified mail' => [static fn (): MovementProvenance => MovementProvenance::unverified(MovementChannel::Mail, 'cz.kb')];
    }

    #[DataProvider('wrongProvenances')]
    public function testAWrongProvenanceIsRefused(\Closure $make): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $make();
    }

    public function testAFileIsKnownByItsHashAndKeepsAShortName(): void
    {
        $file = new StatementFile(str_repeat('n', 300).'.json', 'bytes');

        self::assertSame(hash('sha256', 'bytes'), $file->sha256());
        self::assertSame(255, mb_strlen($file->shortName()));
        self::assertSame('statement.json', new StatementFile('statement.json', 'x')->shortName());
    }

    public function testAnEmptyFileIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StatementFile('empty.json', '');
    }

    public function testTrustIsAScale(): void
    {
        self::assertTrue(MovementTrust::Verified->isAbove(MovementTrust::Unverified));
        self::assertTrue(MovementTrust::Unverified->isAbove(MovementTrust::Failed));
        self::assertFalse(MovementTrust::Unverified->isAbove(MovementTrust::Unverified));
        self::assertFalse(MovementTrust::Unverified->isAbove(MovementTrust::Verified));
        self::assertTrue(MovementTrust::Verified->isAbove(MovementTrust::Failed));
        self::assertFalse(MovementTrust::Verified->isAbove(MovementTrust::Verified));
        self::assertFalse(MovementTrust::Failed->isAbove(MovementTrust::Failed));
        self::assertFalse(MovementTrust::Failed->isAbove(MovementTrust::Unverified));
    }

    public function testANameIsCutByCharacters(): void
    {
        self::assertSame(str_repeat('ř', 255), new StatementFile(str_repeat('ř', 300), 'bytes')->shortName());
    }

    public function testARefusalCarriesItsReasonAndDetail(): void
    {
        $refused = new StatementRefused(StatementRefused::UNKNOWN_ACCOUNT, 'No such account.');

        self::assertSame([StatementRefused::UNKNOWN_ACCOUNT, 'No such account.'], [$refused->reason, $refused->getMessage()]);
    }

    public function testAnIntakeResultOpensAndClosesNoGapByDefault(): void
    {
        $result = new IntakeResult(null, [], [], 0);

        self::assertSame([0, 0], [$result->gapsDetected, $result->gapsClosed]);
    }

    public function testSequencesOfTwoKindsAreNotCompared(): void
    {
        self::assertNull(StatementSequence::electronic(5)->followsOn(StatementSequence::withinYear(4, 2026)));
        self::assertNull(StatementSequence::withinYear(5, 2026)->followsOn(StatementSequence::electronic(4)));
    }

    public function testAStatementIdIsCountedInCharacters(): void
    {
        $id = str_repeat('ř', 64);

        self::assertSame($id, new BankStatement(self::IBAN, Currency::CZK, [], $id)->statementId);
    }

    public function testAPeriodMayBeOpenAtEitherEnd(): void
    {
        $day = new \DateTimeImmutable('2026-11-30');

        self::assertSame($day, new BankStatement(self::IBAN, Currency::CZK, [], from: $day)->from);
        self::assertSame($day, new BankStatement(self::IBAN, Currency::CZK, [], to: $day)->to);
    }

    public function testAnEntryOfAnotherCurrencyFirstLeavesTheRestInTheNet(): void
    {
        $statement = new BankStatement(self::IBAN, Currency::CZK, [self::entry('5.00', currency: Currency::EUR), self::entry('210.00')]);

        self::assertTrue(Money::of('210.00', Currency::CZK)->equals($statement->net()));
    }

    private static function entry(string $amount, MovementDirection $direction = MovementDirection::Incoming, Currency $currency = Currency::CZK): ParsedMovement
    {
        return new ParsedMovement($direction, Money::of($amount, $currency), self::IBAN, null, null, null, new \DateTimeImmutable('2026-11-20'), null, null, null, null, null);
    }
}
