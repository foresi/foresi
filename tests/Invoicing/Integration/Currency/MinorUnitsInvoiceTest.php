<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Currency;

use Foresi\Country\Integration\Invoicing\CzechRepublic\CzechInvoicing;
use Foresi\Country\Integration\Invoicing\CzechRepublic\CzechSupplyCodes;
use Foresi\Country\Integration\Invoicing\CzechRepublic\CzechVatRates;
use Foresi\Country\Integration\Invoicing\CzechRepublic\IsdocWriter;
use Foresi\Country\Integration\Invoicing\CzechRepublic\QrPlatba;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Invoicing\Application\InvoicePdf;
use Foresi\Invoicing\Application\IssuedDocumentFactory;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Contract\Quantity;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Invoicing\Support\HomeCurrencyCountry;
use Foresi\Tests\Invoicing\Support\IssuesInvoices;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use Foresi\Tests\Support\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * An invoice issued in a currency that is not the crown, with 0 minor units (JPY)
 * or 3 (BHD): created over REST, read back, and printed as a PDF. The amounts are
 * whole minor units of the currency in the API and carry its minor_units, the
 * PDF prints exactly as many decimals as the currency has, and an input amount
 * with more decimals than the currency has is refused.
 */
final class MinorUnitsInvoiceTest extends ApiTestCase
{
    use IssuesInvoices;

    private string $writer;
    private CzechInvoicing $czech;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = $this->issued([ApiScope::from('invoices:write')]);
        // The Czech rules with the currency under test as the home currency, set before anything has used the country.
        $container = self::getContainer();
        $this->czech = new CzechInvoicing(new CzechVatRates(), new CzechSupplyCodes(), $container->get(IsdocWriter::class), $container->get(QrPlatba::class));
    }

    /**
     * @return iterable<string, array{string, string, string, int, int, int, int, string, string}>
     */
    public static function currencies(): iterable
    {
        // code, unit price, quantity, net, vat (21 %), total, minor units, the net as printed, the total as printed
        yield 'JPY has no minor units' => ['JPY', '1500', '2', 3000, 630, 3630, 0, '3 000', '3 630'];
        yield 'BHD has three' => ['BHD', '12.345', '1', 12345, 2592, 14937, 3, '12,345', '14,937'];
    }

    #[DataProvider('currencies')]
    public function testItIsCreatedReadAndPrintedInTheMinorUnitsOfTheCurrency(string $code, string $price, string $quantity, int $net, int $vat, int $total, int $digits, string $printedNet, string $printedTotal): void
    {
        $this->homeCurrency($code);

        $this->send('POST', '/api/v1/invoices', $this->fields($price, $quantity));

        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);
        self::assertSame($code, $this->at('data', 'currency'));
        self::assertSame(['amount_minor' => $net, 'currency' => $code, 'minor_units' => $digits], $this->at('data', 'net'));
        self::assertSame(['amount_minor' => $vat, 'currency' => $code, 'minor_units' => $digits], $this->at('data', 'vat'));
        self::assertSame(['amount_minor' => $total, 'currency' => $code, 'minor_units' => $digits], $this->at('data', 'total'));
        self::assertSame(['amount_minor' => $net, 'currency' => $code, 'minor_units' => $digits], $this->at('data', 'lines', 0, 'net'));
        self::assertSame(['amount_minor' => $net / (int) $quantity, 'currency' => $code, 'minor_units' => $digits], $this->at('data', 'lines', 0, 'unit_price'));
        $created = $this->json()['data'];

        $this->get('/api/v1/invoices/'.$id, secret: $this->writer);
        self::assertSame($created, $this->json()['data']);
        $this->get('/api/v1/invoices', secret: $this->writer);
        self::assertSame(['amount_minor' => $total, 'currency' => $code, 'minor_units' => $digits], $this->at('data', 0, 'total'));
        self::assertSame(['amount_minor' => $total, 'currency' => $code, 'minor_units' => $digits], $this->at('data', 0, 'remaining'));

        $this->assertPrinted($id, $printedNet, $printedTotal);
    }

    /**
     * The same invoice issued by the service, so that reading and printing stand
     * apart from the writing of the amounts.
     */
    #[DataProvider('currencies')]
    public function testAnIssuedInvoiceIsReadAndPrintedInTheMinorUnitsOfTheCurrency(string $code, string $price, string $quantity, int $net, int $vat, int $total, int $digits, string $printedNet, string $printedTotal): void
    {
        $this->homeCurrency($code);
        $line = new LineInput('Work', Quantity::fromString($quantity), 'h', Money::of($price, Currency::of($code)), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));
        $invoice = $this->flushed(fn (): \Foresi\Invoicing\Domain\Invoice => $this->invoice(overrides: ['lines' => [$line]]));

        $this->get('/api/v1/invoices/'.$invoice->id());

        self::assertResponseIsSuccessful();
        self::assertSame($code, $this->at('data', 'currency'));
        self::assertSame(['amount_minor' => $net, 'currency' => $code, 'minor_units' => $digits], $this->at('data', 'net'));
        self::assertSame(['amount_minor' => $vat, 'currency' => $code, 'minor_units' => $digits], $this->at('data', 'vat'));
        self::assertSame(['amount_minor' => $total, 'currency' => $code, 'minor_units' => $digits], $this->at('data', 'total'));
        self::assertSame($digits, $this->at('data', 'lines', 0, 'unit_price', 'minor_units'));
        $this->assertPrinted($invoice->id(), $printedNet, $printedTotal);
    }

    private function assertPrinted(string $id, string $net, string $total): void
    {
        $text = PdfTools::text(self::getContainer()->get(InvoicePdf::class)->render(self::getContainer()->get(IssuedDocumentFactory::class)->of($id))->bytes);
        self::assertStringContainsString($net, $text);
        self::assertStringContainsString($total, $text);
        // No decimals the currency does not have: "3 630,00" for the yen would be wrong, and a digit more or less for the dinar too.
        self::assertMatchesRegularExpression('/'.preg_quote($total, '/').'(?![\d,])/u', $text, 'The total is printed with the decimals of the currency, no more.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function tooManyDecimals(): iterable
    {
        yield 'JPY takes no decimals' => ['JPY', '12.5'];
        yield 'BHD takes three at most' => ['BHD', '1.2345'];
    }

    #[DataProvider('tooManyDecimals')]
    public function testAnAmountWithMoreDecimalsThanTheCurrencyHasIsRefused(string $code, string $price): void
    {
        $this->homeCurrency($code);

        $this->send('POST', '/api/v1/invoices', $this->fields($price, '1'));

        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'lines[0].unit_price', 'reason' => 'number_invalid']);
        $this->get('/api/v1/invoices', secret: $this->writer);
        self::assertSame([], $this->ids());
    }

    private function homeCurrency(string $code): void
    {
        self::getContainer()->set(CzechInvoicing::class, new HomeCurrencyCountry($this->czech, Currency::of($code)));
    }

    /**
     * @return array<string, mixed>
     */
    private function fields(string $price, string $quantity): array
    {
        return [
            'customer_id' => $this->customerId,
            'issue_date' => '2026-11-02',
            'lines' => [['text' => 'Consulting', 'unit_price' => $price, 'quantity' => $quantity, 'unit' => 'h']],
        ];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function send(string $method, string $path, ?array $body = null): void
    {
        $this->client->request($method, $path, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->writer,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: null === $body ? null : json_encode((object) $body, \JSON_THROW_ON_ERROR));
    }
}
