<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Infrastructure\Api;

use Foresi\Identity\Domain\ApiScope;
use Foresi\Invoicing\Domain\Payment;
use Foresi\Shared\Infrastructure\Api\Cursor;
use Foresi\Tests\Invoicing\Support\IssuesInvoices;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * GET /api/v1/payments and /api/v1/payments/{id}: the payments of the token's
 * organisation with the invoices they pay, narrowed by the query string and read
 * by cursor.
 */
final class PaymentApiTest extends ApiTestCase
{
    use IssuesInvoices;

    public function testACancelledPaymentIsListedWhenAskedForAndSaysWhen(): void
    {
        [$live, $cancelled] = $this->flushed(fn (): array => [
            $this->payment(note: 'Live', amount: '100'),
            $this->payment(note: 'Cancelled', amount: '100', cancelled: true),
        ]);

        $this->get('/api/v1/payments');
        self::assertSame([$live->id()], $this->ids());
        $this->get('/api/v1/payments', ['include_cancelled' => 'true']);
        self::assertSame([$cancelled->id(), $live->id()], $this->ids());
        self::assertSame(new \DateTimeImmutable('2026-11-11 08:00:00')->format(\DATE_ATOM), $this->at('data', 0, 'cancelled_at'));
    }

    public function testTheDaysAreInclusive(): void
    {
        [$early, $middle, $late] = $this->flushed(fn (): array => array_map(fn (string $day): Payment => $this->payment($day, amount: '100'), ['2026-11-01', '2026-11-05', '2026-11-10']));

        $this->get('/api/v1/payments', ['received_from' => '2026-11-05']);
        self::assertSame([$late->id(), $middle->id()], $this->ids());
        $this->get('/api/v1/payments', ['received_to' => '2026-11-05']);
        self::assertSame([$middle->id(), $early->id()], $this->ids());
    }

    public function testTheCursorPagesThroughTheListWithoutOverlap(): void
    {
        $ids = $this->flushed(fn (): array => array_map(fn (int $day): string => $this->payment(\sprintf('2026-11-%02d', $day), amount: '100')->id(), [1, 2, 3]));

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
        yield 'a retired firm' => [['firm_id' => 'nope'], 'firm_id'];
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

    public function testATokenScopedToAnotherResourceReadsNothing(): void
    {
        $payment = $this->flushed(fn (): Payment => $this->payment(amount: '100'));
        $secret = $this->issued([ApiScope::from('invoices:read')]);

        $this->get('/api/v1/payments', secret: $secret);
        $this->assertProblem(403, 'insufficient_scope');

        $this->get('/api/v1/payments/'.$payment->id(), secret: $secret);
        $this->assertProblem(403, 'insufficient_scope');
    }
}
