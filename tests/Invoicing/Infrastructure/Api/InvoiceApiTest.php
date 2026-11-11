<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure\Api;

use App\Identity\Domain\ApiScope;
use App\Invoicing\Application\TrashInvoice;
use App\Invoicing\Domain\Invoice;
use App\Shared\Domain\Id;
use App\Shared\Infrastructure\Api\Cursor;
use App\Tests\Shared\Infrastructure\Api\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * GET /api/v1/invoices and /api/v1/invoices/{id}: the documents of the token's
 * organisation, narrowed by the query string and read by cursor.
 */
final class InvoiceApiTest extends ApiTestCase
{
    public function testAnItemOfTheListIsWrittenAsTheDocumentHoldsIt(): void
    {
        $invoice = $this->inTenant($this->tenant, fn (): Invoice => $this->invoice('2026-11-02', '2026-11-30'));

        $this->get('/api/v1/invoices');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        self::assertSame([
            'data' => [[
                'id' => $invoice->id(),
                'number' => $invoice->number(),
                'kind' => 'invoice',
                'firm' => ['id' => $this->firmId, 'name' => 'Example Firm s.r.o.'],
                'customer' => ['id' => $this->customerId, 'name' => 'Example Customer a.s.'],
                'issue_date' => '2026-11-02',
                'due_date' => '2026-11-30',
                'tax_point_date' => '2026-11-02',
                'total' => ['amount_minor' => 242000, 'currency' => 'CZK'],
                'paid' => ['amount_minor' => 0, 'currency' => 'CZK'],
                'remaining' => ['amount_minor' => 242000, 'currency' => 'CZK'],
                'payment_state' => 'unpaid',
                'overdue' => false,
                'delivered' => false,
                'trashed' => false,
                'created_at' => $invoice->createdAt()->format(\DATE_ATOM),
            ]],
            'next_cursor' => null,
        ], $this->json());
    }

    public function testTheDetailHoldsTheLinesThePaymentsAndTheBalances(): void
    {
        $invoice = $this->inTenant($this->tenant, fn (): Invoice => $this->invoice('2026-11-02', '2026-11-30'));
        $payment = $this->inTenant($this->tenant, fn (): \App\Invoicing\Domain\Payment => $this->payment('2026-11-12', [$invoice->id() => '1000']));

        $this->get('/api/v1/invoices/'.$invoice->id());

        self::assertResponseIsSuccessful();
        self::assertSame(['data' => [
            'id' => $invoice->id(),
            'number' => $invoice->number(),
            'kind' => 'invoice',
            'firm' => ['id' => $this->firmId, 'name' => 'Example Firm s.r.o.'],
            'customer' => [
                'id' => $this->customerId,
                'name' => 'Example Customer a.s.',
                'registration_number' => '87654320',
                'vat_id' => 'CZ87654320',
                'address' => ['street' => 'Customer Street 2', 'city' => 'Customer City', 'postal_code' => '200 00', 'country_code' => 'CZ'],
            ],
            'issue_date' => '2026-11-02',
            'due_date' => '2026-11-30',
            'tax_point_date' => '2026-11-02',
            'currency' => 'CZK',
            'variable_symbol' => '20260001',
            'order_number' => 'PO-1',
            'description' => 'Consulting',
            'payment_method' => 'bank_transfer',
            'net' => ['amount_minor' => 200000, 'currency' => 'CZK'],
            'vat' => ['amount_minor' => 42000, 'currency' => 'CZK'],
            'total' => ['amount_minor' => 242000, 'currency' => 'CZK'],
            'payment_state' => 'partly_paid',
            'delivered' => false,
            'trashed' => false,
            'created_at' => $invoice->createdAt()->format(\DATE_ATOM),
            'corrected_invoice_id' => null,
            'lines' => [[
                'description' => 'Work',
                'quantity' => '2.0000',
                'unit' => 'h',
                'unit_price' => ['amount_minor' => 100000, 'currency' => 'CZK'],
                'price_type' => 'without_vat',
                'discount_percent' => '0.00',
                'vat_treatment' => 'standard',
                'vat_rate_kind' => 'base',
                'vat_rate_percent' => '21.00',
                'net' => ['amount_minor' => 200000, 'currency' => 'CZK'],
                'vat' => ['amount_minor' => 42000, 'currency' => 'CZK'],
                'gross' => ['amount_minor' => 242000, 'currency' => 'CZK'],
            ]],
            'payments' => [[
                'payment_id' => $payment->id(),
                'received_on' => '2026-11-12',
                'allocated' => ['amount_minor' => 100000, 'currency' => 'CZK'],
                'settlement' => ['amount_minor' => 0, 'currency' => 'CZK'],
                'method' => 'bank_transfer',
                'source' => 'manual',
            ]],
            'corrections' => [],
            'balances' => [
                'paid' => ['amount_minor' => 100000, 'currency' => 'CZK'],
                'corrections' => ['amount_minor' => 0, 'currency' => 'CZK'],
                'remaining' => ['amount_minor' => 142000, 'currency' => 'CZK'],
                'to_refund' => ['amount_minor' => 0, 'currency' => 'CZK'],
            ],
        ]], $this->json());
    }

    public function testTheKindNarrowsTheList(): void
    {
        $invoice = $this->inTenant($this->tenant, fn (): Invoice => $this->invoice());

        $this->get('/api/v1/invoices', ['kind' => 'invoice']);
        self::assertSame([$invoice->id()], $this->ids());

        $this->get('/api/v1/invoices', ['kind' => 'advance']);
        self::assertSame([], $this->ids());
    }

    public function testTheFirmAndTheCustomerNarrowTheList(): void
    {
        [$first, $other, $otherCustomer] = $this->inTenant($this->tenant, function (): array {
            $first = $this->invoice();
            [$firm, $series, $customer] = $this->firmWithCustomer('Other Firm s.r.o.', '25596641');

            return [$first, $this->invoice(firmId: $firm, seriesId: $series, customerId: $customer), $customer];
        });

        $this->get('/api/v1/invoices', ['firm_id' => $this->firmId]);
        self::assertSame([$first->id()], $this->ids());
        $this->get('/api/v1/invoices', ['firm_id' => $other->firm()->id()]);
        self::assertSame([$other->id()], $this->ids());
        $this->get('/api/v1/invoices', ['customer_id' => $otherCustomer]);
        self::assertSame([$other->id()], $this->ids());
        $this->get('/api/v1/invoices', ['customer_id' => strtoupper($this->customerId)]);
        self::assertSame([$first->id()], $this->ids(), 'An id is read whatever its case.');
    }

    public function testTheStateNarrowsTheList(): void
    {
        [$paid, $overdue, $unpaid] = $this->inTenant($this->tenant, function (): array {
            $paid = $this->invoice('2026-11-01', '2099-12-31');
            $this->payment('2026-11-05', [$paid->id() => '2420']);

            return [$paid, $this->invoice('2020-01-01', '2020-01-15'), $this->invoice('2026-11-01', '2099-12-31')];
        });

        $this->get('/api/v1/invoices', ['state' => 'paid']);
        self::assertSame([$paid->id()], $this->ids());
        self::assertSame('paid', $this->at('data', 0, 'payment_state'));
        $this->get('/api/v1/invoices', ['state' => 'overdue']);
        self::assertSame([$overdue->id()], $this->ids());
        self::assertTrue($this->at('data', 0, 'overdue'));
        $this->get('/api/v1/invoices', ['state' => 'unpaid']);
        self::assertSame([$unpaid->id(), $overdue->id()], $this->ids());
    }

    public function testTheTrashIsListedOnlyWhenAskedFor(): void
    {
        [$kept, $trashed] = $this->inTenant($this->tenant, function (): array {
            $kept = $this->invoice();
            $trashed = $this->invoice();
            self::getContainer()->get(TrashInvoice::class)->trash($trashed->id(), 'Wrong customer');

            return [$kept, $trashed];
        });

        $this->get('/api/v1/invoices');
        self::assertSame([$kept->id()], $this->ids());
        $this->get('/api/v1/invoices', ['trashed' => 'false']);
        self::assertSame([$kept->id()], $this->ids());
        $this->get('/api/v1/invoices', ['trashed' => 'true']);
        self::assertSame([$trashed->id()], $this->ids());
        self::assertTrue($this->at('data', 0, 'trashed'));
    }

    public function testTheIssueDaysAreInclusive(): void
    {
        [$early, $middle, $late] = $this->inTenant($this->tenant, fn (): array => [
            $this->invoice('2026-11-01', '2026-11-30'),
            $this->invoice('2026-11-05', '2026-11-30'),
            $this->invoice('2026-11-10', '2026-11-30'),
        ]);

        $this->get('/api/v1/invoices', ['issued_from' => '2026-11-05']);
        self::assertSame([$late->id(), $middle->id()], $this->ids());
        $this->get('/api/v1/invoices', ['issued_to' => '2026-11-05']);
        self::assertSame([$middle->id(), $early->id()], $this->ids());
        $this->get('/api/v1/invoices', ['issued_from' => '2026-11-05', 'issued_to' => '2026-11-05']);
        self::assertSame([$middle->id()], $this->ids());
    }

    public function testTheTextIsLookedForInTheNumber(): void
    {
        [$first, $second] = $this->inTenant($this->tenant, fn (): array => [$this->invoice(), $this->invoice()]);

        $this->get('/api/v1/invoices', ['q' => ' '.$second->number().' ']);
        self::assertSame([$second->id()], $this->ids());
        $this->get('/api/v1/invoices', ['q' => 'Nothing like it']);
        self::assertSame([], $this->ids());
        self::assertNotSame($first->id(), $second->id());
    }

    public function testTheCursorPagesThroughTheListWithoutOverlap(): void
    {
        $ids = $this->inTenant($this->tenant, fn (): array => array_map(fn (int $day): string => $this->invoice(\sprintf('2026-11-%02d', $day), '2026-11-30')->id(), [1, 2, 3, 4, 5]));

        self::assertSame([[$ids[4], $ids[3]], [$ids[2], $ids[1]], [$ids[0]]], $this->pages('/api/v1/invoices', 2));
        $this->get('/api/v1/invoices', ['limit' => '2']);
        self::assertSame(Cursor::encode($ids[3]), $this->json()['next_cursor']);
        self::assertSame([[$ids[4], $ids[3], $ids[2], $ids[1], $ids[0]]], $this->pages('/api/v1/invoices', 5), 'A slice that ends with the last row has no cursor.');
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidParameters(): iterable
    {
        yield 'a cursor that is not base64url' => [['cursor' => '!!!'], 'cursor'];
        yield 'a cursor that is no id' => [['cursor' => Cursor::encode('not-an-id')], 'cursor'];
        yield 'a cursor with a space' => [['cursor' => ' '.Cursor::encode('01963c5e-0000-7000-8000-000000000001')], 'cursor'];
        yield 'a limit above the maximum' => [['limit' => '101'], 'limit'];
        yield 'a limit below one' => [['limit' => '0'], 'limit'];
        yield 'a limit that is no number' => [['limit' => 'many'], 'limit'];
        yield 'an unknown kind' => [['kind' => 'receipt'], 'kind'];
        yield 'an unknown state' => [['state' => 'cancelled'], 'state'];
        yield 'a firm that is no id' => [['firm_id' => 'nope'], 'firm_id'];
        yield 'a customer that is no id' => [['customer_id' => 'nope'], 'customer_id'];
        yield 'a trash flag that is no boolean' => [['trashed' => 'maybe'], 'trashed'];
        yield 'a first day that is no day' => [['issued_from' => '2026-13-01'], 'issued_from'];
        yield 'a last day in another format' => [['issued_to' => '1. 11. 2026'], 'issued_to'];
    }

    /**
     * @param array<string, string> $query
     */
    #[DataProvider('invalidParameters')]
    public function testAParameterThatCannotBeUsedIsNamed(array $query, string $parameter): void
    {
        $this->get('/api/v1/invoices', $query);

        $this->assertProblem(400, 'invalid_parameter', ['parameter' => $parameter, 'title' => 'Bad Request']);
    }

    public function testTheLimitsOfTheLimitAreAccepted(): void
    {
        $this->get('/api/v1/invoices', ['limit' => '1']);
        self::assertResponseIsSuccessful();
        $this->get('/api/v1/invoices', ['limit' => '100']);
        self::assertResponseIsSuccessful();
        $this->get('/api/v1/invoices', ['cursor' => '']);
        self::assertResponseIsSuccessful();
    }

    public function testAnInvoiceOfAnotherOrganisationIsNotFound(): void
    {
        $foreign = $this->foreignTenant();
        $theirs = $this->inTenant($foreign, function (): Invoice {
            [$firm, $series, $customer] = $this->firmWithCustomer();

            return $this->invoice(firmId: $firm, seriesId: $series, customerId: $customer);
        });

        $this->get('/api/v1/invoices');
        self::assertSame([], $this->ids());

        foreach ([$theirs->id(), Id::generate(), 'not-an-id'] as $id) {
            $this->get('/api/v1/invoices/'.$id);
            $this->assertProblem(404, 'not_found', ['detail' => 'No such invoice.']);
        }
    }

    public function testATokenWithoutTheReadScopeReadsNothing(): void
    {
        $invoice = $this->inTenant($this->tenant, fn (): Invoice => $this->invoice());
        $secret = $this->issued([ApiScope::Write]);

        $this->get('/api/v1/invoices', secret: $secret);
        $this->assertProblem(403, 'insufficient_scope');

        $this->get('/api/v1/invoices/'.$invoice->id(), secret: $secret);
        $this->assertProblem(403, 'insufficient_scope');
    }
}
