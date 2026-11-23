<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Purchasing\Domain;

use Foresi\Purchasing\Domain\DocumentLines;
use Foresi\Purchasing\Domain\ExchangeRate;
use Foresi\Purchasing\Domain\PurchaseTaxTreatment;
use Foresi\Purchasing\Domain\ReceivedDocumentLineInput;
use Foresi\Purchasing\Domain\ReceivedDocumentRefused;
use Foresi\Purchasing\Domain\VatRate;
use Foresi\Purchasing\Domain\VatRecap;
use Foresi\Purchasing\Domain\VatRecapLine;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use PHPUnit\Framework\TestCase;

final class DocumentLinesTest extends TestCase
{
    public function testLinesMustAddUpToTheRecapBasePerRate(): void
    {
        $recap = $this->recap(['21', 100_000], ['12', 50_000]);

        $lines = DocumentLines::check([
            $this->line('Hosting', 60_000, '21'),
            $this->line('Domain', 40_000, '21'),
            $this->line('Books', 50_000, '12'),
        ], $recap, $this->czk(171_000), Currency::CZK);

        self::assertCount(3, $lines);
        $this->assertRefused(ReceivedDocumentRefused::LINES_DO_NOT_ADD_UP, fn (): array => DocumentLines::check([
            $this->line('Hosting', 60_000, '21'),
            $this->line('Domain', 39_999, '21'),
            $this->line('Books', 50_000, '12'),
        ], $recap, $this->czk(171_000), Currency::CZK));
        // A rate of the recap without lines adds up to nothing.
        $this->assertRefused(ReceivedDocumentRefused::LINES_DO_NOT_ADD_UP, fn (): array => DocumentLines::check([
            $this->line('Hosting', 100_000, '21'),
        ], $recap, $this->czk(171_000), Currency::CZK));
    }

    public function testNoLinesAlwaysFit(): void
    {
        self::assertSame([], DocumentLines::check([], $this->recap(['21', 100_000]), null, Currency::CZK));
    }

    public function testALineNamesARateOfTheRecap(): void
    {
        $this->assertRefused(ReceivedDocumentRefused::UNKNOWN_VAT_RATE, fn (): array => DocumentLines::check([
            $this->line('Hosting', 100_000, '21'),
            $this->line('Books', 0, '12'),
        ], $this->recap(['21', 100_000]), $this->czk(121_000), Currency::CZK));
    }

    public function testTheTextIsTrimmedAndTheRateWrittenAsTheRecapWritesIt(): void
    {
        $lines = DocumentLines::check([$this->line('  Hosting  ', 100_000, '21.00')], $this->recap(['21', 100_000]), null, Currency::CZK);

        self::assertSame(['Hosting', '21'], [$lines[0]->text, $lines[0]->vatRate]);
    }

    public function testALineNeedsAText(): void
    {
        $this->assertRefused(ReceivedDocumentRefused::LINE_TEXT_REQUIRED, fn (): array => DocumentLines::check([$this->line('   ', 100_000, '21')], $this->recap(['21', 100_000]), null, Currency::CZK));
    }

    public function testTheTextHasAtMost255CharactersNotBytes(): void
    {
        $recap = $this->recap(['21', 100_000]);

        self::assertCount(1, DocumentLines::check([$this->line(str_repeat('č', 255), 100_000, '21')], $recap, null, Currency::CZK));
        $this->assertRefused(ReceivedDocumentRefused::LINE_TEXT_TOO_LONG, fn (): array => DocumentLines::check([$this->line(str_repeat('č', 256), 100_000, '21')], $recap, null, Currency::CZK));
    }

    public function testADocumentHasAtMostAHundredLines(): void
    {
        $recap = $this->recap(['21', 100]);
        $lines = array_map(fn (int $i): ReceivedDocumentLineInput => $this->line('Line '.$i, 1, '21'), range(1, 100));

        self::assertCount(100, DocumentLines::check($lines, $recap, null, Currency::CZK));
        $lines[] = $this->line('One more', 0, '21');
        $this->assertRefused(ReceivedDocumentRefused::LINES_TOO_MANY, fn (): array => DocumentLines::check($lines, $recap, null, Currency::CZK));
    }

    public function testALineIsInTheCurrencyOfTheDocument(): void
    {
        $this->assertRefused(ReceivedDocumentRefused::CURRENCY_MISMATCH, fn (): array => DocumentLines::check(
            [new ReceivedDocumentLineInput('Hosting', Money::ofMinor(100_000, Currency::EUR), '21')],
            $this->recap(['21', 100_000]),
            null,
            Currency::CZK,
        ));
    }

    public function testTheLinesOfADocumentWithoutARecapAddUpToItsTotalAtRateZero(): void
    {
        $empty = VatRecap::empty(Currency::CZK);

        self::assertCount(2, DocumentLines::check([$this->line('Fee', 30_000, '0'), $this->line('Charge', 20_000, '0')], $empty, $this->czk(50_000), Currency::CZK));
        $this->assertRefused(ReceivedDocumentRefused::LINES_DO_NOT_ADD_UP, fn (): array => DocumentLines::check([$this->line('Fee', 30_000, '0')], $empty, $this->czk(50_000), Currency::CZK));
        $this->assertRefused(ReceivedDocumentRefused::UNKNOWN_VAT_RATE, fn (): array => DocumentLines::check([$this->line('Fee', 50_000, '21')], $empty, $this->czk(50_000), Currency::CZK));
        // Without a total there is nothing to add up to.
        $this->assertRefused(ReceivedDocumentRefused::LINES_DO_NOT_ADD_UP, fn (): array => DocumentLines::check([$this->line('Fee', 0, '0')], $empty, null, Currency::CZK));
    }

    public function testTheLastLineOfARateTakesTheRoundingDifferenceInCzk(): void
    {
        // 0.30 USD at 23.455 is 7.04 CZK, while 0.10 USD alone is 2.35 CZK.
        $rate = ExchangeRate::of('23.455');
        $recap = VatRecap::of([new VatRecapLine(VatRate::of('21'), Money::ofMinor(30, Currency::USD), Money::ofMinor(0, Currency::USD))], Currency::USD, $rate, PurchaseTaxTreatment::NonEuService);
        $usd = static fn (int $minor): ReceivedDocumentLineInput => new ReceivedDocumentLineInput('Calls', Money::ofMinor($minor, Currency::USD), '21');

        $bases = DocumentLines::czkBases([$usd(10), $usd(10), $usd(10)], $recap, null, $rate);

        self::assertSame(704, $recap->baseCzk()?->minor());
        self::assertSame([235, 235, 234], array_map(static fn (?Money $czk): ?int => $czk?->minor(), $bases));
    }

    public function testLinesHaveNoCzkBaseWhileTheRecapHasNone(): void
    {
        $recap = VatRecap::of([new VatRecapLine(VatRate::of('21'), Money::ofMinor(30, Currency::USD), Money::ofMinor(0, Currency::USD))], Currency::USD, null, PurchaseTaxTreatment::NonEuService);

        self::assertSame([null], DocumentLines::czkBases([new ReceivedDocumentLineInput('Calls', Money::ofMinor(30, Currency::USD), '21')], $recap, null, null));
    }

    /**
     * @param array{string, int} ...$bases the rate and the base in CZK minor units
     */
    private function recap(array ...$bases): VatRecap
    {
        $lines = [];
        foreach ($bases as [$rate, $base]) {
            $rate = VatRate::of($rate);
            $lines[] = new VatRecapLine($rate, $this->czk($base), $this->czk($this->czk($base)->multipliedBy($rate->factor())->minor()));
        }

        return VatRecap::of($lines, Currency::CZK, ExchangeRate::one(), PurchaseTaxTreatment::Domestic);
    }

    private function line(string $text, int $base, string $rate): ReceivedDocumentLineInput
    {
        return new ReceivedDocumentLineInput($text, $this->czk($base), $rate);
    }

    private function czk(int $minor): Money
    {
        return Money::ofMinor($minor, Currency::CZK);
    }

    private function assertRefused(string $reason, \Closure $change): void
    {
        try {
            $change();
            self::fail('The lines were taken.');
        } catch (ReceivedDocumentRefused $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }
}
