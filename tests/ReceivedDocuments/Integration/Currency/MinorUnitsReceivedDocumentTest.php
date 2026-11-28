<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\ReceivedDocuments\Integration\Currency;

use Foresi\Identity\Domain\ApiScope;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A received document in a currency with 0 minor units (JPY) or 3 (BHD), over REST:
 * recorded from fields, read back, given lines and paid. Every amount is whole minor
 * units of the currency and carries its minor_units, and an input amount with more
 * decimals than the currency has is refused. A received document has no PDF or
 * spreadsheet of its own (the file of the supplier is kept as it came, and the lists
 * of the module export nothing), so the API read is the output covered here.
 */
final class MinorUnitsReceivedDocumentTest extends ApiTestCase
{
    private string $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = $this->issued([ApiScope::from('received_documents:write')]);
    }

    /**
     * @return iterable<string, array{string, string, string, string, string, string, int, int, int}>
     */
    public static function currencies(): iterable
    {
        // code, total, base, vat, the VAT in crowns, the exchange rate, minor units of the base, of the vat, of the total
        yield 'JPY has no minor units' => ['JPY', '1500', '1240', '260', '44.20', '0.17', 1240, 260, 1500];
        yield 'BHD has three' => ['BHD', '14.937', '12.345', '2.592', '155.52', '60', 12345, 2592, 14937];
    }

    #[DataProvider('currencies')]
    public function testItIsRecordedReadAndPaidInTheMinorUnitsOfTheCurrency(string $code, string $total, string $base, string $vat, string $vatCzk, string $rate, int $baseMinor, int $vatMinor, int $totalMinor): void
    {
        $digits = 'JPY' === $code ? 0 : 3;
        $money = static fn (int $minor): array => ['amount_minor' => $minor, 'currency' => $code, 'minor_units' => $digits];

        $this->send('POST', '/api/v1/received-documents', ['currency' => $code, 'exchange_rate' => $rate, 'total' => $total, 'vat_recap' => ['21:'.$base.':'.$vat.':'.$vatCzk]] + $this->fields());

        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);
        self::assertSame($money($totalMinor), $this->at('data', 'total'));
        self::assertSame($money($baseMinor), $this->at('data', 'base'));
        self::assertSame($money($vatMinor), $this->at('data', 'vat'));
        self::assertSame($money($totalMinor), $this->at('data', 'remaining'));
        self::assertSame($money($baseMinor), $this->at('data', 'vat_recap', 0, 'base'));
        self::assertSame($money($vatMinor), $this->at('data', 'vat_recap', 0, 'vat'));
        // The VAT in crowns is a crown amount, whatever the currency of the document.
        self::assertSame(['amount_minor' => (int) round((float) $vatCzk * 100), 'currency' => 'CZK', 'minor_units' => 2], $this->at('data', 'vat_recap', 0, 'vat_czk'));
        $created = $this->json()['data'];

        $this->get('/api/v1/received-documents/'.$id, secret: $this->writer);
        self::assertSame($created, $this->json()['data']);
        $this->get('/api/v1/received-documents', secret: $this->writer);
        self::assertSame($money($totalMinor), $this->at('data', 0, 'total'));

        // Lines in the currency of the document: the base of a line is written with its decimals alone.
        $this->send('PUT', '/api/v1/received-documents/'.$id.'/lines', ['lines' => [['text' => 'Hosting', 'base' => $base, 'vat_rate' => '21']]]);
        self::assertResponseIsSuccessful();
        self::assertSame($money($baseMinor), $this->at('data', 'lines', 0, 'base'));

        // A part payment with the decimals of the currency, then the rest.
        $this->send('POST', '/api/v1/received-documents/'.$id.'/payments', ['paid_on' => '2026-11-12', 'amount' => $vat]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame($money($totalMinor - $vatMinor), $this->at('data', 'remaining'));
        $this->send('POST', '/api/v1/received-documents/'.$id.'/mark-paid', []);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->at('data', 'is_paid'));
        self::assertSame($money($vatMinor), $this->at('data', 'payments', 0, 'amount'));
        self::assertSame($money($totalMinor - $vatMinor), $this->at('data', 'payments', 1, 'amount'));
        self::assertSame($money(0), $this->at('data', 'remaining'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function tooManyDecimals(): iterable
    {
        yield 'JPY takes no decimals' => ['JPY', '1250.5'];
        yield 'BHD takes three at most' => ['BHD', '1.2345'];
    }

    #[DataProvider('tooManyDecimals')]
    public function testAnAmountWithMoreDecimalsThanTheCurrencyHasIsRefused(string $code, string $total): void
    {
        $this->send('POST', '/api/v1/received-documents', ['currency' => $code, 'exchange_rate' => '1', 'total' => $total, 'vat_recap' => ['21:1000:210']] + $this->fields());

        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'total', 'reason' => 'amount_invalid']);
        $this->get('/api/v1/received-documents', secret: $this->writer);
        self::assertSame([], $this->ids());
    }

    /**
     * @return array<string, mixed>
     */
    private function fields(): array
    {
        return [
            'kind' => 'tax_invoice',
            'supplier_id' => $this->customerId,
            'supplier_number' => 'FV-2026-123',
            'issue_date' => '2026-11-02',
            'due_date' => '2026-11-16',
            'received_date' => '2026-11-03',
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
