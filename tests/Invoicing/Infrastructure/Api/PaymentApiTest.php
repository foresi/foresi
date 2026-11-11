<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure\Api;

use App\Identity\Domain\ApiScope;
use App\Invoicing\Domain\Payment;
use App\Invoicing\Domain\PaymentDirection;
use App\Shared\Domain\Id;
use App\Shared\Infrastructure\Api\Cursor;
use App\Tests\Shared\Infrastructure\Api\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * GET /api/v1/payments and /api/v1/payments/{id}: the payments of the token's
 * organisation with the invoices they pay, narrowed by the query string and read
 * by cursor.
 */
final class PaymentApiTest extends ApiTestCase
{
    public function testAPaymentIsWrittenWithItsAllocationsInTheListAndAlone(): void
    {
        [$invoice, $payment] = $this->inTenant($this->tenant, function (): array {
            $invoice = $this->invoice();

            return [$invoice, $this->payment('2026-11-12', [$invoice->id() => '1000'], note: 'Paid by bank')];
        });
        $expected = [
            'id' => $payment->id(),
            'firm' => ['id' => $this->firmId, 'name' => 'Example Firm s.r.o.'],
            'received_on' => '2026-11-12',
            'amount' => ['amount_minor' => 100000, 'currency' => 'CZK'],
            'direction' => 'incoming',
            'method' => 'bank_transfer',
            'source' => 'manual',
            'note' => 'Paid by bank',
            'bank_movement_id' => null,
            'cancelled_at' => null,
            'created_at' => new \DateTimeImmutable('2026-11-10 12:00:00')->format(\DATE_ATOM),
            'allocations' => [[
                'invoice' => ['id' => $invoice->id(), 'number' => $invoice->number()],
                'amount' => ['amount_minor' => 100000, 'currency' => 'CZK'],
                'settlement' => ['amount_minor' => 0, 'currency' => 'CZK'],
            ]],
        ];

        $this->get('/api/v1/payments');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        self::assertSame(['data' => [$expected], 'next_cursor' => null], $this->json());

        $this->get('/api/v1/payments/'.$payment->id());

        self::assertResponseIsSuccessful();
        self::assertSame(['data' => $expected], $this->json());
    }

    public function testACancelledPaymentIsListedWhenAskedForAndSaysWhen(): void
    {
        [$live, $cancelled] = $this->inTenant($this->tenant, fn (): array => [
            $this->payment(note: 'Live', amount: '100'),
            $this->payment(note: 'Cancelled', amount: '100', cancelled: true),
        ]);

        $this->get('/api/v1/payments');
        self::assertSame([$live->id()], $this->ids());
        $this->get('/api/v1/payments', ['include_cancelled' => 'true']);
        self::assertSame([$cancelled->id(), $live->id()], $this->ids());
        self::assertSame(new \DateTimeImmutable('2026-11-11 08:00:00')->format(\DATE_ATOM), $this->at('data', 0, 'cancelled_at'));
    }

    public function testTheFirmTheInvoiceAndTheDirectionNarrowTheList(): void
    {
        [$first, $other, $refund, $otherFirm, $invoice] = $this->inTenant($this->tenant, function (): array {
            $invoice = $this->invoice();
            $first = $this->payment(parts: [$invoice->id() => '1000']);
            $refund = $this->payment(parts: [$invoice->id() => '100'], direction: PaymentDirection::Refund);
            [$firm] = $this->firmWithCustomer('Other Firm s.r.o.', '25596641');

            return [$first, $this->payment(firmId: $firm, amount: '100'), $refund, $firm, $invoice];
        });

        $this->get('/api/v1/payments', ['firm_id' => $otherFirm]);
        self::assertSame([$other->id()], $this->ids());
        $this->get('/api/v1/payments', ['firm_id' => $this->firmId]);
        self::assertSame([$refund->id(), $first->id()], $this->ids());
        $this->get('/api/v1/payments', ['invoice_id' => $invoice->id()]);
        self::assertSame([$refund->id(), $first->id()], $this->ids());
        $this->get('/api/v1/payments', ['invoice_id' => strtoupper($invoice->id())]);
        self::assertSame([$refund->id(), $first->id()], $this->ids(), 'An id is read whatever its case.');
        $this->get('/api/v1/payments', ['direction' => 'refund']);
        self::assertSame([$refund->id()], $this->ids());
        $this->get('/api/v1/payments', ['direction' => 'incoming']);
        self::assertSame([$other->id(), $first->id()], $this->ids());
    }

    public function testTheDaysAreInclusive(): void
    {
        [$early, $middle, $late] = $this->inTenant($this->tenant, fn (): array => array_map(fn (string $day): Payment => $this->payment($day, amount: '100'), ['2026-11-01', '2026-11-05', '2026-11-10']));

        $this->get('/api/v1/payments', ['received_from' => '2026-11-05']);
        self::assertSame([$late->id(), $middle->id()], $this->ids());
        $this->get('/api/v1/payments', ['received_to' => '2026-11-05']);
        self::assertSame([$middle->id(), $early->id()], $this->ids());
    }

    public function testTheCursorPagesThroughTheListWithoutOverlap(): void
    {
        $ids = $this->inTenant($this->tenant, fn (): array => array_map(fn (int $day): string => $this->payment(\sprintf('2026-11-%02d', $day), amount: '100')->id(), [1, 2, 3]));

        self::assertSame([[$ids[2], $ids[1]], [$ids[0]]], $this->pages('/api/v1/payments', 2));
        self::assertSame([[$ids[2], $ids[1], $ids[0]]], $this->pages('/api/v1/payments', 3));
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidParameters(): iterable
    {
        yield 'a cursor that is no id' => [['cursor' => Cursor::encode('not-an-id')], 'cursor'];
        yield 'a limit above the maximum' => [['limit' => '101'], 'limit'];
        yield 'a firm that is no id' => [['firm_id' => 'nope'], 'firm_id'];
        yield 'an invoice that is no id' => [['invoice_id' => 'nope'], 'invoice_id'];
        yield 'an unknown direction' => [['direction' => 'sideways'], 'direction'];
        yield 'a first day that is no day' => [['received_from' => '2026-02-30'], 'received_from'];
        yield 'a last day that is no day' => [['received_to' => 'yesterday'], 'received_to'];
        yield 'a flag that is no boolean' => [['include_cancelled' => 'perhaps'], 'include_cancelled'];
    }

    /**
     * @param array<string, string> $query
     */
    #[DataProvider('invalidParameters')]
    public function testAParameterThatCannotBeUsedIsNamed(array $query, string $parameter): void
    {
        $this->get('/api/v1/payments', $query);

        $this->assertProblem(400, 'invalid_parameter', ['parameter' => $parameter]);
    }

    public function testAPaymentOfAnotherOrganisationIsNotFound(): void
    {
        $theirs = $this->inTenant($this->foreignTenant(), function (): Payment {
            [$firm] = $this->firmWithCustomer();

            return $this->payment(firmId: $firm, amount: '100');
        });

        $this->get('/api/v1/payments');
        self::assertSame([], $this->ids());

        foreach ([$theirs->id(), Id::generate(), 'not-an-id'] as $id) {
            $this->get('/api/v1/payments/'.$id);
            $this->assertProblem(404, 'not_found', ['detail' => 'No such payment.']);
        }
    }

    public function testATokenWithoutTheReadScopeReadsNothing(): void
    {
        $payment = $this->inTenant($this->tenant, fn (): Payment => $this->payment(amount: '100'));
        $secret = $this->issued([ApiScope::Write]);

        $this->get('/api/v1/payments', secret: $secret);
        $this->assertProblem(403, 'insufficient_scope');

        $this->get('/api/v1/payments/'.$payment->id(), secret: $secret);
        $this->assertProblem(403, 'insufficient_scope');
    }
}
