<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Purchasing\Application;

use App\Purchasing\Application\ReceivedDocumentInput;
use App\Purchasing\Application\ReceivedDocumentWarning;
use App\Purchasing\Domain\ExchangeRate;
use App\Purchasing\Domain\ReceivedDocumentKind;
use App\Shared\Domain\Currency;
use PHPUnit\Framework\Attributes\DataProvider;

final class DocumentWarningsTest extends PurchasingTestCase
{
    /**
     * A document with a tax point on 1 June 2023 can be deducted until 31 December 2025.
     *
     * @return iterable<string, array{string, list<string>}>
     */
    public static function deadlines(): iterable
    {
        yield 'on the last day it is close, not passed' => ['2025-12-31 09:00:00', [ReceivedDocumentWarning::DEDUCTION_DEADLINE_NEAR]];
        yield 'the day after it is passed' => ['2026-01-01 09:00:00', [ReceivedDocumentWarning::DEDUCTION_DEADLINE_PASSED]];
        yield 'sixty days before it is close' => ['2025-11-01 09:00:00', [ReceivedDocumentWarning::DEDUCTION_DEADLINE_NEAR]];
        yield 'sixty-one days before it is not' => ['2025-10-31 09:00:00', []];
    }

    /**
     * @param list<string> $codes
     */
    #[DataProvider('deadlines')]
    public function testTheDeductionDeadlineIsReadInDays(string $now, array $codes): void
    {
        $this->clock->modify($now);

        $saved = $this->recording()->create($this->firm->id(), $this->input(issue: '2023-06-01', taxPoint: '2023-06-01', received: '2025-10-01'));

        self::assertSame($codes, $saved->warnings->codes());
    }

    public function testOnlyASimplifiedReceiptIsHeldToTheLimit(): void
    {
        $invoice = $this->recording()->create($this->firm->id(), $this->input(base: 900_000));

        self::assertTrue($invoice->warnings->isEmpty(), 'A tax invoice of 10,890 CZK is not a simplified one.');
    }

    public function testTheLimitItselfIsAllowed(): void
    {
        $saved = $this->recording()->create($this->firm->id(), $this->input(kind: ReceivedDocumentKind::SimplifiedReceipt, base: 826_446, vat: 173_554));

        self::assertSame(1_000_000, $saved->document->total()?->minor());
        self::assertTrue($saved->warnings->isEmpty(), '10,000 CZK with VAT.');
    }

    public function testASimplifiedReceiptInAnotherCurrencyIsHeldToTheLimitInCrowns(): void
    {
        $eur = $this->recording()->create($this->firm->id(), $this->input(kind: ReceivedDocumentKind::SimplifiedReceipt, currency: Currency::EUR, base: 40_000, rate: ExchangeRate::of('25')));

        self::assertSame([ReceivedDocumentWarning::SIMPLIFIED_OVER_LIMIT], $eur->warnings->codes(), '484 EUR is 12,100 CZK.');
    }

    public function testASimplifiedReceiptInAnotherCurrencyWithinTheLimitInCrownsDoesNotWarn(): void
    {
        $eur = $this->recording()->create($this->firm->id(), $this->input(kind: ReceivedDocumentKind::SimplifiedReceipt, currency: Currency::EUR, base: 10_000, rate: ExchangeRate::of('25')));

        self::assertTrue($eur->warnings->isEmpty(), '121 EUR is 3,025 CZK.');
    }

    public function testADraftInAnotherCurrencyWithoutARateIsNotHeldToTheLimit(): void
    {
        $draft = $this->draft();

        $saved = $this->editing()->edit($draft, $this->input(kind: ReceivedDocumentKind::SimplifiedReceipt, currency: Currency::USD, base: 2_000_000));

        self::assertNull($saved->document->exchangeRate());
        self::assertTrue($saved->warnings->isEmpty(), 'Its value in crowns is not known.');
    }

    public function testADraftInCrownsWithoutARateIsHeldToTheLimit(): void
    {
        $saved = $this->editing()->edit($this->draft(), $this->input(kind: ReceivedDocumentKind::SimplifiedReceipt, base: 2_000_000));

        self::assertSame([ReceivedDocumentWarning::SIMPLIFIED_OVER_LIMIT], $saved->warnings->codes());
    }

    public function testADraftOfASimplifiedReceiptWithoutATotalDoesNotWarn(): void
    {
        $given = $this->input(kind: ReceivedDocumentKind::SimplifiedReceipt);
        $saved = $this->editing()->edit($this->draft(), new ReceivedDocumentInput(
            $given->kind,
            $given->treatment,
            $given->supplierId,
            $given->supplierNumber,
            $given->variableSymbol,
            $given->issueDate,
            $given->taxPointDate,
            $given->dueDate,
            $given->receivedDate,
            $given->currency,
            null,
            null,
            null,
            [],
        ));

        self::assertNull($saved->document->total());
        self::assertTrue($saved->warnings->isEmpty());
    }
}
