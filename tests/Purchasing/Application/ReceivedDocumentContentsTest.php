<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Purchasing\Application;

use App\Party\Application\PartyDetails;
use App\Party\Application\PartyQuery;
use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use App\Purchasing\Application\ReceivedDocumentContents;
use App\Purchasing\Application\ReceivedDocumentInput;
use App\Purchasing\Application\ReceivedDocuments;
use App\Purchasing\Domain\ExchangeRate;
use App\Purchasing\Domain\PurchaseTaxTreatment;
use App\Purchasing\Domain\ReceivedDocumentKind;
use App\Purchasing\Domain\ReceivedDocumentRefused;
use App\Purchasing\Domain\VatRate;
use App\Purchasing\Domain\VatRecapLine;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tests\Purchasing\Support\FixedExchangeRates;
use PHPUnit\Framework\TestCase;

final class ReceivedDocumentContentsTest extends TestCase
{
    private FixedExchangeRates $rates;
    private ReceivedDocumentContents $contents;
    private LegalEntity $firm;

    protected function setUp(): void
    {
        $this->rates = new FixedExchangeRates();
        $this->contents = new ReceivedDocumentContents(self::createStub(PartyQuery::class), self::createStub(PartyDetails::class), self::createStub(ReceivedDocuments::class), $this->rates);
        $this->firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, '');
    }

    public function testADocumentInAnotherCurrencyTakesTheRateOfItsTaxPoint(): void
    {
        $this->rates->set('USD', '20.918', publishedOn: '2026-11-06');

        $content = $this->contents->of($this->firm, $this->input(Currency::USD, taxPoint: '2026-11-08'), null, true);

        self::assertSame('20.918', $content->exchangeRate?->toString());
        self::assertSame('2026-11-06', $content->exchangeRateDate?->format('Y-m-d'));
        self::assertSame([['USD', '2026-11-08']], $this->rates->asked);
    }

    public function testTheIssueDateStandsInForATaxPointNotNamed(): void
    {
        $this->rates->set('EUR', '24.335');

        $this->contents->of($this->firm, $this->input(Currency::EUR, taxPoint: null), null, false);

        self::assertSame([['EUR', '2026-10-28']], $this->rates->asked);
    }

    public function testARateQuotedForSeveralUnitsIsKeptForOne(): void
    {
        $this->rates->set('USD', '2091.8', 100);

        self::assertSame('20.918', $this->contents->of($this->firm, $this->input(Currency::USD), null, true)->exchangeRate?->toString());
    }

    public function testATypedRateWinsAndACzkDocumentAsksForNone(): void
    {
        $this->rates->set('EUR', '24.335');

        self::assertSame('25', $this->contents->of($this->firm, $this->input(Currency::EUR, rate: ExchangeRate::of('25')), null, true)->exchangeRate?->toString());
        self::assertNull($this->contents->of($this->firm, $this->input(Currency::CZK), null, true)->exchangeRate);
        self::assertSame([], $this->rates->asked);
    }

    public function testADraftWithoutAPublishedRateStaysWithoutOne(): void
    {
        self::assertNull($this->contents->of($this->firm, $this->input(Currency::USD), null, false)->exchangeRate);

        try {
            $this->contents->of($this->firm, $this->input(Currency::USD), null, true);
            self::fail('A document to record took no rate.');
        } catch (ReceivedDocumentRefused $refused) {
            self::assertSame(ReceivedDocumentRefused::EXCHANGE_RATE_UNAVAILABLE, $refused->reason);
        }
    }

    public function testAnUnknownSupplierIsRefused(): void
    {
        $this->expectException(ReceivedDocumentRefused::class);
        $this->contents->of($this->firm, $this->input(Currency::CZK, supplierId: '0190b4a1-0000-7000-8000-000000000000'), null, false);
    }

    private function input(Currency $currency, ?string $taxPoint = '2026-10-28', ?ExchangeRate $rate = null, ?string $supplierId = null): ReceivedDocumentInput
    {
        return new ReceivedDocumentInput(
            ReceivedDocumentKind::ForeignInvoice,
            PurchaseTaxTreatment::EuService,
            $supplierId,
            'INV-1',
            '',
            new \DateTimeImmutable('2026-10-28'),
            null === $taxPoint ? null : new \DateTimeImmutable($taxPoint),
            null,
            new \DateTimeImmutable('2026-11-02'),
            $currency,
            Money::ofMinor(10_000, $currency),
            $rate,
            null,
            [new VatRecapLine(VatRate::of('21'), Money::ofMinor(10_000, $currency), Money::ofMinor(0, $currency))],
        );
    }
}
